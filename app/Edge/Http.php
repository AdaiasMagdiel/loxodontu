<?php

namespace App\Edge;

use RuntimeException;

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
     */
    public static function request(
        string $method,
        string $url,
        array|string|null $body = null,
        array $headers = [],
        int $timeoutSeconds = 5,
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available in this edge runtime.');
        }

        self::assertAllowedUrl($url);

        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize HTTP request.');
        }

        $payload = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        if (is_array($body) && !array_key_exists('Content-Type', $headers) && !array_key_exists('content-type', $headers)) {
            $headerLines[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => min(max(1, $timeoutSeconds), 10),
            CURLOPT_TIMEOUT => min(max(1, $timeoutSeconds), 10),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $responseBody = curl_exec($ch);
        $error = curl_error($ch) ?: null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'ok' => $error === null && $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }

    private static function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Only http and https URLs are allowed.');
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            throw new RuntimeException('Localhost requests are not allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Private and reserved network targets are not allowed.');
            }
        }
    }
}
