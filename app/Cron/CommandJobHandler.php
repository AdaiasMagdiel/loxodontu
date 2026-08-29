<?php

namespace App\Cron;

class CommandJobHandler implements JobHandler
{
    /** @param array<string, mixed> $job */
    public function handle(array $job): JobResult
    {
        if (env('CRON_ALLOW_COMMANDS') !== 'true') {
            return JobResult::failure('Command jobs are disabled. Set CRON_ALLOW_COMMANDS=true to enable them.');
        }

        $command = trim((string) $job['target']);
        if ($command === '') {
            return JobResult::failure('Command target is empty.');
        }

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, ROOT_DIR);

        if (!is_resource($process)) {
            return JobResult::failure('Unable to start command process.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $timeoutAt = microtime(true) + max(1, (int) ($job['timeout_seconds'] ?? 30));
        $output = '';
        $exitCode = null;

        while (true) {
            $output .= stream_get_contents($pipes[1]) ?: '';
            $output .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (microtime(true) >= $timeoutAt) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);

                return JobResult::failure('Command timed out.', mb_substr($output, 0, 65000));
            }

            usleep(100000);
        }

        foreach ($pipes as $pipe) {
            $output .= stream_get_contents($pipe) ?: '';
            fclose($pipe);
        }

        proc_close($process);

        $text = mb_substr($output, 0, 65000);

        if ($exitCode !== 0) {
            return JobResult::failure("Command exited with code {$exitCode}.", $text);
        }

        return JobResult::success($text);
    }
}
