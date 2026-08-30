<?php

function bucketForStoragePolicies(): array
{
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);

    return [$owner['token'], $project, $bucket];
}

test('creates a policy with an unconditional (fully open) expression', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], [
        'operation' => 'SELECT', 'expression' => '1=1',
    ]);

    expect($policy['expression'])->toBe('1=1');
});

test('creates a policy scoped to a role with an $auth.id placeholder', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], [
        'operation' => 'INSERT', 'expression' => 'owner_id = $auth.id',
    ]);

    expect($policy['expression'])->toBe('owner_id = $auth.id');
});

test('rejects a missing name', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['operation' => 'SELECT', 'expression' => '1=1'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a missing expression', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => ''],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid operation', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'TRUNCATE', 'expression' => '1=1'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an expression containing a statement separator', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => '1=1; DROP TABLE project_storage_objects'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an unknown $auth placeholder', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => 'owner_id = $auth.password'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('lists and deletes a policy', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], ['operation' => 'SELECT', 'expression' => '1=1']);

    $list = api()->get("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);
    expect(array_column(json($list), 'id'))->toContain($policy['id']);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies/{$policy['id']}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);
    expect($destroy->getStatusCode())->toBe(204);

    $listAfter = api()->get("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);
    expect(array_column(json($listAfter), 'id'))->not->toContain($policy['id']);
});

test('404s deleting a policy that does not belong to the given bucket', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();
    $otherBucket = createBucket($token, $project['id']);
    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], ['operation' => 'SELECT', 'expression' => '1=1']);

    $response = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$otherBucket['id']}/policies/{$policy['id']}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('index/store/destroy all 404 for a bucket in a project the caller does not own', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();
    $intruder = registerPlatformUser();

    $index = api()->get("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($index->getStatusCode())->toBe(404);

    $store = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'expression' => '1=1'],
    ]);
    expect($store->getStatusCode())->toBe(404);

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], ['operation' => 'SELECT', 'expression' => '1=1']);
    $destroy = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies/{$policy['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});
