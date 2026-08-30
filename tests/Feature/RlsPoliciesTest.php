<?php

test('creates a policy with an unconditional (fully open) expression', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], [
        'operation' => 'SELECT',
        'expression' => '1=1',
    ]);

    expect($policy['expression'])->toBe('1=1');
});

test('creates a policy scoped to a role with an $auth.id placeholder', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], [
        'operation' => 'UPDATE',
        'expression' => "\$auth.role = 'manager' AND created_by = \$auth.id",
    ]);

    expect($policy['expression'])->toBe("\$auth.role = 'manager' AND created_by = \$auth.id");
});

test('creates a policy combining multiple roles with OR', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], [
        'operation' => 'SELECT',
        'expression' => "\$auth.role = 'manager' OR \$auth.role = 'admin'",
    ]);

    expect($policy['expression'])->toContain('OR');
});

test('creates a policy with a literal (non-placeholder) condition', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], [
        'operation' => 'SELECT',
        'expression' => "title = 'exact-match-only'",
    ]);

    expect($policy['expression'])->toBe("title = 'exact-match-only'");
});

test('rejects a missing name', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['operation' => 'SELECT', 'expression' => '1=1'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a missing expression', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => ''],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid operation', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'TRUNCATE', 'expression' => '1=1'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an expression containing a statement separator', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => '1=1; DROP TABLE posts'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an expression with a SQL comment', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => "1=1 -- comment"],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an unknown $auth placeholder', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => 'created_by = $auth.password'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('lists and deletes a policy', function () {
    [$token, $project, $table] = postsTableForRls();

    $policy = createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'SELECT', 'expression' => '1=1']);

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
    $policy = createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'SELECT', 'expression' => '1=1']);

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
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => '1=1'],
    ]);
    expect($store->getStatusCode())->toBe(404);

    $policy = createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'SELECT', 'expression' => '1=1']);
    $destroy = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies/{$policy['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});
