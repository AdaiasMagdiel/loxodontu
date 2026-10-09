<?php

use App\Cron\CronJob;
use App\Cron\CronJobRunner;
use App\Database;

class SecurityProbeCallback
{
    public static bool $called = false;

    public static function run(): string
    {
        self::$called = true;

        return 'ran';
    }
}

test('a regular tenant cannot create command or callback cron jobs', function (string $type, string $target) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['name' => 'Escalate', 'type' => $type, 'target' => $target, 'run_at' => '2099-01-01 00:00:00'],
    ]);

    expect($response->getStatusCode())->toBe(403);
})->with([
    ['command', 'cat .env'],
    ['callback', 'App\\Database::getConn'],
]);

test('a regular tenant cannot switch an existing job to a privileged type', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $job = json(api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['name' => 'Ping', 'type' => 'http', 'target' => 'https://example.test', 'run_at' => '2099-01-01 00:00:00'],
    ]));

    $response = api()->patch("/api/v1/projects/{$project['id']}/cron-jobs/{$job['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['type' => 'command', 'target' => 'id'],
    ]);

    expect($response->getStatusCode())->toBe(403);
});

test('a platform admin can create callback jobs', function () {
    $admin = registerPlatformAdmin();
    $project = createProject($admin['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/cron-jobs", [
        'headers' => ['Authorization' => "Bearer {$admin['token']}"],
        'json' => ['name' => 'Ok', 'type' => 'callback', 'target' => 'SecurityProbeCallback::run', 'run_at' => '2099-01-01 00:00:00'],
    ]);

    expect($response->getStatusCode())->toBe(201);
});

test('the runner refuses callback jobs of projects whose owner is not an admin', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $queue = uniqueSlug('security');

    // Simulates a job stored before the admin-only rule existed.
    CronJob::schedule(projectInternalId($project['id']), 'Legacy', 'callback', 'SecurityProbeCallback::run', queue: $queue, runAt: '2000-01-01 00:00:00');
    SecurityProbeCallback::$called = false;

    $summary = (new CronJobRunner(Database::getConn('default'), 'security-worker'))->runDue(10, $queue);

    expect($summary)->toMatchArray(['ran' => 1, 'failed' => 1]);
    expect(SecurityProbeCallback::$called)->toBeFalse();
});

test('a regular tenant cannot create an edge function with a class handler', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/functions", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['name' => 'Escalate', 'slug' => 'escalate', 'handler' => 'SecurityProbeCallback::run'],
    ]);

    expect($response->getStatusCode())->toBe(403);
});

test('a stored class handler is not invoked for a non-admin project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    Database::getConn('default')->prepare(
        'INSERT INTO project_functions (project_id, slug, name, handler, require_api_key, enabled) VALUES (?, ?, ?, ?, 0, 1)'
    )->execute([projectInternalId($project['id']), 'legacy', 'Legacy', 'SecurityProbeCallback::run']);
    SecurityProbeCallback::$called = false;

    $response = api()->post("/api/v1/{$project['id']}/functions/legacy");

    expect($response->getStatusCode())->toBe(403);
    expect(SecurityProbeCallback::$called)->toBeFalse();
});
