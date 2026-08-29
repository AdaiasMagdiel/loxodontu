<?php

use App\Cron\CronJobRunner;
use App\Database;

class TestCronCallback
{
    public static function ok(?array $payload): string
    {
        return $payload['message'] ?? 'ok';
    }

    public static function fail(): void
    {
        throw new RuntimeException('planned failure');
    }
}

test('creates and lists cron jobs for a project owner', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $created = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Ping webhook',
            'type' => 'http',
            'target' => 'https://example.test/webhook',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'payload' => ['ok' => true],
            'interval_seconds' => 300,
            'run_at' => '2099-01-01 00:00:00',
        ],
    ]);

    expect($created->getStatusCode())->toBe(201);
    expect(json($created))
        ->toMatchArray([
            'name' => 'Ping webhook',
            'type' => 'http',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'interval_seconds' => 300,
            'enabled' => true,
        ]);

    $list = api()->get("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($list->getStatusCode())->toBe(200);
    expect(json($list))->toHaveCount(1);
});

test('rejects invalid cron job payloads', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Bad webhook',
            'type' => 'http',
            'target' => 'not-a-url',
            'interval_seconds' => 30,
        ],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects specific invalid cron job fields', function (array $payload, string $message) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => $payload,
    ]);

    expect($response->getStatusCode())->toBe(422);
    expect(json($response)['error'])->toBe($message);
})->with([
    [['name' => '', 'type' => 'callback', 'target' => 'TestCronCallback::ok', 'run_at' => '2099-01-01 00:00:00'], 'name is required'],
    [['name' => 'Bad', 'type' => 'bad', 'target' => 'x', 'run_at' => '2099-01-01 00:00:00'], 'type must be one of: http, command, callback, function'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => '', 'run_at' => '2099-01-01 00:00:00'], 'target is required'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x', 'queue' => 'bad space', 'run_at' => '2099-01-01 00:00:00'], 'queue is required'],
    [['name' => 'Bad', 'type' => 'http', 'target' => 'https://example.test', 'method' => 'TRACE', 'run_at' => '2099-01-01 00:00:00'], 'method must be one of: GET, POST, PUT, PATCH, DELETE, HEAD'],
    [['name' => 'Bad', 'type' => 'function', 'target' => 'bad slug', 'run_at' => '2099-01-01 00:00:00'], 'target must be a function slug for function jobs'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x', 'headers' => 123, 'run_at' => '2099-01-01 00:00:00'], 'headers must be an object'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x', 'interval_seconds' => 30], 'interval_seconds must be at least 60'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x'], 'run_at or interval_seconds is required'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x', 'run_at' => 'not-a-date'], 'run_at must be a valid date time'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x', 'run_at' => '2099-01-01 00:00:00', 'retry_delay_seconds' => 0], 'retry_delay_seconds must be at least 1'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x', 'run_at' => '2099-01-01 00:00:00', 'retry_backoff' => 'never'], 'retry_backoff must be one of: fixed, exponential'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x', 'run_at' => '2099-01-01 00:00:00', 'max_retry_delay_seconds' => 0], 'max_retry_delay_seconds must be at least 1'],
    [['name' => 'Bad', 'type' => 'callback', 'target' => 'x', 'run_at' => '2099-01-01 00:00:00', 'timeout_seconds' => 0], 'timeout_seconds must be at least 1'],
]);

