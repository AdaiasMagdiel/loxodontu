<?php

test('lists RLS policies across every table in the project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $posts = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);
    $comments = createTable($owner['token'], $project['id'], 'comments', [['name' => 'body', 'type' => 'text']]);

    createRlsPolicy($owner['token'], $project['id'], $posts['id'], ['operation' => 'SELECT', 'expression' => '1 = 1']);
    createRlsPolicy($owner['token'], $project['id'], $comments['id'], ['operation' => 'DELETE', 'expression' => '1 = 1']);

    $response = api()->get("/api/v1/projects/{$project['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $policies = json($response);
    expect($policies)->toHaveCount(2);
    expect(array_column($policies, 'table_name'))->toEqualCanonicalizing(['posts', 'comments']);
});

test('404s the project-wide policy list for a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});
