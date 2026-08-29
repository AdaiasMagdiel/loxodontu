<?php

namespace App\Edge;

class FunctionRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $function
     * @param array{id: int, email: string, role: ?string}|null $auth
     */
    public function __construct(
        public readonly int $projectId,
        public readonly string $method,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $body,
        public readonly array $function,
        public readonly ?array $auth = null,
    ) {}
}
