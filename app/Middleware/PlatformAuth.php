<?php

namespace App\Middleware;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;

class PlatformAuth
{
    public static function handle(Request $req, Response $res, callable $next, mixed $params): void
    {
        $header = $req->getHeader('Authorization') ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            $res->setStatusCode(401)->withJson(['error' => 'Unauthorized']);
            return;
        }

        $hash = hash('sha256', substr($header, 7));
        $pdo  = Database::getConn('default');

        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email
             FROM platform_auth_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $user = $stmt->fetch();

        if (!$user) {
            $res->setStatusCode(401)->withJson(['error' => 'Unauthorized']);
            return;
        }

        $params->user = $user;
        $next($req, $res, $params);
    }
}
