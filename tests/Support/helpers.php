<?php

/** A unique-enough email so parallel test cases never collide on the unique constraint. */
function uniqueEmail(string $prefix = 'user'): string
{
    return sprintf('%s_%s@example.test', $prefix, bin2hex(random_bytes(6)));
}

function uniqueSlug(string $prefix = 'item'): string
{
    return sprintf('%s_%s', $prefix, bin2hex(random_bytes(4)));
}

/**
 * Registers a fresh platform user (a Loxodontu account, i.e. a project owner).
 *
 * @return array{token: string, user: array{id: int, name: string, email: string}}
 */
function registerPlatformUser(?string $email = null, string $password = 'password123'): array
{
    $email = $email ?? uniqueEmail('owner');

    $response = api()->post('/api/v1/auth/register', [
        'json' => ['name' => 'Test Owner', 'email' => $email, 'password' => $password],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/** @return array{id: int, name: string, slug: string} */
function createProject(string $token, ?string $name = null): array
{
    $response = api()->post('/api/v1/projects', [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => $name ?? uniqueSlug('Project')],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/**
 * @param array<int, array{name: string, type: string, nullable?: bool, default_value?: mixed}> $columns
 * @return array{id: int, name: string, columns: array}
 */
function createTable(string $token, int $projectId, ?string $name = null, array $columns = []): array
{
    $response = api()->post("/api/v1/projects/{$projectId}/tables", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => $name ?? uniqueSlug('table'), 'columns' => $columns],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/** @param string[] $permissions @return array{id: int, key: string} */
function createApiKey(string $token, int $projectId, array $permissions, ?string $name = null): array
{
    $response = api()->post("/api/v1/projects/{$projectId}/keys", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => $name ?? uniqueSlug('key'), 'permissions' => $permissions],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/** @param array{name?: string, role?: ?string, operation: string, conditions?: array, enabled?: bool} $payload */
function createRlsPolicy(string $token, int $projectId, int $tableId, array $payload): array
{
    $payload['name'] ??= uniqueSlug('policy');

    $response = api()->post("/api/v1/projects/{$projectId}/tables/{$tableId}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => $payload,
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/** @return array{token: string, user: array{id: int, email: string, role: ?string}} */
function registerEndUser(int $projectId, ?string $email = null, string $password = 'password123'): array
{
    $response = api()->post("/api/v1/{$projectId}/auth/register", [
        'json' => ['email' => $email ?? uniqueEmail('enduser'), 'password' => $password],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

function setEndUserRole(string $platformToken, int $projectId, int $endUserId, ?string $role): array
{
    $response = api()->patch("/api/v1/projects/{$projectId}/end-users/{$endUserId}", [
        'headers' => ['Authorization' => "Bearer {$platformToken}"],
        'json'    => ['role' => $role],
    ]);

    expect($response->getStatusCode())->toBe(200);

    return json($response);
}

/**
 * A platform owner + project + "posts" table (title, created_by) — the shape
 * used across the RLS test suites.
 *
 * @return array{0: string, 1: array, 2: array} [ownerToken, project, table]
 */
function postsTableForRls(): array
{
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $table   = createTable($owner['token'], $project['id'], 'posts', [
        ['name' => 'title', 'type' => 'text'],
        ['name' => 'created_by', 'type' => 'integer', 'nullable' => true],
    ]);

    return [$owner['token'], $project, $table];
}

/** Convenience: owner + project + table + full-permission API key in one call. */
function bootstrapProjectWithTable(array $columns, ?string $tableName = null): array
{
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $table   = createTable($owner['token'], $project['id'], $tableName, $columns);
    $key     = createApiKey($owner['token'], $project['id'], ['select', 'insert', 'update', 'delete']);

    return [
        'ownerToken' => $owner['token'],
        'project'    => $project,
        'table'      => $table,
        'apiKey'     => $key['key'],
    ];
}
