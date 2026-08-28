<?php

test('registers a new platform user and returns a token', function () {
    $email = uniqueEmail('owner');

    $response = api()->post('/api/v1/auth/register', [
        'json' => ['name' => 'Ada', 'email' => $email, 'password' => 'password123'],
    ]);

    expect($response->getStatusCode())->toBe(201);

    $body = json($response);
    expect($body['token'])->toBeString()->not->toBeEmpty();
    expect($body['user']['email'])->toBe($email);
});

test('rejects registration with missing fields', function () {
    $response = api()->post('/api/v1/auth/register', [
        'json' => ['name' => '', 'email' => '', 'password' => ''],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects registration with an invalid email', function () {
    $response = api()->post('/api/v1/auth/register', [
        'json' => ['name' => 'Ada', 'email' => 'not-an-email', 'password' => 'password123'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects login with missing fields', function () {
    $response = api()->post('/api/v1/auth/login', [
        'json' => ['email' => '', 'password' => ''],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects registration with a duplicate email', function () {
    $email = uniqueEmail('dup');
    registerPlatformUser($email);

    $response = api()->post('/api/v1/auth/register', [
        'json' => ['name' => 'Another', 'email' => $email, 'password' => 'password123'],
    ]);

    expect($response->getStatusCode())->toBe(409);
});

test('rejects registration with a short password', function () {
    $response = api()->post('/api/v1/auth/register', [
        'json' => ['name' => 'Ada', 'email' => uniqueEmail(), 'password' => 'short'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('logs in with correct credentials', function () {
    $email = uniqueEmail('login');
    registerPlatformUser($email, 'password123');

    $response = api()->post('/api/v1/auth/login', [
        'json' => ['email' => $email, 'password' => 'password123'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['token'])->toBeString()->not->toBeEmpty();
});

test('rejects login with the wrong password', function () {
    $email = uniqueEmail('login');
    registerPlatformUser($email, 'password123');

    $response = api()->post('/api/v1/auth/login', [
        'json' => ['email' => $email, 'password' => 'wrong-password'],
    ]);

    expect($response->getStatusCode())->toBe(401);
});

test('a token stops working after logout', function () {
    $owner = registerPlatformUser();

    $logout = api()->post('/api/v1/auth/logout', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($logout->getStatusCode())->toBe(204);

    $response = api()->get('/api/v1/projects', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($response->getStatusCode())->toBe(401);
});

test('rejects a request with no Authorization header', function () {
    $response = api()->get('/api/v1/projects');

    expect($response->getStatusCode())->toBe(401);
});

test('gets the current platform user', function () {
    $email = uniqueEmail('me');
    $owner = registerPlatformUser($email);

    $response = api()->get('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response))->toMatchArray(['id' => $owner['user']['id'], 'email' => $email]);
});

test('updates the current user\'s name', function () {
    $owner = registerPlatformUser();

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'New Name'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['name'])->toBe('New Name');
});

test('rejects updating the name to an empty string', function () {
    $owner = registerPlatformUser();

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => '  '],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('updates the current user\'s email', function () {
    $owner = registerPlatformUser();
    $newEmail = uniqueEmail('updated');

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['email' => $newEmail],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['email'])->toBe($newEmail);
});

test('rejects updating the email to an invalid address', function () {
    $owner = registerPlatformUser();

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['email' => 'not-an-email'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects updating the email to one already used by another account', function () {
    $taken = uniqueEmail('taken');
    registerPlatformUser($taken);
    $owner = registerPlatformUser();

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['email' => $taken],
    ]);

    expect($response->getStatusCode())->toBe(409);
});

test('updates the current user\'s password with the correct current password', function () {
    $email = uniqueEmail('pwd');
    $owner = registerPlatformUser($email, 'old-password');

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['current_password' => 'old-password', 'password' => 'new-password'],
    ]);

    expect($response->getStatusCode())->toBe(200);

    $login = api()->post('/api/v1/auth/login', [
        'json' => ['email' => $email, 'password' => 'new-password'],
    ]);
    expect($login->getStatusCode())->toBe(200);
});

test('rejects a password change with the wrong current password', function () {
    $owner = registerPlatformUser(null, 'old-password');

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['current_password' => 'wrong', 'password' => 'new-password'],
    ]);

    expect($response->getStatusCode())->toBe(401);
});

test('rejects a new password that is too short', function () {
    $owner = registerPlatformUser(null, 'old-password');

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['current_password' => 'old-password', 'password' => 'short'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an account update with nothing to update', function () {
    $owner = registerPlatformUser();

    $response = api()->patch('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => [],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('deletes the current account with the correct password', function () {
    $email = uniqueEmail('del');
    $owner = registerPlatformUser($email, 'password123');

    $response = api()->delete('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['password' => 'password123'],
    ]);

    expect($response->getStatusCode())->toBe(204);

    $login = api()->post('/api/v1/auth/login', [
        'json' => ['email' => $email, 'password' => 'password123'],
    ]);
    expect($login->getStatusCode())->toBe(401);
});

test('rejects deleting the account with the wrong password', function () {
    $owner = registerPlatformUser(null, 'password123');

    $response = api()->delete('/api/v1/auth/me', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['password' => 'wrong'],
    ]);

    expect($response->getStatusCode())->toBe(401);
});
