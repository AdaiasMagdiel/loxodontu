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
