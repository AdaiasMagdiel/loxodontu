<?php

namespace App\Mail;

class ResendMailer implements MailerInterface
{
    /** @param array{api_key: string, from_address: string, from_name: ?string} $config */
    public function __construct(private array $config)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $from = $this->config['from_name']
            ? "{$this->config['from_name']} <{$this->config['from_address']}>"
            : $this->config['from_address'];

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'from' => $from,
                'to' => [$to],
                'subject' => $subject,
                'html' => $body,
            ]),
        ]);

        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status >= 200 && $status < 300;
    }
}
