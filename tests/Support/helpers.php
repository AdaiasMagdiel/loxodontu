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

/**
 * Resolves a project's internal numeric id from its public id. Only needed by
 * tests that assert against the physical MySQL table name (which is keyed by
 * the internal id, never the public one) — the API surface itself never
 * exposes or accepts the internal id.
 */
function projectInternalId(string $publicId): int
{
    $stmt = App\Database::getConn('default')->prepare('SELECT id FROM projects WHERE public_id = ? LIMIT 1');
    $stmt->execute([$publicId]);

    return (int) $stmt->fetchColumn();
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
function createTable(string $token, string $projectId, ?string $name = null, array $columns = []): array
{
    $response = api()->post("/api/v1/projects/{$projectId}/tables", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => $name ?? uniqueSlug('table'), 'columns' => $columns],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/** @param string[] $permissions @return array{id: int, key: string} */
function createApiKey(string $token, string $projectId, array $permissions, ?string $name = null): array
{
    $response = api()->post("/api/v1/projects/{$projectId}/keys", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => $name ?? uniqueSlug('key'), 'permissions' => $permissions],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/** @param array{name?: string, operation: string, expression: string, enabled?: bool} $payload */
function createRlsPolicy(string $token, string $projectId, int $tableId, array $payload): array
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
function registerEndUser(string $projectId, ?string $email = null, string $password = 'password123'): array
{
    $response = api()->post("/api/v1/{$projectId}/auth/register", [
        'json' => ['email' => $email ?? uniqueEmail('enduser'), 'password' => $password],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

function setEndUserRole(string $platformToken, string $projectId, int $endUserId, ?string $role): array
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

/** @return array{id: int, name: string, public: bool} */
function createBucket(string $token, string $projectId, ?string $name = null, bool $public = false): array
{
    $response = api()->post("/api/v1/projects/{$projectId}/storage/buckets", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => $name ?? uniqueSlug('bucket'), 'public' => $public],
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/** @param array{name?: string, operation: string, expression: string, enabled?: bool} $payload */
function createStoragePolicy(string $token, string $projectId, int $bucketId, array $payload): array
{
    $payload['name'] ??= uniqueSlug('storage-policy');

    $response = api()->post("/api/v1/projects/{$projectId}/storage/buckets/{$bucketId}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => $payload,
    ]);

    expect($response->getStatusCode())->toBe(201);

    return json($response);
}

/**
 * Uploads $contents as a file to a bucket via the storage passthrough.
 *
 * @param array<string, mixed> $extraHeaders e.g. ['X-User-Token' => ...]
 */
function uploadObject(
    string $apiKey,
    string $projectId,
    string $bucket,
    string $path,
    string $contents = 'hello',
    string $mimeType = 'text/plain',
    array $extraHeaders = [],
): \AdaiasMagdiel\Erlenmeyer\Response {
    $tmpFile = tempnam(sys_get_temp_dir(), 'loxodontu-upload-');
    file_put_contents($tmpFile, $contents);

    return api()->post("/api/v1/{$projectId}/storage/{$bucket}", [
        'headers'     => array_merge(['Authorization' => "Bearer {$apiKey}"], $extraHeaders),
        'form_params' => ['path' => $path],
        'files'       => [
            'file' => [
                'name'     => basename($path),
                'type'     => $mimeType,
                'tmp_name' => $tmpFile,
                'error'    => 0,
                'size'     => strlen($contents),
            ],
        ],
    ]);
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
