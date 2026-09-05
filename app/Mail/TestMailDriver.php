<?php

namespace App\Mail;

/**
 * Used only when ENV=testing. Appends each "sent" message as a JSON line to
 * storage/mail.log instead of making a real network call, so feature tests
 * can pull the magic link / reset link / verification link back out without
 * a real inbox. See tests/Support/helpers.php's lastSentMail().
 */
class TestMailDriver implements MailerInterface
{
    public function __construct(private string $logPath)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $line = json_encode([
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'sent_at' => date('c'),
        ]) . "\n";

        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);

        return true;
    }
}
