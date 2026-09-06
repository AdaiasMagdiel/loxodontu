<?php

namespace App\Edge;

use RuntimeException;

class Http
{
    /** Bounded so a malicious or misconfigured server can't redirect forever. */
    private const MAX_REDIRECTS = 5;

    /**
     * Response bodies larger than this are aborted mid-transfer rather than
     * buffered into memory. Enforced from the declared Content-Length
     * header, so a server that omits it (or lies about it) with a chunked
     * body isn't caught — this stops the common case (a huge response
     * blowing the sandbox's memory_limit), not every case.
     */
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

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
        return self::doRequest($method, $url, $body, $headers, $timeoutSeconds, self::MAX_REDIRECTS);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string, error: string|null}
     */
    private static function doRequest(
        string $method,
        string $url,
        array|string|null $body,
        array $headers,
        int $timeoutSeconds,
        int $redirectsLeft,
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available in this edge runtime.');
        }

        // Resolved and validated once, here, then pinned onto the curl handle
        // below via CURLOPT_RESOLVE. Validating the host without pinning
        // would leave a DNS-rebinding gap: curl re-resolves the host itself
        // at connect time, so a host that resolves to a public IP during our
        // check and a private one moments later would sail through.
        $pinnedIp = self::assertAllowedUrl($url);

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = self::normalizeHost((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $responseHeaders = [];
        $aborted = false;

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
            // A host that's already a literal IP has nothing to pin against.
            CURLOPT_RESOLVE => filter_var($host, FILTER_VALIDATE_IP) ? [] : ["{$host}:{$port}:{$pinnedIp}"],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$aborted): int {
                $length = strlen($line);

                if (preg_match('/^content-length:\s*(\d+)/i', $line, $matches) && (int) $matches[1] > self::MAX_RESPONSE_BYTES) {
                    $aborted = true;

                    // Returning a length other than what we were given tells
                    // curl to abort the transfer with a write error.
                    return 0;
                }

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

        if ($aborted) {
            return [
                'ok' => false,
                'status' => $status,
                'headers' => $responseHeaders,
                'body' => '',
                'error' => 'Response exceeded the maximum allowed size of ' . self::MAX_RESPONSE_BYTES . ' bytes.',
            ];
        }

        if ($redirectsLeft > 0 && in_array($status, [301, 302, 303, 307, 308], true) && isset($responseHeaders['location'])) {
            return self::followRedirect($method, $url, $body, $headers, $timeoutSeconds, $redirectsLeft, $status, $responseHeaders['location']);
        }

        return [
            'ok' => $error === null && $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }

    /**
     * Re-runs the request against the redirect target through the same
     * validation and IP-pinning path — a redirect to a private address is
     * rejected exactly like a direct request to one would be, closing the
     * classic "SSRF via redirect" bypass that letting curl follow
     * redirects on its own would reopen.
     *
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string, error: string|null}
     */
    private static function followRedirect(
        string $method,
        string $currentUrl,
        array|string|null $body,
        array $headers,
        int $timeoutSeconds,
        int $redirectsLeft,
        int $status,
        string $location,
    ): array {
        $nextUrl = self::resolveRedirectUrl($currentUrl, $location);

        $currentHost = strtolower((string) (parse_url($currentUrl)['host'] ?? ''));
        $nextHost = strtolower((string) (parse_url($nextUrl)['host'] ?? ''));
        if ($nextHost !== $currentHost) {
            unset($headers['Authorization'], $headers['authorization']);
        }

        // 303 always downgrades to GET; 301/302 downgrade a non-GET/HEAD
        // method to GET (matching what browsers and curl's own
        // CURLOPT_FOLLOWLOCATION do by default); 307/308 preserve the
        // method and body exactly.
        $downgradeToGet = $status === 303 || (in_array($status, [301, 302], true) && !in_array($method, ['GET', 'HEAD'], true));

        return self::doRequest(
            $downgradeToGet ? 'GET' : $method,
            $nextUrl,
            $downgradeToGet ? null : $body,
            $headers,
            $timeoutSeconds,
            $redirectsLeft - 1,
        );
    }

    /**
     * Resolves a Location header against the URL it came from. Handles
     * absolute URLs and root-relative paths; a path relative to the
     * current request's own path isn't resolved further than the origin,
     * since well-behaved APIs send absolute or root-relative Location
     * values.
     */
    private static function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . '/' . ltrim($location, '/');
    }

    /**
     * Validates the URL's scheme and host, then resolves the host to a
     * single public IP address (returned so the caller can pin the
     * connection to it). Rejects the request if the host — or any address
     * it resolves to — is private, reserved, or loopback.
     */
    private static function assertAllowedUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = self::normalizeHost((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Only http and https URLs are allowed.');
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            throw new RuntimeException('Localhost requests are not allowed.');
        }

        return self::resolvePublicIp($host);
    }

    /**
     * parse_url() keeps the brackets around an IPv6 host literal
     * (`[fc00::1]`); strip them so filter_var()/gethostbynamel() see a bare
     * address instead of silently failing to recognize it as one.
     */
    private static function normalizeHost(string $host): string
    {
        return strtolower(trim($host, '[]'));
    }

    /**
     * Resolves $host to a single public IP, checking both IPv4 (A) and
     * IPv6 (AAAA) records. The previous version of this check only asked
     * for A records via gethostbynamel(); a host that resolves solely via
     * AAAA made that call return an empty list, which skipped the private-
     * range check entirely instead of failing it — a host that only
     * answers with e.g. `::1` or an fc00::/7 address sailed straight
     * through. Every address the host resolves to is validated, even
     * though only the first is returned for pinning, so a host answering
     * with a mix of public and private addresses is still rejected.
     */
    private static function resolvePublicIp(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertPublicIp($host);

            return $host;
        }

        $ips = gethostbynamel($host) ?: [];
        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            throw new RuntimeException('Unable to resolve host.');
        }

        foreach ($ips as $ip) {
            self::assertPublicIp($ip);
        }

        return $ips[0];
    }

    private static function assertPublicIp(string $ip): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Private and reserved network targets are not allowed.');
        }

        // FILTER_FLAG_NO_RES_RANGE isn't consistently documented to exclude
        // IPv6 link-local addresses (fe80::/10) across PHP builds — reject
        // them explicitly rather than rely on it.
        if (preg_match('/^fe[89ab]/i', $ip) === 1) {
            throw new RuntimeException('Private and reserved network targets are not allowed.');
        }
    }
}
