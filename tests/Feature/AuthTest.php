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
