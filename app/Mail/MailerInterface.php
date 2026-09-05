<?php

namespace App\Mail;

interface MailerInterface
{
    public function send(string $to, string $subject, string $body): bool;
}
