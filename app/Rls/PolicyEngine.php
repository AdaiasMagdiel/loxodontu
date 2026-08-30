<?php

namespace App\Rls;

use AdaiasMagdiel\PdoRestify\RawCondition;

/**
 * Merges a flat list of RLS-style policy rows (operation, raw SQL
 * expression) into one `?RawCondition` per operation, substituting
 * `$auth.*` tokens with uniquely-named bound parameters. Shared by REST
 * passthrough (`project_rls_policies`) and Storage (`project_storage_policies`)
 * — same shape, different table.
 *
 * Multiple enabled policies for the same operation are OR'd together
 * (Postgres's permissive-policy semantics): `(policy1) OR (policy2) OR ...`.
 * No policies at all for an operation means fully open (`null`) — the same
 * "pre-RLS" default as before. There is no explicit "deny" sentinel anymore:
 * an anonymous caller's `$auth.id`/`email`/`role` bind to `NULL`, and
 * `column = NULL` is never true in SQL, so ownership-style policies exclude
 * anonymous callers for free, without special-casing it here.
 */
class PolicyEngine
{
    private const PLACEHOLDERS = ['id', 'email', 'role'];

    /**
     * @param array<int, array{operation: string, expression: string}> $policies
     * @param array{id: int, email: string, role: ?string}|null $auth
     * @return array<string, ?RawCondition> Keyed by 'select'|'insert'|'update'|'delete'.
     */
    public static function resolve(array $policies, ?array $auth): array
    {
        $byOperation = ['select' => [], 'insert' => [], 'update' => [], 'delete' => []];

        foreach ($policies as $policy) {
            $op = strtolower($policy['operation']);
            $ops = $op === 'all' ? array_keys($byOperation) : [$op];

            foreach ($ops as $o) {
                if (isset($byOperation[$o])) {
                    $byOperation[$o][] = $policy;
                }
            }
        }

        $conditions = [];

        foreach ($byOperation as $op => $opPolicies) {
            if ($opPolicies === []) {
                $conditions[$op] = null;
                continue;
            }

            $clauses = [];
            $params = [];

            foreach ($opPolicies as $i => $policy) {
                [$sql, $policyParams] = self::bindPlaceholders($policy['expression'], $auth, $i);
                $clauses[] = "({$sql})";
                $params += $policyParams;
            }

            $conditions[$op] = new RawCondition(implode(' OR ', $clauses), $params);
        }

        return $conditions;
    }

    /**
     * Replaces every `$auth.id` / `$auth.email` / `$auth.role` occurrence in
     * $expression with a uniquely-named bound parameter (unique per policy
     * index and per occurrence, so OR-combining policies — or a token
     * appearing twice in one expression — never collides, regardless of the
     * database driver's support for repeated named placeholders).
     *
     * @param array{id: int, email: string, role: ?string}|null $auth
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function bindPlaceholders(string $expression, ?array $auth, int $policyIndex): array
    {
        $params = [];
        $n = 0;

        $sql = preg_replace_callback(
            '/\$auth\.(id|email|role)/',
            function (array $m) use ($auth, $policyIndex, &$n, &$params): string {
                $placeholder = ":rls{$policyIndex}_{$m[1]}_{$n}";
                $params[$placeholder] = $auth[$m[1]] ?? null;
                $n++;

                return $placeholder;
            },
            $expression,
        );

        return [$sql, $params];
    }
}
