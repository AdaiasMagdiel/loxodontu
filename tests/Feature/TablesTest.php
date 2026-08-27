<?php

use App\Database;

test('lists tables with their columns', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text', 'nullable' => false],
    ]);

    $response = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $body = json($response);
    expect($body)->toHaveCount(1);
    expect($body[0]['columns'][0]['name'])->toBe('title');
});

test('404s listing/creating/destroying tables for a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', []);

    $index = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($index->getStatusCode())->toBe(404);

    $store = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['name' => 'x', 'columns' => []],
    ]);
    expect($store->getStatusCode())->toBe(404);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});

test('404s destroying a table that does not exist', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->delete("/api/v1/projects/{$project['id']}/tables/999999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('rejects a missing table name', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => '', 'columns' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a table name that is not a valid identifier', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'not a valid name!', 'columns' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a non-array columns field', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => uniqueSlug('t'), 'columns' => 'not-an-array'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a column with an empty name', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => uniqueSlug('t'), 'columns' => [['name' => '', 'type' => 'text']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a column name that is not a valid identifier', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => uniqueSlug('t'), 'columns' => [['name' => 'not valid!', 'type' => 'text']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a table name that is too long once prefixed with the project id', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => str_repeat('a', 100), 'columns' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rolls back metadata when two columns share the same name', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $name = uniqueSlug('t');

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => $name, 'columns' => [
            ['name' => 'dup', 'type' => 'text'],
            ['name' => 'dup', 'type' => 'text'],
        ]],
    ]);

    expect($response->getStatusCode())->toBe(500);

    // The failed attempt must not have left a metadata row behind.
    $list = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect(array_column(json($list), 'name'))->not->toContain($name);
});

test('rolls back metadata when the physical table already exists', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $name = uniqueSlug('t');

    Database::getConn('default')->exec('CREATE TABLE `' . \App\Controllers\Tables::physicalName($project['id'], $name) . '` (id INT)');

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => $name, 'columns' => [['name' => 'title', 'type' => 'text']]],
    ]);

    expect($response->getStatusCode())->toBe(500);

    $list = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect(array_column(json($list), 'name'))->not->toContain($name);
});

test('applies default values per column type when creating the physical table', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'widgets', [
        ['name' => 'title', 'type' => 'text'], // required, no default — forces a non-empty insert
        ['name' => 'count', 'type' => 'integer', 'default_value' => 3],
        ['name' => 'price', 'type' => 'decimal', 'default_value' => '9.99'],
        ['name' => 'active', 'type' => 'boolean', 'default_value' => 1],
        ['name' => 'label', 'type' => 'text', 'default_value' => "it's fine"],
        ['name' => 'seen_at', 'type' => 'timestamp', 'nullable' => true, 'default_value' => 'CURRENT_TIMESTAMP'],
        ['name' => 'meta', 'type' => 'json', 'nullable' => true],
    ]);
    $key = createApiKey($owner['token'], $project['id'], ['select', 'insert']);
    createRlsPolicy($owner['token'], $project['id'], $table['id'], ['operation' => 'ALL', 'conditions' => []]);

    // Every column but the required "title" is left out — their DB-level defaults should kick in.
    $insert = api()->post("/api/v1/{$project['id']}/rest/widgets", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'a widget'],
    ]);

    expect($insert->getStatusCode())->toBe(200);
    $row = json($insert);
    expect((int) $row['count'])->toBe(3);
    expect((float) $row['price'])->toBe(9.99);
    expect((int) $row['active'])->toBe(1);
    expect($row['label'])->toBe("it's fine");
    expect($row['seen_at'])->not->toBeNull();
});

test('creates a table with a physical backing table usable via REST passthrough', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
    ]);
    $key = createApiKey($owner['token'], $project['id'], ['select', 'insert']);

    createRlsPolicy($owner['token'], $project['id'], $table['id'], [
        'operation' => 'ALL',
        'conditions' => [],
    ]);

    $insert = api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'Hello world'],
    ]);

    expect($insert->getStatusCode())->toBe(200);
    expect(json($insert)['title'])->toBe('Hello world');
});

test('rejects a duplicate table name within the same project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'posts', 'columns' => []],
    ]);

    expect($response->getStatusCode())->toBe(409);
});

test('rejects an unknown column type', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => uniqueSlug('t'), 'columns' => [['name' => 'x', 'type' => 'not-a-type']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a column literally named id', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => uniqueSlug('t'), 'columns' => [['name' => 'id', 'type' => 'text']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('destroying a table drops the physical table too', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'temp', [
        ['name' => 'title', 'type' => 'text'],
    ]);
    $key = createApiKey($owner['token'], $project['id'], ['select']);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(204);

    $rest = api()->get("/api/v1/{$project['id']}/rest/temp", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($rest->getStatusCode())->toBe(404);

    // The logical name is free again and a fresh table can reuse it.
    $recreated = api()->post("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'temp', 'columns' => [['name' => 'title', 'type' => 'text']]],
    ]);
    expect($recreated->getStatusCode())->toBe(201);
});
