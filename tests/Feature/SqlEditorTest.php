<?php

use App\Controllers\Tables;

test('runs sql using logical table names and rewrites them to the project table prefix', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
    ]);

    $insert = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => "insert into posts (title) values ('Hello')"],
    ]);
    expect($insert->getStatusCode())->toBe(200);
    expect(json($insert)['sql'])->toContain(Tables::physicalName(projectInternalId($project['id']), 'posts'));

    $select = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => 'select id, title from posts;'],
    ]);

    expect($select->getStatusCode())->toBe(200);
    $body = json($select);
    expect($body['columns'])->toBe(['id', 'title']);
    expect($body['rows'][0]['title'])->toBe('Hello');
    expect($body['sql'])->toBe('select id, title from `' . Tables::physicalName(projectInternalId($project['id']), 'posts') . '`');
});

test('rejects sql that references another project physical table', function () {
    $owner = registerPlatformUser();
    $projectA = createProject($owner['token']);
    $projectB = createProject($owner['token']);
    createTable($owner['token'], $projectA['id'], 'posts', []);
    createTable($owner['token'], $projectB['id'], 'posts', []);

    $response = api()->post("/api/v1/projects/{$projectA['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => 'select id from ' . Tables::physicalName(projectInternalId($projectB['id']), 'posts')],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects sql with unknown project tables', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $unknown = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => 'select id from posts'],
    ]);
    expect($unknown->getStatusCode())->toBe(422);
});

test('runs multiple sql commands split by semicolon or line', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
    ]);

    $semicolon = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => "insert into posts (title) values ('First'); select title from posts"],
    ]);

    expect($semicolon->getStatusCode())->toBe(200);
    $semicolonBody = json($semicolon);
    expect($semicolonBody['results'])->toHaveCount(2);
    expect($semicolonBody['results'][0]['operation'])->toBe('INSERT');
    expect($semicolonBody['results'][1]['rows'][0]['title'])->toBe('First');

    $lines = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => "insert into posts (title) values ('Second')\nselect title from posts"],
    ]);

    expect($lines->getStatusCode())->toBe(200);
    $lineBody = json($lines);
    expect($lineBody['results'])->toHaveCount(2);
    expect(array_column($lineBody['results'][1]['rows'], 'title'))->toContain('Second');
});

test('allows semicolons inside string literals while still executing one statement', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
    ]);

    $insert = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => "insert into posts (title) values ('Hello; still one statement');"],
    ]);

    expect($insert->getStatusCode())->toBe(200);
});

test('rewrites backtick quoted logical table names', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => 'select id from `posts`'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['sql'])->toBe('select id from `' . Tables::physicalName(projectInternalId($project['id']), 'posts') . '`');
});

test('rejects sql comments in the editor', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => "select id from posts -- hidden tail"],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects sql that does not reference a project table', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => 'select @@version'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects comma joins so every table reference must be explicit', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);
    createTable($owner['token'], $project['id'], 'comments', []);

    $response = api()->post("/api/v1/projects/{$project['id']}/sql", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['sql' => 'select posts.id from posts, comments'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});
