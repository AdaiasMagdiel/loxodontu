<?php

namespace App\Cron;

class CallbackJobHandler implements JobHandler
{
    /** @param array<string, mixed> $job */
    public function handle(array $job): JobResult
    {
        $target = trim((string) $job['target']);
        if (!str_contains($target, '::')) {
            return JobResult::failure('Callback target must use ClassName::method syntax.');
        }

        [$class, $method] = explode('::', $target, 2);
        if (!is_callable([$class, $method])) {
            return JobResult::failure("Callback {$target} is not callable.");
        }

        $payload = json_decode((string) ($job['payload'] ?? 'null'), true);
        $result = [$class, $method]($payload, $job);

        if ($result instanceof JobResult) {
            return $result;
        }

        return JobResult::success(is_scalar($result) ? (string) $result : json_encode($result));
    }
}
