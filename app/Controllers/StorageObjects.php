<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use App\Storage\LocalDisk;
use stdClass;

/**
 * Owner-facing object management for the dashboard's Storage screen — list,
 * upload, download and delete files in a bucket, authenticated with the
 * platform owner's own login (PlatformAuth), not a project API key.
 *
 * This deliberately bypasses bucket RLS policies entirely: a policy scopes
 * what a project's *own* API keys/end users can do through the passthrough
 * (App\Controllers\Storage), the same way table RLS never applies to the
 * owner's own SQL Editor access. The owner can always see/manage every
 * object in their own bucket.
 */
class StorageObjects
{
    private const OBJECT_COLUMNS = ['id', 'bucket_id', 'path', 'owner_id', 'size', 'mime_type', 'created_at', 'updated_at'];

    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_storage_objects WHERE bucket_id = ?');
        $countStmt->execute([$bucket['id']]);
        $total = (int) $countStmt->fetchColumn();

        $columns = implode(', ', self::OBJECT_COLUMNS);
        $stmt = $pdo->prepare(
            "SELECT {$columns} FROM project_storage_objects
             WHERE bucket_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$bucket['id']]);

        return $res
            ->setHeader('X-Total-Count', (string) $total)
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson($stmt->fetchAll());
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        $file = $req->getFile('file');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $res->setStatusCode(422)->withJson(['error' => 'file is required']);
        }

        $formData = $req->getFormData();
        $path     = trim($formData['path'] ?? $file['name'] ?? '');
        if ($path === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'path is required']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_storage_objects WHERE bucket_id = ? AND path = ? LIMIT 1');
        $stmt->execute([$bucket['id'], $path]);
        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'An object with this path already exists in the bucket']);
        }

        $pdo->prepare(
            'INSERT INTO project_storage_objects (bucket_id, path, owner_id, size, mime_type) VALUES (?, ?, NULL, ?, ?)'
        )->execute([$bucket['id'], $path, (int) $file['size'], $file['type'] ?? null]);
        $objectId = (int) $pdo->lastInsertId();

        LocalDisk::put((int) $bucket['project_id'], (int) $bucket['id'], $objectId, $file['tmp_name']);

        $columns = implode(', ', self::OBJECT_COLUMNS);
        $stmt = $pdo->prepare("SELECT {$columns} FROM project_storage_objects WHERE id = ? LIMIT 1");
        $stmt->execute([$objectId]);

        return $res->setStatusCode(201)->withJson($stmt->fetch());
    }

    public static function download(Request $req, Response $res, stdClass $params): Response
    {
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        $stmt = $pdo->prepare('SELECT id, path, mime_type FROM project_storage_objects WHERE id = ? AND bucket_id = ? LIMIT 1');
        $stmt->execute([$params->object_id, $bucket['id']]);
        $object = $stmt->fetch();

        if (!$object) {
            return $res->setStatusCode(404)->withJson(['error' => 'Object not found']);
        }

        $diskPath = LocalDisk::path((int) $bucket['project_id'], (int) $bucket['id'], (int) $object['id']);
        if (!is_readable($diskPath)) {
            return $res->setStatusCode(404)->withJson(['error' => 'Object not found']);
        }

        $response = $res->withFile($diskPath);
        $response->setHeader('Content-Disposition', 'inline; filename="' . basename($object['path']) . '"');

        if (!empty($object['mime_type'])) {
            $response->setContentType($object['mime_type']);
        }

        return $response;
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_storage_objects WHERE id = ? AND bucket_id = ? LIMIT 1');
        $stmt->execute([$params->object_id, $bucket['id']]);
        $object = $stmt->fetch();

        if (!$object) {
            return $res->setStatusCode(404)->withJson(['error' => 'Object not found']);
        }

        $pdo->prepare('DELETE FROM project_storage_objects WHERE id = ?')->execute([$object['id']]);
        LocalDisk::delete((int) $bucket['project_id'], (int) $bucket['id'], (int) $object['id']);

        return $res->setStatusCode(204);
    }

    /** @return array{id: int, project_id: int}|false */
    private static function findOwnedBucket(\PDO $pdo, mixed $publicProjectId, mixed $bucketId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT b.id, p.id AS project_id FROM project_storage_buckets b
             INNER JOIN projects p ON p.id = b.project_id
             WHERE b.id = ? AND p.public_id = ? AND p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$bucketId, $publicProjectId, $userId]);
        return $stmt->fetch();
    }
}
