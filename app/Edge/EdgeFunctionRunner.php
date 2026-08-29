<?php

namespace App\Edge;

use App\Database;
use PDO;
use Throwable;

class EdgeFunctionRunner
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array{id: int, email: string, role: ?string}|null $auth
     */
    public static function call(
        int $projectId,
        string $slug,
        string $method = 'POST',
        array $headers = [],
        array $query = [],
        array $body = [],
        ?array $auth = null,
    ): FunctionResponse {
        return (new self(Database::getConn('default')))->invoke($projectId, $slug, $method, $headers, $query, $body, $auth);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array{id: int, email: string, role: ?string}|null $auth
     */
    public function invoke(
        int $projectId,
        string $slug,
        string $method,
        array $headers = [],
        array $query = [],
        array $body = [],
        ?array $auth = null,
    ): FunctionResponse {
        $function = $this->findFunction($projectId, $slug);

        if (!$function || !(bool) $function['enabled']) {
            return FunctionResponse::json(['error' => 'Function not found'], 404);
        }

        $method = strtoupper($method);
        $methods = $function['methods'] !== null ? json_decode($function['methods'], true) : null;
        if (is_array($methods) && $methods !== [] && !in_array($method, array_map('strtoupper', $methods), true)) {
            return FunctionResponse::json(['error' => 'Method not allowed'], 405);
        }

        $target = trim((string) $function['handler']);
        if (!str_contains($target, '::')) {
            return FunctionResponse::json(['error' => 'Function handler must use ClassName::method syntax'], 500);
        }

        [$class, $handler] = explode('::', $target, 2);
        if (!is_callable([$class, $handler])) {
            return FunctionResponse::json(['error' => "Function handler {$target} is not callable"], 500);
        }

        $request = new FunctionRequest($projectId, $method, $headers, $query, $body, $function, $auth);

        try {
            $result = [$class, $handler]($request);
            $this->markInvoked((int) $function['id']);
        } catch (Throwable $e) {
            return FunctionResponse::json(['error' => $e->getMessage()], 500);
        }

        if ($result instanceof FunctionResponse) {
            return $result;
        }

        return FunctionResponse::json($result);
    }

    private function findFunction(int $projectId, string $slug): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM project_functions WHERE project_id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$projectId, $slug]);

        return $stmt->fetch();
    }

    private function markInvoked(int $id): void
    {
        $this->pdo->prepare('UPDATE project_functions SET last_invoked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }
}
