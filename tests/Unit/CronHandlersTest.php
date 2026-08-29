<?php

use App\Cron\CommandJobHandler;
use App\Cron\CronJob;
use App\Cron\HttpJobHandler;
use App\Cron\JobResult;
use App\Database;
use App\Edge\FunctionResponse;

test('command jobs are disabled unless explicitly allowed', function () {
    putenv('CRON_ALLOW_COMMANDS=false');
    $_ENV['CRON_ALLOW_COMMANDS'] = 'false';

    $result = (new CommandJobHandler())->handle([
        'target' => PHP_BINARY . ' -r "echo 1;"',
    ]);

    expect($result->ok)->toBeFalse();
    expect($result->error)->toContain('Command jobs are disabled');
});

test('command jobs reject empty targets when enabled', function () {
    putenv('CRON_ALLOW_COMMANDS=true');
    $_ENV['CRON_ALLOW_COMMANDS'] = 'true';

    $result = (new CommandJobHandler())->handle(['target' => '']);

    expect($result->ok)->toBeFalse();
    expect($result->error)->toBe('Command target is empty.');
});

test('command jobs capture successful command output', function () {
    putenv('CRON_ALLOW_COMMANDS=true');
    $_ENV['CRON_ALLOW_COMMANDS'] = 'true';

    $result = (new CommandJobHandler())->handle([
        'target' => PHP_BINARY . ' -r "echo \"done\";"',
        'timeout_seconds' => 5,
    ]);

    expect($result->ok)->toBeTrue();
    expect($result->output)->toBe('done');
});

test('command jobs capture non-zero exits', function () {
    putenv('CRON_ALLOW_COMMANDS=true');
    $_ENV['CRON_ALLOW_COMMANDS'] = 'true';

    $result = (new CommandJobHandler())->handle([
        'target' => PHP_BINARY . ' -r "fwrite(STDERR, \"bad\"); exit(7);"',
        'timeout_seconds' => 5,
    ]);

    expect($result->ok)->toBeFalse();
    expect($result->error)->toContain('Command exited with code');
    expect($result->output)->toContain('bad');
});

test('http jobs report requests that never receive a response', function () {
    $result = (new HttpJobHandler())->handle([
        'method' => 'GET',
        'headers' => 'not-json',
        'target' => 'data://text/plain,not-http',
        'payload' => null,
        'timeout_seconds' => 1,
    ]);

    expect($result->ok)->toBeFalse();
    expect($result->error)->toBe('HTTP request returned status 0.');
});

test('cron job schedule inserts a job and returns its id', function () {
    $pdo = Database::getConn('default');
    $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)')->execute([
        'Schedule Owner',
        uniqueEmail('schedule'),
        password_hash('password123', PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();

    $columns = $pdo->query("SHOW COLUMNS FROM projects LIKE 'public_id'")->fetch();
    if ($columns) {
        $pdo->prepare('INSERT INTO projects (public_id, user_id, name, slug) VALUES (?, ?, ?, ?)')->execute([
            'prj_' . bin2hex(random_bytes(12)),
            $userId,
            'Scheduled Project',
            uniqueSlug('scheduled-project'),
        ]);
    } else {
        $pdo->prepare('INSERT INTO projects (user_id, name, slug) VALUES (?, ?, ?)')->execute([
            $userId,
            'Scheduled Project',
            uniqueSlug('scheduled-project'),
        ]);
    }
    $projectId = (int) $pdo->lastInsertId();

    $id = CronJob::schedule(
        $projectId,
        'Scheduled callback',
        'callback',
        'TestCronCallback::ok',
        headers: ['X-Test' => '1'],
        payload: ['ok' => true],
        runAt: '2099-01-01 00:00:00',
    );

    $stmt = $pdo->prepare('SELECT * FROM cron_jobs WHERE id = ?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();

    expect($id)->toBeGreaterThan(0);
    expect((int) $job['project_id'])->toBe($projectId);
    expect(json_decode($job['headers'], true))->toBe(['X-Test' => '1']);
    expect(json_decode($job['payload'], true))->toBe(['ok' => true]);
    expect($job)->toMatchArray([
        'name' => 'Scheduled callback',
        'type' => 'callback',
        'target' => 'TestCronCallback::ok',
    ]);
});

test('simple response value objects expose their state', function () {
    $success = JobResult::success('ok');
    $failure = JobResult::failure('nope', 'partial');
    $response = new FunctionResponse('plain', 202, ['X-Test' => '1']);

    expect($success->ok)->toBeTrue();
    expect($success->output)->toBe('ok');
    expect($failure->ok)->toBeFalse();
    expect($failure->output)->toBe('partial');
    expect($failure->error)->toBe('nope');
    expect($response->body)->toBe('plain');
    expect($response->status)->toBe(202);
    expect($response->headers)->toBe(['X-Test' => '1']);
});
