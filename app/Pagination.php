<?php

namespace App;

/**
 * Parses `limit`/`offset` query params the same way pdo-restify's REST
 * passthrough does, so every list endpoint in the API — management or
 * passthrough — paginates the same way.
 */
class Pagination
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT     = 100;

    /** @param array<string, mixed> $query @return array{limit: int, offset: int} */
    public static function fromQuery(array $query): array
    {
        $limit = isset($query['limit']) ? (int) $query['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;
        $offset = max(0, $offset);

        return ['limit' => $limit, 'offset' => $offset];
    }
}
