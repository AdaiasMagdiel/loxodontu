<?php

namespace App\Edge;

use RuntimeException;

/**
 * The sandboxed child's only channel to the outside world. Each call is one
 * line of JSON written to fd 3 and answered with one line on fd 4 by the
 * trusted parent (App\Edge\EdgeFunctionRunner), which decides what the
 * request may do: database calls are scoped to the project and its RLS,
 * HTTP calls go through App\Net\SafeHttpClient. The child itself has no
 * network functions, no PDO and no credentials.
 *
 * Only ever loaded inside the sandbox (see SandboxRunner.php).
 */
class Bridge
{
    /** @var resource|null */
    private static $requestPipe = null;

    /** @var resource|null */
    private static $responsePipe = null;

    public static function open(): bool
    {
        if (self::$requestPipe !== null) {
            return true;
        }

        $request = @fopen('php://fd/3', 'wb');
        $response = @fopen('php://fd/4', 'rb');

        if ($request === false || $response === false) {
            return false;
        }

        self::$requestPipe = $request;
        self::$responsePipe = $response;

        return true;
    }

    public static function isOpen(): bool
    {
        return self::$requestPipe !== null;
    }

    /**
     * @param array<string, mixed> $payload Must include a `kind` ("db" or "http").
     * @return array<string, mixed>
     */
    public static function call(array $payload): array
    {
        if (!self::isOpen()) {
            throw new RuntimeException('The sandbox bridge is not available in this runtime.');
        }

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false || fwrite(self::$requestPipe, $line . "\n") === false) {
            throw new RuntimeException('Failed to reach the sandbox bridge.');
        }
        fflush(self::$requestPipe);

        $responseLine = fgets(self::$responsePipe);
        if ($responseLine === false) {
            throw new RuntimeException('The sandbox bridge closed the connection.');
        }

        $decoded = json_decode($responseLine, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The sandbox bridge returned an invalid response.');
        }

        return $decoded;
    }
}
