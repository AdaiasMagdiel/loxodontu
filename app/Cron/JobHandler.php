<?php

namespace App\Cron;

interface JobHandler
{
    /** @param array<string, mixed> $job */
    public function handle(array $job): JobResult;
}
