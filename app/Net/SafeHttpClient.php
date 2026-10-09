<?php

namespace App\Net;

use RuntimeException;

/**
 * Outbound HTTP for tenant-controlled URLs (cron HTTP jobs, edge functions'
 * `Http` helper). Every request goes through UrlGuard, the connection is
 * pinned to the IPs UrlGuard approved, redirects are never followed, only
 * http/https are spoken and the response body is capped.
 */
class SafeHttpClient
{
    public const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;
    public const MAX_TIMEOUT_SECONDS = 30;

    public const MAX_REDIRECTS = 5;

    /**
     * Redirects are never followed by curl itself. With $maxRedirects > 0
     * each hop is re-issued through request(), so the target is validated
     * and pinned again — a redirect to a private address is refused exactly
     * like a direct request to one.
     *
     * @param array<string, scalar> $headers
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string, error: string|null}
     * @throws RuntimeException When the URL is not allowed or cURL is unavailable.
     */
    public static function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        int $timeoutSeconds = 10,
        int $maxRedirects = 0,
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available.');
        }

        $target = UrlGuard::assertAllowed($url);
        $timeoutSeconds = min(max(1, $timeoutSeconds), self::MAX_TIMEOUT_SECONDS);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize HTTP request.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_scalar($value) && !preg_match('/[\r\n]/', $name . $value)) {
                $headerLines[] = "{$name}: {$value}";
            }
        }

        $responseHeaders = [];
        $responseBody = '';
        $truncated = false;

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => array_map(
                fn(string $ip) => "{$target['host']}:{$target['port']}:" . (str_contains($ip, ':') ? "[{$ip}]" : $ip),
                $target['ips'],
            ),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 10),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$truncated): int {
                $remaining = self::MAX_RESPONSE_BYTES - strlen($responseBody);
                if ($remaining <= 0) {
                    $truncated = true;
                    return 0; // aborts the transfer
                }
                $responseBody .= substr($chunk, 0, $remaining);

                return strlen($chunk);
            },
        ]);

        if ($body !== null && !in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_exec($ch);
        $error = $truncated ? null : (curl_error($ch) ?: null);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($maxRedirects > 0 && in_array($status, [301, 302, 303, 307, 308], true) && isset($responseHeaders['location'])) {
            $nextUrl = self::resolveLocation($url, $responseHeaders['location']);

            // Credentials are for the original host only.
            if (strtolower((string) parse_url($nextUrl, PHP_URL_HOST)) !== strtolower((string) parse_url($url, PHP_URL_HOST))) {
                $headers = array_filter($headers, fn($name) => !in_array(strtolower((string) $name), ['authorization', 'cookie'], true), ARRAY_FILTER_USE_KEY);
            }

            // 303 always becomes GET; 301/302 turn a non-GET/HEAD into GET
            // (what browsers and curl do); 307/308 keep method and body.
            $method = strtoupper($method);
            $toGet = $status === 303 || (in_array($status, [301, 302], true) && !in_array($method, ['GET', 'HEAD'], true));

            return self::request($toGet ? 'GET' : $method, $nextUrl, $toGet ? null : $body, $headers, $timeoutSeconds, $maxRedirects - 1);
        }

        return [
            'ok' => $error === null && $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'error' => $error,
        ];
    }

    private static function resolveLocation(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'http';

        if (str_starts_with($location, '//')) {
            return "{$scheme}:{$location}";
        }

        $origin = $scheme . '://' . ($base['host'] ?? '') . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $dir = rtrim(dirname($base['path'] ?? '/') === '.' ? '/' : str_replace('\\', '/', dirname($base['path'] ?? '/')), '/');

        return $origin . $dir . '/' . $location;
    }
}
