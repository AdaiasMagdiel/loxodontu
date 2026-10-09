<?php

namespace App\Edge;

/**
 * Fluent query builder for `$request->db->table(...)`, translated into the
 * same `column=operator.value` filter syntax pdo-restify's REST layer
 * accepts (e.g. `where('status', 'eq', 'done')` -> `status=eq.done`).
 */
class DbQuery
{
    /** @var array<string, string|list<string>> */
    private array $query = [];
    private string|int|null $id = null;

    /** @var list<string> Legs of the single OR group pdo-restify's `or=` param supports, added via orWhere(). */
    private array $orClauses = [];

    public function __construct(private readonly Db $db, private readonly string $table) {}

    public function select(string $columns = '*'): static
    {
        // pdo-restify's `select=` filter has no wildcard token of its own —
        // omitting the param entirely is how it means "every allowed column".
        if ($columns === '*') {
            unset($this->query['select']);
        } else {
            $this->query['select'] = $columns;
        }

        return $this;
    }

    /**
     * Adds a filter, AND'ed with every other one. Calling this more than
     * once for the same column adds an additional condition on it rather
     * than replacing the previous one — e.g. a range query:
     * `where('age', 'gte', 18)->where('age', 'lte', 65)`.
     */
    public function where(string $column, string $operator, mixed $value): static
    {
        $clause = $this->buildClause($operator, $value);

        if (!isset($this->query[$column])) {
            $this->query[$column] = $clause;
        } elseif (is_array($this->query[$column])) {
            $this->query[$column][] = $clause;
        } else {
            $this->query[$column] = [$this->query[$column], $clause];
        }

        return $this;
    }

    /**
     * Adds one leg of an OR group — every `orWhere()` call on this query
     * joins the same group, e.g. `orWhere('status', 'eq', 'draft')
     * ->orWhere('status', 'eq', 'pending')` matches rows where status is
     * 'draft' OR 'pending', itself AND'ed with every `where()` filter and
     * the table's RLS policy. Only one OR group per query is supported.
     */
    public function orWhere(string $column, string $operator, mixed $value): static
    {
        $this->orClauses[] = $column . '.' . $this->buildClause($operator, $value);

        return $this;
    }

    private function buildClause(string $operator, mixed $value): string
    {
        return $operator . '.' . (is_array($value)
            ? implode(',', array_map([$this, 'escapeListItem'], array_map('strval', $value)))
            : (string) $value);
    }

    /** Escapes `\` and `,` so a literal comma inside an in()/not_in() value survives pdo-restify's list splitting. */
    private function escapeListItem(string $value): string
    {
        return str_replace(['\\', ','], ['\\\\', '\,'], $value);
    }

    public function order(string $column, string $direction = 'asc'): static
    {
        $clause = $column . '.' . $direction;
        $this->query['order'] = isset($this->query['order']) ? $this->query['order'] . ',' . $clause : $clause;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->query['limit'] = (string) $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->query['offset'] = (string) $offset;

        return $this;
    }

    public function id(string|int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function get(): DbResult
    {
        return $this->db->send('GET', $this->path(), $this->buildQuery(), []);
    }

    /** Runs get() and returns the first row, or null if none matched. */
    public function first(): ?array
    {
        $result = $this->id !== null
            ? $this->db->send('GET', $this->path(), [], [])
            : $this->get();

        if (!$result->ok) {
            return null;
        }

        if ($this->id !== null) {
            return is_array($result->body) ? $result->body : null;
        }

        return is_array($result->body) ? ($result->body[0] ?? null) : null;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): DbResult
    {
        return $this->db->send('POST', $this->table, [], $data);
    }

    /**
     * Inserts every row in one request, in a single transaction — if any
     * row fails, none of them are persisted.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertMany(array $rows): DbResult
    {
        return $this->db->send('POST', $this->table, [], array_values($rows));
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): DbResult
    {
        return $this->db->send('PATCH', $this->path(), $this->buildQuery(), $data);
    }

    /**
     * Updates every row in one request, in a single transaction. Each row
     * must include the table's primary key column identifying which row it
     * updates.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function updateMany(array $rows): DbResult
    {
        return $this->db->send('PATCH', $this->table, [], array_values($rows));
    }

    public function delete(): DbResult
    {
        return $this->db->send('DELETE', $this->path(), $this->buildQuery(), []);
    }

    /** @return array<string, mixed> */
    private function buildQuery(): array
    {
        $query = $this->query;

        if ($this->orClauses !== []) {
            $query['or'] = '(' . implode(',', $this->orClauses) . ')';
        }

        return $query;
    }

    private function path(): string
    {
        return $this->id !== null ? $this->table . '/' . $this->id : $this->table;
    }
}
