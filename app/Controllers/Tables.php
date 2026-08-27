<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use stdClass;

class Tables
{
    private const VALID_TYPES = ['text', 'integer', 'decimal', 'boolean', 'timestamp', 'json'];

    private const SQL_TYPES = [
        'text'      => 'VARCHAR(255)',
        'integer'   => 'BIGINT',
        'decimal'   => 'DECIMAL(18,4)',
        'boolean'   => 'TINYINT(1)',
        'timestamp' => 'DATETIME', // not TIMESTAMP: MySQL's TIMESTAMP tops out at 2038-01-19 (32-bit Unix time)
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

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_tables WHERE project_id = ?');
        $countStmt->execute([$project['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, name, created_at FROM project_tables WHERE project_id = ? ORDER BY name LIMIT {$limit} OFFSET {$offset}"
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

        return $res
            ->setHeader('X-Total-Count', (string) $total)
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson($tables);
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

    /** Renames a table, including its physical backing table. */
    public static function rename(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
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

        $newName = trim($body['name'] ?? '');
        $error   = self::validateIdentifier($newName, 'name');
        if ($error) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        if ($newName === $table['name']) {
            return $res->withJson(['id' => (int) $table['id'], 'name' => $newName]);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_tables WHERE project_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$project['id'], $newName]);
        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'Table name already exists in this project']);
        }

        $oldPhysical = self::physicalName((int) $project['id'], $table['name']);
        $newPhysical = self::physicalName((int) $project['id'], $newName);

        if (strlen($newPhysical) > 64) {
            return $res->setStatusCode(422)->withJson(['error' => 'name is too long once prefixed with the project id']);
        }

        // DDL first: nothing has changed yet if it fails, so there's no metadata to roll back.
        try {
            $pdo->exec('RENAME TABLE `' . $oldPhysical . '` TO `' . $newPhysical . '`');
        } catch (\Throwable $e) {
            return $res->setStatusCode(500)->withJson(['error' => 'Failed to rename underlying table: ' . $e->getMessage()]);
        }

        $pdo->prepare('UPDATE project_tables SET name = ? WHERE id = ?')->execute([$newName, $table['id']]);

        return $res->withJson(['id' => (int) $table['id'], 'name' => $newName]);
    }

