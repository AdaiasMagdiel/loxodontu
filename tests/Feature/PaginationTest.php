<?php

// Exercised through Tables::index — the pagination behavior itself (App\Pagination) is shared
// verbatim by Keys::index, RlsPolicies::index, and EndUsers::index, checked for wiring below.

function createTables(string $token, string $projectId, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        createTable($token, $projectId, "t{$i}_" . uniqueSlug(), []);
    }
}

test('defaults to a limit of 25 and an offset of 0', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTables($owner['token'], $project['id'], 3);

    $response = api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$owner['token']}"]]);

    expect($response->getHeaders()['X-Total-Count'])->toBe('3');
    expect($response->getHeaders()['X-Page-Limit'])->toBe('25');
    expect($response->getHeaders()['X-Page-Offset'])->toBe('0');
    expect(json($response))->toHaveCount(3);
});

test('limit and offset actually page through the results', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTables($owner['token'], $project['id'], 5);

    $firstPage = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'query'   => ['limit' => 2, 'offset' => 0],
    ]);
    expect(json($firstPage))->toHaveCount(2);
    expect($firstPage->getHeaders()['X-Total-Count'])->toBe('5');
    expect($firstPage->getHeaders()['X-Page-Limit'])->toBe('2');

    $secondPage = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'query'   => ['limit' => 2, 'offset' => 2],
    ]);
    expect(json($secondPage))->toHaveCount(2);

    $thirdPage = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'query'   => ['limit' => 2, 'offset' => 4],
    ]);
    expect(json($thirdPage))->toHaveCount(1); // 5 total, last page has the remainder

    // No overlap and no gaps: the three pages' ids reconstruct the full set exactly once each.
    $allIds = array_merge(array_column(json($firstPage), 'id'), array_column(json($secondPage), 'id'), array_column(json($thirdPage), 'id'));
    expect($allIds)->toHaveCount(5);
    expect(array_unique($allIds))->toHaveCount(5);
});

test('clamps an excessive limit down to the maximum of 100', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTables($owner['token'], $project['id'], 2);

    $response = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'query'   => ['limit' => 99999],
    ]);

    expect($response->getHeaders()['X-Page-Limit'])->toBe('100');
    expect(json($response))->toHaveCount(2); // only 2 rows exist, clamp doesn't invent rows
});

test('clamps a non-positive limit up to 1', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTables($owner['token'], $project['id'], 3);

    $zero = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'query'   => ['limit' => 0],
    ]);
    expect($zero->getHeaders()['X-Page-Limit'])->toBe('1');
    expect(json($zero))->toHaveCount(1);

    $negative = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'query'   => ['limit' => -5],
    ]);
    expect($negative->getHeaders()['X-Page-Limit'])->toBe('1');
});

test('clamps a negative offset up to 0', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTables($owner['token'], $project['id'], 3);

    $response = api()->get("/api/v1/projects/{$project['id']}/tables", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'query'   => ['offset' => -10],
    ]);

    expect($response->getHeaders()['X-Page-Offset'])->toBe('0');
    expect(json($response))->toHaveCount(3);
});

test('Keys, RlsPolicies and EndUsers index endpoints all expose pagination headers', function () {
    [$token, $project, $table] = postsTableForRls();
    createApiKey($token, $project['id'], ['select']);
    createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'SELECT', 'expression' => '1=1']);
    registerEndUser($project['id']);

    $keys = api()->get("/api/v1/projects/{$project['id']}/keys", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'query'   => ['limit' => 1],
    ]);
    expect($keys->getHeaders())->toHaveKeys(['X-Total-Count', 'X-Page-Limit', 'X-Page-Offset']);
    expect(json($keys))->toHaveCount(1);

    $policies = api()->get("/api/v1/projects/{$project['id']}/tables/{$table['id']}/rls-policies", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'query'   => ['limit' => 1],
    ]);
    expect($policies->getHeaders())->toHaveKeys(['X-Total-Count', 'X-Page-Limit', 'X-Page-Offset']);
    expect(json($policies))->toHaveCount(1);

    $endUsers = api()->get("/api/v1/projects/{$project['id']}/end-users", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'query'   => ['limit' => 1],
    ]);
    expect($endUsers->getHeaders())->toHaveKeys(['X-Total-Count', 'X-Page-Limit', 'X-Page-Offset']);
    expect(json($endUsers))->toHaveCount(1);
});
