<?php

test('creates a project and lists it back', function () {
    $owner = registerPlatformUser();

    $project = createProject($owner['token'], 'My Blog');
    expect($project['slug'])->toBe('my-blog');

    $response = api()->get('/api/v1/projects', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $ids = array_column(json($response), 'id');
    expect($ids)->toContain($project['id']);
});

test('rejects a project with no name', function () {
    $owner = registerPlatformUser();

    $response = api()->post('/api/v1/projects', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => ''],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('show returns the project along with its tables and columns', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text', 'nullable' => true],
    ]);

    $response = api()->get("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $body = json($response);
    expect($body['tables'])->toHaveCount(1);
    expect($body['tables'][0]['name'])->toBe('posts');
    expect($body['tables'][0]['columns'][0])->toMatchArray(['name' => 'title', 'nullable' => true]);
});

test('two projects with the same name get distinct slugs', function () {
    $owner = registerPlatformUser();

    $a = createProject($owner['token'], 'Blog');
    $b = createProject($owner['token'], 'Blog');

    expect($a['slug'])->toBe('blog');
    expect($b['slug'])->not->toBe('blog');
});

test('a user cannot see another user\'s project', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();

    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('a user cannot delete another user\'s project', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();

    $project = createProject($owner['token']);

    $response = api()->delete("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);

    // still visible to its actual owner
    $stillThere = api()->get("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($stillThere->getStatusCode())->toBe(200);
});

test('updates a project\'s name', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token'], 'Old Name');

    $response = api()->patch("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'New Name'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['name'])->toBe('New Name');
});

test('updates a project\'s description', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->patch("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['description' => 'A new description'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['description'])->toBe('A new description');
});

test('rejects updating a project\'s name to an empty string', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->patch("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => '  '],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a project update with nothing to update', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->patch("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => [],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('404s updating a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->patch("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['name' => 'Hijacked'],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('deletes an owned project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->delete("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($response->getStatusCode())->toBe(204);

    $gone = api()->get("/api/v1/projects/{$project['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($gone->getStatusCode())->toBe(404);
});
