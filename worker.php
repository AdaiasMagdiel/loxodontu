<?php

use App\Cron\CronJobRunner;
use App\Database;

set_time_limit(300);

require_once __DIR__ . '/bootstrap.php';

$token = env('CRON_WORKER_TOKEN');
$options = PHP_SAPI === 'cli' ? getopt('', ['token:', 'limit:', 'queue:']) : [];
$providedToken = PHP_SAPI === 'cli'
    ? ($options['token'] ?? ($_SERVER['argv'][1] ?? null))
    : ($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? null));

if ($token && !hash_equals((string) $token, (string) $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']) . PHP_EOL;
    exit(1);
}

$positionalLimit = isset($_SERVER['argv'][2]) && is_numeric($_SERVER['argv'][2]) ? $_SERVER['argv'][2] : null;
$limit = PHP_SAPI === 'cli'
    ? (int) ($options['limit'] ?? $positionalLimit ?? env('CRON_WORKER_LIMIT', 10))
    : (int) ($_GET['limit'] ?? env('CRON_WORKER_LIMIT', 10));
$queue = null;

if (PHP_SAPI === 'cli') {
    $queue = isset($options['queue']) ? trim((string) $options['queue']) : null;
} else {
    $queue = isset($_GET['queue']) ? trim((string) $_GET['queue']) : null;
}

$workerId = substr(gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4)), 0, 64);
$runner = new CronJobRunner(Database::getConn('default'), $workerId);
$summary = $runner->runDue($limit, $queue);

header('Content-Type: application/json');
echo json_encode(['worker_id' => $workerId, 'queue' => $queue, 'summary' => $summary]) . PHP_EOL;
