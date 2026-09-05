<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Crypto;
use App\Database;
use App\Mail\MailerFactory;
use stdClass;
use Throwable;

/** Owner-facing CRUD for a project's email provider settings (SMTP or Resend). */
class EmailConfig
{
    public static function show(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('SELECT * FROM project_email_configs WHERE project_id = ? LIMIT 1');
        $stmt->execute([$project['id']]);
        $config = $stmt->fetch();

        if (!$config) {
            return $res->withJson([
                'provider' => 'smtp',
                'from_address' => null,
                'from_name' => null,
                'smtp_host' => null,
                'smtp_port' => null,
                'smtp_username' => null,
                'smtp_encryption' => null,
                'has_smtp_password' => false,
                'has_resend_api_key' => false,
                'require_email_confirmation' => false,
            ]);
        }

        return $res->withJson(self::present($config));
    }

    public static function update(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $provider = $body['provider'] ?? 'smtp';
        if (!in_array($provider, ['smtp', 'resend'], true)) {
            return $res->setStatusCode(422)->withJson(['error' => "provider must be 'smtp' or 'resend'"]);
        }

        $fromAddress = trim($body['from_address'] ?? '');
        if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            return $res->setStatusCode(422)->withJson(['error' => 'A valid from_address is required']);
        }

        $stmt = $pdo->prepare('SELECT * FROM project_email_configs WHERE project_id = ? LIMIT 1');
        $stmt->execute([$project['id']]);
        $existing = $stmt->fetch() ?: [];

        // A blank secret field means "keep the existing value" — the show()
        // endpoint never returns decrypted secrets for the client to round-trip.
        $smtpPassword = array_key_exists('smtp_password', $body) && $body['smtp_password'] !== ''
            ? Crypto::encrypt($body['smtp_password'])
            : ($existing['smtp_password_encrypted'] ?? null);

        $resendApiKey = array_key_exists('resend_api_key', $body) && $body['resend_api_key'] !== ''
            ? Crypto::encrypt($body['resend_api_key'])
            : ($existing['resend_api_key_encrypted'] ?? null);

        $requireConfirmation = array_key_exists('require_email_confirmation', $body)
            ? (bool) $body['require_email_confirmation']
            : (bool) ($existing['require_email_confirmation'] ?? false);

        $pdo->prepare(
            'INSERT INTO project_email_configs
                (project_id, provider, from_address, from_name, smtp_host, smtp_port, smtp_username,
                 smtp_encryption, smtp_password_encrypted, resend_api_key_encrypted, require_email_confirmation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                provider = VALUES(provider), from_address = VALUES(from_address), from_name = VALUES(from_name),
                smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port), smtp_username = VALUES(smtp_username),
                smtp_encryption = VALUES(smtp_encryption), smtp_password_encrypted = VALUES(smtp_password_encrypted),
                resend_api_key_encrypted = VALUES(resend_api_key_encrypted),
                require_email_confirmation = VALUES(require_email_confirmation)'
        )->execute([
            $project['id'],
            $provider,
            $fromAddress,
            $body['from_name'] ?? ($existing['from_name'] ?? null),
            $body['smtp_host'] ?? ($existing['smtp_host'] ?? null),
            $body['smtp_port'] ?? ($existing['smtp_port'] ?? null),
            $body['smtp_username'] ?? ($existing['smtp_username'] ?? null),
            $body['smtp_encryption'] ?? ($existing['smtp_encryption'] ?? null),
            $smtpPassword,
            $resendApiKey,
            (int) $requireConfirmation,
        ]);

        $stmt->execute([$project['id']]);

        return $res->withJson(self::present($stmt->fetch()));
    }

    public static function sendTest(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $to = trim($body['to'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $res->setStatusCode(422)->withJson(['error' => 'A valid to address is required']);
        }

        try {
            $sent = MailerFactory::forProject($project['id'])->send(
                $to,
                'Loxodontu test email',
                '<p>This is a test email from your Loxodontu project\'s email configuration.</p>'
            );
        } catch (Throwable $e) {
            return $res->setStatusCode(422)->withJson(['error' => $e->getMessage()]);
        }

        if (!$sent) {
            return $res->setStatusCode(422)->withJson(['error' => 'The email provider rejected the message.']);
        }

        return $res->withJson(['message' => 'Test email sent.']);
    }

    /** @return array<string, mixed> */
    private static function present(array $config): array
    {
        return [
            'provider' => $config['provider'],
            'from_address' => $config['from_address'],
            'from_name' => $config['from_name'],
            'smtp_host' => $config['smtp_host'],
            'smtp_port' => $config['smtp_port'] !== null ? (int) $config['smtp_port'] : null,
            'smtp_username' => $config['smtp_username'],
            'smtp_encryption' => $config['smtp_encryption'],
            'has_smtp_password' => $config['smtp_password_encrypted'] !== null,
            'has_resend_api_key' => $config['resend_api_key_encrypted'] !== null,
            'require_email_confirmation' => (bool) $config['require_email_confirmation'],
        ];
    }

    private static function findOwnedProject(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$publicId, $userId]);
        return $stmt->fetch();
    }
}
