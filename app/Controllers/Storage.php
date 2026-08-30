<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request as ErlRequest;
use AdaiasMagdiel\Erlenmeyer\Response as ErlResponse;
use AdaiasMagdiel\PdoRestify\QueryBuilder;
use AdaiasMagdiel\PdoRestify\RawCondition;
use App\Auth\EndUserAuth;
use App\Database;
use App\Pagination;
use App\Rls\PolicyEngine;
use App\Storage\LocalDisk;
use PDO;
use stdClass;

/**
 * Project-scoped storage passthrough: buckets/policies are managed via the
 * platform API (StorageBuckets/StoragePolicies), this handles the actual
 * object list/upload/download/update/delete traffic, gated the same way REST
 * passthrough is — a project API key (Authorization: Bearer) with the
 * matching `storage:*` permission, further narrowed by the bucket's RLS-style
 * policies (see App\Rls\PolicyEngine) and an optional end-user identity
 * (X-User-Token).
 */
class Storage
{
    private const OPERATION_TO_PERMISSION = [
        'select' => 'storage:select',
        'insert' => 'storage:insert',
        'update' => 'storage:update',
        'delete' => 'storage:delete',
    ];

    private const OBJECT_COLUMNS = ['id', 'bucket_id', 'path', 'owner_id', 'size', 'mime_type', 'created_at', 'updated_at'];

    /** GET /[project_id]/storage/public/[bucket]/[object_id] — no auth, bucket must be public. */
    public static function publicDownload(ErlRequest $req, ErlResponse $res, stdClass $params): ErlResponse
    {
        $pdo       = Database::getConn('default');
        $projectId = Projects::resolveInternalId($pdo, $params->project_id);

        if ($projectId === null) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }

