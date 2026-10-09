<?php

use App\Mail\SmtpMailer;

function smtpConfig(string $host, int $port = 587, string $encryption = 'tls'): array
{
    return [
        'provider' => 'smtp',
        'from_address' => 'noreply@example.com',
        'smtp_host' => $host,
        'smtp_port' => $port,
        'smtp_encryption' => $encryption,
    ];
}

test('a tenant cannot point SMTP at internal hosts', function (string $host) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->put("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => smtpConfig($host),
    ]);

    expect($response->getStatusCode())->toBe(422);
    expect(json($response)['error'])->toStartWith('smtp_host:');
})->with([
    'localhost'      => 'localhost',
    'loopback'       => '127.0.0.1',
    'private range'  => '10.0.0.5',
    'cloud metadata' => '169.254.169.254',
    'ipv6 loopback'  => '[::1]',
    'single label'   => 'mysql',
    'with scheme'    => 'smtp://mail.example.com',
]);

test('a tenant cannot use SMTP to reach arbitrary ports', function (int $port) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->put("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => smtpConfig('smtp.example.com', $port),
    ]);

    expect($response->getStatusCode())->toBe(422);
    expect(json($response)['error'])->toStartWith('smtp_port must be one of');
})->with([22, 80, 3306, 6379]);

test('the smtp mailer refuses internal hosts and ports at send time', function (string $host, int $port, string $message) {
    $mailer = new SmtpMailer([
        'host' => $host, 'port' => $port, 'username' => null, 'password' => null,
        'encryption' => 'tls', 'from_address' => 'noreply@example.com', 'from_name' => null,
    ]);

    expect(fn () => $mailer->send('someone@example.com', 'x', 'y'))->toThrow(RuntimeException::class, $message);
})->with([
    ['127.0.0.1', 587, 'Private and reserved network targets are not allowed.'],
    ['localhost', 587, 'Localhost requests are not allowed.'],
    ['93.184.215.14', 3306, 'SMTP port must be one of'],
]);
