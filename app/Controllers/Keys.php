<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use stdClass;

class Keys
{
    private const VALID_PERMISSIONS = ['select', 'insert', 'update', 'delete', 'function'];

    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_api_keys WHERE project_id = ?');
        $countStmt->execute([$project['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, name, key_prefix, permissions, last_used_at, expires_at, created_at
             FROM project_api_keys WHERE project_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$project['id']]);
        $keys = $stmt->fetchAll();

        foreach ($keys as &$key) {
            $key['permissions'] = json_decode($key['permissions'], true) ?? [];
        }
        unset($key);

        return $res
            ->setHeader('X-Total-Count', (string) $total)
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson($keys);
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $name        = trim($body['name'] ?? '');
        $permissions = $body['permissions'] ?? [];
        $expiresAt   = $body['expires_at'] ?? null;

        if ($name === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'name is required']);
        }

        if (!is_array($permissions) || empty($permissions)) {
            return $res->setStatusCode(422)->withJson(['error' => 'permissions must be a non-empty array']);
        }

        foreach ($permissions as $perm) {
            if (!in_array($perm, self::VALID_PERMISSIONS, true)) {
                return $res->setStatusCode(422)->withJson([
                    'error' => 'permissions must contain only: ' . implode(', ', self::VALID_PERMISSIONS),
                ]);
            }
        }
        $permissions = array_values(array_unique($permissions));

        if ($expiresAt !== null) {
            $parsed = strtotime($expiresAt);
            if ($parsed === false || $parsed <= time()) {
                return $res->setStatusCode(422)->withJson(['error' => 'expires_at must be a future datetime']);
            }
            $expiresAt = date('Y-m-d H:i:s', $parsed);
        }

        $token     = bin2hex(random_bytes(32));
        $prefix    = substr($token, 0, 8);
        $hash      = hash('sha256', $token);

        $pdo->prepare(
            'INSERT INTO project_api_keys (project_id, name, key_prefix, key_hash, permissions, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$project['id'], $name, $prefix, $hash, json_encode($permissions), $expiresAt]);

        $id = (int) $pdo->lastInsertId();

        return $res->setStatusCode(201)->withJson([
            'id'          => $id,
            'name'        => $name,
            'key'         => $token,
            'key_prefix'  => $prefix,
            'permissions' => $permissions,
            'expires_at'  => $expiresAt,
        ]);
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_api_keys WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$params->key_id, $project['id']]);
        $key = $stmt->fetch();

        if (!$key) {
            return $res->setStatusCode(404)->withJson(['error' => 'API key not found']);
        }

        $pdo->prepare('DELETE FROM project_api_keys WHERE id = ?')->execute([$key['id']]);

        return $res->setStatusCode(204);
    }

    private static function findOwnedProject(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$publicId, $userId]);
        return $stmt->fetch();
    }
}
