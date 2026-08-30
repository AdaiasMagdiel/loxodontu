<?php

namespace App\Edge;

use AdaiasMagdiel\PdoRestify\Http\Request as RestRequest;
use App\Controllers\Rest;
use App\Database;
use PDO;
use Throwable;

class EdgeFunctionRunner
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array{id: int, email: string, role: ?string}|null $auth
     */
    public static function call(
        int $projectId,
        string $slug,
        string $method = 'POST',
        array $headers = [],
        array $query = [],
        array $body = [],
        ?array $auth = null,
    ): FunctionResponse {
        return (new self(Database::getConn('default')))->invoke($projectId, $slug, $method, $headers, $query, $body, $auth);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array{id: int, email: string, role: ?string}|null $auth
     */
    public function invoke(
        int $projectId,
        string $slug,
        string $method,
        array $headers = [],
        array $query = [],
        array $body = [],
        ?array $auth = null,
    ): FunctionResponse {
        $function = $this->findFunction($projectId, $slug);

        if (!$function || !(bool) $function['enabled']) {
            return FunctionResponse::json(['error' => 'Function not found'], 404);
        }

        $method = strtoupper($method);
        $methods = $function['methods'] !== null ? json_decode($function['methods'], true) : null;
        if (is_array($methods) && $methods !== [] && !in_array($method, array_map('strtoupper', $methods), true)) {
            return FunctionResponse::json(['error' => 'Method not allowed'], 405);
        }

        if (!empty($function['source_code'])) {
            return $this->invokeSource($function, $projectId, $method, $headers, $query, $body, $auth);
        }

        $target = trim((string) ($function['handler'] ?? ''));
        if (!str_contains($target, '::')) {
            return FunctionResponse::json(['error' => 'Function handler must use ClassName::method syntax'], 500);
        }

        [$class, $handler] = explode('::', $target, 2);
        if (!is_callable([$class, $handler])) {
            return FunctionResponse::json(['error' => "Function handler {$target} is not callable"], 500);
        }

        $request = new FunctionRequest($projectId, $method, $headers, $query, $body, $function, $auth);

        try {
            $result = [$class, $handler]($request);
            $this->markInvoked((int) $function['id']);
        } catch (Throwable $e) {
            return FunctionResponse::json(['error' => $e->getMessage()], 500);
        }

        if ($result instanceof FunctionResponse) {
            return $result;
        }

        return FunctionResponse::json($result);
    }

    private function findFunction(int $projectId, string $slug): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM project_functions WHERE project_id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$projectId, $slug]);

        return $stmt->fetch();
    }

    private function markInvoked(int $id): void
    {
        $this->pdo->prepare('UPDATE project_functions SET last_invoked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, mixed> $function
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array{id: int, email: string, role: ?string}|null $auth
     */
    private function invokeSource(
        array $function,
        int $projectId,
        string $method,
        array $headers,
        array $query,
        array $body,
        ?array $auth,
    ): FunctionResponse {
        $sandboxDir = $this->sandboxDir($projectId, (string) $function['slug']);
        if (!is_dir($sandboxDir) && !mkdir($sandboxDir, 0700, true)) {
            return FunctionResponse::json(['error' => 'Unable to prepare function sandbox'], 500);
        }
        $runtimeDir = $this->prepareRuntimeDir();
        if ($runtimeDir === null) {
            return FunctionResponse::json(['error' => 'Unable to prepare function sandbox runtime'], 500);
        }

        $codePath = $sandboxDir . '/index_' . hash('sha256', (string) $function['source_code']) . '.php';
        file_put_contents($codePath, (string) $function['source_code']);

        $timeout = max(1, (int) ($function['timeout_seconds'] ?? 10));
        $memoryLimit = max(16, (int) ($function['memory_limit_mb'] ?? 32)) . 'M';
        $input = json_encode([
            'code_path' => $codePath,
            'request' => [
                'project_id' => $projectId,
                'method' => $method,
                'headers' => $headers,
                'query' => $query,
                'body' => $body,
                'function' => $this->publicFunctionShape($function),
                'auth' => $auth,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $phpBinary = $this->phpBinary();
        $process = proc_open([
            $phpBinary,
            '-q',
            '-d',
            'open_basedir=' . $runtimeDir . PATH_SEPARATOR . $sandboxDir,
            '-d',
            'memory_limit=' . $memoryLimit,
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-d',
            'allow_url_fopen=0',
            '-d',
            'disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,putenv,mail,link,symlink,readlink,realpath,glob,scandir,opendir,readdir,dir',
            $runtimeDir . '/SandboxRunner.php',
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            // Database bridge: the child writes query requests to fd 3 and
            // reads our responses from fd 4. It never gets a PDO connection
            // or database credentials directly — see App\Edge\Db.
            3 => ['pipe', 'w'],
            4 => ['pipe', 'r'],
        ], $pipes);

        if (!is_resource($process)) {
            return FunctionResponse::json(['error' => 'Unable to start function sandbox'], 500);
        }

        fwrite($pipes[0], $input ?: '{}');
        fclose($pipes[0]);
        foreach ([1, 2, 3] as $index) {
            stream_set_blocking($pipes[$index], false);
        }

        $stdout = '';
        $stderr = '';
        $dbRequestBuffer = '';
        $deadline = microtime(true) + $timeout;
        $exitCode = null;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $dbRequestBuffer .= stream_get_contents($pipes[3]) ?: '';
            $dbRequestBuffer = $this->serviceDbRequests($dbRequestBuffer, $pipes[4], $projectId, $auth);

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                foreach ([1, 2, 3, 4] as $index) {
                    fclose($pipes[$index]);
                }
                proc_close($process);

                return FunctionResponse::json(['error' => 'Function execution timed out'], 504);
            }

            usleep(50000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        foreach ([1, 2, 3, 4] as $index) {
            fclose($pipes[$index]);
        }
        proc_close($process);

        if ($exitCode !== 0) {
            return FunctionResponse::json(['error' => trim($stderr) ?: 'Function execution failed'], 500);
        }

        $jsonError = null;
        $payload = $this->decodeSandboxPayload($stdout, $jsonError);
        if (!is_array($payload)) {
            return $this->invalidSandboxResponse($stdout, $stderr, $exitCode, $jsonError, $phpBinary);
        }

        $this->markInvoked((int) $function['id']);

        return new FunctionResponse(
            $payload['body'] ?? null,
            (int) ($payload['status'] ?? 200),
            is_array($payload['headers'] ?? null) ? $payload['headers'] : [],
        );
    }

    /**
     * Drains complete newline-delimited requests from the child's database
     * bridge buffer, answers each one on $responsePipe, and returns
     * whatever partial line is left over for the next call.
     *
     * @param resource $responsePipe
     * @param array{id: int, email: string, role: ?string}|null $auth
     */
    private function serviceDbRequests(string $buffer, $responsePipe, int $projectId, ?array $auth): string
    {
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline);
            $buffer = substr($buffer, $newline + 1);

            $payload = json_decode($line, true);
            $result = is_array($payload)
                ? $this->handleDbCall($projectId, $auth, $payload)
                : ['status' => 400, 'body' => null, 'error' => 'Invalid database request'];

            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            fwrite($responsePipe, ($encoded !== false ? $encoded : '{"status":500,"body":null,"error":"Internal error"}') . "\n");
            fflush($responsePipe);
        }

        return $buffer;
    }

    /**
     * Executes one edge-function database call against this project's own
     * tables only, through the exact same pdo-restify Api + RLS conditions
     * REST passthrough uses (see Rest::scopedApi()) — the sandboxed child
     * never sees a PDO connection or another project's data.
     *
     * @param array{id: int, email: string, role: ?string}|null $auth
     * @param array<string, mixed> $payload
     * @return array{status: int, body: mixed, error: ?string}
     */
    private function handleDbCall(int $projectId, ?array $auth, array $payload): array
    {
        $method = strtoupper((string) ($payload['method'] ?? 'GET'));
        $segments = trim((string) ($payload['path'] ?? ''), '/');
        $parts = $segments === '' ? [] : explode('/', $segments);
        $table = array_shift($parts) ?? '';
        $id = $parts[0] ?? null;

        $query = is_array($payload['query'] ?? null) ? $payload['query'] : [];
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

        $scoped = Rest::scopedApi($this->pdo, $projectId, $table, $auth);
        if ($scoped === null) {
            return ['status' => 404, 'body' => ['error' => 'Resource not found'], 'error' => 'Resource not found'];
        }

        $restPath = $scoped['physicalTable'] . ($id !== null ? '/' . $id : '');
        $response = $scoped['api']->handle(new RestRequest($method, $restPath, $query, $body));

        return ['status' => $response->status, 'body' => $response->body, 'error' => null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeSandboxPayload(string $stdout, ?string &$jsonError = null): ?array
    {
        $payload = json_decode($stdout, true);
        if (is_array($payload)) {
            $jsonError = null;
            return $payload;
        }
        $jsonError = json_last_error_msg();

        $start = strpos($stdout, '{');
        $end = strrpos($stdout, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $payload = json_decode(substr($stdout, $start, $end - $start + 1), true);
        $jsonError = is_array($payload) ? null : json_last_error_msg();

        return is_array($payload) ? $payload : null;
    }

    private function phpBinary(): string
    {
        $configured = trim((string) env('EDGE_PHP_BINARY', ''));
        if ($configured !== '') {
            return $configured;
        }

        $dir = dirname(PHP_BINARY);
        foreach ([$dir . '/php', $dir . '/php-cli', '/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return PHP_BINARY;
    }

    private function prepareRuntimeDir(): ?string
    {
        $runtimeDir = sys_get_temp_dir() . '/loxodontu-edge-runtime/' . hash('sha256', __DIR__);
        if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0700, true)) {
            return null;
        }

        foreach (['SandboxRunner.php', 'FunctionRequest.php', 'FunctionResponse.php', 'Http.php', 'DbResult.php', 'DbQuery.php', 'Db.php'] as $file) {
            $source = __DIR__ . '/' . $file;
            $target = $runtimeDir . '/' . $file;

            if (!is_file($source)) {
                return null;
            }

            if (!is_file($target) || hash_file('sha256', $source) !== hash_file('sha256', $target)) {
                if (!copy($source, $target)) {
                    return null;
                }
                chmod($target, 0600);
            }
        }

        return $runtimeDir;
    }

    private function invalidSandboxResponse(string $stdout, string $stderr, ?int $exitCode, ?string $jsonError, string $phpBinary): FunctionResponse
    {
        $body = ['error' => 'Function returned an invalid response'];

        if (env('DEBUG') === 'true') {
            $body['debug'] = [
                'php_binary' => PHP_BINARY,
                'sandbox_php_binary' => $phpBinary,
                'php_sapi' => PHP_SAPI,
                'exit_code' => $exitCode,
                'json_error' => $jsonError,
                'stdout_length' => strlen($stdout),
                'stderr_length' => strlen($stderr),
                'stdout_preview' => mb_substr($stdout, 0, 4000),
                'stderr_preview' => mb_substr($stderr, 0, 4000),
                'stdout_base64_preview' => base64_encode(substr($stdout, 0, 4000)),
            ];
        }

        return FunctionResponse::json($body, 500);
    }

    private function sandboxDir(int $projectId, string $slug): string
    {
        return sys_get_temp_dir() . '/loxodontu-edge-functions/' . $projectId . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
    }

    /** @param array<string, mixed> $function */
    private function publicFunctionShape(array $function): array
    {
        unset($function['source_code'], $function['handler']);

        return $function;
    }
}
