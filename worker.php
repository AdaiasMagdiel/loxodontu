<?php

use App\Cron\CronJobRunner;
use App\Database;

set_time_limit(300);

require_once __DIR__ . '/bootstrap.php';

const WORKER_MAX_LIMIT = 100;

$isCli = PHP_SAPI === 'cli';
$token = (string) env('CRON_WORKER_TOKEN', '');
$options = $isCli ? getopt('', ['token:', 'limit:', 'queue:']) : [];

$deny = function (string $message): never {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => $message]) . PHP_EOL;
    exit(1);
};

if ($isCli) {
    // Whoever can run the CLI can already read .env, so the token is only
    // enforced here when one is configured.
    $providedToken = $options['token'] ?? ($_SERVER['argv'][1] ?? null);
    if ($token !== '' && !hash_equals($token, (string) $providedToken)) {
        $deny('Forbidden');
    }
} else {
    // Over HTTP the worker is reachable by anyone, so it refuses to run at
    // all without a configured token. The X-Cron-Token header is preferred;
    // `?token=` is kept for web-cron services that can't send headers, but
    // query strings end up in access logs.
    if ($token === '') {
        $deny('The HTTP worker is disabled until CRON_WORKER_TOKEN is set.');
    }
    $providedToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? null);
    if (!hash_equals($token, (string) $providedToken)) {
        $deny('Forbidden');
    }
}

$positionalLimit = isset($_SERVER['argv'][2]) && is_numeric($_SERVER['argv'][2]) ? $_SERVER['argv'][2] : null;
$limit = $isCli
    ? (int) ($options['limit'] ?? $positionalLimit ?? env('CRON_WORKER_LIMIT', 10))
    : (int) ($_GET['limit'] ?? env('CRON_WORKER_LIMIT', 10));
$limit = min(max(1, $limit), WORKER_MAX_LIMIT);

$queue = $isCli
    ? (isset($options['queue']) ? trim((string) $options['queue']) : null)
    : (isset($_GET['queue']) ? trim((string) $_GET['queue']) : null);

$workerId = substr(gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4)), 0, 64);
$runner = new CronJobRunner(Database::getConn('default'), $workerId);
$summary = $runner->runDue($limit, $queue);

if (!$isCli) {
    header('Content-Type: application/json');
}
echo json_encode(['worker_id' => $workerId, 'queue' => $queue, 'summary' => $summary]) . PHP_EOL;
