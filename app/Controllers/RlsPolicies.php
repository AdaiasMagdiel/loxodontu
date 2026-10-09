<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use App\Rls\ExpressionValidator;
use stdClass;

class RlsPolicies
{
    private const VALID_OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALL'];

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

    /** Lists every RLS policy across every table in a project — powers the Database page. */
    public static function indexForProject(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM project_rls_policies p
             INNER JOIN project_tables t ON t.id = p.table_id
             WHERE t.project_id = ?'
        );
        $countStmt->execute([$project['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.operation, p.expression, p.enabled, p.created_at,
                    t.id AS table_id, t.name AS table_name
             FROM project_rls_policies p
             INNER JOIN project_tables t ON t.id = p.table_id
             WHERE t.project_id = ?
             ORDER BY p.created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$project['id']]);
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

        if ($error = ExpressionValidator::validate($expression, self::tableColumns($pdo, (int) $table['id']))) {
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
     * The columns a policy on this table may reference — the same list
     * Rest::scopedApi() exposes (and re-validates against at request time).
     *
     * @return string[]
     */
    private static function tableColumns(\PDO $pdo, int $tableId): array
    {
        $stmt = $pdo->prepare('SELECT name FROM project_columns WHERE table_id = ?');
        $stmt->execute([$tableId]);
        $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return in_array('id', $columns, true) ? $columns : ['id', ...$columns];
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

    private static function findOwnedProject(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$publicId, $userId]);
        return $stmt->fetch();
    }
}
