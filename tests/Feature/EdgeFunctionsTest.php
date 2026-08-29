<?php

use App\Cron\CronJobRunner;
use App\Database;
function edgeFunctionSource(): string
{
    return <<<'PHP'
<?php

use App\Edge\FunctionRequest;
use App\Edge\FunctionResponse;

return function (FunctionRequest $request): FunctionResponse {
    return FunctionResponse::json([
        'ok' => true,
        'project_id' => $request->projectId,
        'message' => $request->body['message'] ?? 'hello',
    ]);
};
PHP;
}

test('creates and lists edge functions for a project owner', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $created = api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Hello',
            'slug' => 'hello',
            'source_code' => edgeFunctionSource(),
            'methods' => ['POST'],
        ],
    ]);

    expect($created->getStatusCode())->toBe(201);
    expect(json($created))->toMatchArray([
        'name' => 'Hello',
        'slug' => 'hello',
        'methods' => ['POST'],
        'require_api_key' => true,
        'enabled' => true,
    ]);

    $list = api()->get("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($list->getStatusCode())->toBe(200);
    expect(json($list))->toHaveCount(1);
});

test('invokes an edge function with the owning platform token', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Hello',
            'slug' => 'hello',
            'source_code' => edgeFunctionSource(),
            'methods' => ['POST'],
        ],
    ]);

    $response = api()->post("/api/v1/{$project['id']}/functions/hello", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['message' => 'from ui'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response))->toMatchArray(['ok' => true, 'message' => 'from ui']);
});

test('captures function output without corrupting the sandbox response', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $source = <<<'PHP'
<?php

use App\Edge\FunctionRequest;
use App\Edge\FunctionResponse;

return function (FunctionRequest $request): FunctionResponse {
    echo "debug output that should not leak";

    return FunctionResponse::json([
        'ok' => true,
        'method' => $request->method,
    ]);
};
PHP;

    api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Noisy',
            'slug' => 'noisy',
            'source_code' => $source,
            'methods' => ['GET'],
        ],
    ]);

    $response = api()->get("/api/v1/{$project['id']}/functions/noisy", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response))->toMatchArray(['ok' => true, 'method' => 'GET']);
});

test('invokes an edge function with a project api key that has function permission', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $key = createApiKey($owner['token'], $project['id'], ['function']);

    api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Hello',
            'slug' => 'hello',
            'source_code' => edgeFunctionSource(),
            'methods' => ['POST'],
        ],
    ]);

    $response = api()->post("/api/v1/{$project['id']}/functions/hello", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json' => ['message' => 'from api'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response))->toMatchArray(['ok' => true, 'message' => 'from api']);
});

test('cron jobs can invoke edge functions', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Hello',
            'slug' => 'hello',
            'source_code' => edgeFunctionSource(),
            'methods' => ['POST'],
        ],
    ]);

    $created = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Run hello',
            'type' => 'function',
            'target' => 'hello',
            'payload' => ['message' => 'from cron'],
            'run_at' => '2000-01-01 00:00:00',
        ],
    ]);
    $job = json($created);

    $summary = (new CronJobRunner(Database::getConn('default'), 'test-worker'))->runDue();
    expect($summary)->toMatchArray(['ran' => 1, 'succeeded' => 1, 'failed' => 0]);

    $runs = api()->get("/api/v1/projects/{$project['id']}/cron-jobs/{$job['id']}/runs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect(json($runs)[0]['output'])->toContain('from cron');
});
