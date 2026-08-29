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

    public static function me(Request $req, Response $res, stdClass $params): Response
    {
        $u = $params->user;
        return $res->withJson(['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email']]);
    }

    public static function updateAccount(Request $req, Response $res, stdClass $params): Response
    {
        $body = $req->getJson(ignoreContentType: true) ?? [];
        $user = $params->user;
        $pdo  = Database::getConn('default');

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

        if (array_key_exists('email', $body)) {
            $email = trim($body['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $res->setStatusCode(422)->withJson(['error' => 'Invalid email']);
            }
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch()) {
                return $res->setStatusCode(409)->withJson(['error' => 'Email already in use']);
            }
            $fields[] = 'email = ?';
            $values[] = $email;
        }

        if (array_key_exists('password', $body)) {
            $currentPassword = $body['current_password'] ?? '';
            $newPassword     = $body['password'];

            $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($currentPassword, $row['password'])) {
                return $res->setStatusCode(401)->withJson(['error' => 'Current password is incorrect']);
            }
            if (strlen($newPassword) < 8) {
                return $res->setStatusCode(422)->withJson(['error' => 'Password must be at least 8 characters']);
            }
            $fields[] = 'password = ?';
            $values[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        if ($fields === []) {
            return $res->setStatusCode(422)->withJson(['error' => 'Nothing to update']);
        }

        $values[] = $user['id'];
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $updated = $stmt->fetch();

        return $res->withJson(['id' => $updated['id'], 'name' => $updated['name'], 'email' => $updated['email']]);
    }

    public static function deleteAccount(Request $req, Response $res, stdClass $params): Response
    {
        $body     = $req->getJson(ignoreContentType: true) ?? [];
        $user     = $params->user;
        $password = $body['password'] ?? '';

        $pdo  = Database::getConn('default');
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password'])) {
            return $res->setStatusCode(401)->withJson(['error' => 'Password is incorrect']);
        }

        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);

        return $res->setStatusCode(204);
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
