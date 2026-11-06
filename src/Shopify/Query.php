<?php

namespace Banimark\Shopify;

/**
 * The SELECT a Shopify tool is allowed to be, and how it runs. A tool against
 * a Shopify store goes through the same gate as every database tool
 * (SqlToolValidator: one SELECT, bound placeholders, declared parameters)
 * and then through here, which accepts the shape the Tool Builder and the
 * draftsman write:
 *
 *   SELECT col, col FROM orders
 *   WHERE order_number = :order_number AND email = :_email
 *     AND (:status IS NULL OR fulfillment_status = :status)
 *     AND title LIKE CONCAT('%', :words, '%')
 *   ORDER BY created_at DESC LIMIT 10
 *
 * One table, equality or "contains" conditions joined by AND, each on a
 * column Shopify can search by. The conditions become a Shopify search
 * string ("name:1001 email:ada@example.com"), the rows come back, and every
 * condition is checked AGAIN on the rows themselves - Shopify's search is
 * the way to fetch few rows, never the thing that decides who sees what.
 * Anything else (a JOIN, a subquery, a < comparison) is refused with a
 * sentence saying what Shopify can do instead, at save time and in the
 * draftsman's retry.
 */
final class Query
{
    /** @return array{ok: bool, error?: string} */
    public static function check(string $sql): array
    {
        try {
            self::parse($sql);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true];
    }

