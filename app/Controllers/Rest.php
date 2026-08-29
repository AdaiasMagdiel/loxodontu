<?php

namespace App\Controllers;

use AdaiasMagdiel\Erlenmeyer\Request as ErlRequest;
use AdaiasMagdiel\Erlenmeyer\Response as ErlResponse;
use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Http\Request as RestRequest;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\QueryBuilder;
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

        $stmt = $pdo->prepare(
            'SELECT id FROM project_tables WHERE project_id = ? AND name = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $table]);
        $projectTable = $stmt->fetch();

        if (!$projectTable) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
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

        $auth = EndUserAuth::resolve($pdo, $req, $projectId);

        $stmt = $pdo->prepare(
            'SELECT role, operation, expression FROM project_rls_policies
             WHERE table_id = ? AND enabled = 1'
        );
        $stmt->execute([$projectTable['id']]);
        $rlsPolicies = $stmt->fetchAll();

        $policyConditions = PolicyEngine::resolve($rlsPolicies, $auth);

        $physicalTable = Tables::physicalName((int) $projectId, $table);
        $resource      = (new Resource($physicalTable))->columns($columns);

        foreach (Operation::cases() as $op) {
            $frozen = $policyConditions[$op->value];

            if ($frozen === null) {
                continue; // not allow()-ed => pdo-restify denies with 403 if the client attempts it
            }

            $resource->allow($op, fn(array $ctx) => $frozen);
        }

        // pdo-restify's update()/delete() re-fetch the row via the SELECT policy to build
        // their response/404, not the UPDATE/DELETE policy — so when SELECT is public but
        // UPDATE/DELETE is owner-scoped (a very normal RLS setup), a write blocked by RLS
        // still comes back 200 with the untouched row instead of 404. Pre-check visibility
        // under the actual write policy ourselves so a denied write fails honestly.
        if (in_array($method, ['PATCH', 'PUT', 'DELETE'], true) && isset($params->id)) {
            $writeOp         = $method === 'DELETE' ? 'delete' : 'update';
            $writeConditions = $policyConditions[$writeOp];

            if ($writeConditions !== null) {
                $checkConditions = $writeConditions;
                $checkConditions['id'] = $params->id;

                [$checkSql, $checkParams] = QueryBuilder::select($physicalTable, ['id'], [], $checkConditions, null, 1, 0);
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute($checkParams);

                if ($checkStmt->fetch() === false) {
                    return $res->setStatusCode(404)->withJson([
                        'error' => "Resource '{$table}' with id '{$params->id}' not found",
                    ]);
                }
            }
        }

        $api  = (new Api($pdo))->register($resource);
        $path = $physicalTable . (isset($params->id) ? '/' . $params->id : '');

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

}
