<?php

namespace App\Cron;

use App\Net\SafeHttpClient;
use RuntimeException;

class HttpJobHandler implements JobHandler
{
    /** @param array<string, mixed> $job */
    public function handle(array $job): JobResult
    {
        $method = strtoupper((string) ($job['method'] ?: 'GET'));
        $headers = json_decode((string) ($job['headers'] ?? '[]'), true);
        $headers = is_array($headers) ? $headers : [];
        $payload = $job['payload'] ?? null;

        try {
            $response = SafeHttpClient::request(
                $method,
                (string) $job['target'],
                $payload === null ? null : (string) $payload,
                $headers,
                (int) ($job['timeout_seconds'] ?? 30),
            );
        } catch (RuntimeException $e) {
            return JobResult::failure('HTTP request blocked: ' . $e->getMessage());
        }

        if ($response['error'] !== null && $response['status'] === 0) {
            return JobResult::failure('HTTP request failed before a response was received.');
        }

        if (!$response['ok']) {
            return JobResult::failure("HTTP request returned status {$response['status']}.", $this->truncate($response['body']));
        }

        return JobResult::success($this->truncate($response['body']));
    }

    private function truncate(string $value): string
    {
        return mb_substr($value, 0, 65000);
    }
}
