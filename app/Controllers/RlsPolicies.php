<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use stdClass;

class RlsPolicies
{
    private const VALID_OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALL'];
    private const VALID_PLACEHOLDERS = ['$auth.id', '$auth.email', '$auth.role'];

    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_rls_policies WHERE table_id = ?');
        $countStmt->execute([$table['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, name, role, operation, expression, enabled, created_at, updated_at
             FROM project_rls_policies WHERE table_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$table['id']]);
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
        $body  = $req->getJson(ignoreContentType: true) ?? [];
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
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

        // Empty conditions is valid: it means "no extra row restriction" for
        // whichever role this policy applies to (or everyone, if role is null).
        if (!is_array($condition)) {
            return $res->setStatusCode(422)->withJson(['error' => 'conditions must be an object of column => value']);
        }

        $columns = self::columnNames($pdo, $table['id']);
        foreach ($condition as $column => $value) {
            if (!in_array($column, $columns, true)) {
                return $res->setStatusCode(422)->withJson(['error' => "unknown column in conditions: {$column}"]);
            }
            if (is_string($value) && str_starts_with($value, '$auth.') && !in_array($value, self::VALID_PLACEHOLDERS, true)) {
                return $res->setStatusCode(422)->withJson([
                    'error' => "invalid placeholder '{$value}' for conditions.{$column}; use one of: " . implode(', ', self::VALID_PLACEHOLDERS),
                ]);
            }
            if (!is_scalar($value) && $value !== null) {
                return $res->setStatusCode(422)->withJson(['error' => "conditions.{$column} must be a scalar value or placeholder"]);
            }
        }

        $pdo->prepare(
            'INSERT INTO project_rls_policies (table_id, name, role, operation, expression, enabled)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$table['id'], $name, $role, $operation, json_encode($condition), (int) $enabled]);

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
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_rls_policies WHERE id = ? AND table_id = ? LIMIT 1');
        $stmt->execute([$params->policy_id, $table['id']]);
        $policy = $stmt->fetch();

        if (!$policy) {
            return $res->setStatusCode(404)->withJson(['error' => 'RLS policy not found']);
        }

        $pdo->prepare('DELETE FROM project_rls_policies WHERE id = ?')->execute([$policy['id']]);

        return $res->setStatusCode(204);
    }

    private static function findOwnedTable(\PDO $pdo, mixed $projectId, mixed $tableId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT t.id FROM project_tables t
             INNER JOIN projects p ON p.id = t.project_id
             WHERE t.id = ? AND t.project_id = ? AND p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$tableId, $projectId, $userId]);
        return $stmt->fetch();
    }

    private static function columnNames(\PDO $pdo, int $tableId): array
    {
        $stmt = $pdo->prepare('SELECT name FROM project_columns WHERE table_id = ?');
        $stmt->execute([$tableId]);
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $names[] = 'id';
        return $names;
    }
}
