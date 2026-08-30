<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use stdClass;

class StoragePolicies
{
    private const VALID_OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALL'];

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
            "SELECT id, name, operation, expression, enabled, created_at, updated_at
             FROM project_storage_policies WHERE bucket_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$bucket['id']]);
        $policies = $stmt->fetchAll();

        foreach ($policies as &$policy) {
            $policy['enabled'] = (bool) $policy['enabled'];
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

        $name       = trim($body['name'] ?? '');
        $operation  = strtoupper(trim($body['operation'] ?? ''));
        $expression = trim($body['expression'] ?? '');
        $enabled    = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true;

        if ($name === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'name is required']);
        }

        if (!in_array($operation, self::VALID_OPERATIONS, true)) {
            return $res->setStatusCode(422)->withJson([
                'error' => 'operation must be one of: ' . implode(', ', self::VALID_OPERATIONS),
            ]);
        }

        if ($error = RlsPolicies::validateExpression($expression)) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        $pdo->prepare(
            'INSERT INTO project_storage_policies (bucket_id, name, operation, expression, enabled)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$bucket['id'], $name, $operation, $expression, (int) $enabled]);

        $id = (int) $pdo->lastInsertId();

        return $res->setStatusCode(201)->withJson([
            'id'         => $id,
            'name'       => $name,
            'operation'  => $operation,
            'expression' => $expression,
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
