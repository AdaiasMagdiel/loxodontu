<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use PDO;
use stdClass;

/**
 * Owner-only row browsing/editing for the dashboard's Table Editor. Bypasses
 * RLS entirely (same relationship StorageObjects has to the public Storage
 * passthrough) — the platform owner can always see and edit every row of
 * their own table, regardless of what RLS policies say for API/end-user
 * access via REST passthrough.
 */
class TableRows
{
    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $columns = self::columnNames($pdo, $table['id']);
        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $where = [];
        $bindings = [];

        $search = trim($req->getQueryParams()['search'] ?? '');
        if ($search !== '') {
            $likeParts = [];
            foreach ($columns as $column) {
                $likeParts[] = "`{$column}` LIKE ?";
                $bindings[] = "%{$search}%";
            }
            if ($likeParts !== []) {
                $where[] = '(' . implode(' OR ', $likeParts) . ')';
            }
        }

        $sortColumn = $req->getQueryParams()['sort'] ?? null;
        $sortDir = strtolower($req->getQueryParams()['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $orderBy = ($sortColumn !== null && in_array($sortColumn, $columns, true))
            ? "ORDER BY `{$sortColumn}` {$sortDir}"
            : 'ORDER BY `id` ASC';

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table['physical_name']}` {$whereSql}");
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT * FROM `{$table['physical_name']}` {$whereSql} {$orderBy} LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($bindings);

        return $res
            ->setHeader('X-Total-Count', (string) $total)
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson($stmt->fetchAll());
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $body  = $req->getJson(ignoreContentType: true) ?? [];
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $columns = self::columnNames($pdo, $table['id']);
        $data = self::sanitizeColumns($body, $columns);

        if ($data === []) {
            $pdo->exec("INSERT INTO `{$table['physical_name']}` () VALUES ()");
        } else {
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", array_keys($data)));
            $pdo->prepare("INSERT INTO `{$table['physical_name']}` ({$columnList}) VALUES ({$placeholders})")
                ->execute(array_values($data));
        }

        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT * FROM `{$table['physical_name']}` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);

        return $res->setStatusCode(201)->withJson($stmt->fetch());
    }

    public static function update(Request $req, Response $res, stdClass $params): Response
    {
        $body  = $req->getJson(ignoreContentType: true) ?? [];
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $columns = self::columnNames($pdo, $table['id']);
        $data = self::sanitizeColumns($body, $columns);

        if ($data === []) {
            return $res->setStatusCode(422)->withJson(['error' => 'Nothing to update']);
        }

        $stmt = $pdo->prepare("SELECT id FROM `{$table['physical_name']}` WHERE id = ? LIMIT 1");
        $stmt->execute([$params->row_id]);
        if (!$stmt->fetch()) {
            return $res->setStatusCode(404)->withJson(['error' => 'Row not found']);
        }

        $setSql = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        $pdo->prepare("UPDATE `{$table['physical_name']}` SET {$setSql} WHERE id = ?")
            ->execute([...array_values($data), $params->row_id]);

        $stmt = $pdo->prepare("SELECT * FROM `{$table['physical_name']}` WHERE id = ? LIMIT 1");
        $stmt->execute([$params->row_id]);

        return $res->withJson($stmt->fetch());
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $stmt = $pdo->prepare("SELECT id FROM `{$table['physical_name']}` WHERE id = ? LIMIT 1");
        $stmt->execute([$params->row_id]);
        if (!$stmt->fetch()) {
            return $res->setStatusCode(404)->withJson(['error' => 'Row not found']);
        }

        $pdo->prepare("DELETE FROM `{$table['physical_name']}` WHERE id = ?")->execute([$params->row_id]);

        return $res->setStatusCode(204);
    }

    /** Drops unknown columns and the immutable `id`; JSON-encodes array/object values. */
    private static function sanitizeColumns(array $body, array $knownColumns): array
    {
        $data = [];
        foreach ($body as $key => $value) {
            if ($key === 'id' || !in_array($key, $knownColumns, true)) {
                continue;
            }
            $data[$key] = is_array($value) ? json_encode($value) : $value;
        }

        return $data;
    }

    /** @return string[] */
    private static function columnNames(PDO $pdo, int $tableId): array
    {
        $stmt = $pdo->prepare('SELECT name FROM project_columns WHERE table_id = ? ORDER BY position ASC, id ASC');
        $stmt->execute([$tableId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function findOwnedTable(PDO $pdo, mixed $publicProjectId, mixed $tableId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.project_id, t.name FROM project_tables t
             INNER JOIN projects p ON p.id = t.project_id
             WHERE t.id = ? AND p.public_id = ? AND p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$tableId, $publicProjectId, $userId]);
        $table = $stmt->fetch();

        if (!$table) {
            return false;
        }

        $table['physical_name'] = \App\Controllers\Tables::physicalName((int) $table['project_id'], $table['name']);

        return $table;
    }
}
