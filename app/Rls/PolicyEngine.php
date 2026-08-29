<?php

namespace App\Rls;

/**
 * Merges a flat list of RLS-style policy rows (role, operation, expression) into
 * per-operation query conditions, resolving `$auth.*` placeholders against the
 * caller's identity. Shared by REST passthrough (`project_rls_policies`) and
 * Storage (`project_storage_policies`) — same shape, different table.
 */
class PolicyEngine
{
    /**
     * @param array<int, array{role: ?string, operation: string, expression: string}> $policies
     * @param array{id: int, email: string, role: ?string}|null $auth
     * @return array<string, array|null> Per-operation conditions ('select'|'insert'|'update'|'delete').
     *   - [] (empty array): no extra restriction (operation is open).
     *   - non-empty array: column => resolved value conditions to merge into the query.
     *   - null (sentinel): the operation is denied entirely for this caller.
     */
    public static function resolve(array $policies, ?array $auth): array
    {
        // Bucket every policy under each operation it applies to ('all' fans out to all four).
        $byOperation = ['select' => [], 'insert' => [], 'update' => [], 'delete' => []];
        foreach ($policies as $policy) {
            $op  = strtolower($policy['operation']);
            $ops = $op === 'all' ? array_keys($byOperation) : [$op];

            foreach ($ops as $o) {
                if (isset($byOperation[$o])) {
                    $byOperation[$o][] = $policy;
                }
            }
        }

        // No policies at all for an operation => open (pre-RLS behavior).
        // Policies exist but none match the caller's role => deny entirely.
        // Otherwise, merge the conditions of every applicable policy; any
        // applicable policy with no conditions makes the whole operation open.
        $policyConditions = [];
        foreach ($byOperation as $op => $opPolicies) {
            if ($opPolicies === []) {
                $policyConditions[$op] = [];
                continue;
            }

            $applicable = array_filter(
                $opPolicies,
                fn(array $p): bool => $p['role'] === null || ($auth !== null && $p['role'] === $auth['role']),
            );

            if ($applicable === []) {
                $policyConditions[$op] = null; // sentinel: deny
                continue;
            }

            $merged = [];
            foreach ($applicable as $p) {
                $conditions = json_decode($p['expression'], true) ?? [];

                if ($conditions === []) {
                    $merged = [];
                    break; // an unconditional applicable policy makes this operation fully open
                }

                foreach ($conditions as $column => $value) {
                    $merged[$column] = self::resolvePlaceholder($value, $auth);
                }
            }

            $policyConditions[$op] = $merged;
        }

        return $policyConditions;
    }

    /** @param array{id: int, email: string, role: ?string}|null $auth */
    public static function resolvePlaceholder(mixed $value, ?array $auth): mixed
    {
        if (is_array($value) && isset($value['op'])) {
            if (isset($value['value'])) {
                $value['value'] = self::resolvePlaceholder($value['value'], $auth);
            }
            return $value;
        }

        if (!is_string($value) || !str_starts_with($value, '$auth.')) {
            return $value;
        }

        return $auth[substr($value, 6)] ?? null;
    }
}
