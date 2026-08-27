<?php

test('rejects a REST request with no api key', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->get("/api/v1/{$project['id']}/rest/posts");

    expect($response->getStatusCode())->toBe(401);
});

test('rejects a REST request with a bogus api key', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->get("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => 'Bearer not-a-real-key'],
    ]);

    expect($response->getStatusCode())->toBe(401);
});

test('an api key from another project cannot be used here', function () {
    $owner = registerPlatformUser();
    $projectA = createProject($owner['token']);
    $projectB = createProject($owner['token']);
    createTable($owner['token'], $projectA['id'], 'posts', []);
    $keyForB = createApiKey($owner['token'], $projectB['id'], ['select']);

    $response = api()->get("/api/v1/{$projectA['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$keyForB['key']}"],
    ]);

    expect($response->getStatusCode())->toBe(401);
});

test('404s for a table that was never created', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $key = createApiKey($owner['token'], $project['id'], ['select']);

    $response = api()->get("/api/v1/{$project['id']}/rest/ghost_table", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('rejects an unsupported HTTP method', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);
    $key = createApiKey($owner['token'], $project['id'], ['select']);

    $response = api()->options("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);

    expect($response->getStatusCode())->toBe(405);
});

test('an RLS policy can filter rows by a literal (non-placeholder) value', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
        ['name' => 'status', 'type' => 'text'],
    ]);
    $key = createApiKey($owner['token'], $project['id'], ['select', 'insert']);
    createRlsPolicy($owner['token'], $project['id'], $table['id'], ['operation' => 'INSERT', 'conditions' => []]);
    createRlsPolicy($owner['token'], $project['id'], $table['id'], [
        'operation' => 'SELECT', 'conditions' => ['status' => 'published'],
    ]);

    api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'draft one', 'status' => 'draft'],
    ]);
    api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'published one', 'status' => 'published'],
    ]);

    $list = api()->get("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);

    expect(json($list))->toHaveCount(1);
    expect(json($list)[0]['status'])->toBe('published');
});

test('a table with no RLS policies at all is open by default (pre-RLS behavior)', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);
    $key = createApiKey($owner['token'], $project['id'], ['select', 'insert']);

    $insert = api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'no rls here'],
    ]);
    expect($insert->getStatusCode())->toBe(200);

    $list = api()->get("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($list->getStatusCode())->toBe(200);
    expect(json($list))->toHaveCount(1);
});
