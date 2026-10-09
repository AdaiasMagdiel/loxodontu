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

    /**
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

        return [
            'ok' => $error === null && $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'error' => $error,
        ];
    }
}
