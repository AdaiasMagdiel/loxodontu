<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use PDO;
use stdClass;

class CronJobs
{
    private const TYPES = ['http', 'command', 'callback', 'function'];
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
    private const RETRY_BACKOFFS = ['fixed', 'exponential'];

    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $count = $pdo->prepare('SELECT COUNT(*) FROM cron_jobs WHERE project_id = ?');
        $count->execute([$project['id']]);

        $stmt = $pdo->prepare(
            "SELECT id, project_id, queue, name, type, target, method, headers, payload, interval_seconds, run_at, next_run_at,
                    last_run_at, last_finished_at, last_status, last_error, failure_count, max_retries,
                    retry_backoff, retry_delay_seconds, max_retry_delay_seconds, timeout_seconds,
                    allow_overlap, enabled, created_at, updated_at
             FROM cron_jobs WHERE project_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$project['id']]);

        return $res
            ->setHeader('X-Total-Count', (string) (int) $count->fetchColumn())
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson(array_map([self::class, 'normalize'], $stmt->fetchAll()));
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $body = $req->getJson(ignoreContentType: true) ?? [];
        $error = self::validate($body);
        if ($error) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        $name = trim($body['name']);
        $queue = self::queue($body['queue'] ?? 'default');
        $type = $body['type'];
        $target = trim($body['target']);
        $method = $type === 'http' ? strtoupper($body['method'] ?? 'GET') : null;
        $headers = array_key_exists('headers', $body) ? json_encode($body['headers']) : null;
        $payload = array_key_exists('payload', $body)
            ? (is_string($body['payload']) ? $body['payload'] : json_encode($body['payload']))
            : null;
        $intervalSeconds = isset($body['interval_seconds']) ? (int) $body['interval_seconds'] : null;
        $runAt = $body['run_at'] ?? null;
        $nextRunAt = $runAt ?: gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO cron_jobs
             (project_id, queue, name, type, target, method, headers, payload, interval_seconds, run_at, next_run_at,
              max_retries, retry_backoff, retry_delay_seconds, max_retry_delay_seconds, timeout_seconds, allow_overlap, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $project['id'],
            $queue,
            $name,
            $type,
            $target,
            $method,
            $headers,
            $payload,
            $intervalSeconds,
            $runAt,
            $nextRunAt,
            array_key_exists('max_retries', $body) ? $body['max_retries'] : 3,
            $body['retry_backoff'] ?? 'exponential',
            isset($body['retry_delay_seconds']) ? (int) $body['retry_delay_seconds'] : 300,
            isset($body['max_retry_delay_seconds']) ? (int) $body['max_retry_delay_seconds'] : 86400,
            isset($body['timeout_seconds']) ? (int) $body['timeout_seconds'] : 30,
            isset($body['allow_overlap']) ? (int) (bool) $body['allow_overlap'] : 0,
            isset($body['enabled']) ? (int) (bool) $body['enabled'] : 1,
        ]);

        return $res->setStatusCode(201)->withJson(self::findJob($pdo, (int) $pdo->lastInsertId()));
    }

    public static function show(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $job = self::findOwnedJob($pdo, $params->job_id, (int) $project['id']);
        if (!$job) {
            return $res->setStatusCode(404)->withJson(['error' => 'Cron job not found']);
        }

        return $res->withJson(self::normalize($job));
    }

    public static function update(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $job = self::findOwnedJob($pdo, $params->job_id, (int) $project['id']);
        if (!$job) {
            return $res->setStatusCode(404)->withJson(['error' => 'Cron job not found']);
        }

        $body = $req->getJson(ignoreContentType: true) ?? [];
        if ($body === []) {
            return $res->setStatusCode(422)->withJson(['error' => 'Nothing to update']);
        }

        $candidate = array_merge($job, $body);
        $error = self::validate($candidate);
        if ($error) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        $fields = [];
        $values = [];
        foreach ([
            'name',
            'queue',
            'type',
            'target',
            'interval_seconds',
            'run_at',
            'max_retries',
            'retry_backoff',
            'retry_delay_seconds',
            'max_retry_delay_seconds',
            'timeout_seconds',
            'allow_overlap',
            'enabled',
        ] as $field) {
            if (array_key_exists($field, $body)) {
                $fields[] = "{$field} = ?";
                $values[] = match ($field) {
                    'allow_overlap', 'enabled' => (int) (bool) $body[$field],
                    'queue' => self::queue($body[$field]),
                    default => $body[$field],
                };
            }
        }

        if (array_key_exists('method', $body) || array_key_exists('type', $body)) {
            $fields[] = 'method = ?';
            $values[] = $candidate['type'] === 'http' ? strtoupper($candidate['method'] ?? 'GET') : null;
        }

        if (array_key_exists('headers', $body)) {
            $fields[] = 'headers = ?';
            $values[] = $body['headers'] !== null ? json_encode($body['headers']) : null;
        }

        if (array_key_exists('payload', $body)) {
            $fields[] = 'payload = ?';
            $values[] = is_string($body['payload']) ? $body['payload'] : json_encode($body['payload']);
        }

        if (array_key_exists('run_at', $body) || array_key_exists('interval_seconds', $body)) {
            $fields[] = 'next_run_at = ?';
            $values[] = $candidate['run_at'] ?: gmdate('Y-m-d H:i:s');
        }

        if ($fields === []) {
            return $res->setStatusCode(422)->withJson(['error' => 'Nothing to update']);
        }

        $values[] = $job['id'];
        $pdo->prepare('UPDATE cron_jobs SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

        return $res->withJson(self::findJob($pdo, (int) $job['id']));
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('DELETE FROM cron_jobs WHERE id = ? AND project_id = ?');
        $stmt->execute([$params->job_id, $project['id']]);

        return $stmt->rowCount() === 1
            ? $res->setStatusCode(204)
            : $res->setStatusCode(404)->withJson(['error' => 'Cron job not found']);
    }

    public static function runs(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $job = self::findOwnedJob($pdo, $params->job_id, (int) $project['id']);
        if (!$job) {
            return $res->setStatusCode(404)->withJson(['error' => 'Cron job not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $count = $pdo->prepare('SELECT COUNT(*) FROM cron_job_runs WHERE cron_job_id = ?');
        $count->execute([$job['id']]);

        $stmt = $pdo->prepare(
            "SELECT id, cron_job_id, started_at, finished_at, status, attempt, duration_ms, output, error, worker_id
             FROM cron_job_runs WHERE cron_job_id = ? ORDER BY started_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$job['id']]);

        return $res
            ->setHeader('X-Total-Count', (string) (int) $count->fetchColumn())
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson(array_map(function (array $run): array {
                $run['id'] = (int) $run['id'];
                $run['cron_job_id'] = (int) $run['cron_job_id'];
                $run['attempt'] = (int) $run['attempt'];
                $run['duration_ms'] = $run['duration_ms'] !== null ? (int) $run['duration_ms'] : null;

                return $run;
            }, $stmt->fetchAll()));
    }

    /** @param array<string, mixed> $body */
    private static function validate(array $body): ?string
    {
        if (trim($body['name'] ?? '') === '') {
            return 'name is required';
        }

        if (!in_array($body['type'] ?? '', self::TYPES, true)) {
            return 'type must be one of: ' . implode(', ', self::TYPES);
        }

        if (trim($body['target'] ?? '') === '') {
            return 'target is required';
        }

        if (self::queue($body['queue'] ?? 'default') === '') {
            return 'queue is required';
        }

        if (($body['type'] ?? '') === 'http') {
            $method = strtoupper($body['method'] ?? 'GET');
            if (!in_array($method, self::METHODS, true)) {
                return 'method must be one of: ' . implode(', ', self::METHODS);
            }
            if (!filter_var($body['target'], FILTER_VALIDATE_URL)) {
                return 'target must be a valid URL for http jobs';
            }
        }

        if (($body['type'] ?? '') === 'function' && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) $body['target'])) {
            return 'target must be a function slug for function jobs';
        }

        if (array_key_exists('headers', $body) && $body['headers'] !== null && !is_array($body['headers']) && !is_string($body['headers'])) {
            return 'headers must be an object';
        }

        $interval = isset($body['interval_seconds']) ? (int) $body['interval_seconds'] : null;
        if ($interval !== null && $interval < 60) {
            return 'interval_seconds must be at least 60';
        }

        if (empty($body['run_at']) && $interval === null) {
            return 'run_at or interval_seconds is required';
        }

        if (!empty($body['run_at']) && strtotime($body['run_at']) === false) {
            return 'run_at must be a valid date time';
        }

        if (isset($body['retry_delay_seconds']) && (int) $body['retry_delay_seconds'] < 1) {
            return 'retry_delay_seconds must be at least 1';
        }

        if (isset($body['retry_backoff']) && !in_array($body['retry_backoff'], self::RETRY_BACKOFFS, true)) {
            return 'retry_backoff must be one of: ' . implode(', ', self::RETRY_BACKOFFS);
        }

        if (isset($body['max_retry_delay_seconds']) && (int) $body['max_retry_delay_seconds'] < 1) {
            return 'max_retry_delay_seconds must be at least 1';
        }

        if (isset($body['timeout_seconds']) && (int) $body['timeout_seconds'] < 1) {
            return 'timeout_seconds must be at least 1';
        }

        return null;
    }

    private static function findOwnedProject(PDO $pdo, mixed $id, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);

        return $stmt->fetch();
    }

    private static function findJob(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM cron_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return self::normalize($stmt->fetch());
    }

    private static function findOwnedJob(PDO $pdo, mixed $id, int $projectId): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM cron_jobs WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$id, $projectId]);

        return $stmt->fetch();
    }

    /** @param array<string, mixed> $job */
    private static function normalize(array $job): array
    {
        $job['id'] = (int) $job['id'];
        $job['project_id'] = (int) $job['project_id'];
        $job['headers'] = $job['headers'] !== null ? json_decode($job['headers'], true) : null;
        $job['interval_seconds'] = $job['interval_seconds'] !== null ? (int) $job['interval_seconds'] : null;
        $job['failure_count'] = (int) $job['failure_count'];
        $job['max_retries'] = $job['max_retries'] !== null ? (int) $job['max_retries'] : null;
        $job['retry_delay_seconds'] = (int) $job['retry_delay_seconds'];
        $job['max_retry_delay_seconds'] = (int) $job['max_retry_delay_seconds'];
        $job['timeout_seconds'] = (int) $job['timeout_seconds'];
        $job['allow_overlap'] = (bool) $job['allow_overlap'];
        $job['enabled'] = (bool) $job['enabled'];

        unset($job['locked_at'], $job['locked_by']);

        return $job;
    }

    private static function queue(mixed $value): string
    {
        $queue = trim((string) $value);

        return preg_match('/^[a-zA-Z0-9_.:-]{1,50}$/', $queue) ? $queue : '';
    }
}
