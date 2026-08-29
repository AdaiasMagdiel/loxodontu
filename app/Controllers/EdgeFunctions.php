<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use App\Database;
use App\Edge\EdgeFunctionRunner;
use App\Pagination;
use PDO;
use stdClass;

class EdgeFunctions
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public static function index(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        ['limit' => $limit, 'offset' => $offset] = Pagination::fromQuery($req->getQueryParams());

        $count = $pdo->prepare('SELECT COUNT(*) FROM project_functions WHERE project_id = ?');
        $count->execute([$project['id']]);

        $stmt = $pdo->prepare(
            "SELECT * FROM project_functions WHERE project_id = ? ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$project['id']]);

        return $res
            ->setHeader('X-Total-Count', (string) (int) $count->fetchColumn())
            ->setHeader('X-Page-Limit', (string) $limit)
            ->setHeader('X-Page-Offset', (string) $offset)
            ->withJson(array_map([self::class, 'normalize'], $stmt->fetchAll()));
    }

    public static function store(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $body = $req->getJson(ignoreContentType: true) ?? [];
        $error = self::validate($body);
        if ($error) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        $slug = self::slug($body['slug']);
        $methods = self::methods($body['methods'] ?? []);

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO project_functions
                 (project_id, slug, name, description, handler, source_code, methods, require_api_key, enabled, timeout_seconds, memory_limit_mb)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $project['id'],
                $slug,
                trim($body['name']),
                trim($body['description'] ?? '') ?: null,
                isset($body['handler']) ? trim((string) $body['handler']) : null,
                (string) $body['source_code'],
                $methods !== [] ? json_encode($methods) : null,
                isset($body['require_api_key']) ? (int) (bool) $body['require_api_key'] : 1,
                isset($body['enabled']) ? (int) (bool) $body['enabled'] : 1,
                isset($body['timeout_seconds']) ? (int) $body['timeout_seconds'] : 10,
                isset($body['memory_limit_mb']) ? (int) $body['memory_limit_mb'] : 32,
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return $res->setStatusCode(409)->withJson(['error' => 'Function slug already exists in this project']);
            }

            throw $e;
        }

        return $res->setStatusCode(201)->withJson(self::findFunction($pdo, (int) $pdo->lastInsertId()));
    }

    public static function show(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $function = self::findOwnedFunction($pdo, $params->function_id, (int) $project['id']);
        if (!$function) {
            return $res->setStatusCode(404)->withJson(['error' => 'Function not found']);
        }

        return $res->withJson(self::normalize($function));
    }

    public static function update(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $function = self::findOwnedFunction($pdo, $params->function_id, (int) $project['id']);
        if (!$function) {
            return $res->setStatusCode(404)->withJson(['error' => 'Function not found']);
        }

        $body = $req->getJson(ignoreContentType: true) ?? [];
        if ($body === []) {
            return $res->setStatusCode(422)->withJson(['error' => 'Nothing to update']);
        }

        $candidate = array_merge($function, $body);
        $error = self::validate($candidate);
        if ($error) {
            return $res->setStatusCode(422)->withJson(['error' => $error]);
        }

        $fields = [];
        $values = [];
        foreach (['slug', 'name', 'description', 'handler', 'source_code', 'methods', 'require_api_key', 'enabled', 'timeout_seconds', 'memory_limit_mb'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }

            $fields[] = "{$field} = ?";
            $values[] = match ($field) {
                'slug' => self::slug($body[$field]),
                'description' => trim((string) $body[$field]) ?: null,
                'methods' => self::methods($body[$field]) !== [] ? json_encode(self::methods($body[$field])) : null,
                'require_api_key', 'enabled' => (int) (bool) $body[$field],
                'timeout_seconds' => (int) $body[$field],
                'memory_limit_mb' => (int) $body[$field],
                default => trim((string) $body[$field]),
            };
        }

        if ($fields === []) {
            return $res->setStatusCode(422)->withJson(['error' => 'Nothing to update']);
        }

        try {
            $values[] = $function['id'];
            $pdo->prepare('UPDATE project_functions SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return $res->setStatusCode(409)->withJson(['error' => 'Function slug already exists in this project']);
            }

            throw $e;
        }

        return $res->withJson(self::findFunction($pdo, (int) $function['id']));
    }

    public static function destroy(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $project = self::findOwnedProject($pdo, $params->project_id, $params->user['id']);
        if (!$project) {
            return $res->setStatusCode(404)->withJson(['error' => 'Project not found']);
        }

        $stmt = $pdo->prepare('DELETE FROM project_functions WHERE id = ? AND project_id = ?');
        $stmt->execute([$params->function_id, $project['id']]);

        return $stmt->rowCount() === 1
            ? $res->setStatusCode(204)
            : $res->setStatusCode(404)->withJson(['error' => 'Function not found']);
    }

    public static function invoke(Request $req, Response $res, stdClass $params): Response
    {
        $pdo = Database::getConn('default');
        $function = self::findBySlug($pdo, $params->project_id, $params->slug);
        if (!$function || !(bool) $function['enabled']) {
            return $res->setStatusCode(404)->withJson(['error' => 'Function not found']);
        }

        $auth = null;
        if ((bool) $function['require_api_key']) {
            $token = self::extractToken($req);
            if ($token === null || !self::tokenCanInvoke($pdo, (int) $params->project_id, $token)) {
                return $res->setStatusCode(401)->withJson(['error' => 'Unauthorized']);
            }
            $auth = self::resolveAuth($pdo, $req, $params->project_id);
        }

        try {
            $body = $req->getJson(ignoreContentType: true) ?? [];
        } catch (\RuntimeException) {
            $body = [];
        }

        $headers = [
            'authorization' => $req->getHeader('Authorization') ?? '',
            'x-user-token' => $req->getHeader('X-User-Token') ?? '',
        ];

        $result = (new EdgeFunctionRunner($pdo))->invoke(
            (int) $params->project_id,
            (string) $params->slug,
            $req->getMethod(),
            $headers,
            $req->getQueryParams(),
            $body,
            $auth,
        );

        $response = $res->setStatusCode($result->status);
        foreach ($result->headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response->withJson($result->body);
    }

    /** @param array<string, mixed> $body */
    private static function validate(array $body): ?string
    {
        if (self::slug($body['slug'] ?? '') === '') {
            return 'slug must contain only letters, numbers, dashes, and underscores';
        }

        if (trim($body['name'] ?? '') === '') {
            return 'name is required';
        }

        $hasSource = trim((string) ($body['source_code'] ?? '')) !== '';
        $hasHandler = trim((string) ($body['handler'] ?? '')) !== '';

        if (!$hasSource && !$hasHandler) {
            return 'source_code is required';
        }

        if ($hasHandler && !str_contains((string) $body['handler'], '::')) {
            return 'handler must use ClassName::method syntax';
        }

        if ($hasSource && !str_starts_with(ltrim((string) $body['source_code']), '<?php')) {
            return 'source_code must be a PHP file starting with <?php';
        }

        if (array_key_exists('methods', $body) && !is_array($body['methods']) && !is_string($body['methods'])) {
            return 'methods must be an array';
        }

        foreach (self::methods($body['methods'] ?? []) as $method) {
            if (!in_array($method, self::METHODS, true)) {
                return 'methods must contain only: ' . implode(', ', self::METHODS);
            }
        }

        if (isset($body['timeout_seconds']) && ((int) $body['timeout_seconds'] < 1 || (int) $body['timeout_seconds'] > 60)) {
            return 'timeout_seconds must be between 1 and 60';
        }

        if (isset($body['memory_limit_mb']) && ((int) $body['memory_limit_mb'] < 16 || (int) $body['memory_limit_mb'] > 256)) {
            return 'memory_limit_mb must be between 16 and 256';
        }

        return null;
    }

    private static function findOwnedProject(PDO $pdo, mixed $id, int $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);

        return $stmt->fetch();
    }

    private static function findFunction(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM project_functions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return self::normalize($stmt->fetch());
    }

    private static function findOwnedFunction(PDO $pdo, mixed $id, int $projectId): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM project_functions WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$id, $projectId]);

        return $stmt->fetch();
    }

    private static function findBySlug(PDO $pdo, mixed $projectId, mixed $slug): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM project_functions WHERE project_id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$projectId, $slug]);

        return $stmt->fetch();
    }

    private static function tokenCanInvoke(PDO $pdo, int $projectId, string $token): bool
    {
        return self::apiKeyCanInvoke($pdo, $projectId, $token) || self::platformTokenOwnsProject($pdo, $projectId, $token);
    }

    private static function apiKeyCanInvoke(PDO $pdo, int $projectId, string $token): bool
    {
        $stmt = $pdo->prepare(
            'SELECT key_hash, permissions FROM project_api_keys
             WHERE key_prefix = ? AND project_id = ?
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([substr($token, 0, 8), $projectId]);
        $apiKey = $stmt->fetch();

        if (!$apiKey || !hash_equals($apiKey['key_hash'], hash('sha256', $token))) {
            return false;
        }

        $permissions = json_decode($apiKey['permissions'], true) ?? [];

        return in_array('function', $permissions, true);
    }

    private static function platformTokenOwnsProject(PDO $pdo, int $projectId, string $token): bool
    {
        $stmt = $pdo->prepare(
            'SELECT p.id
             FROM platform_auth_tokens t
             JOIN projects p ON p.user_id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > NOW() AND p.id = ?
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), $projectId]);

        return (bool) $stmt->fetch();
    }

    private static function extractToken(Request $req): ?string
    {
        $header = $req->getHeader('Authorization') ?? '';

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    /** @return array{id: int, email: string, role: ?string}|null */
    private static function resolveAuth(PDO $pdo, Request $req, mixed $projectId): ?array
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

        return $user ? ['id' => (int) $user['id'], 'email' => $user['email'], 'role' => $user['role']] : null;
    }

    /** @param array<string, mixed> $function */
    private static function normalize(array $function): array
    {
        $function['id'] = (int) $function['id'];
        $function['project_id'] = (int) $function['project_id'];
        $function['methods'] = $function['methods'] !== null ? json_decode($function['methods'], true) : [];
        $function['require_api_key'] = (bool) $function['require_api_key'];
        $function['enabled'] = (bool) $function['enabled'];
        if (array_key_exists('timeout_seconds', $function)) {
            $function['timeout_seconds'] = (int) $function['timeout_seconds'];
        }
        if (array_key_exists('memory_limit_mb', $function)) {
            $function['memory_limit_mb'] = (int) $function['memory_limit_mb'];
        }

        return $function;
    }

    private static function slug(mixed $value): string
    {
        $slug = trim((string) $value);

        return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $slug) ? $slug : '';
    }

    /** @return string[] */
    private static function methods(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(fn($method): string => strtoupper((string) $method), $value)));
    }
}
