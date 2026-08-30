<?php

test('registers an end user scoped to a project, starting with no role', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $user = registerEndUser($project['id']);

    expect($user['token'])->toBeString()->not->toBeEmpty();
    expect($user['user']['role'])->toBeNull();
});

test('404s registering against a project that does not exist', function () {
    $response = api()->post('/api/v1/999999999/auth/register', ['json' => ['email' => uniqueEmail(), 'password' => 'password123']]);

    expect($response->getStatusCode())->toBe(404);
});

test('404s logging in against a project that does not exist', function () {
    $response = api()->post('/api/v1/999999999/auth/login', ['json' => ['email' => uniqueEmail(), 'password' => 'password123']]);

    expect($response->getStatusCode())->toBe(404);
});

test('rejects registration with missing fields', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/register", ['json' => ['email' => '', 'password' => '']]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects registration with an invalid email', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/register", ['json' => ['email' => 'not-an-email', 'password' => 'password123']]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects login with missing fields', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/login", ['json' => ['email' => '', 'password' => '']]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects registration with a short password', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/register", ['json' => ['email' => uniqueEmail(), 'password' => 'short']]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an end user login with the wrong password', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $response = api()->post("/api/v1/{$project['id']}/auth/login", [
        'json' => ['email' => $user['user']['email'], 'password' => 'wrong-password'],
    ]);

    expect($response->getStatusCode())->toBe(401);
});

test('the same email can register in two different projects', function () {
    $owner = registerPlatformUser();
    $projectA = createProject($owner['token']);
    $projectB = createProject($owner['token']);
    $email = uniqueEmail('shared');

    $a = api()->post("/api/v1/{$projectA['id']}/auth/register", ['json' => ['email' => $email, 'password' => 'password123']]);
    $b = api()->post("/api/v1/{$projectB['id']}/auth/register", ['json' => ['email' => $email, 'password' => 'password123']]);

    expect($a->getStatusCode())->toBe(201);
    expect($b->getStatusCode())->toBe(201);
});

test('rejects a duplicate email within the same project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $email = uniqueEmail('dup');

    registerEndUser($project['id'], $email);
    $response = api()->post("/api/v1/{$project['id']}/auth/register", ['json' => ['email' => $email, 'password' => 'password123']]);

    expect($response->getStatusCode())->toBe(409);
});

test('an end user cannot self-assign a role at registration', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/register", [
        'json' => ['email' => uniqueEmail(), 'password' => 'password123', 'role' => 'admin'],
    ]);

    expect($response->getStatusCode())->toBe(201);
    expect(json($response)['user']['role'])->toBeNull();
});

test('the project owner can grant a role', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $updated = setEndUserRole($owner['token'], $project['id'], $user['user']['id'], 'manager');
    expect($updated['role'])->toBe('manager');

    $login = api()->post("/api/v1/{$project['id']}/auth/login", [
        'json' => ['email' => $user['user']['email'], 'password' => 'password123'],
    ]);
    expect(json($login)['user']['role'])->toBe('manager');
});

test('lists a project\'s end users', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $response = api()->get("/api/v1/projects/{$project['id']}/end-users", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(array_column(json($response), 'id'))->toContain($user['user']['id']);
});

test('404s listing end users for a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/end-users", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('404s granting a role to an end user that does not exist', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->patch("/api/v1/projects/{$project['id']}/end-users/999999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['role' => 'admin'],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('rejects a role update with no role field at all', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $response = api()->patch("/api/v1/projects/{$project['id']}/end-users/{$user['user']['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => [],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid role format', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $response = api()->patch("/api/v1/projects/{$project['id']}/end-users/{$user['user']['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['role' => 'not a valid role!'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('clears a role by setting it to null', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);
    setEndUserRole($owner['token'], $project['id'], $user['user']['id'], 'manager');

    $cleared = setEndUserRole($owner['token'], $project['id'], $user['user']['id'], null);

    expect($cleared['role'])->toBeNull();
});

test('the project owner can remove an end user', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/end-users/{$user['user']['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(204);

    $list = api()->get("/api/v1/projects/{$project['id']}/end-users", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect(array_column(json($list), 'id'))->not->toContain($user['user']['id']);
});

test('404s removing an end user that does not exist', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->delete("/api/v1/projects/{$project['id']}/end-users/999999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('404s removing an end user from a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $response = api()->delete("/api/v1/projects/{$project['id']}/end-users/{$user['user']['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('an end user cannot manage roles in a project they do not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $response = api()->patch("/api/v1/projects/{$project['id']}/end-users/{$user['user']['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['role' => 'admin'],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('logging out invalidates the end user token for REST passthrough', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);
    createRlsPolicy($owner['token'], $project['id'], $table['id'], ['operation' => 'SELECT', 'expression' => "\$auth.role = 'nobody'"]);
    $key = createApiKey($owner['token'], $project['id'], ['select']);
    $user = registerEndUser($project['id']);

    $logout = api()->post("/api/v1/{$project['id']}/auth/logout", [
        'headers' => ['Authorization' => "Bearer {$user['token']}"],
    ]);
    expect($logout->getStatusCode())->toBe(204);

    // SELECT is role-gated to "nobody", so a stale/invalid X-User-Token must not resolve
    // to anyone — the $auth.role placeholder binds to NULL, which never equals 'nobody',
    // so every row is filtered out (200 with an empty list, not a 403: nothing here is
    // "forbidden", the policy's WHERE just matches no rows for an unauthenticated caller).
    $response = api()->get("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}", 'X-User-Token' => $user['token']],
    ]);
    expect($response->getStatusCode())->toBe(200);
    expect(json($response))->toBe([]);
});
