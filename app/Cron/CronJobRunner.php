<?php

namespace App\Cron;

use PDO;
use Throwable;

class CronJobRunner
{
    /** @var array<string, JobHandler> */
    private array $handlers;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $workerId,
        ?array $handlers = null,
    ) {
        $this->handlers = $handlers ?? [
            'http' => new HttpJobHandler(),
            'command' => new CommandJobHandler(),
            'callback' => new CallbackJobHandler(),
            'function' => new FunctionJobHandler(),
        ];
    }

    /** @return array{checked: int, ran: int, succeeded: int, failed: int} */
    public function runDue(int $limit = 10, ?string $queue = null): array
    {
        $jobs = $this->dueJobs(max(1, $limit), $queue);
        $summary = ['checked' => count($jobs), 'ran' => 0, 'succeeded' => 0, 'failed' => 0];

        foreach ($jobs as $job) {
            $claimed = $this->claim((int) $job['id'], (bool) $job['allow_overlap']);
            if (!$claimed) {
                continue;
            }

            $summary['ran']++;
            $result = $this->runOne($job);
            $summary[$result->ok ? 'succeeded' : 'failed']++;
        }

        return $summary;
    }

    /** @return array<int, array<string, mixed>> */
    private function dueJobs(int $limit, ?string $queue): array
    {
        $queueSql = $queue !== null && $queue !== '' ? 'AND queue = ?' : '';
        $stmt = $this->pdo->prepare(
            "SELECT * FROM cron_jobs
             WHERE enabled = 1
               {$queueSql}
               AND next_run_at <= UTC_TIMESTAMP()
               AND (allow_overlap = 1 OR locked_at IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL timeout_seconds SECOND))
             ORDER BY next_run_at ASC, id ASC
             LIMIT {$limit}"
        );
        $stmt->execute($queue !== null && $queue !== '' ? [$queue] : []);

        return $stmt->fetchAll();
    }

    private function claim(int $jobId, bool $allowOverlap): bool
    {
        $overlapSql = $allowOverlap
            ? ''
            : 'AND (locked_at IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL timeout_seconds SECOND))';

        $stmt = $this->pdo->prepare(
            "UPDATE cron_jobs
             SET locked_at = UTC_TIMESTAMP(), locked_by = ?
             WHERE id = ?
               AND enabled = 1
               AND next_run_at <= UTC_TIMESTAMP()
               {$overlapSql}"
        );
        $stmt->execute([$this->workerId, $jobId]);

        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed> $job */
    private function runOne(array $job): JobResult
    {
        $started = microtime(true);
        $runId = $this->createRun($job);

        try {
            $handler = $this->handlers[$job['type']] ?? null;
            $result = $handler
                ? $handler->handle($job)
                : JobResult::failure("Unsupported job type: {$job['type']}");
        } catch (Throwable $e) {
            $result = JobResult::failure($e->getMessage());
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->finishRun($runId, $result, $durationMs);
        $this->reschedule($job, $result);

        return $result;
    }

    /** @param array<string, mixed> $job */
    private function createRun(array $job): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_job_runs (cron_job_id, started_at, attempt, worker_id) VALUES (?, UTC_TIMESTAMP(), ?, ?)'
        );
        $stmt->execute([(int) $job['id'], ((int) $job['failure_count']) + 1, $this->workerId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function finishRun(int $runId, JobResult $result, int $durationMs): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cron_job_runs
             SET finished_at = UTC_TIMESTAMP(), status = ?, duration_ms = ?, output = ?, error = ?
             WHERE id = ?'
        );
        $stmt->execute([$result->ok ? 'success' : 'failed', $durationMs, $result->output, $result->error, $runId]);
    }

    /** @param array<string, mixed> $job */
    private function reschedule(array $job, JobResult $result): void
    {
        if ($result->ok) {
            $nextRunSql = $job['interval_seconds'] === null
                ? 'NULL'
                : 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . (int) $job['interval_seconds'] . ' SECOND)';
            $enabledSql = $job['interval_seconds'] === null ? '0' : '1';

            $this->pdo->prepare(
                "UPDATE cron_jobs
                 SET enabled = {$enabledSql},
                     next_run_at = COALESCE({$nextRunSql}, next_run_at),
                     last_run_at = UTC_TIMESTAMP(),
                     last_finished_at = UTC_TIMESTAMP(),
                     last_status = 'success',
                     last_error = NULL,
                     failure_count = 0,
                     locked_at = NULL,
                     locked_by = NULL
                 WHERE id = ?"
            )->execute([(int) $job['id']]);

            return;
        }

        $failureCount = ((int) $job['failure_count']) + 1;
        $maxRetries = $job['max_retries'] === null ? null : (int) $job['max_retries'];
        $shouldRetry = $maxRetries === null || $maxRetries < 0 || $failureCount <= $maxRetries;
        $retryDelay = max(1, (int) $job['retry_delay_seconds']);
        if (($job['retry_backoff'] ?? 'exponential') === 'exponential') {
            $retryDelay *= 2 ** max(0, $failureCount - 1);
        }
        $retryDelay = min($retryDelay, max(1, (int) ($job['max_retry_delay_seconds'] ?? 86400)));

        $this->pdo->prepare(
            'UPDATE cron_jobs
             SET enabled = ?,
                 next_run_at = IF(? = 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), next_run_at),
                 last_run_at = UTC_TIMESTAMP(),
                 last_finished_at = UTC_TIMESTAMP(),
                 last_status = \'failed\',
                 last_error = ?,
                 failure_count = ?,
                 locked_at = NULL,
                 locked_by = NULL
             WHERE id = ?'
        )->execute([$shouldRetry ? 1 : 0, $shouldRetry ? 1 : 0, $retryDelay, $result->error, $failureCount, (int) $job['id']]);
    }
}
