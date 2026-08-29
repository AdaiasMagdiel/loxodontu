<?php

namespace App\Auth;

use AdaiasMagdiel\Erlenmeyer\Request;
use PDO;

/**
 * Resolves the end user authenticated via the `X-User-Token` header (separate
 * from the project API key in Authorization, which only gates the request at
 * the project level). Returns null for anonymous requests — RLS conditions
 * referencing $auth.* then resolve to NULL, which never matches a row.
 * Shared by REST passthrough and Storage.
 */
class EndUserAuth
{
    /** @return array{id: int, email: string, role: ?string}|null */
    public static function resolve(PDO $pdo, Request $req, mixed $projectId): ?array
    {
        $token = $req->getHeader('X-User-Token') ?? '';

        if ($token === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT u.id, u.email, u.role
             FROM project_end_user_tokens t
             JOIN project_end_users u ON u.id = t.end_user_id
             WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.project_id = ?
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), $projectId]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        return ['id' => (int) $user['id'], 'email' => $user['email'], 'role' => $user['role']];
    }
}
