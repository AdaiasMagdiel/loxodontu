<?php

test('creates an api key and only returns the raw key once', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $created = createApiKey($owner['token'], $project['id'], ['select']);
    expect($created['key'])->toBeString()->toHaveLength(64); // 32 random bytes, hex-encoded

    $list = api()->get("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($list->getStatusCode())->toBe(200);

    $listed = json($list)[0];
    expect($listed)->not->toHaveKey('key');
    expect($listed)->not->toHaveKey('key_hash');
});

test('404s listing/creating keys for a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);

    $index = api()->get("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($index->getStatusCode())->toBe(404);

    $store = api()->post("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['name' => 'k', 'permissions' => ['select']],
    ]);
    expect($store->getStatusCode())->toBe(404);
});

test('rejects a missing name', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => '', 'permissions' => ['select']],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an expires_at in the past', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'k', 'permissions' => ['select'], 'expires_at' => '2000-01-01 00:00:00'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('accepts a future expires_at', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'k', 'permissions' => ['select'], 'expires_at' => '2099-01-01 00:00:00'],
    ]);

    expect($response->getStatusCode())->toBe(201);
    expect(json($response)['expires_at'])->toBe('2099-01-01 00:00:00');
});

test('an expired api key no longer authenticates REST requests', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);

    $created = api()->post("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'k', 'permissions' => ['select'], 'expires_at' => date('Y-m-d H:i:s', time() + 5)],
    ]);
    $key = json($created)['key'];

    // Force it into the past directly, since the API won't accept an already-expired value.
    $pdo = App\Database::getConn('default');
    $pdo->prepare('UPDATE project_api_keys SET expires_at = ? WHERE key_prefix = ?')
        ->execute(['2000-01-01 00:00:00', substr($key, 0, 8)]);

    $response = api()->get("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key}"],
    ]);

    expect($response->getStatusCode())->toBe(401);
});

test('404s destroying a key for a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);
    $key = createApiKey($owner['token'], $project['id'], ['select']);

    $response = api()->delete("/api/v1/projects/{$project['id']}/keys/{$key['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('404s destroying a key that does not exist', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->delete("/api/v1/projects/{$project['id']}/keys/999999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('rejects an empty permissions list', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'k', 'permissions' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an unknown permission', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'k', 'permissions' => ['drop_database']],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('a REST request without a matching permission is forbidden', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);
    createRlsPolicy($owner['token'], $project['id'], $table['id'], ['operation' => 'ALL', 'expression' => '1=1']);
    $key = createApiKey($owner['token'], $project['id'], ['select']); // no insert

    $response = api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'nope'],
    ]);

    expect($response->getStatusCode())->toBe(403);
});

test('deletes an api key, which then stops authenticating REST requests', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);
    $key = createApiKey($owner['token'], $project['id'], ['select']);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/keys/{$key['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(204);

    $response = api()->get("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($response->getStatusCode())->toBe(401);
});
