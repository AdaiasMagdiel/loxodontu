<?php

namespace App\Net;

use RuntimeException;

/**
 * Validates that a URL is safe for the server to request on behalf of a
 * tenant: http/https only, and only to public addresses. Resolves the host
 * once and returns the IPs it checked, so callers can pin the connection to
 * them (CURLOPT_RESOLVE) — otherwise a DNS answer that changes between the
 * check and the request (DNS rebinding) would slip past it.
 *
 * Shared by edge functions' outbound HTTP and cron HTTP jobs.
 */
class UrlGuard
{
    /**
     * @return array{scheme: string, host: string, port: int, ips: string[]}
     * @throws RuntimeException When the URL is not allowed.
     */
    public static function assertAllowed(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Only http and https URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Credentials in URLs are not allowed.');
        }

        // parse_url() keeps the brackets of an IPv6 literal ("[::1]").
        $bareHost = trim($host, '[]');

        if ($bareHost === 'localhost' || str_ends_with($bareHost, '.localhost') || $bareHost === 'localhost.localdomain') {
            throw new RuntimeException('Localhost requests are not allowed.');
        }

        $ips = filter_var($bareHost, FILTER_VALIDATE_IP) ? [$bareHost] : self::resolve($bareHost);

        if ($ips === []) {
            throw new RuntimeException('Unable to resolve host.');
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new RuntimeException('Private and reserved network targets are not allowed.');
            }
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return ['scheme' => $scheme, 'host' => $bareHost, 'port' => $port, 'ips' => $ips];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:10.0.0.1) — check the embedded IPv4 too.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return self::isPublicIp($m[1]);
        }

        // Ranges PHP's flags don't cover: 100.64.0.0/10 (CGNAT, used by some
        // cloud metadata/internal networks) and IPv6 unique-local fc00::/7.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if (($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        } elseif (preg_match('/^f[cd]/i', $ip)) {
            return false;
        }

        return true;
    }

    /** @return string[] */
    private static function resolve(string $host): array
    {
        $ips = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
