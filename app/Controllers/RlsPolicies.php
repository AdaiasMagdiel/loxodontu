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
    private const MAX_EXPRESSION_LENGTH = 10000;
    private const VALID_PLACEHOLDERS = ['id', 'email', 'role'];

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
            "SELECT id, name, operation, expression, enabled, created_at, updated_at
             FROM project_rls_policies WHERE table_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$table['id']]);
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
        $body  = $req->getJson(ignoreContentType: true) ?? [];
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
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

        if ($error = self::validateExpression($expression)) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        $pdo->prepare(
            'INSERT INTO project_rls_policies (table_id, name, operation, expression, enabled)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$table['id'], $name, $operation, $expression, (int) $enabled]);

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

    /**
     * Cheap guardrails, not a security boundary (the author is a platform
     * owner who already has unrestricted SQL access via the SQL Editor) —
     * these just turn common mistakes into a friendly 422 instead of a
     * confusing runtime SQL error.
     */
    public static function validateExpression(string $expression): ?string
    {
        if ($expression === '') {
            return 'expression is required';
        }

        if (strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            return 'expression is too long (max ' . self::MAX_EXPRESSION_LENGTH . ' characters)';
        }

        if (preg_match('/;|--|\/\*/', $expression)) {
            return 'expression must be a single boolean expression: ";", "--" and "/*" are not allowed';
        }

        if (preg_match_all('/\$auth\.(\w+)/', $expression, $matches)) {
            foreach ($matches[1] as $name) {
                if (!in_array($name, self::VALID_PLACEHOLDERS, true)) {
                    return "unknown placeholder \$auth.{$name}; use one of: " . implode(', ', array_map(fn($p) => "\$auth.{$p}", self::VALID_PLACEHOLDERS));
                }
            }
        }

        return null;
    }

    private static function findOwnedTable(\PDO $pdo, mixed $publicProjectId, mixed $tableId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT t.id FROM project_tables t
             INNER JOIN projects p ON p.id = t.project_id
             WHERE t.id = ? AND p.public_id = ? AND p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$tableId, $publicProjectId, $userId]);
        return $stmt->fetch();
    }
}
