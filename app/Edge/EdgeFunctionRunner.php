<?php

namespace App\Edge;

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

        $process = proc_open([
            PHP_BINARY,
            '-q',
            '-d',
            'open_basedir=' . __DIR__ . PATH_SEPARATOR . $sandboxDir,
            '-d',
            'memory_limit=' . $memoryLimit,
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-d',
            'disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,putenv,mail,link,symlink,readlink,realpath,glob,scandir,opendir,readdir,dir',
            __DIR__ . '/SandboxRunner.php',
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return FunctionResponse::json(['error' => 'Unable to start function sandbox'], 500);
        }

        fwrite($pipes[0], $input ?: '{}');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $exitCode = null;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                foreach ([1, 2] as $index) {
                    fclose($pipes[$index]);
                }
                proc_close($process);

                return FunctionResponse::json(['error' => 'Function execution timed out'], 504);
            }

            usleep(50000);
        }

        foreach ([1, 2] as $index) {
            $stdout .= stream_get_contents($pipes[$index]) ?: '';
            fclose($pipes[$index]);
        }
        proc_close($process);

        if ($exitCode !== 0) {
            return FunctionResponse::json(['error' => trim($stderr) ?: 'Function execution failed'], 500);
        }

        $payload = $this->decodeSandboxPayload($stdout);
        if (!is_array($payload)) {
            return FunctionResponse::json(['error' => 'Function returned an invalid response'], 500);
        }

        $this->markInvoked((int) $function['id']);

        return new FunctionResponse(
            $payload['body'] ?? null,
            (int) ($payload['status'] ?? 200),
            is_array($payload['headers'] ?? null) ? $payload['headers'] : [],
        );
    }

    /** @return array<string, mixed>|null */
    private function decodeSandboxPayload(string $stdout): ?array
    {
        $payload = json_decode($stdout, true);
        if (is_array($payload)) {
            return $payload;
        }

        $start = strpos($stdout, '{');
        $end = strrpos($stdout, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $payload = json_decode(substr($stdout, $start, $end - $start + 1), true);

        return is_array($payload) ? $payload : null;
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
