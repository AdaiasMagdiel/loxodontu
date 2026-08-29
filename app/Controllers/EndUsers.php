<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Pagination;
use stdClass;

/**
 * End users are the accounts of a *project's own app* (the developer's
 * users), completely separate from platform users in `users` /
 * `platform_auth_tokens`. Their token authenticates REST passthrough
 * requests and feeds `$auth.id` / `$auth.email` / `$auth.role` into RLS
 * policy expressions.
 */
class EndUsers
{
    public static function register(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if ($email === '' || $password === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'email and password are required']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $res->setStatusCode(422)->withJson(['error' => 'Invalid email']);
        }

        if (strlen($password) < 8) {
            return $res->setStatusCode(422)->withJson(['error' => 'Password must be at least 8 characters']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_end_users WHERE project_id = ? AND email = ? LIMIT 1');
        $stmt->execute([$project['id'], $email]);

        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'Email already registered']);
        }

        // Role is never client-assignable at registration — only a project owner
        // can grant elevated roles, via the management endpoint below.
        $pdo->prepare('INSERT INTO project_end_users (project_id, email, password) VALUES (?, ?, ?)')->execute([
            $project['id'],
            $email,
            password_hash($password, PASSWORD_DEFAULT),
        ]);

        $userId = (int) $pdo->lastInsertId();
        $token  = self::issueToken($pdo, $userId);

        return $res->setStatusCode(201)->withJson([
            'token' => $token,
            'user'  => ['id' => $userId, 'email' => $email, 'role' => null],
        ]);
    }

    public static function login(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $pdo  = Database::getConn('default');

        $project = self::findProject($pdo, $params->project_id);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if ($email === '' || $password === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'email and password are required']);
        }

        $stmt = $pdo->prepare(
            'SELECT id, email, password, role FROM project_end_users WHERE project_id = ? AND email = ? LIMIT 1'
        );
        $stmt->execute([$project['id'], $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return $res->setStatusCode(401)->withJson(['error' => 'Invalid credentials']);
        }

        $token = self::issueToken($pdo, $user['id']);

        return $res->withJson([
            'token' => $token,
            'user'  => ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']],
        ]);
    }

    public static function logout(Request $req, Response $res, stdClass $params): Response
    {
        $hash = hash('sha256', substr($req->getHeader('Authorization') ?? '', 7));
        Database::getConn('default')->prepare('DELETE FROM project_end_user_tokens WHERE token_hash = ?')->execute([$hash]);

        return $res->setStatusCode(204);
    }

    /** Lists a project's end users. Platform-owner only. */
    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM project_end_users WHERE project_id = ?');
        $countStmt->execute([$project['id']]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, email, role, created_at FROM project_end_users WHERE project_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$project['id']]);

        return $res
            ->setHeader('X-Total-Count', (string) $total)
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson($stmt->fetchAll());
    }

    /** Sets an end user's role (e.g. "manager", "admin"). Platform-owner only. */
    public static function updateRole(Request $req, Response $res, stdClass $params): Response
    {
        $body    = $req->getJson(ignoreContentType: true) ?? [];
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_end_users WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$params->end_user_id, $project['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            return $res->setStatusCode(404)->withJson(['error' => 'End user not found']);
        }

        $role = array_key_exists('role', $body) ? $body['role'] : false;
        if ($role === false) {
            return $res->setStatusCode(422)->withJson(['error' => 'role is required (use null to clear it)']);
        }

        if ($role !== null && (!is_string($role) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $role))) {
            return $res->setStatusCode(422)->withJson(['error' => 'role must be null or an alphanumeric string (max 64 chars)']);
        }

        $pdo->prepare('UPDATE project_end_users SET role = ? WHERE id = ?')->execute([$role, $user['id']]);

        return $res->withJson(['id' => (int) $user['id'], 'role' => $role]);
    }

    /** Removes an end user. Platform-owner only. */
    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo     = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);

        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('SELECT id FROM project_end_users WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$params->end_user_id, $project['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            return $res->setStatusCode(404)->withJson(['error' => 'End user not found']);
        }

        $pdo->prepare('DELETE FROM project_end_users WHERE id = ?')->execute([$user['id']]);

        return $res->setStatusCode(204);
    }

    private static function issueToken(\PDO $pdo, int $endUserId): string
    {
        $token = bin2hex(random_bytes(32));

        $pdo->prepare(
            'INSERT INTO project_end_user_tokens (end_user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        )->execute([$endUserId, hash('sha256', $token), date('Y-m-d H:i:s', strtotime('+30 days'))]);

        return $token;
    }

    private static function findProject(\PDO $pdo, mixed $publicId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? LIMIT 1');
        $stmt->execute([$publicId]);
        return $stmt->fetch();
    }

    private static function findOwnedProject(\PDO $pdo, mixed $publicId, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$publicId, $userId]);
        return $stmt->fetch();
    }
}
