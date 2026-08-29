<?php

namespace App\Cron;

use App\Edge\EdgeFunctionRunner;

class FunctionJobHandler implements JobHandler
{
    /** @param array<string, mixed> $job */
    public function handle(array $job): JobResult
    {
        $payload = json_decode((string) ($job['payload'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];

        $response = EdgeFunctionRunner::call(
            (int) $job['project_id'],
            (string) $job['target'],
            'POST',
            [],
            [],
            $payload,
        );

        $output = is_string($response->body) ? $response->body : json_encode($response->body);

        if ($response->status < 200 || $response->status >= 300) {
            return JobResult::failure("Function returned status {$response->status}.", (string) $output);
        }

        return JobResult::success((string) $output);
    }
}
