<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use stdClass;

class Tables
{
    private const VALID_TYPES = ['text', 'integer', 'decimal', 'boolean', 'timestamp', 'json'];

    private const SQL_TYPES = [
        'text'      => 'VARCHAR(255)',
        'integer'   => 'BIGINT',
        'decimal'   => 'DECIMAL(18,4)',
        'boolean'   => 'TINYINT(1)',
        'timestamp' => 'TIMESTAMP',
        'json'      => 'JSON',
    ];

    /**
     * Every project shares one physical database, so a project's logical table
     * name (e.g. "posts") is prefixed with its project id to avoid two
     * projects colliding on the same underlying MySQL table.
     */
    public static function physicalName(int $projectId, string $name): string
    {
        return "p{$projectId}_{$name}";
    }

    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, created_at FROM project_tables WHERE project_id = ? ORDER BY name'
        );
        $stmt->execute([$project['id']]);
        $tables = $stmt->fetchAll();

        foreach ($tables as &$table) {
            $stmt = $pdo->prepare(
                'SELECT id, name, type, nullable, default_value, position FROM project_columns
                 WHERE table_id = ? ORDER BY position ASC, id ASC'
            );
            $stmt->execute([$table['id']]);
            $cols = $stmt->fetchAll();

            foreach ($cols as &$col) {
                $col['nullable'] = (bool) $col['nullable'];
                $col['position'] = (int) $col['position'];
            }
            unset($col);

            $table['columns'] = $cols;
        }
        unset($table);

        return $res->withJson($tables);
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $name = trim($body['name'] ?? '');

        if ($name === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'name is required']);
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            return $res->setStatusCode(422)->withJson(['error' => 'name must be a valid identifier']);
        }

        $columns = $body['columns'] ?? [];

        if (!is_array($columns)) {
            return $res->setStatusCode(422)->withJson(['error' => 'columns must be an array']);
        }

        foreach ($columns as $i => $col) {
            $colName = trim($col['name'] ?? '');
            if ($colName === '') {
                return $res->setStatusCode(422)->withJson(['error' => "columns[$i].name is required"]);
            }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $colName)) {
                return $res->setStatusCode(422)->withJson(['error' => "columns[$i].name must be a valid identifier"]);
            }
            if ($colName === 'id') {
                return $res->setStatusCode(422)->withJson(['error' => "columns[$i].name cannot be 'id'"]);
            }
            $colType = $col['type'] ?? '';
            if (!in_array($colType, self::VALID_TYPES, true)) {
                return $res->setStatusCode(422)->withJson([
                    'error' => "columns[$i].type must be one of: " . implode(', ', self::VALID_TYPES),
                ]);
            }
        }

        // Check duplicate table name within project
        $stmt = $pdo->prepare('SELECT id FROM project_tables WHERE project_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$project['id'], $name]);
        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'Table name already exists in this project']);
        }

        $physicalName = self::physicalName((int) $project['id'], $name);
        if (strlen($physicalName) > 64) {
            return $res->setStatusCode(422)->withJson(['error' => 'name is too long once prefixed with the project id']);
        }

        // DDL (CREATE TABLE) implicitly commits any open transaction, so it can't
        // be rolled back together with the metadata insert below. Metadata is
        // committed first; if the physical CREATE TABLE then fails, it's deleted
        // again as a compensating action instead.
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO project_tables (project_id, name) VALUES (?, ?)')->execute([$project['id'], $name]);
            $tableId = (int) $pdo->lastInsertId();

            $colStmt = $pdo->prepare(
                'INSERT INTO project_columns (table_id, name, type, nullable, default_value, position) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($columns as $i => $col) {
                $colStmt->execute([
                    $tableId,
                    trim($col['name']),
                    $col['type'],
                    isset($col['nullable']) ? (int) (bool) $col['nullable'] : 0,
                    $col['default_value'] ?? null,
                    $i,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        try {
            $pdo->exec(self::buildCreateTableSql($pdo, $physicalName, $columns));
        } catch (\Throwable $e) {
            // Roll the metadata back too — project_columns cascades via FK.
            $pdo->prepare('DELETE FROM project_tables WHERE id = ?')->execute([$tableId]);

            return $res->setStatusCode(500)->withJson(['error' => 'Failed to create underlying table: ' . $e->getMessage()]);
        }

        return $res->setStatusCode(201)->withJson([
            'id'      => $tableId,
            'name'    => $name,
            'columns' => array_values(array_map(function ($col, $i) use ($pdo, $tableId) {
                $stmt = $pdo->prepare('SELECT id FROM project_columns WHERE table_id = ? AND name = ? LIMIT 1');
                $stmt->execute([$tableId, trim($col['name'])]);
                $row = $stmt->fetch();
                return [
                    'id'            => $row['id'],
                    'name'          => trim($col['name']),
                    'type'          => $col['type'],
                    'nullable'      => (bool) ($col['nullable'] ?? false),
                    'default_value' => $col['default_value'] ?? null,
                    'position'      => $i,
                ];
            }, $columns, array_keys($columns))),
        ]);
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('SELECT id, name FROM project_tables WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$params->table_id, $project['id']]);
        $table = $stmt->fetch();

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $physicalName = self::physicalName((int) $project['id'], $table['name']);

        // Drop the physical table first: if it fails, metadata is left intact
        // rather than orphaning a physical table nothing references anymore.
        $pdo->exec('DROP TABLE IF EXISTS `' . $physicalName . '`');
        $pdo->prepare('DELETE FROM project_tables WHERE id = ?')->execute([$table['id']]);

        return $res->setStatusCode(204);
    }

    /** @param array<int, array{name: string, type: string, nullable?: bool, default_value?: mixed}> $columns */
    private static function buildCreateTableSql(\PDO $pdo, string $physicalName, array $columns): string
    {
        $defs = ['`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'];

        foreach ($columns as $col) {
            $defs[] = self::columnDefinitionSql($pdo, $col);
        }

        $defs[] = 'PRIMARY KEY (`id`)';

        return "CREATE TABLE `{$physicalName}` (" . implode(', ', $defs)
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /** @param array{name: string, type: string, nullable?: bool, default_value?: mixed} $col */
    private static function columnDefinitionSql(\PDO $pdo, array $col): string
    {
        $name     = trim($col['name']);
        $sqlType  = self::SQL_TYPES[$col['type']];
        $nullable = (bool) ($col['nullable'] ?? false);

        $sql = "`{$name}` {$sqlType} " . ($nullable ? 'NULL' : 'NOT NULL');

        $default = $col['default_value'] ?? null;

        // MySQL doesn't accept a literal DEFAULT on JSON columns.
        if ($default !== null && $col['type'] !== 'json') {
            if (in_array($col['type'], ['integer', 'decimal', 'boolean'], true) && is_numeric($default)) {
                $sql .= " DEFAULT {$default}";
            } elseif ($col['type'] === 'timestamp' && strtoupper((string) $default) === 'CURRENT_TIMESTAMP') {
                $sql .= ' DEFAULT CURRENT_TIMESTAMP';
            } else {
                $sql .= ' DEFAULT ' . $pdo->quote((string) $default);
            }
        } elseif ($nullable) {
            $sql .= ' DEFAULT NULL';
        }

        return $sql;
    }

    private static function findOwnedProject(\PDO $pdo, mixed $projectId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM projects WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $userId]);
        return $stmt->fetch();
    }
}
