<?php

namespace App\Edge;

use AdaiasMagdiel\PdoRestify\Http\Request as RestRequest;
use App\Controllers\Rest;
use App\Database;
use App\Net\SafeHttpClient;
use PDO;
use Throwable;

class EdgeFunctionRunner
{
    private const MAX_OUTPUT_BYTES = 10 * 1024 * 1024;
    private const MAX_HTTP_CALLS = 20;

    private const DISABLED_FUNCTIONS = [
        // Processes and signals
        'exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen',
        'pcntl_exec', 'pcntl_fork', 'pcntl_rfork', 'pcntl_unshare', 'pcntl_signal', 'pcntl_alarm',
        'posix_kill', 'posix_mkfifo', 'posix_setpgid', 'posix_setsid', 'posix_setuid', 'posix_setgid',
        'posix_seteuid', 'posix_setegid', 'proc_nice',
        // Network — outbound HTTP goes through the bridge (App\Edge\Http)
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'stream_socket_pair',
        'socket_create', 'socket_create_listen', 'socket_create_pair', 'socket_connect', 'socket_bind',
        'socket_listen', 'socket_accept', 'socket_addrinfo_connect', 'socket_addrinfo_bind',
        'curl_init', 'curl_exec', 'curl_multi_init', 'curl_multi_exec', 'curl_share_init', 'curl_copy_handle',
        'ftp_connect', 'ftp_ssl_connect', 'mysqli_connect', 'mysqli_init', 'mysqli_real_connect',
        'imap_open', 'ldap_connect', 'mail',
        // DNS lookups double as an exfiltration channel
        'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'dns_get_record', 'dns_check_record',
        'checkdnsrr', 'dns_get_mx', 'getmxrr',
        // Environment, filesystem discovery and links
        'putenv', 'phpinfo', 'link', 'symlink', 'readlink', 'realpath', 'glob', 'scandir', 'opendir', 'readdir', 'dir',
        // Shared memory / IPC across processes
        'shmop_open', 'msg_get_queue', 'sem_get', 'shm_attach',
        'dl',
    ];

    private const DISABLED_CLASSES = ['PDO', 'mysqli', 'SoapClient', 'SoapServer', 'FFI'];

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

        if (!$this->projectOwnerIsAdmin($projectId)) {
            return FunctionResponse::json(['error' => 'Class-based function handlers are only available to platform administrators'], 403);
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

    /**
     * Re-checked on every invocation so a handler saved before the
     * admin-only rule existed can't keep running unsandboxed code.
     */
    private function projectOwnerIsAdmin(int $projectId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.is_admin FROM projects p INNER JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$projectId]);

        return (bool) $stmt->fetchColumn();
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
        $runtimeDir = $this->prepareRuntimeDir($sandboxDir);
        if ($runtimeDir === null) {
            return FunctionResponse::json(['error' => 'Unable to prepare function sandbox runtime'], 500);
        }

        $codePath = $sandboxDir . '/index_' . hash('sha256', (string) $function['source_code']) . '.php';
        file_put_contents($codePath, (string) $function['source_code']);
        $this->removeStaleSources($sandboxDir, $codePath);

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
        $phpArgs = [$phpBinary, '-q'];
        foreach ($this->sandboxIni($sandboxDir, $memoryLimit) as $directive => $value) {
            array_push($phpArgs, '-d', "{$directive}={$value}");
        }
        $phpArgs[] = $runtimeDir . '/SandboxRunner.php';

