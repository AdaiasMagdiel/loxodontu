<?php

namespace App\Mail;

use App\Crypto;
use App\Database;

class MailerFactory
{
    public static function forProject(int $projectId): MailerInterface
    {
        if (env('ENV') === 'testing') {
            return new TestMailDriver(ROOT_DIR . '/storage/mail.log');
        }

        $config = self::configFor($projectId);

        return match ($config['provider']) {
            'resend' => new ResendMailer([
                'api_key' => Crypto::decrypt($config['resend_api_key_encrypted']),
                'from_address' => $config['from_address'],
                'from_name' => $config['from_name'],
            ]),
            default => new SmtpMailer([
                'host' => $config['smtp_host'],
                'port' => (int) $config['smtp_port'],
                'username' => $config['smtp_username'],
                'password' => $config['smtp_password_encrypted'] !== null
                    ? Crypto::decrypt($config['smtp_password_encrypted'])
                    : null,
                'encryption' => $config['smtp_encryption'],
                'from_address' => $config['from_address'],
                'from_name' => $config['from_name'],
            ]),
        };
    }

    /** @return array<string, mixed> */
    public static function configFor(int $projectId): array
    {
        $stmt = Database::getConn('default')->prepare(
            'SELECT * FROM project_email_configs WHERE project_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId]);
        $config = $stmt->fetch();

        if (!$config) {
            throw new EmailNotConfiguredException('This project has no email provider configured yet.');
        }

        return $config;
    }

    /** Renders a template (custom override, falling back to the built-in default) for a project. */
    public static function renderTemplate(int $projectId, string $templateKey, array $vars): array
    {
        $stmt = Database::getConn('default')->prepare(
            'SELECT subject, body FROM project_email_templates WHERE project_id = ? AND template_key = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $templateKey]);
        $template = $stmt->fetch() ?: DefaultTemplates::get($templateKey);

        return [
            'subject' => TemplateRenderer::render($template['subject'], $vars),
            'body' => TemplateRenderer::render($template['body'], $vars),
        ];
    }
}
