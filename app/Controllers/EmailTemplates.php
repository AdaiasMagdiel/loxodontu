<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Mail\DefaultTemplates;
use App\Mail\TemplateRenderer;
use stdClass;

/** Owner-facing CRUD for a project's overridable email templates. */
class EmailTemplates
{
    /** Sample values used to preview a template outside of a real send. */
    private const PREVIEW_VARS = [
        'link' => 'https://example.com/confirm?token=sample-token',
        'email' => 'user@example.com',
        'new_email' => 'new-user@example.com',
        'project_name' => 'My Project',
    ];

    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('SELECT template_key, subject, body FROM project_email_templates WHERE project_id = ?');
        $stmt->execute([$project['id']]);
        $custom = [];
        foreach ($stmt->fetchAll() as $row) {
            $custom[$row['template_key']] = $row;
        }

        $templates = [];
        foreach (DefaultTemplates::KEYS as $key) {
            $templates[] = isset($custom[$key])
                ? ['template_key' => $key, 'subject' => $custom[$key]['subject'], 'body' => $custom[$key]['body'], 'is_custom' => true]
                : ['template_key' => $key, ...DefaultTemplates::get($key), 'is_custom' => false];
        }

        return $res->withJson($templates);
    }

    public static function show(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        if (!self::isValidKey($params->key)) {
            return $res->setStatusCode(404)->withJson(['error' => 'Unknown template key']);
        }

        $stmt = $pdo->prepare(
            'SELECT subject, body FROM project_email_templates WHERE project_id = ? AND template_key = ? LIMIT 1'
        );
        $stmt->execute([$project['id'], $params->key]);
        $custom = $stmt->fetch();

        return $res->withJson([
            'template_key' => $params->key,
            ...($custom ?: DefaultTemplates::get($params->key)),
            'is_custom' => (bool) $custom,
        ]);
    }

    public static function update(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        if (!self::isValidKey($params->key)) {
            return $res->setStatusCode(404)->withJson(['error' => 'Unknown template key']);
        }

        $subject = trim($body['subject'] ?? '');
        $bodyText = $body['body'] ?? '';

        if ($subject === '' || trim($bodyText) === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'subject and body are required']);
        }

        $pdo->prepare(
            'INSERT INTO project_email_templates (project_id, template_key, subject, body)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body)'
        )->execute([$project['id'], $params->key, $subject, $bodyText]);

        return $res->withJson(['template_key' => $params->key, 'subject' => $subject, 'body' => $bodyText, 'is_custom' => true]);
    }

    public static function resetToDefault(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        if (!self::isValidKey($params->key)) {
            return $res->setStatusCode(404)->withJson(['error' => 'Unknown template key']);
        }

        $pdo->prepare('DELETE FROM project_email_templates WHERE project_id = ? AND template_key = ?')
            ->execute([$project['id'], $params->key]);

        return $res->withJson(['template_key' => $params->key, ...DefaultTemplates::get($params->key), 'is_custom' => false]);
    }

    /** Renders arbitrary subject/body with sample data — no send, no persistence. */
    public static function preview(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $subject = $body['subject'] ?? '';
        $bodyText = $body['body'] ?? '';

        return $res->withJson([
            'subject' => TemplateRenderer::render($subject, self::PREVIEW_VARS),
            'body' => TemplateRenderer::render($bodyText, self::PREVIEW_VARS),
        ]);
    }

    private static function isValidKey(mixed $key): bool
    {
        return in_array($key, DefaultTemplates::KEYS, true);
    }

    private static function findOwnedProject(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$publicId, $userId]);
        return $stmt->fetch();
    }
}
