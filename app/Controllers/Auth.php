<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use stdClass;

class Auth
{
    public static function register(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];

        $name     = trim($body['name'] ?? '');
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'name, email and password are required']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $res->setStatusCode(422)->withJson(['error' => 'Invalid email']);
        }

        if (strlen($password) < 8) {
            return $res->setStatusCode(422)->withJson(['error' => 'Password must be at least 8 characters']);
        }

        $pdo  = Database::getConn('default');
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            return $res->setStatusCode(409)->withJson(['error' => 'Email already registered']);
        }

        $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)')->execute([
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
        ]);

        $userId = (int) $pdo->lastInsertId();
        $token  = self::issueToken($pdo, $userId);

        return $res->setStatusCode(201)->withJson([
            'token' => $token,
            'user'  => ['id' => $userId, 'name' => $name, 'email' => $email],
        ]);
    }

    public static function login(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];

        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if ($email === '' || $password === '') {
            return $res->setStatusCode(422)->withJson(['error' => 'email and password are required']);
        }

        $pdo  = Database::getConn('default');
        $stmt = $pdo->prepare('SELECT id, name, email, password FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return $res->setStatusCode(401)->withJson(['error' => 'Invalid credentials']);
        }

        $token = self::issueToken($pdo, $user['id']);

        return $res->withJson([
            'token' => $token,
            'user'  => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ]);
    }

    public static function logout(Request $req, Response $res, stdClass $params): Response
    {
        $hash = hash('sha256', substr($req->getHeader('Authorization') ?? '', 7));
        Database::getConn('default')->prepare('DELETE FROM platform_auth_tokens WHERE token_hash = ?')->execute([$hash]);

        return $res->setStatusCode(204);
    }

    private static function issueToken(\PDO $pdo, int $userId): string
    {
        $token = bin2hex(random_bytes(32));

        $pdo->prepare(
            'INSERT INTO platform_auth_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        )->execute([$userId, hash('sha256', $token), date('Y-m-d H:i:s', strtotime('+30 days'))]);

        return $token;
    }
}
