<?php

namespace App\Edge;

class FunctionResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly mixed $body = null,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {}

    /** @param array<string, string> $headers */
    public static function json(mixed $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + ['Content-Type' => 'application/json']);
    }
}