    /** Adds a column to an existing table. */
    public static function addColumn(Request $req, Response $res, stdClass $params): Response
    {
        $body  = $req->getJson(ignoreContentType: true) ?? [];
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $name = trim($body['name'] ?? '');
        $error = self::validateIdentifier($name, 'name');
        if ($error) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        if ($name === 'id') {
            return $res->setStatusCode(422)->withJson(['error' => "name cannot be 'id'"]);
        }

        $type = $body['type'] ?? '';
        if (!in_array($type, self::VALID_TYPES, true)) {
            return $res->setStatusCode(422)->withJson(['error' => 'type must be one of: ' . implode(', ', self::VALID_TYPES)]);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_columns WHERE table_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$table['id'], $name]);
        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'Column name already exists on this table']);
        }

        $col = [
            'name'          => $name,
            'type'          => $type,
            'nullable'      => (bool) ($body['nullable'] ?? false),
            'default_value' => $body['default_value'] ?? null,
        ];

        // DDL first: nothing has changed yet if it fails, so there's no metadata to roll back.
        try {
            $pdo->exec('ALTER TABLE `' . $table['physical_name'] . '` ADD COLUMN ' . self::columnDefinitionSql($pdo, $col));
        } catch (\Throwable $e) {
            return $res->setStatusCode(500)->withJson(['error' => 'Failed to add column: ' . $e->getMessage()]);
        }

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM project_columns WHERE table_id = ?');
        $stmt->execute([$table['id']]);
        $position = (int) $stmt->fetchColumn();

        $pdo->prepare(
            'INSERT INTO project_columns (table_id, name, type, nullable, default_value, position) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$table['id'], $name, $type, (int) $col['nullable'], $col['default_value'], $position]);

        return $res->setStatusCode(201)->withJson([
            'id'            => (int) $pdo->lastInsertId(),
            'name'          => $name,
            'type'          => $type,
            'nullable'      => $col['nullable'],
            'default_value' => $col['default_value'],
            'position'      => $position,
        ]);
    }

    /** Renames a column and/or changes its type, nullability, or default. */
    public static function updateColumn(Request $req, Response $res, stdClass $params): Response
    {
        $body  = $req->getJson(ignoreContentType: true) ?? [];
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $stmt = $pdo->prepare('SELECT * FROM project_columns WHERE id = ? AND table_id = ? LIMIT 1');
        $stmt->execute([$params->column_id, $table['id']]);
        $column = $stmt->fetch();

        if (!$column) {
            return $res->setStatusCode(404)->withJson(['error' => 'Column not found']);
        }

        $newName = array_key_exists('name', $body) ? trim((string) $body['name']) : $column['name'];
        $error   = self::validateIdentifier($newName, 'name');
        if ($error) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        if ($newName === 'id') {
            return $res->setStatusCode(422)->withJson(['error' => "name cannot be 'id'"]);
        }

        if ($newName !== $column['name']) {
            $stmt = $pdo->prepare('SELECT id FROM project_columns WHERE table_id = ? AND name = ? AND id != ? LIMIT 1');
            $stmt->execute([$table['id'], $newName, $column['id']]);
            if ($stmt->fetch()) {
                return $res->setStatusCode(409)->withJson(['error' => 'Column name already exists on this table']);
            }
        }

        $newType = $body['type'] ?? $column['type'];
        if (!in_array($newType, self::VALID_TYPES, true)) {
            return $res->setStatusCode(422)->withJson(['error' => 'type must be one of: ' . implode(', ', self::VALID_TYPES)]);
        }

        $newNullable = array_key_exists('nullable', $body) ? (bool) $body['nullable'] : (bool) $column['nullable'];
        $newDefault  = array_key_exists('default_value', $body) ? $body['default_value'] : $column['default_value'];

        $newCol = ['name' => $newName, 'type' => $newType, 'nullable' => $newNullable, 'default_value' => $newDefault];

        // DDL first: nothing has changed yet if it fails, so there's no metadata to roll back.
        try {
            $pdo->exec(
                'ALTER TABLE `' . $table['physical_name'] . '` CHANGE COLUMN `' . $column['name'] . '` '
                . self::columnDefinitionSql($pdo, $newCol)
            );
        } catch (\Throwable $e) {
            return $res->setStatusCode(500)->withJson(['error' => 'Failed to alter column: ' . $e->getMessage()]);
        }

        $pdo->prepare(
            'UPDATE project_columns SET name = ?, type = ?, nullable = ?, default_value = ? WHERE id = ?'
        )->execute([$newName, $newType, (int) $newNullable, $newDefault, $column['id']]);

        return $res->withJson([
            'id'            => (int) $column['id'],
            'name'          => $newName,
            'type'          => $newType,
            'nullable'      => $newNullable,
            'default_value' => $newDefault,
            'position'      => (int) $column['position'],
        ]);
    }

    /** Removes a column. Destructive — requires `?confirm=true`. */
    public static function destroyColumn(Request $req, Response $res, stdClass $params): Response
    {
        $pdo   = Database::getConn('default');
        $table = self::findOwnedTable($pdo, $params->project_id, $params->table_id, $params->user['id']);

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $stmt = $pdo->prepare('SELECT id, name FROM project_columns WHERE id = ? AND table_id = ? LIMIT 1');
        $stmt->execute([$params->column_id, $table['id']]);
        $column = $stmt->fetch();

        if (!$column) {
            return $res->setStatusCode(404)->withJson(['error' => 'Column not found']);
        }

        if (($req->getQueryParams()['confirm'] ?? null) !== 'true') {
            return $res->setStatusCode(422)->withJson([
                'error' => 'Removing a column drops its data irreversibly. Pass ?confirm=true to proceed.',
            ]);
        }

        // DDL first: nothing has changed yet if it fails, so there's no metadata to roll back.
        try {
            $pdo->exec('ALTER TABLE `' . $table['physical_name'] . '` DROP COLUMN `' . $column['name'] . '`');
        } catch (\Throwable $e) {
            return $res->setStatusCode(500)->withJson(['error' => 'Failed to drop column: ' . $e->getMessage()]);
        }

        $pdo->prepare('DELETE FROM project_columns WHERE id = ?')->execute([$column['id']]);

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

    /**
     * A table owned (transitively, via its project) by $userId, with its
     * physical table name pre-computed as `physical_name`.
     */
    private static function findOwnedTable(\PDO $pdo, mixed $projectId, mixed $tableId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.project_id, t.name FROM project_tables t
             INNER JOIN projects p ON p.id = t.project_id
             WHERE t.id = ? AND t.project_id = ? AND p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$tableId, $projectId, $userId]);
        $table = $stmt->fetch();

        if (!$table) {
            return false;
        }

        $table['physical_name'] = self::physicalName((int) $table['project_id'], $table['name']);

        return $table;
    }

    /** @return string|null An error message, or null if $name is a valid identifier. */
    private static function validateIdentifier(string $name, string $field): ?string
    {
        if ($name === '') {
            return "{$field} is required";
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            return "{$field} must be a valid identifier";
        }

        return null;
    }
}
