<?php

namespace App\Edge;

use RuntimeException;

/**
 * Database access for user-provided edge function code, available as
 * `$request->db`. The sandboxed process has no PDO, no database
 * credentials, and no knowledge of other projects' tables — every call is
 * sent as a line of JSON over an extra pipe to the trusted parent process
 * (App\Edge\EdgeFunctionRunner), which resolves the logical table against
 * this project only, applies RLS, and runs the query. This is the same
 * isolation REST passthrough relies on (see Rest::scopedApi()), just
 * reached through a pipe instead of an HTTP request.
 */
class Db
{
    /** @var resource */
    private $requestPipe;

    /** @var resource */
    private $responsePipe;

    public function __construct()
    {
        $requestPipe = @fopen('php://fd/3', 'wb');
        $responsePipe = @fopen('php://fd/4', 'rb');

        if ($requestPipe === false || $responsePipe === false) {
            throw new RuntimeException('Database bridge is not available in this runtime.');
        }

        $this->requestPipe = $requestPipe;
        $this->responsePipe = $responsePipe;
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
        $line = json_encode(
            ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($line === false || fwrite($this->requestPipe, $line . "\n") === false) {
            throw new RuntimeException('Failed to reach the database bridge.');
        }
        fflush($this->requestPipe);

        $responseLine = fgets($this->responsePipe);
        if ($responseLine === false) {
            throw new RuntimeException('Database bridge closed the connection.');
        }

        $decoded = json_decode($responseLine, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Database bridge returned an invalid response.');
        }

        $status = (int) ($decoded['status'] ?? 500);

        return new DbResult(
            $status >= 200 && $status < 300,
            $status,
            $decoded['body'] ?? null,
            $decoded['error'] ?? null,
        );
    }
}
