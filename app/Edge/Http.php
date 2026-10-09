<?php

namespace App\Edge;

use App\Net\SafeHttpClient;
use RuntimeException;

/**
 * Outbound HTTP for edge functions.
 *
 * Inside the sandbox the child has no network functions at all: requests
 * are handed to the parent process over the Bridge, which performs them
 * through App\Net\SafeHttpClient (http/https only, public addresses only,
 * pinned DNS, redirects re-validated hop by hop, capped body). Outside the sandbox (class-based
 * handlers run by platform admins) it calls SafeHttpClient directly.
 */
class Http
{
    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string, error: string|null}
     */
    public static function get(string $url, array $headers = [], int $timeoutSeconds = 5): array
    {
        return self::request('GET', $url, null, $headers, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string, error: string|null}
     */
    public static function post(string $url, array|string|null $body = null, array $headers = [], int $timeoutSeconds = 5): array
    {
        return self::request('POST', $url, $body, $headers, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string, error: string|null}
     * @throws RuntimeException When the URL is not allowed.
     */
    public static function request(
        string $method,
        string $url,
        array|string|null $body = null,
        array $headers = [],
        int $timeoutSeconds = 5,
    ): array {
        $payload = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;
        if (is_array($body) && !array_key_exists('Content-Type', $headers) && !array_key_exists('content-type', $headers)) {
            $headers['Content-Type'] = 'application/json';
        }
        $method = strtoupper($method);
        $timeoutSeconds = min(max(1, $timeoutSeconds), 10);

        if (!Bridge::isOpen()) {
            return SafeHttpClient::request($method, $url, $payload === false ? null : $payload, $headers, $timeoutSeconds, SafeHttpClient::MAX_REDIRECTS);
        }

        $response = Bridge::call([
            'kind' => 'http',
            'method' => $method,
            'url' => $url,
            'body' => $payload === false ? null : $payload,
            'headers' => $headers,
            'timeout' => $timeoutSeconds,
            'max_redirects' => 5,
        ]);

        if (isset($response['exception'])) {
            throw new RuntimeException((string) $response['exception']);
        }

        return [
            'ok' => (bool) ($response['ok'] ?? false),
            'status' => (int) ($response['status'] ?? 0),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'body' => (string) ($response['body'] ?? ''),
            'error' => isset($response['error']) ? (string) $response['error'] : null,
        ];
    }
}
