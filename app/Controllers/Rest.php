<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request as ErlRequest;
use AdaiasMagdiel\Erlenmeyer\Response as ErlResponse;
use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Http\Request as RestRequest;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Resource;
use App\Auth\EndUserAuth;
use App\Database;
use App\Rls\PolicyEngine;
use stdClass;

class Rest
{
    private const OPERATION_MAP = [
        'GET'    => Operation::Select,
        'POST'   => Operation::Insert,
        'PATCH'  => Operation::Update,
        'PUT'    => Operation::Update,
        'DELETE' => Operation::Delete,
    ];

    public static function dispatch(ErlRequest $req, ErlResponse $res, stdClass $params): ErlResponse
    {
        $method    = $req->getMethod();
        $operation = self::OPERATION_MAP[$method] ?? null;

        if ($operation === null) {
            return $res->setStatusCode(405)->withJson(['error' => 'Method not allowed']);
        }

        $token = self::extractToken($req);
        if ($token === null) {
            return $res->setStatusCode(401)->withJson(['error' => 'Unauthorized']);
        }

        $pdo       = Database::getConn('default');
        $projectId = Projects::resolveInternalId($pdo, $params->project_id);
        if ($projectId === null) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }
        $prefix    = substr($token, 0, 8);

        $stmt = $pdo->prepare(
            'SELECT key_hash, permissions FROM project_api_keys
             WHERE key_prefix = ? AND project_id = ?
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([$prefix, $projectId]);
        $apiKey = $stmt->fetch();

        if (!$apiKey || !hash_equals($apiKey['key_hash'], hash('sha256', $token))) {
            return $res->setStatusCode(401)->withJson(['error' => 'Unauthorized']);
        }

        $permissions = json_decode($apiKey['permissions'], true) ?? [];
        if (!in_array($operation->value, $permissions, true)) {
            return $res->setStatusCode(403)->withJson(['error' => 'Forbidden']);
        }

        $table = $params->table;

        $auth = EndUserAuth::resolve($pdo, $req, $projectId);

        $scoped = self::scopedApi($pdo, (int) $projectId, $table, $auth);
        if ($scoped === null) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }

        $api  = $scoped['api'];
        $path = $scoped['physicalTable'] . (isset($params->id) ? '/' . $params->id : '');

        try {
            $body = $req->getJson() ?? [];
        } catch (\RuntimeException) {
            $body = [];
        }

        $restRequest = new RestRequest(
            $method,
            $path,
            $req->getQueryParams(),
            $body,
        );

        $restResponse = $api->handle($restRequest);

        $erlResponse = $res->setStatusCode($restResponse->status);

        foreach ($restResponse->headers as $name => $value) {
            $erlResponse->setHeader($name, $value);
        }

        if ($restResponse->body !== null) {
            return $erlResponse->withJson($restResponse->body);
        }

        return $erlResponse;
    }

    private static function extractToken(ErlRequest $req): ?string
    {
        $header = $req->getHeader('Authorization') ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }

    /**
     * Builds a pdo-restify Api scoped to a single logical table of one
     * project, with RLS policies resolved and applied exactly as REST
     * passthrough enforces them. This is the one place that maps a
     * project-scoped logical table name to its physical table and its
     * access rules — REST passthrough and the edge-function database
     * bridge (App\Edge\EdgeFunctionRunner) both go through here, so neither
     * can drift from the other's isolation guarantees.
     *
     * @param array{id: int, email: string, role: ?string}|null $auth
     * @return array{api: Api, physicalTable: string}|null Null if the table doesn't exist for this project.
     */
    public static function scopedApi(\PDO $pdo, int $projectId, string $table, ?array $auth): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM project_tables WHERE project_id = ? AND name = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $table]);
        $projectTable = $stmt->fetch();

        if (!$projectTable) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT name FROM project_columns
             WHERE table_id = ?
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$projectTable['id']]);
        $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!in_array('id', $columns, true)) {
            array_unshift($columns, 'id');
        }

        $stmt = $pdo->prepare(
            'SELECT operation, expression FROM project_rls_policies
             WHERE table_id = ? AND enabled = 1'
        );
        $stmt->execute([$projectTable['id']]);
        $rlsPolicies = $stmt->fetchAll();

        $policyConditions = PolicyEngine::resolve($rlsPolicies, $auth);

        $physicalTable = Tables::physicalName($projectId, $table);
        $resource      = (new Resource($physicalTable))->columns($columns);

        // Every operation is allow()-ed regardless of which one this request is
        // for — pdo-restify needs e.g. the select policy internally to echo back
        // an inserted/updated row. The caller's own permission gate (API key
        // permissions for REST, nothing extra for edge functions) already
        // restricts which operation *this* request may invoke; a null
        // condition here means no RLS policies exist for that operation (fully
        // open), not a denial — a policy that does exist but never matches this
        // caller/row simply filters everything out via ordinary SQL, with no
        // separate "deny" case to special-case.
        foreach (Operation::cases() as $op) {
            $condition = $policyConditions[$op->value];
            $resource->allow($op, fn(array $ctx) => $condition);
        }

        return ['api' => (new Api($pdo))->register($resource), 'physicalTable' => $physicalTable];
    }

}
