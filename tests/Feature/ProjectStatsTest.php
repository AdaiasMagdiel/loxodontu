<?php

test('reports real counts for a fresh project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/stats", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response))->toBe([
        'tables' => 0, 'end_users' => 0, 'api_keys' => 0, 'cron_jobs' => 0,
        'functions' => 0, 'buckets' => 0, 'storage_bytes' => 0,
    ]);
});

test('counts reflect real resources created in the project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);
    createApiKey($owner['token'], $project['id'], ['select']);
    registerEndUser($project['id']);
    $bucket = createBucket($owner['token'], $project['id']);
    uploadObject(createApiKey($owner['token'], $project['id'], ['storage:insert'])['key'], $project['id'], $bucket['name'], 'a.txt', 'hello');

    $response = api()->get("/api/v1/projects/{$project['id']}/stats", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    $stats = json($response);
    expect($stats['tables'])->toBe(1);
    expect($stats['end_users'])->toBe(1);
    expect($stats['api_keys'])->toBe(2);
    expect($stats['buckets'])->toBe(1);
    expect($stats['storage_bytes'])->toBe(5);
});

test('404s stats for a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/stats", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});
