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
            'SELECT id, name, slug, description, created_at FROM projects
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

        $pdo    = Database::getConn('default');
        $userId = $params->user['id'];
        $slug   = self::uniqueSlug($pdo, $userId, self::slugify($name));

        $pdo->prepare(
            'INSERT INTO projects (user_id, name, slug, description) VALUES (?, ?, ?, ?)'
        )->execute([$userId, $name, $slug, $description]);

        $id = (int) $pdo->lastInsertId();

        return $res->setStatusCode(201)->withJson([
            'id'          => $id,
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

        $values[] = $project['id'];
        $pdo->prepare('UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

        $stmt = $pdo->prepare(
            'SELECT id, name, slug, description, created_at FROM projects WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$project['id']]);

        return $res->withJson($stmt->fetch());
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwned($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$project['id']]);

        return $res->setStatusCode(204);
    }

    private static function findOwned(\PDO $pdo, mixed $id, int $userId): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id, name, slug, description, created_at FROM projects
             WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->fetch();
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
