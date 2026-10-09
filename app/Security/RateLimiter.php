<?php

namespace App\Security;

use PDO;

/**
 * Fixed-window rate limiter backed by the `rate_limits` table, so it works
 * the same on shared hosting (no Redis/APCu) and across php-fpm workers.
 *
 * Usage pattern for login: check tooManyAttempts() first, record a hit()
 * only on failure, and clear() the per-account bucket on success — so
 * legitimate users are never locked out by their own successful logins.
 */
class RateLimiter
{
    public function __construct(private readonly PDO $pdo) {}

    /** Seconds until the bucket resets, or null when $key is still under $maxHits. */
    public function tooManyAttempts(string $key, int $maxHits): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT hits, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), reset_at) AS retry_after
             FROM rate_limits WHERE bucket = ? AND reset_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $stmt->execute([self::bucket($key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int) $row['hits'] < $maxHits) {
            return null;
        }

        return max(1, (int) $row['retry_after']);
    }

    public function hit(string $key, int $windowSeconds): void
    {
        // Atomic: an expired window restarts at 1, a live one increments.
        $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, hits, reset_at)
             VALUES (?, 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE
                hits     = IF(reset_at <= UTC_TIMESTAMP(), 1, hits + 1),
                reset_at = IF(reset_at <= UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), reset_at)'
        )->execute([self::bucket($key), $windowSeconds, $windowSeconds]);
    }

    public function clear(string $key): void
    {
        $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([self::bucket($key)]);
    }

    private static function bucket(string $key): string
    {
        return hash('sha256', $key);
    }
}
