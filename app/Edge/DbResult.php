<?php

namespace App\Edge;

class DbResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly mixed $body,
        public readonly ?string $error = null,
    ) {}
}