test('updates and deletes cron jobs', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $created = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Original job',
            'type' => 'http',
            'target' => 'https://example.test/original',
            'method' => 'POST',
            'headers' => ['X-Initial' => '1'],
            'payload' => ['initial' => true],
            'run_at' => '2099-01-01 00:00:00',
        ],
    ]);
    $job = json($created);

    $updated = api()->patch("/api/v1/projects/{$project['id']}/cron-jobs/{$job['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Updated job',
            'queue' => 'emails:high',
            'type' => 'callback',
            'target' => 'TestCronCallback::ok',
            'method' => 'GET',
            'headers' => null,
            'payload' => 'raw payload',
            'interval_seconds' => 120,
            'run_at' => null,
            'max_retries' => null,
            'retry_backoff' => 'fixed',
            'retry_delay_seconds' => 5,
            'max_retry_delay_seconds' => 10,
            'timeout_seconds' => 2,
            'allow_overlap' => true,
            'enabled' => false,
        ],
    ]);

    expect($updated->getStatusCode())->toBe(200);
    expect(json($updated))->toMatchArray([
        'name' => 'Updated job',
        'queue' => 'emails:high',
        'type' => 'callback',
        'target' => 'TestCronCallback::ok',
        'method' => null,
        'headers' => null,
        'payload' => 'raw payload',
        'interval_seconds' => 120,
        'max_retries' => null,
        'retry_backoff' => 'fixed',
        'retry_delay_seconds' => 5,
        'max_retry_delay_seconds' => 10,
        'timeout_seconds' => 2,
        'allow_overlap' => true,
        'enabled' => false,
    ]);

    $deleted = api()->delete("/api/v1/projects/{$project['id']}/cron-jobs/{$job['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($deleted->getStatusCode())->toBe(204);
});

test('cron job endpoints return not found and empty update errors', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $created = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Callback job',
            'type' => 'callback',
            'target' => 'TestCronCallback::ok',
            'run_at' => '2099-01-01 00:00:00',
        ],
    ]);
    $job = json($created);

    $emptyUpdate = api()->patch("/api/v1/projects/{$project['id']}/cron-jobs/{$job['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [],
    ]);
    expect($emptyUpdate->getStatusCode())->toBe(422);

    $missingShow = api()->get("/api/v1/projects/{$project['id']}/cron-jobs/999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($missingShow->getStatusCode())->toBe(404);

    $missingRuns = api()->get("/api/v1/projects/{$project['id']}/cron-jobs/999999/runs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($missingRuns->getStatusCode())->toBe(404);

    $missingDelete = api()->delete("/api/v1/projects/{$project['id']}/cron-jobs/999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($missingDelete->getStatusCode())->toBe(404);

    $wrongProject = api()->get('/api/v1/projects/prj_missing/cron-jobs', [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($wrongProject->getStatusCode())->toBe(404);
});

test('runs due callback jobs and stores run history', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $created = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Callback job',
            'type' => 'callback',
            'target' => 'TestCronCallback::ok',
            'payload' => ['message' => 'done'],
            'run_at' => '2000-01-01 00:00:00',
        ],
    ]);
    $job = json($created);

    $summary = (new CronJobRunner(Database::getConn('default'), 'test-worker'))->runDue();
    expect($summary)->toMatchArray(['ran' => 1, 'succeeded' => 1, 'failed' => 0]);

    $stored = api()->get("/api/v1/projects/{$project['id']}/cron-jobs/{$job['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect(json($stored))
        ->toMatchArray([
            'enabled' => false,
            'last_status' => 'success',
            'failure_count' => 0,
        ]);

    $runs = api()->get("/api/v1/projects/{$project['id']}/cron-jobs/{$job['id']}/runs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($runs->getStatusCode())->toBe(200);
    expect(json($runs)[0])
        ->toMatchArray([
            'status' => 'success',
            'attempt' => 1,
            'output' => 'done',
            'worker_id' => 'test-worker',
        ]);
});

test('failed jobs retry until max retries is exceeded', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $created = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => [
            'name' => 'Failing callback',
            'type' => 'callback',
            'target' => 'TestCronCallback::fail',
            'run_at' => '2000-01-01 00:00:00',
            'max_retries' => 0,
        ],
    ]);
    $job = json($created);

    $summary = (new CronJobRunner(Database::getConn('default'), 'test-worker'))->runDue();
    expect($summary)->toMatchArray(['ran' => 1, 'succeeded' => 0, 'failed' => 1]);

    $stored = api()->get("/api/v1/projects/{$project['id']}/cron-jobs/{$job['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect(json($stored))
        ->toMatchArray([
            'enabled' => false,
            'last_status' => 'failed',
            'failure_count' => 1,
        ]);
});
