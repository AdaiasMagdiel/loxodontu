<?php

test('inserts, lists, updates and deletes rows, bypassing RLS entirely', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
    ]);

    // Lock the table down with a deny-everything RLS policy — the owner-only
    // row endpoints must ignore it completely.
    createRlsPolicy($owner['token'], $project['id'], $table['id'], [
        'operation' => 'ALL',
        'expression' => "\$auth.role = 'nobody'",
    ]);

    $insert = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['title' => 'Hello world'],
    ]);
    expect($insert->getStatusCode())->toBe(201);
    $row = json($insert);
    expect($row['title'])->toBe('Hello world');

    $list = api()->get("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($list->getStatusCode())->toBe(200);
    expect(array_column(json($list), 'id'))->toContain($row['id']);

    $update = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows/{$row['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['title' => 'Updated'],
    ]);
    expect($update->getStatusCode())->toBe(200);
    expect(json($update)['title'])->toBe('Updated');

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows/{$row['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(204);

    $listAfter = api()->get("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect(array_column(json($listAfter), 'id'))->not->toContain($row['id']);
});

test('ignores unknown columns and the id field on insert/update', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
    ]);

    $insert = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['title' => 'Hello', 'id' => 999999, 'not_a_column' => 'x'],
    ]);
    expect($insert->getStatusCode())->toBe(201);
    expect(json($insert)['id'])->not->toBe(999999);
});

test('search filters rows across text columns', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
    ]);

    api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"], 'json' => ['title' => 'Apple pie'],
    ]);
    api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"], 'json' => ['title' => 'Banana bread'],
    ]);

    $response = api()->get("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows?search=apple", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    $titles = array_column(json($response), 'title');
    expect($titles)->toContain('Apple pie');
    expect($titles)->not->toContain('Banana bread');
});

test('404s row endpoints for a table in a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);

    $response = api()->get("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('404s updating/deleting a row that does not exist', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);

    $update = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows/999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"], 'json' => ['title' => 'x'],
    ]);
    expect($update->getStatusCode())->toBe(404);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows/999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});

test('rejects an update with nothing recognizable to update', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);

    $insert = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"], 'json' => ['title' => 'Hello'],
    ]);
    $row = json($insert);

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rows/{$row['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"], 'json' => ['not_a_column' => 'x'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});
