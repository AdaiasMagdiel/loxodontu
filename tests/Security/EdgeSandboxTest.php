<?php

/**
 * Deploys $source as a GET edge function and returns its decoded JSON body.
 *
 * @return array<string, mixed>
 */
function runSandboxProbe(string $source): array
{
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $slug = uniqueSlug('probe');

    $created = api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['name' => 'Probe', 'slug' => $slug, 'source_code' => $source, 'methods' => ['GET']],
    ]);
    expect($created->getStatusCode())->toBe(201);

    $response = api()->get("/api/v1/{$project['id']}/functions/{$slug}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($response->getStatusCode())->toBe(200);

    return json($response);
}

test('the sandbox has no direct network, ffi, process or database access', function () {
    $body = runSandboxProbe(<<<'PHP'
<?php

use App\Edge\FunctionResponse;

return function () {
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $classes = array_map('trim', explode(',', (string) ini_get('disable_classes')));

    return FunctionResponse::json([
        'disabled' => array_values(array_intersect(
            ['fsockopen', 'stream_socket_client', 'curl_init', 'socket_create', 'posix_kill', 'gethostbyname', 'proc_open', 'phpinfo'],
            $disabled,
        )),
        'disabled_classes' => array_values(array_intersect(['PDO', 'FFI'], $classes)),
        'ffi_enable' => ini_get('ffi.enable'),
        'env_has_db' => getenv('DB_PASSWORD_TEST') !== false || getenv('DB_HOST_TEST') !== false,
        'env_keys' => array_keys(getenv()),
    ]);
};
PHP);

    expect($body['disabled'])->toEqualCanonicalizing(
        ['fsockopen', 'stream_socket_client', 'curl_init', 'socket_create', 'posix_kill', 'gethostbyname', 'proc_open', 'phpinfo'],
    );
    expect($body['disabled_classes'])->toEqualCanonicalizing(['PDO', 'FFI']);
    expect($body['env_has_db'])->toBeFalse();
    expect($body['env_keys'])->toBe(['PATH']);
})->skipOnWindows();

test('the sandbox cannot read the runtime of another function', function () {
    $body = runSandboxProbe(<<<'PHP'
<?php

use App\Edge\FunctionResponse;

return function () {
    $basedir = (string) ini_get('open_basedir');
    $functionsRoot = dirname($basedir, 2);

    return FunctionResponse::json([
        'open_basedir' => $basedir,
        'can_read_functions_root' => @file_get_contents($functionsRoot . '/1/hello/runtime/Bridge.php') !== false,
        'shared_runtime_in_basedir' => str_contains($basedir, 'loxodontu-edge-runtime'),
    ]);
};
PHP);

    expect($body['open_basedir'])->toContain('/loxodontu-edge-functions/');
    expect($body['shared_runtime_in_basedir'])->toBeFalse();
    expect($body['can_read_functions_root'])->toBeFalse();
})->skipOnWindows();

test('outbound http from the sandbox is proxied and still blocks internal targets', function () {
    $body = runSandboxProbe(<<<'PHP'
<?php

use App\Edge\FunctionResponse;
use App\Edge\Http;

return function () {
    $results = [];
    foreach (['http://127.0.0.1:3306/', 'http://169.254.169.254/latest/meta-data/', 'file:///etc/passwd'] as $url) {
        try {
            Http::get($url);
            $results[$url] = 'allowed';
        } catch (\RuntimeException $e) {
            $results[$url] = $e->getMessage();
        }
    }

    return FunctionResponse::json($results);
};
PHP);

    expect($body['http://127.0.0.1:3306/'])->toBe('Private and reserved network targets are not allowed.');
    expect($body['http://169.254.169.254/latest/meta-data/'])->toBe('Private and reserved network targets are not allowed.');
    expect($body['file:///etc/passwd'])->toBe('Only http and https URLs are allowed.');
})->skipOnWindows();
