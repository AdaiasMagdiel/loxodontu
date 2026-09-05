<?php

namespace App\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class SmtpMailer implements MailerInterface
{
    /** @param array{host: string, port: int, username: ?string, password: ?string, encryption: ?string, from_address: string, from_name: ?string} $config */
    public function __construct(private array $config)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $this->config['host'];
            $mailer->Port = $this->config['port'];
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
