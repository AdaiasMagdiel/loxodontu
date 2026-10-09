<?php

use App\Cron\HttpJobHandler;
use App\Net\UrlGuard;

function httpJob(string $target): array
{
    return ['method' => 'GET', 'headers' => null, 'target' => $target, 'payload' => null, 'timeout_seconds' => 1];
}

test('cron http jobs cannot read local files or php streams', function (string $target) {
    $result = (new HttpJobHandler())->handle(httpJob($target));

    expect($result->ok)->toBeFalse();
    expect($result->error)->toStartWith('HTTP request blocked:');
    expect((string) $result->output)->not->toContain('DB_');
})->with([
    'file scheme'      => 'file:///etc/passwd',
    'php filter'       => 'php://filter/resource=' . ROOT_DIR . '/.env',
    'relative path'    => ROOT_DIR . '/.env',
    'data scheme'      => 'data://text/plain,secret',
]);

test('cron http jobs cannot reach internal network targets', function (string $target) {
    $result = (new HttpJobHandler())->handle(httpJob($target));

    expect($result->ok)->toBeFalse();
    expect($result->error)->toStartWith('HTTP request blocked:');
})->with([
    'cloud metadata'   => 'http://169.254.169.254/latest/meta-data/',
    'loopback'         => 'http://127.0.0.1:3306/',
    'localhost'        => 'http://localhost/',
    'private range'    => 'http://10.0.0.1/',
    'ipv6 loopback'    => 'http://[::1]/',
    'ipv4-mapped ipv6' => 'http://[::ffff:127.0.0.1]/',
    'cgnat range'      => 'http://100.100.100.200/',
    'url credentials'  => 'http://user:pass@example.com/',
]);

test('creating a cron http job with a non-http url is rejected', function (string $target) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['name' => 'Exfil', 'type' => 'http', 'target' => $target, 'run_at' => '2099-01-01 00:00:00'],
    ]);

    expect($response->getStatusCode())->toBe(422);
    expect(json($response)['error'])->toBe('target must be a valid http or https URL for http jobs');
})->with(['file:///etc/passwd', 'php://filter/resource=/app/.env', 'ftp://example.com/x']);

test('the url guard accepts public addresses', function () {
    expect(UrlGuard::assertAllowed('https://93.184.215.14/path')['ips'])->toBe(['93.184.215.14']);
    expect(UrlGuard::isPublicIp('8.8.8.8'))->toBeTrue();
    expect(UrlGuard::isPublicIp('2001:4860:4860::8888'))->toBeTrue();
    expect(UrlGuard::isPublicIp('fd00::1'))->toBeFalse();
});
