<?php

namespace App\Cron;

class HttpJobHandler implements JobHandler
{
    /** @param array<string, mixed> $job */
    public function handle(array $job): JobResult
    {
        $method = strtoupper((string) ($job['method'] ?: 'GET'));
        $headers = json_decode((string) ($job['headers'] ?? '[]'), true);
        $headers = is_array($headers) ? $headers : [];
        $payload = $job['payload'] ?? null;

        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headerLines[] = "{$name}: {$value}";
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => in_array($method, ['GET', 'HEAD'], true) ? null : (string) $payload,
                'timeout' => max(1, (int) ($job['timeout_seconds'] ?? 30)),
                'ignore_errors' => true,
            ],
        ]);

        $output = @file_get_contents((string) $job['target'], false, $context);
        $statusCode = $this->statusCode($http_response_header ?? []);

        if ($output === false) {
            return JobResult::failure('HTTP request failed before a response was received.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return JobResult::failure("HTTP request returned status {$statusCode}.", $this->truncate($output));
        }

        return JobResult::success($this->truncate($output));
    }

    /** @param string[] $headers */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function truncate(string $value): string
    {
        return mb_substr($value, 0, 65000);
    }
}
