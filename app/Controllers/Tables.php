<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use stdClass;

class Tables
{
    private const VALID_TYPES = ['text', 'integer', 'decimal', 'boolean', 'timestamp', 'json'];

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

        $stmt = $pdo->prepare('SELECT id FROM project_tables WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$params->table_id, $project['id']]);
        $table = $stmt->fetch();

        if (!$table) {
            return $res->setStatusCode(404)->withJson(['error' => 'Table not found']);
        }

        $pdo->prepare('DELETE FROM project_tables WHERE id = ?')->execute([$table['id']]);

        return $res->setStatusCode(204);
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
