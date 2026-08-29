<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use stdClass;

class StorageBuckets
{
    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_storage_buckets WHERE project_id = ?');
        $countStmt->execute([$project['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, name, public, created_at FROM project_storage_buckets
             WHERE project_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$project['id']]);
        $buckets = $stmt->fetchAll();

        foreach ($buckets as &$bucket) {
            $bucket['public'] = (bool) $bucket['public'];
        }
        unset($bucket);

        return $res
            ->setHeader('X-Total-Count', (string) $total)
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson($buckets);
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $name   = trim($body['name'] ?? '');
        $public = array_key_exists('public', $body) ? (bool) $body['public'] : false;

        if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
            return $res->setStatusCode(422)->withJson([
                'error' => 'name is required and must be alphanumeric (max 64 chars, - and _ allowed)',
            ]);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_storage_buckets WHERE project_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$project['id'], $name]);
        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'A bucket with this name already exists']);
        }

        $pdo->prepare(
            'INSERT INTO project_storage_buckets (project_id, name, public) VALUES (?, ?, ?)'
        )->execute([$project['id'], $name, (int) $public]);

        $id = (int) $pdo->lastInsertId();

        return $res->setStatusCode(201)->withJson(['id' => $id, 'name' => $name, 'public' => $public]);
    }

    public static function update(Request $req, Response $res, stdClass $params): Response
    {
        $body   = $req->getJson(ignoreContentType: true) ?? [];
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        if (!array_key_exists('public', $body)) {
            return $res->setStatusCode(422)->withJson(['error' => 'public is required']);
        }

        $public = (bool) $body['public'];
        $pdo->prepare('UPDATE project_storage_buckets SET public = ? WHERE id = ?')->execute([(int) $public, $bucket['id']]);

        return $res->withJson(['id' => (int) $bucket['id'], 'name' => $bucket['name'], 'public' => $public]);
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        $pdo->prepare('DELETE FROM project_storage_buckets WHERE id = ?')->execute([$bucket['id']]);

        return $res->setStatusCode(204);
    }

    private static function findOwnedProject(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$publicId, $userId]);
        return $stmt->fetch();
    }

    private static function findOwnedBucket(\PDO $pdo, mixed $publicProjectId, mixed $bucketId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT b.id, b.name, b.public FROM project_storage_buckets b
             INNER JOIN projects p ON p.id = b.project_id
             WHERE b.id = ? AND p.public_id = ? AND p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$bucketId, $publicProjectId, $userId]);
        return $stmt->fetch();
    }
}
