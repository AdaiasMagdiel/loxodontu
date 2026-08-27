<?php

test('creates a policy with an empty conditions object (fully open)', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], [
        'operation' => 'SELECT',
        'conditions' => [],
    ]);

    expect($policy['conditions'])->toBe([]);
    expect($policy['role'])->toBeNull();
});

test('creates a policy scoped to a role with an $auth.id placeholder', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], [
        'operation' => 'UPDATE',
        'role' => 'manager',
        'conditions' => ['created_by' => '$auth.id'],
    ]);

    expect($policy['role'])->toBe('manager');
    expect($policy['conditions'])->toBe(['created_by' => '$auth.id']);
});

test('creates a policy with a literal (non-placeholder) condition value', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], [
        'operation' => 'SELECT',
        'conditions' => ['title' => 'exact-match-only'],
    ]);

    expect($policy['conditions'])->toBe(['title' => 'exact-match-only']);
});

test('rejects a missing name', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['operation' => 'SELECT', 'conditions' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a condition referencing an unknown column', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['not_a_column' => 1]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid placeholder', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['created_by' => '$auth.password']],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a non-array conditions field', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => 'not-an-object'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a non-scalar condition value', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['created_by' => ['nested' => 'array']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid operation', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'TRUNCATE', 'conditions' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid role format', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'role' => 'not a valid role!', 'conditions' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('lists and deletes a policy', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'SELECT', 'conditions' => []]);

    $list = api()->get("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);
    expect(array_column(json($list), 'id'))->toContain($policy['id']);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies/{$policy['id']}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);
    expect($destroy->getStatusCode())->toBe(204);

    $listAfter = api()->get("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);
    expect(array_column(json($listAfter), 'id'))->not->toContain($policy['id']);
});

test('404s deleting a policy that does not belong to the given table', function () {
    [$token, $project, $table] = postsTableForRls();
    $otherTable = createTable($token, $project['id'], 'other', []);
    $policy = createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'SELECT', 'conditions' => []]);

    $response = api()->delete("/api/v1/projects/{$project['id']}/tables/{$otherTable['id']}/rls-policies/{$policy['id']}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('index/store/destroy all 404 for a table in a project the caller does not own', function () {
    [$token, $project, $table] = postsTableForRls();
    $intruder = registerPlatformUser();

    $index = api()->get("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($index->getStatusCode())->toBe(404);

    $store = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => []],
    ]);
    expect($store->getStatusCode())->toBe(404);

    $policy = createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'SELECT', 'conditions' => []]);
    $destroy = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies/{$policy['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});
