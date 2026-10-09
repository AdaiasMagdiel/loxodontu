<?php

use App\Database;
use App\Rls\ExpressionValidator;
use App\Rls\PolicyEngine;

$columns = ['id', 'title', 'status', 'created_by', 'created_at'];

test('policy expressions accept the restricted grammar', function (string $expression) use ($columns) {
    expect(ExpressionValidator::validate($expression, $columns))->toBeNull();
})->with([
    '1=1',
    'created_by = $auth.id',
    "\$auth.role = 'manager' AND created_by = \$auth.id AND title IS NOT NULL",
    "status IN ('draft', 'published') OR \$auth.role = 'admin'",
    "NOT (status = 'archived')",
    "LOWER(title) LIKE '%news%'",
    'created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)',
    'created_at <= CURRENT_TIMESTAMP',
    "COALESCE(`status`, 'draft') <> 'deleted'",
    "title = 'it''s; -- fine inside a string'",
]);

test('policy expressions reject anything that can reach beyond the row', function (string $expression) use ($columns) {
    expect(ExpressionValidator::validate($expression, $columns))->not->toBeNull();
})->with([
    'subquery'              => 'EXISTS(SELECT 1 FROM users)',
    'subquery in IN'        => 'created_by IN (SELECT id FROM users)',
    'table statement'       => 'created_by IN (TABLE users)',
    'other tenant table'    => 'id IN (SELECT id FROM p2_secrets)',
    'qualified column'      => 'users.password = 1',
    'schema qualified'      => 'loxodontu.users.id = 1',
    'quoted unknown table'  => '`users` = 1',
    'server variable'       => '@@version = 1',
    'user variable'         => '@x := 1',
    'sleep'                 => 'SLEEP(5) = 0',
    'benchmark'             => 'BENCHMARK(1000000, MD5(1)) = 0',
    'load_file'             => "LOAD_FILE('/etc/passwd') IS NOT NULL",
    'current_user'          => "CURRENT_USER = 'root'",
    'database()'            => "DATABASE() = 'x'",
    'unknown column'        => 'password_hash IS NOT NULL',
    'stacked statement'     => '1=1; DROP TABLE users',
    'line comment'          => '1=1 -- comment',
    'hash comment'          => '1=1 # comment',
    'block comment'         => '1=1 /* x */',
    'unbalanced parens'     => '(1=1',
    'unterminated string'   => "title = 'x",
    'unknown placeholder'   => 'created_by = $auth.password',
]);

test('the policy engine fails closed on stored expressions outside the grammar', function () use ($columns) {
    $conditions = PolicyEngine::resolve([
        ['operation' => 'SELECT', 'expression' => 'EXISTS(SELECT 1 FROM users)'],
        ['operation' => 'SELECT', 'expression' => 'created_by = $auth.id'],
    ], ['id' => 7, 'email' => 'a@b.c', 'role' => null], $columns);

    expect($conditions['select']->sql)->toStartWith('(1 = 0) OR (created_by = :rls1_id_0)');
    expect($conditions['select']->sql)->not->toContain('users');
});

test('a tenant cannot create a policy that reads platform tables', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'oracle',
            'operation' => 'SELECT',
            'expression' => "EXISTS(SELECT 1 FROM users WHERE password LIKE '\$2y%')",
        ],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('a malicious policy stored before validation existed returns no rows instead of leaking', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);
    $key = createApiKey($owner['token'], $project['id'], ['select', 'insert']);

    api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json' => ['title' => 'hello'],
    ]);

    Database::getConn('default')->prepare(
        'INSERT INTO project_rls_policies (table_id, name, operation, expression, enabled) VALUES (?, ?, ?, ?, 1)'
    )->execute([$table['id'], 'legacy', 'SELECT', 'EXISTS(SELECT 1 FROM users)']);

    $response = api()->get("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response))->toBe([]);
});
