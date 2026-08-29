<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use stdClass;

class Projects
{
    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo  = Database::getConn('default');
        $stmt = $pdo->prepare(
            'SELECT public_id AS id, name, slug, description, created_at FROM projects
             WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$params->user['id']]);

        return $res->withJson($stmt->fetchAll());
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];

        $name        = trim($body['name'] ?? '');
        $description = trim($body['description'] ?? '') ?: null;

        if ($name === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'name is required']);
        }

        $pdo      = Database::getConn('default');
        $userId   = $params->user['id'];
        $slug     = self::uniqueSlug($pdo, $userId, self::slugify($name));
        $publicId = self::generatePublicId($pdo);

        $pdo->prepare(
            'INSERT INTO projects (public_id, user_id, name, slug, description) VALUES (?, ?, ?, ?, ?)'
        )->execute([$publicId, $userId, $name, $slug, $description]);

        return $res->setStatusCode(201)->withJson([
            'id'          => $publicId,
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
        ]);
    }

    public static function show(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwned($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, created_at FROM project_tables WHERE project_id = ? ORDER BY name'
        );
        $stmt->execute([$project['internal_id']]);
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

        unset($project['internal_id']);
        $project['tables'] = $tables;

        return $res->withJson($project);
    }

    public static function update(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];

        $pdo     = Database::getConn('default');
        $project = self::findOwned($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $fields = [];
        $values = [];

        if (array_key_exists('name', $body)) {
            $name = trim($body['name']);
            if ($name === '') {
                return $res->setStatusCode(422)->withJson(['error' => 'name cannot be empty']);
            }
            $fields[] = 'name = ?';
            $values[] = $name;
        }

        if (array_key_exists('description', $body)) {
            $fields[] = 'description = ?';
            $values[] = trim($body['description']) ?: null;
        }

        if ($fields === []) {
            return $res->setStatusCode(422)->withJson(['error' => 'Nothing to update']);
        }

        $values[] = $project['internal_id'];
        $pdo->prepare('UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

        $stmt = $pdo->prepare(
            'SELECT public_id AS id, name, slug, description, created_at FROM projects WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$project['internal_id']]);

        return $res->withJson($stmt->fetch());
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwned($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$project['internal_id']]);

        return $res->setStatusCode(204);
    }

    /**
     * Looks up a project by its public id, scoped to the owning user. Returns
     * the row shaped for API responses (id = public_id) plus 'internal_id',
     * the numeric primary key other tables actually foreign-key against.
     */
    private static function findOwned(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id AS internal_id, public_id AS id, name, slug, description, created_at FROM projects
             WHERE public_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$publicId, $userId]);
        return $stmt->fetch();
    }

    /**
     * Resolves a project's internal numeric id from its public id, without an
     * ownership check. Used by unauthenticated, project-scoped public routes
     * (edge function invocation, REST passthrough, end-user auth) so those
     * URLs never expose or accept the sequential internal id.
     */
    public static function resolveInternalId(\PDO $pdo, mixed $publicId): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? LIMIT 1');
        $stmt->execute([$publicId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private static function generatePublicId(\PDO $pdo): string
    {
        do {
            $publicId = 'prj_' . bin2hex(random_bytes(12));
            $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? LIMIT 1');
            $stmt->execute([$publicId]);
        } while ($stmt->fetch());

        return $publicId;
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

    private static function uniqueSlug(\PDO $pdo, int $userId, string $base): string
    {
        $slug   = $base;
        $suffix = 1;

        while (true) {
            $stmt = $pdo->prepare('SELECT id FROM projects WHERE user_id = ? AND slug = ? LIMIT 1');
            $stmt->execute([$userId, $slug]);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }
    }

    private static function slugify(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\w\s-]/u', '', $value);
        $value = preg_replace('/[\s_]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return trim($value, '-') ?: 'project';
    }
}
