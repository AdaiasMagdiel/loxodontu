<?php

function bucketForStoragePolicies(): array
{
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);

    return [$owner['token'], $project, $bucket];
}

test('creates a policy with an empty conditions object (fully open)', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], [
        'operation' => 'SELECT', 'conditions' => [],
    ]);

    expect($policy['conditions'])->toBe([]);
    expect($policy['role'])->toBeNull();
});

test('creates a policy scoped to a role with an $auth.id placeholder', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], [
        'operation' => 'INSERT', 'role' => 'manager', 'conditions' => ['owner_id' => '$auth.id'],
    ]);

    expect($policy['role'])->toBe('manager');
    expect($policy['conditions'])->toBe(['owner_id' => '$auth.id']);
});

test('rejects a missing name', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['operation' => 'SELECT', 'conditions' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid operation', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'TRUNCATE', 'conditions' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid role format', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'role' => 'not a valid role!', 'conditions' => []],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a non-array conditions field', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => 'not-an-object'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a condition referencing an unknown column', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['not_a_column' => 1]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid placeholder', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['owner_id' => '$auth.password']],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('creates a policy with an operator condition and a scalar value', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], [
        'operation' => 'SELECT', 'conditions' => ['size' => ['op' => 'gt', 'value' => 5]],
    ]);

    expect($policy['conditions'])->toBe(['size' => ['op' => 'gt', 'value' => 5]]);
});

test('creates a policy with a no-value operator condition', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], [
        'operation' => 'SELECT', 'conditions' => ['owner_id' => ['op' => 'is_not_null']],
    ]);

    expect($policy['conditions'])->toBe(['owner_id' => ['op' => 'is_not_null']]);
});

test('rejects an operator condition with an invalid op', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['owner_id' => ['op' => 'bogus']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an operator condition missing a required value', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['size' => ['op' => 'gt']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an operator condition with an invalid placeholder value', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['owner_id' => ['op' => 'eq', 'value' => '$auth.password']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an operator condition with a non-scalar value', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['owner_id' => ['op' => 'eq', 'value' => ['nested' => 'array']]]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects a non-scalar condition value', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => ['owner_id' => ['nested' => 'array']]],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('lists and deletes a policy', function () {
    [$token, $project, $bucket] = bucketForStoragePolicies();

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], ['operation' => 'SELECT', 'conditions' => []]);

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
    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], ['operation' => 'SELECT', 'conditions' => []]);

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
        'json'    => ['name' => 'p', 'operation' => 'SELECT', 'conditions' => []],
    ]);
    expect($store->getStatusCode())->toBe(404);

    $policy = createStoragePolicy($token, $project['id'], $bucket['id'], ['operation' => 'SELECT', 'conditions' => []]);
    $destroy = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/policies/{$policy['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});