        $bucket = self::findBucket($pdo, $projectId, $params->bucket);
        if (!$bucket || !$bucket['public']) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }

        $object = self::findObject($pdo, (int) $bucket['id'], $params->object_id);
        if (!$object) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }

        return self::streamObject($res, $projectId, $bucket, $object);
    }

    /** GET /[project_id]/storage/[bucket] — list objects. */
    public static function index(ErlRequest $req, ErlResponse $res, stdClass $params): ErlResponse
    {
        return self::withGatedBucket($req, $res, $params, 'select', function (
            PDO $pdo,
            array $bucket,
            array $policyConditions,
        ) use ($req, $res): ErlResponse {
            $condition = $policyConditions['select'];
            $filters   = [['bucket_id', 'eq', (string) $bucket['id']]];

            ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

            [$countSql, $countParams] = QueryBuilder::count('project_storage_objects', $filters, $condition);
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($countParams);
            $total = (int) $countStmt->fetchColumn();

            [$sql, $sqlParams] = QueryBuilder::select('project_storage_objects', self::OBJECT_COLUMNS, $filters, $condition, [['created_at', 'desc']], $limit, $offset);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($sqlParams);

            return $res
                ->setHeader('X-Total-Count', (string) $total)
                ->setHeader('X-Page-Limit', (string) $limit)
                ->setHeader('X-Page-Offset', (string) $offset)
                ->withJson($stmt->fetchAll());
        });
    }

    /** POST /[project_id]/storage/[bucket] — upload a file (multipart/form-data, field "file"; optional field "path"). */
    public static function store(ErlRequest $req, ErlResponse $res, stdClass $params): ErlResponse
    {
        return self::withGatedBucket($req, $res, $params, 'insert', function (
            PDO $pdo,
            array $bucket,
            array $policyConditions,
            ?array $auth,
        ) use ($req, $res, $params): ErlResponse {
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

            $data = [
                'bucket_id' => $bucket['id'],
                'path'      => $path,
                'owner_id'  => $auth['id'] ?? null, // an uploaded object's owner is always the uploader, never client-chosen
                'size'      => (int) $file['size'],
                'mime_type' => $file['type'] ?? null,
            ];

            [$sql, $sqlParams] = QueryBuilder::insert('project_storage_objects', $data);
            $pdo->prepare($sql)->execute($sqlParams);
            $objectId = (int) $pdo->lastInsertId();

            // WITH CHECK: the insert policy, if any, is re-evaluated against the
            // row it just wrote — a violation deletes it before the file ever
            // touches disk, and the request is rejected outright rather than
            // having any of its values silently coerced.
            $condition = $policyConditions['insert'];
            if ($condition !== null && !self::objectSatisfies($pdo, (int) $bucket['id'], $objectId, $condition)) {
                $pdo->prepare('DELETE FROM project_storage_objects WHERE id = ?')->execute([$objectId]);

                return $res->setStatusCode(403)->withJson(['error' => "Upload violates the bucket's policy"]);
            }

            LocalDisk::put(self::projectIdFromParams($pdo, $params), (int) $bucket['id'], $objectId, $file['tmp_name']);

            $object = self::findObject($pdo, (int) $bucket['id'], (string) $objectId);

            return $res->setStatusCode(201)->withJson($object);
        });
    }

    /** GET /[project_id]/storage/[bucket]/[object_id] — authenticated download. */
    public static function show(ErlRequest $req, ErlResponse $res, stdClass $params): ErlResponse
    {
        return self::withGatedBucket($req, $res, $params, 'select', function (
            PDO $pdo,
            array $bucket,
            array $policyConditions,
        ) use ($res, $params): ErlResponse {
            $object = self::findScopedObject($pdo, (int) $bucket['id'], $params->object_id, $policyConditions['select']);
            if ($object === false) {
                return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
            }

            return self::streamObject($res, self::projectIdFromParams($pdo, $params), $bucket, $object);
        });
    }

    /** PATCH /[project_id]/storage/[bucket]/[object_id] — rename the logical path. */
    public static function update(ErlRequest $req, ErlResponse $res, stdClass $params): ErlResponse
    {
        return self::withGatedBucket($req, $res, $params, 'update', function (
            PDO $pdo,
            array $bucket,
            array $policyConditions,
        ) use ($req, $res, $params): ErlResponse {
            $condition = $policyConditions['update'];
            $object    = self::findScopedObject($pdo, (int) $bucket['id'], $params->object_id, $condition);
            if ($object === false) {
                return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
            }

            $body = $req->getJson(ignoreContentType: true) ?? [];
            $path = trim($body['path'] ?? '');
            if ($path === '') {
                return $res->setStatusCode(422)->withJson(['error' => 'path is required']);
            }

            $stmt = $pdo->prepare('SELECT id FROM project_storage_objects WHERE bucket_id = ? AND path = ? AND id != ? LIMIT 1');
            $stmt->execute([$bucket['id'], $path, $object['id']]);
            if ($stmt->fetch()) {
                return $res->setStatusCode(409)->withJson(['error' => 'An object with this path already exists in the bucket']);
            }

            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            $pdo->prepare('UPDATE project_storage_objects SET path = ? WHERE id = ?')->execute([$path, $object['id']]);

            // WITH CHECK, defaulting to the same expression as the USING above
            // (Postgres's own default when a separate check isn't given).
            if ($condition !== null && !self::objectSatisfies($pdo, (int) $bucket['id'], (int) $object['id'], $condition)) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }

                return $res->setStatusCode(403)->withJson(['error' => "Update violates the bucket's policy"]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $res->withJson(self::findObject($pdo, (int) $bucket['id'], (string) $object['id']));
        });
    }

    /** DELETE /[project_id]/storage/[bucket]/[object_id] */
    public static function destroy(ErlRequest $req, ErlResponse $res, stdClass $params): ErlResponse
    {
        return self::withGatedBucket($req, $res, $params, 'delete', function (
            PDO $pdo,
            array $bucket,
            array $policyConditions,
        ) use ($res, $params): ErlResponse {
            $object = self::findScopedObject($pdo, (int) $bucket['id'], $params->object_id, $policyConditions['delete']);
            if ($object === false) {
                return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
            }

            $pdo->prepare('DELETE FROM project_storage_objects WHERE id = ?')->execute([$object['id']]);
            LocalDisk::delete(self::projectIdFromParams($pdo, $params), (int) $bucket['id'], (int) $object['id']);

            return $res->setStatusCode(204);
        });
    }

    /**
     * Shared auth/RLS gate: validates the API key + `storage:{operation}` permission,
     * resolves the bucket and the caller's end-user identity, merges the bucket's
     * storage policies for $operation, then hands off to $handler(pdo, bucket,
     * policyConditions, auth).
     */
    private static function withGatedBucket(ErlRequest $req, ErlResponse $res, stdClass $params, string $operation, callable $handler): ErlResponse
    {
        $token = self::extractToken($req);
        if ($token === null) {
            return $res->setStatusCode(401)->withJson(['error' => 'Unauthorized']);
        }

        $pdo       = Database::getConn('default');
        $projectId = Projects::resolveInternalId($pdo, $params->project_id);
        if ($projectId === null) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }

        $prefix = substr($token, 0, 8);
        $stmt   = $pdo->prepare(
            'SELECT key_hash, permissions FROM project_api_keys
             WHERE key_prefix = ? AND project_id = ?
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([$prefix, $projectId]);
        $apiKey = $stmt->fetch();

        if (!$apiKey || !hash_equals($apiKey['key_hash'], hash('sha256', $token))) {
            return $res->setStatusCode(401)->withJson(['error' => 'Unauthorized']);
        }

        $permissions = json_decode($apiKey['permissions'], true) ?? [];
        if (!in_array(self::OPERATION_TO_PERMISSION[$operation], $permissions, true)) {
            return $res->setStatusCode(403)->withJson(['error' => 'Forbidden']);
        }

        $bucket = self::findBucket($pdo, $projectId, $params->bucket);
        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }

        $auth = EndUserAuth::resolve($pdo, $req, $projectId);

        $stmt = $pdo->prepare(
            'SELECT operation, expression FROM project_storage_policies
             WHERE bucket_id = ? AND enabled = 1'
        );
        $stmt->execute([$bucket['id']]);
        $policies = $stmt->fetchAll();

        $policyConditions = PolicyEngine::resolve($policies, $auth);

        return $handler($pdo, $bucket, $policyConditions, $auth);
    }

    /** @return array{id: int, path: string, ...}|false */
    private static function findScopedObject(PDO $pdo, int $bucketId, mixed $objectId, ?RawCondition $condition): array|false
    {
        $filters = [['bucket_id', 'eq', (string) $bucketId], ['id', 'eq', (string) $objectId]];

        [$sql, $params] = QueryBuilder::select('project_storage_objects', self::OBJECT_COLUMNS, $filters, $condition, null, 1, 0);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch();
    }

    /** Evaluates $condition against a single object — the WITH CHECK re-validation for insert/update. */
    private static function objectSatisfies(PDO $pdo, int $bucketId, int $objectId, RawCondition $condition): bool
    {
        $filters = [['bucket_id', 'eq', (string) $bucketId], ['id', 'eq', (string) $objectId]];

        [$sql, $params] = QueryBuilder::select('project_storage_objects', ['id'], $filters, $condition, null, 1, 0);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    private static function streamObject(ErlResponse $res, int $projectId, array $bucket, array $object): ErlResponse
    {
        $diskPath = LocalDisk::path($projectId, (int) $bucket['id'], (int) $object['id']);

        if (!is_readable($diskPath)) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }

        $response = $res->withFile($diskPath);
        $response->setHeader('Content-Disposition', 'inline; filename="' . basename($object['path']) . '"');

        if (!empty($object['mime_type'])) {
            $response->setContentType($object['mime_type']);
        }

        return $response;
    }

    private static function findBucket(PDO $pdo, int $projectId, string $name): array|false
    {
        $stmt = $pdo->prepare('SELECT id, name, public FROM project_storage_buckets WHERE project_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$projectId, $name]);
        $bucket = $stmt->fetch();

        if ($bucket) {
            $bucket['public'] = (bool) $bucket['public'];
        }

        return $bucket;
    }

    private static function findObject(PDO $pdo, int $bucketId, string $objectId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id, bucket_id, path, owner_id, size, mime_type, created_at, updated_at
             FROM project_storage_objects WHERE bucket_id = ? AND id = ? LIMIT 1'
        );
        $stmt->execute([$bucketId, $objectId]);

        return $stmt->fetch();
    }

    private static function projectIdFromParams(PDO $pdo, stdClass $params): int
    {
        return Projects::resolveInternalId($pdo, $params->project_id);
    }

    private static function extractToken(ErlRequest $req): ?string
    {
        $header = $req->getHeader('Authorization') ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }
}
