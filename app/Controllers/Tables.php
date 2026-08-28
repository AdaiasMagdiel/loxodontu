<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use stdClass;

class Tables
{
    private const SQL_EDITOR_MAX_LENGTH = 20000;
    private const SQL_EDITOR_MAX_ROWS = 200;

    private const VALID_TYPES = [
        'text',
        'longtext',
        'integer',
        'bigint',
        'decimal',
        'float',
        'boolean',
        'date',
        'time',
        'timestamp',
        'json',
        'uuid',
    ];

    private const SQL_TYPES = [
        'text'      => 'VARCHAR(255)',
        'longtext'  => 'LONGTEXT',
        'integer'   => 'BIGINT',
        'bigint'    => 'BIGINT',
        'decimal'   => 'DECIMAL(18,4)',
        'float'     => 'DOUBLE',
        'boolean'   => 'TINYINT(1)',
        'date'      => 'DATE',
        'time'      => 'TIME',
        'timestamp' => 'DATETIME', // not TIMESTAMP: MySQL's TIMESTAMP tops out at 2038-01-19 (32-bit Unix time)
        'json'      => 'JSON',
        'uuid'      => 'CHAR(36)',
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

        $hasReferences = self::projectColumnsHaveReferences($pdo);

        foreach ($tables as &$table) {
            $columnSql = $hasReferences
                ? 'SELECT c.id, c.name, c.type, c.nullable, c.default_value, c.position,
                          c.reference_table_id, c.reference_column, rt.name AS reference_table
                   FROM project_columns c
                   LEFT JOIN project_tables rt ON rt.id = c.reference_table_id
                   WHERE c.table_id = ? ORDER BY c.position ASC, c.id ASC'
                : 'SELECT c.id, c.name, c.type, c.nullable, c.default_value, c.position,
                          NULL AS reference_table_id, NULL AS reference_column, NULL AS reference_table
                   FROM project_columns c
                   WHERE c.table_id = ? ORDER BY c.position ASC, c.id ASC';

            $stmt = $pdo->prepare($columnSql);
            $stmt->execute([$table['id']]);
            $cols = $stmt->fetchAll();

            foreach ($cols as &$col) {
                $col['nullable'] = (bool) $col['nullable'];
                $col['position'] = (int) $col['position'];
                $col['reference_table_id'] = $col['reference_table_id'] !== null ? (int) $col['reference_table_id'] : null;
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

            $referenceError = self::validateReferencePayload($pdo, (int) $project['id'], $col, "columns[$i]");
            if ($referenceError) {
                return $res->setStatusCode(422)->withJson(['error' => $referenceError]);
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
                'INSERT INTO project_columns (table_id, name, type, nullable, default_value, position, reference_table_id, reference_column) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($columns as $i => $col) {
                $reference = self::referenceMetadata($pdo, (int) $project['id'], $col);
                $colStmt->execute([
                    $tableId,
                    trim($col['name']),
                    $col['type'],
                    isset($col['nullable']) ? (int) (bool) $col['nullable'] : 0,
                    $col['default_value'] ?? null,
                    $i,
                    $reference['table_id'],
                    $reference['column'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        try {
            $pdo->exec(self::buildCreateTableSql($pdo, (int) $project['id'], $physicalName, $columns));
        } catch (\Throwable $e) {
            // Roll the metadata back too — project_columns cascades via FK.
            $pdo->prepare('DELETE FROM project_tables WHERE id = ?')->execute([$tableId]);

            return $res->setStatusCode(500)->withJson(['error' => 'Failed to create underlying table: ' . $e->getMessage()]);
        }

        return $res->setStatusCode(201)->withJson([
            'id'      => $tableId,
            'name'    => $name,
            'columns' => array_values(array_map(function ($col, $i) use ($pdo, $tableId, $project) {
                $reference = self::referenceMetadata($pdo, (int) $project['id'], $col);
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
                    'reference_table_id' => $reference['table_id'],
                    'reference_table'    => $reference['table'],
                    'reference_column'   => $reference['column'],
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

        $referenceError = self::validateReferencePayload($pdo, (int) $table['project_id'], $body, 'reference');
        if ($referenceError) {
            return $res->setStatusCode(422)->withJson(['error' => $referenceError]);
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
        $reference = self::referenceMetadata($pdo, (int) $table['project_id'], $body);
        $col['reference_table_id'] = $reference['table_id'];
        $col['reference_column'] = $reference['column'];
        $col['reference_project_id'] = (int) $table['project_id'];

        // DDL first: nothing has changed yet if it fails, so there's no metadata to roll back.
        try {
            $sql = 'ALTER TABLE `' . $table['physical_name'] . '` ADD COLUMN ' . self::columnDefinitionSql($pdo, $col);
            $constraint = self::foreignKeyConstraintSql($pdo, $table['physical_name'], $col);
            if ($constraint !== null) {
                $sql .= ', ADD ' . $constraint;
            }
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            return $res->setStatusCode(500)->withJson(['error' => 'Failed to add column: ' . $e->getMessage()]);
        }

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM project_columns WHERE table_id = ?');
        $stmt->execute([$table['id']]);
        $position = (int) $stmt->fetchColumn();

        $pdo->prepare(
            'INSERT INTO project_columns (table_id, name, type, nullable, default_value, position, reference_table_id, reference_column) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$table['id'], $name, $type, (int) $col['nullable'], $col['default_value'], $position, $reference['table_id'], $reference['column']]);

        return $res->setStatusCode(201)->withJson([
            'id'            => (int) $pdo->lastInsertId(),
            'name'          => $name,
            'type'          => $type,
            'nullable'      => $col['nullable'],
            'default_value' => $col['default_value'],
            'position'      => $position,
            'reference_table_id' => $reference['table_id'],
            'reference_table'    => $reference['table'],
            'reference_column'   => $reference['column'],
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
        $referenceBody = [
            'references_table'  => array_key_exists('references_table', $body) ? $body['references_table'] : null,
            'references_column' => array_key_exists('references_column', $body) ? $body['references_column'] : null,
        ];
        if (!array_key_exists('references_table', $body) && $column['reference_table_id']) {
            $referenceBody['references_table_id'] = $column['reference_table_id'];
            $referenceBody['references_column'] = $column['reference_column'];
        }
        $referenceError = self::validateReferencePayload($pdo, (int) $table['project_id'], $referenceBody, 'reference');
        if ($referenceError) {
            return $res->setStatusCode(422)->withJson(['error' => $referenceError]);
        }
        $reference = self::referenceMetadata($pdo, (int) $table['project_id'], $referenceBody);

        $newCol = [
            'name' => $newName,
            'type' => $newType,
            'nullable' => $newNullable,
            'default_value' => $newDefault,
            'reference_table_id' => $reference['table_id'],
            'reference_column' => $reference['column'],
            'reference_project_id' => (int) $table['project_id'],
        ];

        // DDL first: nothing has changed yet if it fails, so there's no metadata to roll back.
        try {
            if ($column['reference_table_id']) {
                $pdo->exec('ALTER TABLE `' . $table['physical_name'] . '` DROP FOREIGN KEY `' . self::foreignKeyName($table['physical_name'], $column['name']) . '`');
            }
            $pdo->exec(
                'ALTER TABLE `' . $table['physical_name'] . '` CHANGE COLUMN `' . $column['name'] . '` '
                . self::columnDefinitionSql($pdo, $newCol)
            );
            $constraint = self::foreignKeyConstraintSql($pdo, $table['physical_name'], $newCol);
            if ($constraint !== null) {
                $pdo->exec('ALTER TABLE `' . $table['physical_name'] . '` ADD ' . $constraint);
            }
        } catch (\Throwable $e) {
            return $res->setStatusCode(500)->withJson(['error' => 'Failed to alter column: ' . $e->getMessage()]);
        }

        $pdo->prepare(
            'UPDATE project_columns SET name = ?, type = ?, nullable = ?, default_value = ?, reference_table_id = ?, reference_column = ? WHERE id = ?'
        )->execute([$newName, $newType, (int) $newNullable, $newDefault, $reference['table_id'], $reference['column'], $column['id']]);

        return $res->withJson([
            'id'            => (int) $column['id'],
            'name'          => $newName,
            'type'          => $newType,
            'nullable'      => $newNullable,
            'default_value' => $newDefault,
            'position'      => (int) $column['position'],
            'reference_table_id' => $reference['table_id'],
            'reference_table'    => $reference['table'],
            'reference_column'   => $reference['column'],
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

        $stmt = $pdo->prepare('SELECT id, name, reference_table_id FROM project_columns WHERE id = ? AND table_id = ? LIMIT 1');
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
            if ($column['reference_table_id']) {
                $pdo->exec('ALTER TABLE `' . $table['physical_name'] . '` DROP FOREIGN KEY `' . self::foreignKeyName($table['physical_name'], $column['name']) . '`');
            }
            $pdo->exec('ALTER TABLE `' . $table['physical_name'] . '` DROP COLUMN `' . $column['name'] . '`');
        } catch (\Throwable $e) {
            return $res->setStatusCode(500)->withJson(['error' => 'Failed to drop column: ' . $e->getMessage()]);
        }

        $pdo->prepare('DELETE FROM project_columns WHERE id = ?')->execute([$column['id']]);

        return $res->setStatusCode(204);
    }

    /** @param array<int, array{name: string, type: string, nullable?: bool, default_value?: mixed}> $columns */
    private static function buildCreateTableSql(\PDO $pdo, int $projectId, string $physicalName, array $columns): string
    {
        $defs = ['`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'];

        foreach ($columns as $col) {
            $reference = self::referenceMetadata($pdo, $projectId, $col);
            $col['reference_table_id'] = $reference['table_id'];
            $col['reference_column'] = $reference['column'];
            $col['reference_project_id'] = $projectId;
            $defs[] = self::columnDefinitionSql($pdo, $col);
        }

        $defs[] = 'PRIMARY KEY (`id`)';

        foreach ($columns as $col) {
            $reference = self::referenceMetadata($pdo, $projectId, $col);
            $col['reference_table_id'] = $reference['table_id'];
            $col['reference_column'] = $reference['column'];
            $col['reference_project_id'] = $projectId;
            $constraint = self::foreignKeyConstraintSql($pdo, $physicalName, $col);
            if ($constraint !== null) {
                $defs[] = $constraint;
            }
        }

        return "CREATE TABLE `{$physicalName}` (" . implode(', ', $defs)
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /** @param array{name: string, type: string, nullable?: bool, default_value?: mixed} $col */
    private static function columnDefinitionSql(\PDO $pdo, array $col): string
    {
        $name     = trim($col['name']);
        $sqlType  = self::SQL_TYPES[$col['type']];
        if (($col['reference_column'] ?? null) === 'id' && in_array($col['type'], ['integer', 'bigint'], true)) {
            $sqlType = 'BIGINT UNSIGNED';
        }
        $nullable = (bool) ($col['nullable'] ?? false);

        $sql = "`{$name}` {$sqlType} " . ($nullable ? 'NULL' : 'NOT NULL');

        $default = $col['default_value'] ?? null;

        // MySQL doesn't accept a literal DEFAULT on JSON columns.
        if ($default !== null && $col['type'] !== 'json') {
            if (in_array($col['type'], ['integer', 'bigint', 'decimal', 'float', 'boolean'], true) && is_numeric($default)) {
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

    public static function runSql(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $sql = trim((string) ($body['sql'] ?? ''));
        if ($sql === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'sql is required']);
        }
        if (strlen($sql) > self::SQL_EDITOR_MAX_LENGTH) {
            return $res->setStatusCode(422)->withJson(['error' => 'sql is too long']);
        }

        $rewrite = self::rewriteProjectSql($pdo, (int) $project['id'], $sql);
        if (isset($rewrite['error'])) {
            return $res->setStatusCode(422)->withJson(['error' => $rewrite['error']]);
        }

        try {
            $started = microtime(true);
            $stmt = $pdo->query($rewrite['sql']);
            $columns = [];
            if ($stmt->columnCount() > 0) {
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $columns[] = $meta['name'] ?? "column_{$i}";
                }
            }
            $rows = [];
            $truncated = false;
            if ($columns) {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    if (count($rows) >= self::SQL_EDITOR_MAX_ROWS) {
                        $truncated = true;
                        break;
                    }
                    $rows[] = $row;
                }
            }

            return $res->withJson([
                'sql'           => $rewrite['sql'],
                'operation'     => $rewrite['operation'],
                'columns'       => $columns,
                'rows'          => $rows,
                'truncated'     => $truncated,
                'row_limit'     => self::SQL_EDITOR_MAX_ROWS,
                'affected_rows' => $stmt->rowCount(),
                'duration_ms'   => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            return $res->setStatusCode(422)->withJson([
                'error' => 'SQL failed: ' . $e->getMessage(),
                'sql'   => $rewrite['sql'],
            ]);
        }
    }

    private static function findOwnedProject(\PDO $pdo, mixed $projectId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM projects WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $userId]);
        return $stmt->fetch();
    }

    private static function projectColumnsHaveReferences(\PDO $pdo): bool
    {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'project_columns'
              AND COLUMN_NAME IN ('reference_table_id', 'reference_column')
        ");

        return (int) $stmt->fetchColumn() === 2;
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

    private static function validateReferencePayload(\PDO $pdo, int $projectId, array $body, string $field): ?string
    {
        $table = trim((string) ($body['references_table'] ?? ''));
        $tableId = $body['references_table_id'] ?? null;
        $column = trim((string) ($body['references_column'] ?? ''));

        if ($table === '' && !$tableId && $column === '') {
            return null;
        }

        if (($table !== '' || $tableId) && $column === '') {
            return "{$field}.references_column is required when references_table is set";
        }

        if ($column !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            return "{$field}.references_column must be a valid identifier";
        }

        if (!$tableId) {
            $error = self::validateIdentifier($table, "{$field}.references_table");
            if ($error) {
                return $error;
            }
        }

        $reference = self::referenceMetadata($pdo, $projectId, $body);
        if (!$reference['table_id']) {
            return "{$field}.references_table must be a table in this project";
        }

        if ($column !== 'id') {
            $stmt = $pdo->prepare('SELECT id FROM project_columns WHERE table_id = ? AND name = ? LIMIT 1');
            $stmt->execute([$reference['table_id'], $column]);
            if (!$stmt->fetch()) {
                return "{$field}.references_column must exist on the referenced table";
            }
        }

        return null;
    }

    /** @return array{table_id: ?int, table: ?string, column: ?string} */
    private static function referenceMetadata(\PDO $pdo, int $projectId, array $body): array
    {
        $tableId = $body['references_table_id'] ?? null;
        $table = trim((string) ($body['references_table'] ?? ''));
        $column = trim((string) ($body['references_column'] ?? ''));

        if ($table === '' && !$tableId) {
            return ['table_id' => null, 'table' => null, 'column' => null];
        }

        if ($tableId) {
            $stmt = $pdo->prepare('SELECT id, name FROM project_tables WHERE id = ? AND project_id = ? LIMIT 1');
            $stmt->execute([$tableId, $projectId]);
        } else {
            $stmt = $pdo->prepare('SELECT id, name FROM project_tables WHERE name = ? AND project_id = ? LIMIT 1');
            $stmt->execute([$table, $projectId]);
        }

        $row = $stmt->fetch();
        if (!$row) {
            return ['table_id' => null, 'table' => null, 'column' => $column ?: null];
        }

        return ['table_id' => (int) $row['id'], 'table' => $row['name'], 'column' => $column ?: null];
    }

    private static function foreignKeyConstraintSql(\PDO $pdo, string $physicalName, array $col): ?string
    {
        if (empty($col['reference_table_id']) || empty($col['reference_column'])) {
            return null;
        }

        $projectId = (int) $col['reference_project_id'];
        $stmt = $pdo->prepare('SELECT name FROM project_tables WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$col['reference_table_id'], $projectId]);
        $referenceTable = $stmt->fetchColumn();
        if (!$referenceTable) {
            return null;
        }

        $referencePhysical = self::physicalName($projectId, (string) $referenceTable);
        $name = self::foreignKeyName($physicalName, $col['name']);

        return "CONSTRAINT `{$name}` FOREIGN KEY (`{$col['name']}`) REFERENCES `{$referencePhysical}` (`{$col['reference_column']}`)";
    }

    private static function foreignKeyName(string $physicalName, string $column): string
    {
        return 'fk_' . substr(hash('sha1', $physicalName . ':' . $column), 0, 24);
    }

    /** @return array{sql?: string, operation?: string, error?: string} */
    private static function rewriteProjectSql(\PDO $pdo, int $projectId, string $sql): array
    {
        $sql = self::trimFinalStatementTerminator(trim($sql));
        if ($sql === '') {
            return ['error' => 'sql is required'];
        }

        if (self::containsSqlComments($sql)) {
            return ['error' => 'SQL comments are not allowed in the SQL editor'];
        }

        if (self::containsSemicolonOutsideQuotedText($sql)) {
            return ['error' => 'Only one SQL statement can be executed at a time'];
        }

        if (!preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql, $operationMatch)) {
            return ['error' => 'Only SELECT, INSERT, UPDATE, and DELETE statements are allowed in the SQL editor'];
        }
        $operation = strtoupper($operationMatch[1]);

        $searchableSql = self::replaceQuotedText($sql, ' ');

        if (preg_match('/\b(?:ALTER|CREATE|DROP|TRUNCATE|GRANT|REVOKE|USE|CALL|LOAD|LOCK|UNLOCK)\b/i', $searchableSql)) {
            return ['error' => 'DDL and administrative SQL are not allowed in the SQL editor'];
        }
        if (preg_match('/\b(FROM|UPDATE)\s+`?[a-zA-Z_][a-zA-Z0-9_]*`?(?:\s+(?:AS\s+)?[a-zA-Z_][a-zA-Z0-9_]*)?\s*,/i', $searchableSql)) {
            return ['error' => 'Comma joins are not supported; use explicit JOIN clauses'];
        }

        if (preg_match('/\bp(\d+)_([a-zA-Z_][a-zA-Z0-9_]*)\b/', $searchableSql, $matches) && (int) $matches[1] !== $projectId) {
            return ['error' => 'SQL cannot reference physical tables from another project'];
        }

        $stmt = $pdo->prepare('SELECT name FROM project_tables WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $logicalTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $physicalByLogical = [];
        foreach ($logicalTables as $table) {
            $physicalByLogical[$table] = self::physicalName($projectId, $table);
        }

        $rewritten = self::replaceOutsideQuotedText($sql, function (string $segment) use ($physicalByLogical) {
            return preg_replace_callback(
            '/\b(FROM|JOIN|UPDATE|INTO)\s+(`?)([a-zA-Z_][a-zA-Z0-9_]*)(`?)/i',
                function (array $match) use ($physicalByLogical) {
                $table = $match[3];
                if (isset($physicalByLogical[$table])) {
                    return $match[1] . ' `' . $physicalByLogical[$table] . '`';
                }
                if (preg_match('/^p\d+_/', $table)) {
                    return ((string) $match[1]) . ' `' . $table . '`';
                }
                return $match[0];
            },
                $segment
            );
        });

        preg_match_all('/\b(FROM|JOIN|UPDATE|INTO)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', self::replaceQuotedText($rewritten, ' '), $refs);
        if ($refs[2] === []) {
            return ['error' => 'SQL must reference at least one project table'];
        }

        foreach ($refs[2] as $ref) {
            if (preg_match('/^p(\d+)_/', $ref, $match)) {
                if ((int) $match[1] !== $projectId) {
                    return ['error' => 'SQL cannot reference physical tables from another project'];
                }
                if (!in_array($ref, $physicalByLogical, true)) {
                    return ['error' => "Unknown project table: {$ref}"];
                }
                continue;
            }

            return ['error' => "Unknown project table: {$ref}"];
        }

        return ['sql' => $rewritten, 'operation' => $operation];
    }

    private static function trimFinalStatementTerminator(string $sql): string
    {
        $trimmed = rtrim($sql);
        if ($trimmed === '' || substr($trimmed, -1) !== ';') {
            return $trimmed;
        }

        $prefix = substr($trimmed, 0, -1);
        if (self::containsSemicolonOutsideQuotedText($prefix)) {
            return $trimmed;
        }

        return rtrim($prefix);
    }

    private static function containsSqlComments(string $sql): bool
    {
        return self::scanOutsideQuotedText($sql, function (string $char, ?string $next): bool {
            return ($char === '-' && $next === '-') || ($char === '/' && $next === '*') || $char === '#';
        });
    }

    private static function containsSemicolonOutsideQuotedText(string $sql): bool
    {
        return self::scanOutsideQuotedText($sql, fn (string $char, ?string $next): bool => $char === ';');
    }

    private static function replaceQuotedText(string $sql, string $replacement): string
    {
        return self::replaceOutsideQuotedText($sql, fn (string $segment): string => $segment, $replacement);
    }

    private static function replaceOutsideQuotedText(string $sql, callable $replaceSegment, ?string $quotedReplacement = null): string
    {
        $out = '';
        $segment = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $out .= $quotedReplacement ?? $char;
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $i++;
                    $out .= $quotedReplacement ?? $sql[$i];
                    continue;
                }
                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote && $quote !== '`') {
                        $i++;
                        $out .= $quotedReplacement ?? $sql[$i];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $out .= $replaceSegment($segment);
                $segment = '';
                $quote = $char;
                $out .= $quotedReplacement ?? $char;
                continue;
            }

            $segment .= $char;
        }

        return $out . $replaceSegment($segment);
    }

    private static function scanOutsideQuotedText(string $sql, callable $predicate): bool
    {
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote && $quote !== '`') {
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }

            if ($predicate($char, $i + 1 < $length ? $sql[$i + 1] : null)) {
                return true;
            }
        }

        return false;
    }
}