        $process = proc_open($phpArgs, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            // Sandbox bridge (App\Edge\Bridge): the child writes database and
            // HTTP requests to fd 3 and reads our responses from fd 4. It
            // never gets a PDO connection, credentials or network access of
            // its own.
            3 => ['pipe', 'w'],
            4 => ['pipe', 'r'],
        ], $pipes, $sandboxDir, $this->sandboxEnv());

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
        $bridgeBuffer = '';
        $deadline = microtime(true) + $timeout;
        $exitCode = null;
        $httpCalls = 0;

        $abort = function (string $error, int $status) use ($process, $pipes): FunctionResponse {
            proc_terminate($process, 9);
            foreach ([1, 2, 3, 4] as $index) {
                fclose($pipes[$index]);
            }
            proc_close($process);

            return FunctionResponse::json(['error' => $error], $status);
        };

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $bridgeBuffer .= stream_get_contents($pipes[3]) ?: '';

            // The child can print or send as much as it likes; the parent
            // (a web worker) must not buffer it all into memory.
            if (strlen($stdout) + strlen($stderr) + strlen($bridgeBuffer) > self::MAX_OUTPUT_BYTES) {
                return $abort('Function output exceeded the size limit', 500);
            }

            $bridgeBuffer = $this->serviceBridgeRequests($bridgeBuffer, $pipes[4], $projectId, $auth, $deadline, $httpCalls);

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                return $abort('Function execution timed out', 504);
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
     * Drains complete newline-delimited requests from the child's bridge
     * buffer (see App\Edge\Bridge), answers each one on $responsePipe, and
     * returns whatever partial line is left over for the next call.
     *
     * @param resource $responsePipe
     * @param array{id: int, email: string, role: ?string}|null $auth
     */
    private function serviceBridgeRequests(string $buffer, $responsePipe, int $projectId, ?array $auth, float $deadline, int &$httpCalls): string
    {
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline);
            $buffer = substr($buffer, $newline + 1);

            $payload = json_decode($line, true);
            $result = match (true) {
                !is_array($payload) => ['status' => 400, 'body' => null, 'error' => 'Invalid bridge request'],
                ($payload['kind'] ?? 'db') === 'http' => $this->handleHttpCall($payload, $deadline, $httpCalls),
                default => $this->handleDbCall($projectId, $auth, $payload),
            };

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
     * Performs one outbound HTTP request on the sandbox's behalf through
     * SafeHttpClient, bounded by the function's remaining time and a
     * per-invocation call budget.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleHttpCall(array $payload, float $deadline, int &$httpCalls): array
    {
        if (++$httpCalls > self::MAX_HTTP_CALLS) {
            return ['exception' => 'Too many outbound HTTP requests in one invocation (max ' . self::MAX_HTTP_CALLS . ').'];
        }

        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            return ['exception' => 'Function execution time exhausted.'];
        }

        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
        $body = isset($payload['body']) && is_string($payload['body']) ? $payload['body'] : null;

        try {
            return SafeHttpClient::request(
                (string) ($payload['method'] ?? 'GET'),
                (string) ($payload['url'] ?? ''),
                $body,
                $headers,
                min((int) ($payload['timeout'] ?? 5), $remaining),
            );
        } catch (\RuntimeException $e) {
            return ['exception' => $e->getMessage()];
        }
    }

    /**
     * PHP settings for the sandboxed child. open_basedir confines the
     * filesystem to this function's own directory; everything that could
     * reach the network, other processes or native code directly is
     * disabled — outbound HTTP and the database go through the bridge.
     *
     * @return array<string, string>
     */
    private function sandboxIni(string $sandboxDir, string $memoryLimit): array
    {
        return [
            'open_basedir' => $sandboxDir,
            'memory_limit' => $memoryLimit,
            'display_errors' => '0',
            'log_errors' => '0',
            'allow_url_fopen' => '0',
            'allow_url_include' => '0',
            'enable_dl' => '0',
            // FFI defaults to "preload", which still allows FFI::cdef() in CLI.
            'ffi.enable' => '0',
            'auto_prepend_file' => '',
            'auto_append_file' => '',
            'sendmail_path' => '/bin/false',
            'disable_functions' => implode(',', self::DISABLED_FUNCTIONS),
            'disable_classes' => implode(',', self::DISABLED_CLASSES),
        ];
    }

    /**
     * The child gets a minimal environment, never the web server's — which
     * may carry database credentials or other secrets.
     *
     * @return array<string, string>
     */
    private function sandboxEnv(): array
    {
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin'];

        if (PHP_OS_FAMILY === 'Windows') {
            // PHP on Windows needs these to start (CSPRNG, temp paths).
            foreach (['SystemRoot', 'PATH', 'TEMP', 'TMP'] as $name) {
                $value = getenv($name);
                if ($value !== false) {
                    $env[$name] = $value;
                }
            }
        }

        return $env;
    }

    private function removeStaleSources(string $sandboxDir, string $currentCodePath): void
    {
        foreach (glob($sandboxDir . '/index_*.php') ?: [] as $file) {
            if ($file !== $currentCodePath) {
                @unlink($file);
            }
        }
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

    /**
     * Copies the sandbox runtime into the function's own directory. It used
     * to live in one directory shared by every tenant, which each sandbox
     * could write to (it had to be inside open_basedir) — so one tenant's
     * function could tamper with the runtime another tenant's function was
     * about to load. Per-function copies leave nothing shared to tamper with.
     */
    private function prepareRuntimeDir(string $sandboxDir): ?string
    {
        $runtimeDir = $sandboxDir . '/runtime';
        if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0700, true)) {
            return null;
        }

        foreach (['SandboxRunner.php', 'Bridge.php', 'FunctionRequest.php', 'FunctionResponse.php', 'Http.php', 'DbResult.php', 'DbQuery.php', 'Db.php'] as $file) {
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
