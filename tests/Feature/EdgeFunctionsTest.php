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

test('decodes sandbox payloads with cgi headers before json', function () {
    $runner = new App\Edge\EdgeFunctionRunner(App\Database::getConn('default'));
    $method = new ReflectionMethod($runner, 'decodeSandboxPayload');
    $error = null;
    $stdout = "Content-type: text/html; charset=UTF-8\r\n\r\n" . json_encode([
        'status' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => ['ok' => true],
    ]);
    $args = [$stdout, &$error];

    $payload = $method->invokeArgs($runner, $args);

    expect($payload)->toMatchArray([
        'status' => 200,
        'body' => ['ok' => true],
    ]);
    expect($error)->toBeNull();
});

test('includes invalid sandbox output details when debug is enabled', function () {
    putenv('DEBUG=true');
    $_ENV['DEBUG'] = 'true';

    $runner = new App\Edge\EdgeFunctionRunner(App\Database::getConn('default'));
    $method = new ReflectionMethod($runner, 'invalidSandboxResponse');

    $response = $method->invoke($runner, 'not json', 'stderr text', 0, 'Syntax error');

    expect($response->status)->toBe(500);
    expect($response->body['error'])->toBe('Function returned an invalid response');
    expect($response->body['debug'])->toMatchArray([
        'exit_code' => 0,
        'json_error' => 'Syntax error',
        'stdout_length' => 8,
        'stderr_length' => 11,
        'stdout_preview' => 'not json',
        'stderr_preview' => 'stderr text',
    ]);

    putenv('DEBUG=false');
    $_ENV['DEBUG'] = 'false';
});

test('rejects invalid edge function payloads', function (array $payload, string $message) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => $payload,
    ]);

    expect($response->getStatusCode())->toBe(422);
    expect(json($response)['error'])->toBe($message);
})->with([
    [['name' => 'Bad', 'slug' => 'bad slug', 'source_code' => edgeFunctionSource()], 'slug must contain only letters, numbers, dashes, and underscores'],
    [['name' => '', 'slug' => 'bad', 'source_code' => edgeFunctionSource()], 'name is required'],
    [['name' => 'Bad', 'slug' => 'bad'], 'source_code is required'],
    [['name' => 'Bad', 'slug' => 'bad', 'source_code' => 'return [];'], 'source_code must be a PHP file starting with <?php'],
    [['name' => 'Bad', 'slug' => 'bad', 'source_code' => edgeFunctionSource(), 'methods' => 123], 'methods must be an array'],
    [['name' => 'Bad', 'slug' => 'bad', 'source_code' => edgeFunctionSource(), 'methods' => ['OPTIONS']], 'methods must contain only: GET, POST, PUT, PATCH, DELETE'],
    [['name' => 'Bad', 'slug' => 'bad', 'source_code' => edgeFunctionSource(), 'timeout_seconds' => 0], 'timeout_seconds must be between 1 and 60'],
    [['name' => 'Bad', 'slug' => 'bad', 'source_code' => edgeFunctionSource(), 'memory_limit_mb' => 8], 'memory_limit_mb must be between 16 and 256'],
]);

test('updates shows and deletes edge functions', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $created = api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Hello',
            'slug' => 'hello',
            'description' => 'Initial',
            'source_code' => edgeFunctionSource(),
            'methods' => ['POST'],
        ],
    ]);
    $function = json($created);

    $show = api()->get("/api/v1/projects/{$project['id']}/functions/{$function['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($show->getStatusCode())->toBe(200);
    expect(json($show))->toMatchArray(['slug' => 'hello', 'description' => 'Initial']);

    $updated = api()->patch("/api/v1/projects/{$project['id']}/functions/{$function['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Updated',
            'slug' => 'updated',
            'description' => '',
            'methods' => [],
            'require_api_key' => false,
            'enabled' => false,
            'timeout_seconds' => 5,
            'memory_limit_mb' => 64,
        ],
    ]);

    expect($updated->getStatusCode())->toBe(200);
    expect(json($updated))->toMatchArray([
        'name' => 'Updated',
        'slug' => 'updated',
        'description' => null,
        'methods' => [],
        'require_api_key' => false,
        'enabled' => false,
        'timeout_seconds' => 5,
        'memory_limit_mb' => 64,
    ]);

    $deleted = api()->delete("/api/v1/projects/{$project['id']}/functions/{$function['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($deleted->getStatusCode())->toBe(204);
});

test('edge function management returns useful errors', function () {
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

    $duplicate = api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Hello again',
            'slug' => 'hello',
            'source_code' => edgeFunctionSource(),
        ],
    ]);
    expect($duplicate->getStatusCode())->toBe(409);

    $missingShow = api()->get("/api/v1/projects/{$project['id']}/functions/999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($missingShow->getStatusCode())->toBe(404);

    $existing = api()->get("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    $function = json($existing)[0];

    $emptyUpdate = api()->patch("/api/v1/projects/{$project['id']}/functions/{$function['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [],
    ]);
    expect($emptyUpdate->getStatusCode())->toBe(422);

    $missingDelete = api()->delete("/api/v1/projects/{$project['id']}/functions/999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($missingDelete->getStatusCode())->toBe(404);

    $wrongProject = api()->get('/api/v1/projects/prj_missing/functions', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($wrongProject->getStatusCode())->toBe(404);
});

test('invokes public edge functions without an api key', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Public Hello',
            'slug' => 'public_hello',
            'source_code' => edgeFunctionSource(),
            'methods' => ['GET'],
            'require_api_key' => false,
        ],
    ]);

    $response = api()->get("/api/v1/{$project['id']}/functions/public_hello?name=adaias");

    expect($response->getStatusCode())->toBe(200);
    expect(json($response))->toMatchArray([
        'ok' => true,
        'message' => 'hello',
    ]);
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
