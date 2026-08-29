<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use stdClass;

class StoragePolicies
{
    private const VALID_OPERATIONS   = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALL'];
    private const VALID_PLACEHOLDERS = ['$auth.id', '$auth.email', '$auth.role'];
    private const VALID_OPS          = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'is_null', 'is_not_null'];

    /** Fixed columns of `project_storage_objects` — not user-defined, unlike table columns. */
    private const OBJECT_COLUMNS = ['id', 'bucket_id', 'path', 'owner_id', 'size', 'mime_type', 'created_at', 'updated_at'];

    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_storage_policies WHERE bucket_id = ?');
        $countStmt->execute([$bucket['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, name, role, operation, expression, enabled, created_at, updated_at
             FROM project_storage_policies WHERE bucket_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$bucket['id']]);
        $policies = $stmt->fetchAll();

        foreach ($policies as &$policy) {
            $policy['expression'] = json_decode($policy['expression'], true) ?? [];
            $policy['enabled']    = (bool) $policy['enabled'];
        }
        unset($policy);

        return $res
            ->setHeader('X-Total-Count', (string) $total)
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson($policies);
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $body   = $req->getJson(ignoreContentType: true) ?? [];
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        $name      = trim($body['name'] ?? '');
        $operation = strtoupper(trim($body['operation'] ?? ''));
        $condition = $body['conditions'] ?? [];
        $role      = array_key_exists('role', $body) ? $body['role'] : null;
        $enabled   = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true;

        if ($name === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'name is required']);
        }

        if (!in_array($operation, self::VALID_OPERATIONS, true)) {
            return $res->setStatusCode(422)->withJson([
                'error' => 'operation must be one of: ' . implode(', ', self::VALID_OPERATIONS),
            ]);
        }

        if ($role !== null && (!is_string($role) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $role))) {
            return $res->setStatusCode(422)->withJson(['error' => 'role must be null or an alphanumeric string (max 64 chars)']);
        }

        if (!is_array($condition)) {
            return $res->setStatusCode(422)->withJson(['error' => 'conditions must be an object of column => value']);
        }

        foreach ($condition as $column => $value) {
            if (!in_array($column, self::OBJECT_COLUMNS, true)) {
                return $res->setStatusCode(422)->withJson(['error' => "unknown column in conditions: {$column}"]);
            }
            if (is_array($value)) {
                $op = $value['op'] ?? null;
                if (!is_string($op) || !in_array($op, self::VALID_OPS, true)) {
                    return $res->setStatusCode(422)->withJson([
                        'error' => "conditions.{$column}.op must be one of: " . implode(', ', self::VALID_OPS),
                    ]);
                }
                $noValueOps = ['is_null', 'is_not_null'];
                if (!in_array($op, $noValueOps, true)) {
                    if (!array_key_exists('value', $value)) {
                        return $res->setStatusCode(422)->withJson(['error' => "conditions.{$column}.value is required for op '{$op}'"]);
                    }
                    $val = $value['value'];
                    if (is_string($val) && str_starts_with($val, '$auth.') && !in_array($val, self::VALID_PLACEHOLDERS, true)) {
                        return $res->setStatusCode(422)->withJson([
                            'error' => "invalid placeholder '{$val}' for conditions.{$column}.value; use one of: " . implode(', ', self::VALID_PLACEHOLDERS),
                        ]);
                    }
                    if (!is_scalar($val) && $val !== null) {
                        return $res->setStatusCode(422)->withJson(['error' => "conditions.{$column}.value must be a scalar or placeholder"]);
                    }
                }
            } else {
                if (is_string($value) && str_starts_with($value, '$auth.') && !in_array($value, self::VALID_PLACEHOLDERS, true)) {
                    return $res->setStatusCode(422)->withJson([
                        'error' => "invalid placeholder '{$value}' for conditions.{$column}; use one of: " . implode(', ', self::VALID_PLACEHOLDERS),
                    ]);
                }
            }
        }

        $pdo->prepare(
            'INSERT INTO project_storage_policies (bucket_id, name, role, operation, expression, enabled)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$bucket['id'], $name, $role, $operation, json_encode($condition), (int) $enabled]);

        $id = (int) $pdo->lastInsertId();

        return $res->setStatusCode(201)->withJson([
            'id'         => $id,
            'name'       => $name,
            'role'       => $role,
            'operation'  => $operation,
            'conditions' => $condition,
            'enabled'    => $enabled,
        ]);
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo    = Database::getConn('default');
        $bucket = self::findOwnedBucket($pdo, $params->project_id, $params->bucket_id, $params->user['id']);

        if (!$bucket) {
            return $res->setStatusCode(404)->withJson(['error' => 'Bucket not found']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_storage_policies WHERE id = ? AND bucket_id = ? LIMIT 1');
        $stmt->execute([$params->policy_id, $bucket['id']]);
        $policy = $stmt->fetch();

        if (!$policy) {
            return $res->setStatusCode(404)->withJson(['error' => 'Storage policy not found']);
        }

        $pdo->prepare('DELETE FROM project_storage_policies WHERE id = ?')->execute([$policy['id']]);

        return $res->setStatusCode(204);
    }

    private static function findOwnedBucket(\PDO $pdo, mixed $publicProjectId, mixed $bucketId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT b.id FROM project_storage_buckets b
             INNER JOIN projects p ON p.id = b.project_id
             WHERE b.id = ? AND p.public_id = ? AND p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$bucketId, $publicProjectId, $userId]);
        return $stmt->fetch();
    }
}
