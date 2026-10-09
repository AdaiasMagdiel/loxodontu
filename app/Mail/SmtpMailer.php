<?php

namespace App\Mail;

use App\Net\UrlGuard;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

class SmtpMailer implements MailerInterface
{
    /** Ports a tenant may point SMTP at — anything else would turn this into a port scanner. */
    public const ALLOWED_PORTS = [25, 465, 587, 2525];

    public const ALLOWED_ENCRYPTION = ['none', 'ssl', 'tls'];

    private const TIMEOUT_SECONDS = 10;

    /** @param array{host: string, port: int, username: ?string, password: ?string, encryption: ?string, from_address: string, from_name: ?string} $config */
    public function __construct(private array $config)
    {
    }

    /**
     * @throws RuntimeException When the configured host or port is not allowed.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        $host = (string) $this->config['host'];
        $port = (int) $this->config['port'];

        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            throw new RuntimeException('SMTP port must be one of: ' . implode(', ', self::ALLOWED_PORTS) . '.');
        }

        // The host is tenant-controlled: validate every address it resolves
        // to and connect to the validated IP itself, so DNS can't be swapped
        // to an internal address between the check and the connection. TLS
        // still verifies the certificate against the original host name.
        $ip = UrlGuard::assertPublicHost($host)[0];

        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            $mailer->Port = $port;
            $mailer->Timeout = self::TIMEOUT_SECONDS;
            $mailer->SMTPOptions = ['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]];
            $mailer->SMTPAuth = ($this->config['username'] ?? '') !== '';

            if ($mailer->SMTPAuth) {
                $mailer->Username = $this->config['username'];
                $mailer->Password = $this->config['password'] ?? '';
            }

            $mailer->SMTPSecure = match ($this->config['encryption'] ?? 'none') {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };

            $mailer->setFrom($this->config['from_address'], $this->config['from_name'] ?? '');
            $mailer->addAddress($to);
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $body;

            return $mailer->send();
        } catch (PHPMailerException) {
            return false;
        }
    }
}