    /**
     * @throws \InvalidArgumentException with a sentence for the owner
     * @return array{table: string, columns: string[], conditions: list<array{column: string, contains: bool, param: ?string, literal: ?string, optional: bool}>, order: ?array{0: string, 1: bool}, limit: ?int}
     */
    public static function parse(string $sql): array
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $sql));
        $s = str_replace(['`', '"'], '', $s);
        $lower = strtolower($s);
        if (!str_starts_with($lower, 'select ')) {
            throw new \InvalidArgumentException('A Shopify lookup is one SELECT.');
        }
        foreach (['join' => 'one table per tool - there is no JOIN in a Shopify store; pick orders, customers or products', 'group by' => 'no GROUP BY', 'having' => 'no HAVING',
                  'distinct' => 'no DISTINCT', 'union' => 'no UNION', 'count(' => 'no COUNT or other functions', 'sum(' => 'no SUM or other functions', ' in (' => 'no IN lists - one value per condition'] as $word => $why) {
            if (preg_match('/'.($word[0] === ' ' ? '' : '\b').preg_quote($word, '/').'/', $lower)) {
                throw new \InvalidArgumentException('Shopify cannot run that: '.$why.'.');
            }
        }
        // LOWER(x) = LOWER(:y) and friends: matching is case-insensitive already
        for ($i = 0; $i < 3; $i++) {
            $s = (string) preg_replace('/\b(?:lower|upper|trim)\(\s*([^()]*?)\s*\)/i', '$1', $s);
        }
        if (!preg_match('/^select (.+?) from ([a-z_]+)(?: where (.+?))?(?: order by ([a-z_.]+)(?: (asc|desc))?)?(?: limit (\d+))?$/i', $s, $m)) {
            throw new \InvalidArgumentException('Shopify lookups are: SELECT columns FROM orders|customers|products WHERE column = :value AND … [ORDER BY created_at DESC] [LIMIT n].');
        }
        $table = strtolower($m[2]);
        if (!in_array($table, Catalog::TABLES, true)) {
            throw new \InvalidArgumentException('Shopify has no table called "'.$m[2].'". The tables are orders, customers and products.');
        }
        $strip = fn (string $col) => strtolower((string) preg_replace('/^'.preg_quote($table, '/').'\./i', '', trim($col)));

        $columns = [];
        if (trim($m[1]) === '*') {
            $columns = array_keys(Catalog::columns($table));
        } else {
            foreach (explode(',', $m[1]) as $col) {
                $col = $strip($col);
                if (preg_match('/\s+as\s+|\s/', $col)) {
                    throw new \InvalidArgumentException('List the columns plainly, without AS or expressions: '.implode(', ', array_keys(Catalog::columns($table))).'.');
                }
                if (!Catalog::hasColumn($table, $col)) {
                    throw new \InvalidArgumentException('The '.$table.' table has no column "'.$col.'". Its columns are: '.implode(', ', array_keys(Catalog::columns($table))).'.');
                }
                $columns[] = $col;
            }
        }

        $conditions = [];
        foreach (self::split(($m[3] ?? '')) as $piece) {
            $conditions[] = self::condition($table, $piece, $strip);
        }

        $order = null;
        if (($m[4] ?? '') !== '') {
            $col = $strip($m[4]);
            if (Catalog::sortKey($table, $col) === null) {
                throw new \InvalidArgumentException('Shopify can order '.$table.' by created_at or updated_at'.($table === 'orders' ? ' or order_number' : ($table === 'products' ? ' or title' : '')).', not by "'.$col.'".');
            }
            $order = [$col, strtolower($m[5] ?? '') === 'desc'];
        }
        $limit = ($m[6] ?? '') !== '' ? max(1, (int) $m[6]) : null;
        return ['table' => $table, 'columns' => $columns, 'conditions' => $conditions, 'order' => $order, 'limit' => $limit];
    }

    /** The WHERE clause as its top-level AND parts (an OR only lives inside the optional-parameter idiom). @return string[] */
    private static function split(string $where): array
    {
        $where = trim($where);
        if ($where === '') {
            return [];
        }
        $parts = [];
        $depth = 0;
        $quote = false;
        $cur = '';
        $n = strlen($where);
        for ($i = 0; $i < $n; $i++) {
            $c = $where[$i];
            if ($c === "'" && ($i === 0 || $where[$i - 1] !== '\\')) {
                $quote = !$quote;
            } elseif (!$quote && $c === '(') {
                $depth++;
            } elseif (!$quote && $c === ')') {
                $depth--;
            }
            if (!$quote && $depth === 0 && strcasecmp(substr($where, $i, 5), ' and ') === 0) {
                $parts[] = $cur;
                $cur = '';
                $i += 4;
                continue;
            }
            $cur .= $c;
        }
        $parts[] = $cur;
        return array_map('trim', $parts);
    }

    /** @return array{column: string, contains: bool, param: ?string, literal: ?string, optional: bool} */
    private static function condition(string $table, string $piece, callable $strip): array
    {
        $piece = trim($piece);
        while (str_starts_with($piece, '(') && str_ends_with($piece, ')') && self::balanced(substr($piece, 1, -1))) {
            $piece = trim(substr($piece, 1, -1));
        }
        $optional = null;
        if (preg_match('/^:([a-z_]\w*) is null or (.+)$/i', $piece, $o)) {
            $optional = strtolower($o[1]);
            $piece = trim($o[2]);
            while (str_starts_with($piece, '(') && str_ends_with($piece, ')') && self::balanced(substr($piece, 1, -1))) {
                $piece = trim(substr($piece, 1, -1));
            }
        }
        if (preg_match('/\s(or|not)\s/i', $piece) || str_contains($piece, '!=') || str_contains($piece, '<') || str_contains($piece, '>')) {
            throw new \InvalidArgumentException('Shopify conditions are "column = :value" or "column LIKE :value" (contains), joined by AND. There is no OR, NOT, <, > or !=.');
        }
        if (!preg_match('/^([a-z_.]+)\s*(=|like|ilike)\s*(.+)$/i', $piece, $c)) {
            throw new \InvalidArgumentException('Could not read the condition "'.$piece.'". Write it as column = :value, or column LIKE :value for "contains".');
        }
        $column = $strip($c[1]);
        $contains = strtolower($c[2]) !== '=';
        $rhs = trim($c[3]);
        $param = null;
        $literal = null;
        if (preg_match('/^:([a-z_]\w*)$/i', $rhs, $r)) {
            $param = strtolower($r[1]);
        } elseif (preg_match('/^concat\(\s*\'%\'\s*,\s*:([a-z_]\w*)\s*,\s*\'%\'\s*\)$/i', $rhs, $r) || preg_match('/^\'%\'\s*\|\|\s*:([a-z_]\w*)\s*\|\|\s*\'%\'$/i', $rhs, $r)) {
            $param = strtolower($r[1]);
            $contains = true;
        } elseif (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/', $rhs, $r)) {
            $literal = trim(str_replace(["\\'", '\\\\'], ["'", '\\'], $r[1]), '%');
        } elseif (preg_match('/^-?\d+(?:\.\d+)?$/', $rhs)) {
            $literal = $rhs;
        } else {
            throw new \InvalidArgumentException('The value in "'.$piece.'" must be a :placeholder or a quoted \'value\'.');
        }
        if ($optional !== null && $param !== $optional) {
            throw new \InvalidArgumentException('In "(:x IS NULL OR column = :x)" both placeholders must be the same.');
        }
        if (!Catalog::hasColumn($table, $column)) {
            throw new \InvalidArgumentException('The '.$table.' table has no column "'.$column.'". Its columns are: '.implode(', ', array_keys(Catalog::columns($table))).'.');
        }
        if (Catalog::searchKey($table, $column) === null) {
            throw new \InvalidArgumentException('Shopify cannot find '.$table.' by "'.$column.'". It can search '.$table.' by: '.implode(', ', Catalog::searchable($table)).'.');
        }
        return ['column' => $column, 'contains' => $contains, 'param' => $param, 'literal' => $literal, 'optional' => $optional !== null];
    }

    private static function balanced(string $s): bool
    {
        $depth = 0;
        foreach (str_split($s) as $c) {
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth < 0) {
                return false;
            }
        }
        return $depth === 0;
    }

    /* ---------------- running ---------------- */

    /** The callable a SqlTool runs through - identical in shape to DataSource::runner(). */
    public static function runner(Client $client): callable
    {
        return fn (string $sql, array $bindings) => self::run($client, $sql, $bindings);
    }

    /**
     * @param array<string, mixed> $bindings the SqlTool's bindings (:param and :_key, without the colon; repeats as name__2)
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public static function run(Client $client, string $sql, array $bindings, int $max = Client::MAX_ROWS): array
    {
        // SqlTool renames a repeated placeholder (:status, :status__2) for PDO's sake; the bindings carry both names
        $q = self::parse((string) preg_replace('/:([a-zA-Z_]\w*?)__\d+\b/', ':$1', $sql));
        $wants = [];   // column => [contains, value] - what every row must satisfy
        $terms = [];
        foreach ($q['conditions'] as $cond) {
            if ($cond['param'] !== null) {
                $value = $bindings[$cond['param']] ?? null;
                if ($value === null || $value === '') {
                    if ($cond['optional']) {
                        continue;
                    }
                    return [];   // a required value the tool did not get: nothing matches, nothing is fetched
                }
            } else {
                $value = (string) $cond['literal'];
            }
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($cond['column'] === 'order_number') {
                $value = ltrim($value, '#');
            }
            $wants[] = [$cond['column'], $cond['contains'], $value];
            $terms[] = self::term((string) Catalog::searchKey($q['table'], $cond['column']), $value, $cond['contains']);
        }
        $limit = min($q['limit'] ?? $max, $max);
        $sortKey = null;
        $reverse = false;
        if ($q['order'] !== null) {
            $sortKey = Catalog::sortKey($q['table'], $q['order'][0]);
            $reverse = $q['order'][1];
        } elseif ($q['table'] === 'orders') {
            $sortKey = 'CREATED_AT';   // newest first, which is what "my orders" means
            $reverse = true;
        }
        $rows = $client->rows($q['table'], implode(' ', $terms), $limit, $sortKey, $reverse);
        $out = [];
        foreach ($rows as $row) {
            foreach ($wants as [$column, $contains, $value]) {
                if (!self::matches($column, (string) ($row[$column] ?? ''), $value, $contains)) {
                    continue 2;
                }
            }
            $pick = [];
            foreach ($q['columns'] as $col) {   // in the order the SELECT named them
                $pick[$col] = $row[$col] ?? null;
            }
            $out[] = $pick;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** One search term: key:"value" to match, or the bare phrase for "contains" (Shopify matches it across the searchable fields). */
    public static function term(string $key, string $value, bool $contains): string
    {
        $quoted = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        return $contains ? $quoted : $key.':'.$quoted;
    }

    /** The check Shopify's search is not trusted to have made. */
    public static function matches(string $column, string $cell, string $want, bool $contains): bool
    {
        if ($column === 'order_number') {
            $cell = ltrim($cell, '#');
        }
        if ($column === 'tags' && !$contains) {
            return in_array(mb_strtolower($want), array_map(fn ($t) => mb_strtolower(trim($t)), explode(',', $cell)), true);
        }
        return \Banimark\Tools\SheetTool::same($cell, $want, $contains ? 'contains' : 'exact');
    }
}
