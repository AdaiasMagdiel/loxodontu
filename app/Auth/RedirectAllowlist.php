<?php

namespace App\Auth;

/**
 * Decides where auth emails may send an end user. Links in magic-link,
 * password-reset, verification and email-change emails carry a live token,
 * so a client-supplied redirect_url is only honoured when it matches the
 * project's Site URL or one of its allowed redirect URLs — never an
 * arbitrary site that could collect the token.
 *
 * Matching is deliberately strict: same scheme, host and port, and the
 * requested path must start at a path segment of the allowed entry's path
 * ("https://app.com/auth" allows "https://app.com/auth/callback", not
 * "https://app.com/authx"). No wildcards.
 */
class RedirectAllowlist
{
    public const MAX_ENTRIES = 20;
    public const MAX_LENGTH = 2048;

    /**
     * Validates and normalizes one configured entry (Site URL or allowed
     * redirect URL). Returns null when the URL is not acceptable.
     */
    public static function normalizeEntry(string $url): ?string
    {
        $url = trim($url);
        // Backslashes, whitespace and control characters are parsed
        // differently by PHP and by browsers ("https://app.com\@evil.com"),
        // so they are rejected outright rather than interpreted.
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\s\\\\\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        return $url;
    }

    /**
     * @param string[] $allowed Normalized allowed redirect URLs.
     */
    public static function isAllowed(string $requested, ?string $siteUrl, array $allowed): bool
    {
        $target = self::components($requested);
        if ($target === null) {
            return false;
        }

        foreach (array_filter([$siteUrl, ...$allowed]) as $entry) {
            $base = self::components($entry);
            if ($base === null || $base['origin'] !== $target['origin']) {
                continue;
            }

            $basePath = rtrim($base['path'], '/');
            if ($basePath === '' || $target['path'] === $basePath || str_starts_with($target['path'], $basePath . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{origin: string, path: string}|null */
    private static function components(string $url): ?array
    {
        if (self::normalizeEntry($url) === null) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        // Resolve dot segments so "/auth/../evil" can't pass as "/auth/…".
        $segments = [];
        foreach (explode('/', rawurldecode($parts['path'] ?? '/')) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return [
            'origin' => $scheme . '://' . strtolower($parts['host']) . ':' . $port,
            'path' => '/' . implode('/', $segments),
        ];
    }
}
