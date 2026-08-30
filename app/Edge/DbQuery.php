<?php

namespace App\Edge;

/**
 * Fluent query builder for `$request->db->table(...)`, translated into the
 * same `column=operator.value` filter syntax pdo-restify's REST layer
 * accepts (e.g. `where('status', 'eq', 'done')` -> `status=eq.done`).
 */
class DbQuery
{
    /** @var array<string, string> */
    private array $query = [];
    private string|int|null $id = null;

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

    public function where(string $column, string $operator, mixed $value): static
    {
        $this->query[$column] = $operator . '.' . (is_array($value) ? implode(',', $value) : (string) $value);

        return $this;
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
        return $this->db->send('GET', $this->path(), $this->query, []);
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

    /** @param array<string, mixed> $data */
    public function update(array $data): DbResult
    {
        return $this->db->send('PATCH', $this->path(), $this->query, $data);
    }

    public function delete(): DbResult
    {
        return $this->db->send('DELETE', $this->path(), $this->query, []);
    }

    private function path(): string
    {
        return $this->id !== null ? $this->table . '/' . $this->id : $this->table;
    }
}
