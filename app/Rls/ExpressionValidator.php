<?php

namespace App\Rls;

/**
 * Validates an RLS/storage policy expression against a restricted SQL
 * grammar before it is ever interpolated into a query.
 *
 * Policy expressions are written by tenants and run against the shared
 * database, so this *is* a security boundary: an expression may only
 * reference the columns of its own table, the `$auth.*` placeholders,
 * literals, operators, a fixed set of keywords and an allowlist of pure
 * scalar functions. Anything that could reach another table (subqueries,
 * `TABLE x`, qualified names), read server state (`@@vars`, `USER()`,
 * `LOAD_FILE()`) or stall the database (`SLEEP()`, `BENCHMARK()`) is
 * rejected because it is simply not in the allowlist.
 */
class ExpressionValidator
{
    public const MAX_LENGTH = 10000;
    public const PLACEHOLDERS = ['id', 'email', 'role'];

    private const KEYWORDS = [
        'AND', 'OR', 'NOT', 'XOR', 'IS', 'NULL', 'TRUE', 'FALSE', 'UNKNOWN',
        'IN', 'LIKE', 'ESCAPE', 'BETWEEN', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
        'DIV', 'MOD', 'INTERVAL',
        'MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR',
        // Niladic date/time keywords, valid without parentheses.
        'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'UTC_DATE', 'UTC_TIME', 'UTC_TIMESTAMP',
    ];

    private const FUNCTIONS = [
        'LOWER', 'UPPER', 'LCASE', 'UCASE', 'TRIM', 'LTRIM', 'RTRIM', 'LENGTH', 'CHAR_LENGTH',
        'CONCAT', 'CONCAT_WS', 'SUBSTRING', 'SUBSTR', 'LEFT', 'RIGHT', 'LOCATE', 'INSTR',
        'REPLACE', 'FIND_IN_SET', 'COALESCE', 'IFNULL', 'NULLIF', 'IF', 'GREATEST', 'LEAST',
        'ABS', 'ROUND', 'FLOOR', 'CEIL', 'CEILING', 'SIGN',
        'NOW', 'CURDATE', 'CURTIME', 'UTC_DATE', 'UTC_TIME', 'UTC_TIMESTAMP', 'CURRENT_DATE',
        'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'DATE', 'TIME', 'YEAR', 'MONTH', 'DAY', 'HOUR',
        'MINUTE', 'SECOND', 'DATE_ADD', 'DATE_SUB', 'DATEDIFF', 'TIMESTAMPDIFF',
        'JSON_CONTAINS', 'JSON_EXTRACT', 'JSON_UNQUOTE', 'JSON_LENGTH',
    ];

    private const OPERATORS = ['<=>', '<>', '!=', '<=', '>=', '&&', '||', '=', '<', '>', '+', '-', '*', '/', '%', '!', '(', ')', ','];

    /**
     * @param string[] $columns Column names of the table the policy applies to.
     * @return string|null An error message, or null when the expression is valid.
     */
    public static function validate(string $expression, array $columns): ?string
    {
        if (trim($expression) === '') {
            return 'expression is required';
        }

        if (strlen($expression) > self::MAX_LENGTH) {
            return 'expression is too long (max ' . self::MAX_LENGTH . ' characters)';
        }

        if (preg_match('/;|--|\/\*|#/', self::withoutStringLiterals($expression))) {
            return 'expression must be a single boolean expression: ";", "--", "#" and "/*" are not allowed';
        }

        if (preg_match_all('/\$auth\.(\w+)/', $expression, $matches)) {
            foreach ($matches[1] as $name) {
                if (!in_array($name, self::PLACEHOLDERS, true)) {
                    return "unknown placeholder \$auth.{$name}; use one of: "
                        . implode(', ', array_map(fn($p) => "\$auth.{$p}", self::PLACEHOLDERS));
                }
            }
        }

        $tokens = self::tokenize($expression);
        if (is_string($tokens)) {
            return $tokens;
        }

        $columnLookup = array_change_key_case(array_flip($columns), CASE_LOWER);
        $depth = 0;

        foreach ($tokens as $i => [$type, $value]) {
            if ($type === 'op') {
                $depth += $value === '(' ? 1 : ($value === ')' ? -1 : 0);
                if ($depth < 0) {
                    return 'expression has unbalanced parentheses';
                }
                continue;
            }

            if ($type !== 'word' && $type !== 'quoted_identifier') {
                continue;
            }

            $isCall = ($tokens[$i + 1][1] ?? null) === '(' && ($tokens[$i + 1][0] ?? null) === 'op';
            $upper = strtoupper($value);

            if ($type === 'word') {
                if ($isCall && in_array($upper, self::FUNCTIONS, true)) {
                    continue;
                }
                // Keywords may precede "(" too: `IN (...)`, `NOT (...)`, `AND (...)`.
                if (in_array($upper, self::KEYWORDS, true)) {
                    continue;
                }
                if ($isCall) {
                    return "function {$value}() is not allowed in policy expressions";
                }
            }

            if (!isset($columnLookup[strtolower($value)])) {
                return "unknown column or keyword \"{$value}\"; expressions may only reference this table's columns";
            }
        }

        if ($depth !== 0) {
            return 'expression has unbalanced parentheses';
        }

        return null;
    }

    /**
     * @return array<int, array{0: string, 1: string}>|string Tokens, or an error message.
     */
    private static function tokenize(string $expression): array|string
    {
        $tokens = [];
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $end = self::findClosingQuote($expression, $i, $char);
                if ($end === null) {
                    return 'expression has an unterminated string literal';
                }
                $tokens[] = ['string', substr($expression, $i, $end - $i + 1)];
                $i = $end + 1;
                continue;
            }

            if ($char === '`') {
                $end = strpos($expression, '`', $i + 1);
                if ($end === false) {
                    return 'expression has an unterminated quoted identifier';
                }
                $tokens[] = ['quoted_identifier', substr($expression, $i + 1, $end - $i - 1)];
                $i = $end + 1;
                continue;
            }

            if (preg_match('/\G\$auth\.(?:id|email|role)\b/', $expression, $m, 0, $i)) {
                $tokens[] = ['placeholder', $m[0]];
                $i += strlen($m[0]);
                continue;
            }

            if (preg_match('/\G(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?(?![A-Za-z_])/', $expression, $m, 0, $i)) {
                $tokens[] = ['number', $m[0]];
                $i += strlen($m[0]);
                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $expression, $m, 0, $i)) {
                $tokens[] = ['word', $m[0]];
                $i += strlen($m[0]);
                // A dot right after an identifier means a qualified name
                // (schema.table / table.column) — never needed in a policy.
                if (($expression[$i] ?? '') === '.') {
                    return 'qualified names are not allowed in policy expressions; reference columns directly';
                }
                continue;
            }

            foreach (self::OPERATORS as $operator) {
                if (substr_compare($expression, $operator, $i, strlen($operator)) === 0) {
                    $tokens[] = ['op', $operator];
                    $i += strlen($operator);
                    continue 2;
                }
            }

            return "character \"{$char}\" is not allowed in policy expressions";
        }

        return $tokens;
    }

    private static function findClosingQuote(string $sql, int $start, string $quote): ?int
    {
        $length = strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($sql[$i] === '\\') {
                $i++;
                continue;
            }
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? null) === $quote) {
                    $i++;
                    continue;
                }
                return $i;
            }
        }

        return null;
    }

    private static function withoutStringLiterals(string $expression): string
    {
        return (string) preg_replace('/\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"/s', "''", $expression);
    }
}
