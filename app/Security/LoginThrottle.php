<?php

namespace App\Security;

use PDO;

/**
 * Brute-force protection shared by platform login and project end-user
 * login. Two buckets per attempt, both counting failures only:
 *
 *  - per account (scope + email + IP): stops guessing one user's password;
 *  - per IP (scope + IP): stops spraying many accounts from one address.
 */
class LoginThrottle
{
    public const MAX_FAILURES_PER_ACCOUNT = 5;
    public const MAX_FAILURES_PER_IP = 50;
    public const WINDOW_SECONDS = 900;

    private readonly RateLimiter $limiter;

    public function __construct(PDO $pdo, private readonly string $scope)
    {
        $this->limiter = new RateLimiter($pdo);
    }

    /** Seconds the caller must wait, or null if the attempt may proceed. */
    public function retryAfter(string $email, ?string $ip): ?int
    {
        return $this->limiter->tooManyAttempts($this->accountKey($email, $ip), self::MAX_FAILURES_PER_ACCOUNT)
            ?? $this->limiter->tooManyAttempts($this->ipKey($ip), self::MAX_FAILURES_PER_IP);
    }

    public function failed(string $email, ?string $ip): void
    {
        $this->limiter->hit($this->accountKey($email, $ip), self::WINDOW_SECONDS);
        $this->limiter->hit($this->ipKey($ip), self::WINDOW_SECONDS);
    }

    public function succeeded(string $email, ?string $ip): void
    {
        $this->limiter->clear($this->accountKey($email, $ip));
    }

    private function accountKey(string $email, ?string $ip): string
    {
        return "login:{$this->scope}:account:" . mb_strtolower(trim($email)) . ':' . ($ip ?? 'unknown');
    }

    private function ipKey(?string $ip): string
    {
        return "login:{$this->scope}:ip:" . ($ip ?? 'unknown');
    }
}
