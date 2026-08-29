<?php

use App\Edge\FunctionRequest;
use App\Edge\FunctionResponse;
use App\Edge\Http;

return function (FunctionRequest $request): FunctionResponse {
    $checks = [];

    $checks['php_runtime'] = [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'memory_limit' => ini_get('memory_limit'),
        'open_basedir' => ini_get('open_basedir'),
        'disable_functions' => ini_get('disable_functions'),
    ];

    $checks['read_etc_passwd'] = probe_read('/etc/passwd');
    $checks['read_root_env_relative_to_edge_runtime'] = probe_read(dirname(runtime_file(), 3) . '/.env');
    $checks['read_project_env_from_common_paths'] = first_allowed_read([
        getcwd() . '/.env',
        dirname(getcwd()) . '/.env',
        dirname(getcwd(), 2) . '/.env',
        dirname(getcwd(), 3) . '/.env',
    ]);
    $checks['write_tmp_outside_sandbox'] = probe_write(sys_get_temp_dir() . '/loxodontu-edge-audit-outside.txt');
    $checks['write_inside_function_sandbox'] = probe_write(__DIR__ . '/audit-write-test.txt');
    $checks['read_runtime_source_via_reflection'] = probe_read(runtime_file());
    $checks['disabled_functions'] = [
        'exec' => probe_disabled_call('exec', 'id'),
        'shell_exec' => probe_disabled_call('shell_exec', 'id'),
        'system' => probe_disabled_call('system', 'id'),
        'proc_open' => probe_proc_open(),
        'scandir' => probe_disabled_call('scandir', __DIR__),
        'realpath' => probe_disabled_call('realpath', __DIR__),
    ];
    $checks['network'] = [
        'url_fopen_http_example_com' => probe_read('http://example.com'),
        'http_helper_available' => class_exists(Http::class),
    ];

    return FunctionResponse::json([
        'ok' => true,
        'method' => $request->method,
        'project_id' => $request->projectId,
        'sandbox_audit' => $checks,
        'verdict' => summarize($checks),
    ]);
};

function runtime_file(): string
{
    return (new ReflectionClass(FunctionRequest::class))->getFileName() ?: '';
}

/**
 * @return array<string, mixed>
 */
function probe_read(string $path): array
{
    try {
        $data = @file_get_contents($path);

        if ($data === false) {
            return [
                'status' => 'blocked_or_missing',
                'path' => $path,
            ];
        }

        return [
            'status' => 'allowed',
            'path' => $path,
            'bytes' => strlen($data),
            'sha256_prefix' => substr(hash('sha256', $data), 0, 16),
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'path' => $path,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @param list<string> $paths
 * @return array<string, mixed>
 */
function first_allowed_read(array $paths): array
{
    $results = [];

    foreach ($paths as $path) {
        $result = probe_read($path);
        $results[] = $result;

        if ($result['status'] === 'allowed') {
            break;
        }
    }

    return [
        'status' => $results !== [] && end($results)['status'] === 'allowed' ? 'allowed' : 'blocked_or_missing',
        'attempts' => $results,
    ];
}

/**
 * @return array<string, mixed>
 */
function probe_write(string $path): array
{
    try {
        $written = @file_put_contents($path, 'edge-audit:' . bin2hex(random_bytes(8)));

        if ($written === false) {
            return [
                'status' => 'blocked',
                'path' => $path,
            ];
        }

        @unlink($path);

        return [
            'status' => 'allowed',
            'path' => $path,
            'bytes' => $written,
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'path' => $path,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function probe_disabled_call(string $function, mixed ...$args): array
{
    try {
        if (!function_exists($function)) {
            return [
                'status' => 'disabled',
            ];
        }

        ob_start();
        $result = @$function(...$args);
        $printed = ob_get_clean();

        return [
            'status' => 'allowed',
            'result_type' => get_debug_type($result),
            'printed_bytes' => strlen((string) $printed),
        ];
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        return [
            'status' => 'error',
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function probe_proc_open(): array
{
    try {
        if (!function_exists('proc_open')) {
            return [
                'status' => 'disabled',
            ];
        }

        $process = @proc_open('id', [], $pipes);
        if (is_resource($process)) {
            @proc_close($process);
        }

        return [
            'status' => is_resource($process) ? 'allowed' : 'blocked',
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @param array<string, mixed> $checks
 * @return array<string, mixed>
 */
function summarize(array $checks): array
{
    $issues = [];

    foreach ([
        'read_etc_passwd',
        'read_root_env_relative_to_edge_runtime',
        'read_project_env_from_common_paths',
        'write_tmp_outside_sandbox',
        'read_runtime_source_via_reflection',
    ] as $key) {
        if (($checks[$key]['status'] ?? null) === 'allowed') {
            $issues[] = $key;
        }
    }

    foreach ($checks['disabled_functions'] ?? [] as $function => $result) {
        if (($result['status'] ?? null) === 'allowed') {
            $issues[] = 'disabled_functions.' . $function;
        }
    }

    if (($checks['network']['url_fopen_http_example_com']['status'] ?? null) === 'allowed') {
        $issues[] = 'network.url_fopen_http_example_com';
    }

    if (($checks['network']['http_helper_available'] ?? false) !== true) {
        $issues[] = 'network.http_helper_unavailable';
    }

    return [
        'has_possible_leaks' => $issues !== [],
        'possible_leaks' => $issues,
    ];
}
