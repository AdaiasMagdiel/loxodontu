<?php

namespace App\Security;

use PDO;

/**
 * Limits how many auth emails (magic link, password reset, verification,
 * email change) a project will send. These endpoints are public and send
 * from the project's own provider, so without a limit anyone could use them
 * to flood a mailbox or burn the project's sending reputation and quota.
 *
 * Every request counts — not just ones for registered addresses — so the
 * limit applies identically whether or not the email exists and the
 * responses stay enumeration-safe.
 */
class EmailSendThrottle
{
    public const MAX_PER_RECIPIENT = 5;
    public const MAX_PER_IP = 20;
    public const WINDOW_SECONDS = 3600;

    private readonly RateLimiter $limiter;

    public function __construct(PDO $pdo, private readonly int $projectId)
    {
        $this->limiter = new RateLimiter($pdo);
    }

    /**
     * Records the attempt and returns the seconds to wait when over the
     * limit, or null when the email may be sent.
     */
    public function attempt(string $recipient, ?string $ip): ?int
    {
        $recipientKey = "auth-email:{$this->projectId}:to:" . mb_strtolower(trim($recipient));
        $ipKey = "auth-email:{$this->projectId}:ip:" . ($ip ?? 'unknown');

        $retryAfter = $this->limiter->tooManyAttempts($recipientKey, self::MAX_PER_RECIPIENT)
            ?? $this->limiter->tooManyAttempts($ipKey, self::MAX_PER_IP);

        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $this->limiter->hit($recipientKey, self::WINDOW_SECONDS);
        $this->limiter->hit($ipKey, self::WINDOW_SECONDS);

        return null;
    }
}
