<?php

use App\Database;

test('shows an empty default config when none has been set yet', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $body = json($response);
    expect($body['provider'])->toBe('smtp');
    expect($body['has_smtp_password'])->toBeFalse();
    expect($body['require_email_confirmation'])->toBeFalse();
});

test('sets SMTP config and never returns the password in plaintext', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->put("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'provider' => 'smtp',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Example App',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_username' => 'apikey',
            'smtp_encryption' => 'tls',
            'smtp_password' => 'super-secret',
            'require_email_confirmation' => true,
        ],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $body = json($response);
    expect($body)->not->toHaveKey('smtp_password');
    expect($body)->not->toHaveKey('smtp_password_encrypted');
    expect($body['has_smtp_password'])->toBeTrue();
    expect($body['require_email_confirmation'])->toBeTrue();

    $stmt = Database::getConn('default')->prepare(
        'SELECT smtp_password_encrypted FROM project_email_configs WHERE project_id = ?'
    );
    $stmt->execute([projectInternalId($project['id'])]);
    $stored = $stmt->fetchColumn();

    expect($stored)->not->toBe('super-secret');
});

test('keeps the existing password when updating without sending a new one', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    api()->put("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['provider' => 'smtp', 'from_address' => 'a@example.com', 'smtp_password' => 'first-secret'],
    ]);

    $response = api()->put("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['provider' => 'smtp', 'from_address' => 'a@example.com', 'from_name' => 'Renamed'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['has_smtp_password'])->toBeTrue();
});

test('rejects an invalid provider', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->put("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['provider' => 'carrier-pigeon', 'from_address' => 'a@example.com'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid from_address', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->put("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['provider' => 'smtp', 'from_address' => 'not-an-email'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('404s email config endpoints for a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/auth/email-config", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('sends a test email through the configured provider', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/auth/email-config/test", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['to' => 'someone@example.com'],
    ]);

    // ENV=testing always routes through TestMailDriver regardless of config,
    // so this succeeds even with no provider configured yet.
    expect($response->getStatusCode())->toBe(200);

    $mail = lastSentMail('someone@example.com');
    expect($mail['subject'])->toBe('Loxodontu test email');
});

test('rejects sending a test email to an invalid address', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/auth/email-config/test", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['to' => 'not-an-email'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});
