<?php

namespace App\Cron;

class JobResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $output = '',
        public readonly ?string $error = null,
    ) {}

    public static function success(string $output = ''): self
    {
        return new self(true, $output);
    }

    public static function failure(string $error, string $output = ''): self
    {
        return new self(false, $output, $error);
    }
}
