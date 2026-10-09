<?php

namespace App\Edge;

use RuntimeException;

/**
 * Database access for user-provided edge function code, available as
 * `$request->db`. The sandboxed process has no PDO, no database
 * credentials, and no knowledge of other projects' tables — every call is
 * sent over the sandbox Bridge to the trusted parent process
 * (App\Edge\EdgeFunctionRunner), which resolves the logical table against
 * this project only, applies RLS, and runs the query. This is the same
 * isolation REST passthrough relies on (see Rest::scopedApi()), just
 * reached through a pipe instead of an HTTP request.
 */
class Db
{
    public function __construct()
    {
        if (!Bridge::open()) {
            throw new RuntimeException('Database bridge is not available in this runtime.');
        }
    }

    public function table(string $name): DbQuery
    {
        return new DbQuery($this, $name);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public function send(string $method, string $path, array $query, array $body): DbResult
    {
        $decoded = Bridge::call(['kind' => 'db', 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body]);
        $status = (int) ($decoded['status'] ?? 500);

        return new DbResult(
            $status >= 200 && $status < 300,
            $status,
            $decoded['body'] ?? null,
            $decoded['error'] ?? null,
        );
    }
}
