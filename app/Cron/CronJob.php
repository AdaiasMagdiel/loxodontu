<?php

namespace App\Cron;

use App\Database;

class CronJob
{
    /**
     * @param array<string, mixed> $headers
     */
    public static function schedule(
        int $projectId,
        string $name,
        string $type,
        string $target,
        string $queue = 'default',
        ?int $intervalSeconds = null,
        ?string $runAt = null,
        ?string $method = null,
        array $headers = [],
        mixed $payload = null,
        ?int $maxRetries = 3,
        string $retryBackoff = 'exponential',
        int $retryDelaySeconds = 300,
        int $maxRetryDelaySeconds = 86400,
        int $timeoutSeconds = 30,
        bool $allowOverlap = false,
        bool $enabled = true,
    ): int {
        $pdo = Database::getConn('default');
        $nextRunAt = $runAt ?: gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO cron_jobs
             (project_id, queue, name, type, target, method, headers, payload, interval_seconds, run_at, next_run_at,
              max_retries, retry_backoff, retry_delay_seconds, max_retry_delay_seconds, timeout_seconds, allow_overlap, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $projectId,
            $queue,
            $name,
            $type,
            $target,
            $method !== null ? strtoupper($method) : null,
            $headers !== [] ? json_encode($headers) : null,
            $payload !== null ? (is_string($payload) ? $payload : json_encode($payload)) : null,
            $intervalSeconds,
            $runAt,
            $nextRunAt,
            $maxRetries,
            $retryBackoff,
            $retryDelaySeconds,
            $maxRetryDelaySeconds,
            $timeoutSeconds,
            $allowOverlap ? 1 : 0,
            $enabled ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
