<?php
/**
 * Report Builder — safe query builder.
 *
 * Turns a block's query spec (JSON-decoded array from the report layout)
 * into a prepared SELECT against a registered dataset. The only things
 * that reach the SQL string are:
 *   - table names, join conditions and field expressions from the dataset
 *     config (developer-written, looked up by whitelisted key),
 *   - column aliases built from registered keys (regex-checked),
 *   - fixed operator / function / direction keywords from the lists below,
 *   - an integer LIMIT.
 * Every user-supplied value goes into $params as a bound parameter.
 *
 * Query spec (all keys optional except where noted):
 *   fields      ['container_number', 'status', ...]        — ignored when grouping
 *   filters     [['field' => 'status', 'op' => 'in', 'value' => ['pending']], ...]  (ANDed)
 *   date_window ['field' => 'created_at', 'range' => 'last_7_days']
 *               or ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-31']
 *   group_by    ['customer'] or [['field' => 'created_at', 'bucket' => 'month']]
 *   aggregates  [['fn' => 'count'], ['fn' => 'sum', 'field' => 'piece_count']]
 *   sort        [['key' => 'count__all', 'dir' => 'desc']]
 *   limit       int, 1..MAX_LIMIT
 *
 * Output column keys: a field key, "<field>__<bucket>" for a bucketed
 * group, or "<fn>__<field|all>" for an aggregate.
 *
 * Run context ($ctx):
 *   user_id       whose scope to apply (dataset scope callback)
 *   unscoped      true = skip the scope callback (trusted callers only, e.g.
 *                 a report an admin marked unscoped — never from request input)
 *   now           DateTime for date windows (default: now)
 *   last_sent_at / created_at   for the 'since_last_report' window
 */
if (count(get_included_files()) == 1) die();

if (!class_exists('RbQueryException')) {
    /** Invalid report spec. The message is safe to show to the report author. */
    class RbQueryException extends \InvalidArgumentException {}
}

if (!class_exists('RbQuery')) {
class RbQuery {
    const MAX_LIMIT     = 10000;
    const DEFAULT_LIMIT = 5000;
    const MAX_IN_VALUES = 500;
    const MAX_TEXT_LEN  = 255;

    const OPS_BY_TYPE = [
        'text'     => ['eq', 'neq', 'in', 'not_in', 'contains', 'not_contains', 'starts_with', 'is_null', 'not_null'],
        'number'   => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'not_in', 'is_null', 'not_null'],
        'date'     => ['eq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'not_null'],
        'datetime' => ['gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'not_null'],
        'enum'     => ['eq', 'neq', 'in', 'not_in', 'is_null', 'not_null'],
        'bool'     => ['eq', 'is_null', 'not_null'],
    ];
    const COMPARE_SQL = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    const AGGREGATES  = ['count', 'count_distinct', 'sum', 'avg', 'min', 'max'];
    const BUCKETS     = ['day', 'week', 'month', 'year'];
    const DATE_RANGES = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_week', 'last_week',
                         'this_month', 'last_month', 'this_year', 'since_last_report', 'all_time', 'custom'];

    /**
     * Build the SQL for $spec against dataset $ds (as returned by RbRegistry::get()).
     * Returns ['sql' => string, 'params' => array, 'columns' => [key => ['label', 'type']], 'grouped' => bool].
     */
    public static function build(array $ds, array $spec, array $ctx = [], $dialect = 'mysql') {
        $fields   = $ds['fields'];
        $joins    = [];      // join aliases needed, in first-use order
        $select   = [];      // alias => sql expression
        $columns  = [];      // alias => ['label', 'type']
        $where    = [];
        $params   = [];
        $group_sql = [];

        $need_field = function ($key, $purpose) use ($fields, &$joins) {
            if (!is_string($key) || !isset($fields[$key])) {
                throw new RbQueryException("Unknown field '" . self::clip($key) . "'.");
            }
            $f = $fields[$key];
            if ($purpose !== 'select' && !$f[$purpose]) {
                $verb = ['filterable' => 'filtered on', 'groupable' => 'grouped by', 'sortable' => 'sorted by', 'aggregatable' => 'summed/averaged'][$purpose];
                throw new RbQueryException("Field '{$f['label']}' can't be $verb.");
            }
            foreach ($f['joins'] as $j) $joins[] = $j;
            return $f;
        };

        // ── Grouping / aggregates ───────────────────────────────────────────
        $group_by   = self::listOf($spec['group_by'] ?? [], 'group_by');
        $aggregates = self::listOf($spec['aggregates'] ?? [], 'aggregates');
        $grouped    = !empty($group_by) || !empty($aggregates);

        foreach ($group_by as $g) {
            if (is_string($g)) $g = ['field' => $g];
            if (!is_array($g)) throw new RbQueryException('Invalid group_by entry.');
            $f = $need_field($g['field'] ?? null, 'groupable');
            $bucket = $g['bucket'] ?? null;
            if ($bucket !== null && $bucket !== '') {
                if (!in_array($f['type'], ['date', 'datetime'], true)) {
                    throw new RbQueryException("Only date fields can be grouped by day/week/month/year.");
                }
                if (!in_array($bucket, self::BUCKETS, true)) {
                    throw new RbQueryException("Unknown date grouping '" . self::clip($bucket) . "'.");
                }
                $alias = $f['key'] . '__' . $bucket;
                $expr  = self::bucketExpr($f['expr'], $bucket, $dialect);
                $label = $f['label'] . ' (' . $bucket . ')';
                $type  = 'text';
            } else {
                $alias = $f['key'];
                $expr  = $f['expr'];
                $label = $f['label'];
                $type  = $f['type'];
            }
            if (isset($select[$alias])) continue;
            $select[$alias]  = $expr;
            $columns[$alias] = ['label' => $label, 'type' => $type];
            $group_sql[]     = $expr;
        }

        foreach ($aggregates as $a) {
            if (!is_array($a)) throw new RbQueryException('Invalid aggregate entry.');
            $fn = $a['fn'] ?? null;
            if (!in_array($fn, self::AGGREGATES, true)) {
                throw new RbQueryException("Unknown aggregate '" . self::clip($fn) . "'.");
            }
            $fkey = $a['field'] ?? null;
            if ($fn === 'count' && ($fkey === null || $fkey === '' || $fkey === '*')) {
                $alias = 'count__all';
                $expr  = 'COUNT(*)';
                $label = 'Count';
                $type  = 'number';
            } else {
                $f = in_array($fn, ['sum', 'avg'], true) ? $need_field($fkey, 'aggregatable') : $need_field($fkey, 'select');
                if (in_array($fn, ['sum', 'avg'], true) && $f['type'] !== 'number') {
                    throw new RbQueryException("Field '{$f['label']}' isn't a number, so it can't be summed/averaged.");
                }
                $alias = $fn . '__' . $f['key'];
                $inner = $f['expr'];
                $expr  = $fn === 'count_distinct' ? "COUNT(DISTINCT $inner)" : strtoupper($fn) . "($inner)";
                $label = ['count' => 'Count of', 'count_distinct' => 'Distinct', 'sum' => 'Total', 'avg' => 'Average', 'min' => 'Earliest/Min', 'max' => 'Latest/Max'][$fn] . ' ' . $f['label'];
                $type  = in_array($fn, ['min', 'max'], true) ? $f['type'] : 'number';
            }
            if (isset($select[$alias])) continue;
            $select[$alias]  = $expr;
            $columns[$alias] = ['label' => $label, 'type' => $type];
        }

        // ── Plain field list (ungrouped) ────────────────────────────────────
        if (!$grouped) {
            $keys = self::listOf($spec['fields'] ?? [], 'fields') ?: $ds['default_fields'];
            foreach ($keys as $k) {
                $f = $need_field($k, 'select');
                if (isset($select[$f['key']])) continue;
                $select[$f['key']]  = $f['expr'];
                $columns[$f['key']] = ['label' => $f['label'], 'type' => $f['type']];
            }
        }
        if (empty($select)) throw new RbQueryException('Choose at least one field.');

        // ── Filters ─────────────────────────────────────────────────────────
        foreach (self::listOf($spec['filters'] ?? [], 'filters') as $flt) {
            if (!is_array($flt)) throw new RbQueryException('Invalid filter entry.');
            $f = $need_field($flt['field'] ?? null, 'filterable');
            [$sql, $p] = self::filterSql($f, $flt['op'] ?? 'eq', $flt['value'] ?? null);
            $where[] = $sql;
            array_push($params, ...$p);
        }

        // ── Date window ─────────────────────────────────────────────────────
        $dw = $spec['date_window'] ?? null;
        if (is_array($dw) && !empty($dw['range']) && $dw['range'] !== 'all_time') {
            $dkey = $dw['field'] ?? $ds['default_date_field'];
            if ($dkey === null) throw new RbQueryException('This dataset has no date field for a date window.');
            $f = $need_field($dkey, 'filterable');
            if (!in_array($f['type'], ['date', 'datetime'], true)) {
                throw new RbQueryException("Date window field '{$f['label']}' isn't a date.");
            }
            [$start, $end] = self::dateBounds($dw, $ctx);
            $fmt = $f['type'] === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
            if ($start) { $where[] = "{$f['expr']} >= ?"; $params[] = $start->format($fmt); }
            if ($end)   { $where[] = "{$f['expr']} <= ?"; $params[] = $end->format($fmt); }
        }

        // ── Dataset scope ───────────────────────────────────────────────────
        if (!empty($ds['scope']) && empty($ctx['unscoped'])) {
            $scope = call_user_func($ds['scope'], $ctx);
            if ($scope === false) {
                $where[] = '1 = 0';
            } elseif (is_array($scope)) {
                if (!empty($scope['where'])) {
                    $where[] = '(' . $scope['where'] . ')';
                    array_push($params, ...array_values((array) ($scope['params'] ?? [])));
                }
                foreach ((array) ($scope['joins'] ?? []) as $j) {
                    if (!isset($ds['joins'][$j])) throw new RbConfigException("Scope uses unknown join '$j'.");
                    $joins[] = $j;
                }
            }
        }

        // ── Sort ────────────────────────────────────────────────────────────
        $order = [];
        foreach (self::listOf($spec['sort'] ?? [], 'sort') as $s) {
            if (is_string($s)) $s = ['key' => $s];
            if (!is_array($s)) throw new RbQueryException('Invalid sort entry.');
            $dir = strtolower((string) ($s['dir'] ?? 'asc'));
            if (!in_array($dir, ['asc', 'desc'], true)) throw new RbQueryException('Sort direction must be asc or desc.');
            $key = $s['key'] ?? ($s['field'] ?? null);
            if (is_string($key) && isset($select[$key])) {
                $order[] = self::q($key) . ' ' . strtoupper($dir);
            } elseif (!$grouped) {
                $f = $need_field($key, 'sortable');
                $order[] = $f['expr'] . ' ' . strtoupper($dir);
            } else {
                throw new RbQueryException("Grouped results can only be sorted by a shown column ('" . self::clip($key) . "' isn't one).");
            }
        }

        $limit = isset($spec['limit']) ? (int) $spec['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // ── Assemble ────────────────────────────────────────────────────────
        $sel = [];
        foreach ($select as $alias => $expr) $sel[] = "$expr AS " . self::q($alias);

        $sql = 'SELECT ' . implode(', ', $sel)
             . ' FROM ' . self::q($ds['table']) . ' ' . $ds['alias']
             . self::joinSql($ds, $joins);
        if ($where)     $sql .= ' WHERE ' . implode(' AND ', $where);
        if ($group_sql) $sql .= ' GROUP BY ' . implode(', ', $group_sql);
        if ($order)     $sql .= ' ORDER BY ' . implode(', ', $order);
        $sql .= ' LIMIT ' . (int) $limit;

        return ['sql' => $sql, 'params' => $params, 'columns' => $columns, 'grouped' => $grouped];
    }

    /**
     * Build and run. $executor is callable(string $sql, array $params): array of
     * assoc rows; defaults to UserSpice's DB class.
     * Returns ['columns' => ..., 'rows' => [...], 'grouped' => bool].
     */
    public static function run($dataset_key, array $spec, array $ctx = [], ?callable $executor = null, $dialect = 'mysql') {
        $ds = RbRegistry::get($dataset_key);
        if (!$ds) throw new RbQueryException("Unknown dataset '" . self::clip($dataset_key) . "'.");
        $q = self::build($ds, $spec, $ctx, $dialect);
        $executor = $executor ?: [__CLASS__, 'userspiceExecutor'];
        $rows = call_user_func($executor, $q['sql'], $q['params']);
        return ['columns' => $q['columns'], 'rows' => $rows, 'grouped' => $q['grouped']];
    }

    public static function userspiceExecutor($sql, array $params) {
        $db = DB::getInstance();
        $q  = $db->query($sql, $params);
        if ($db->error()) {
            error_log('Report builder query failed: ' . $db->errorString() . ' | SQL: ' . $sql);
            throw new \RuntimeException('The report query failed — see the PHP error log.');
        }
        return $q->results(true) ?: [];
    }

    /**
     * [start DateTime|null, end DateTime|null] for a date window.
     * Same ranges and meaning as the original getReportDateRangeBounds(),
     * plus this_week/last_week/this_year/custom.
     */
    public static function dateBounds(array $dw, array $ctx = []) {
        $range = $dw['range'] ?? 'all_time';
        if (!in_array($range, self::DATE_RANGES, true)) {
            throw new RbQueryException("Unknown date range '" . self::clip($range) . "'.");
        }
        $now   = isset($ctx['now']) ? clone $ctx['now'] : new \DateTime();
        $start = null;
        $end   = clone $now;
        $day0  = function (\DateTime $d) { return (clone $d)->setTime(0, 0, 0); };
        $day1  = function (\DateTime $d) { return (clone $d)->setTime(23, 59, 59); };

        switch ($range) {
            case 'today':        $start = $day0($now); break;
            case 'yesterday':    $y = (clone $now)->modify('-1 day'); $start = $day0($y); $end = $day1($y); break;
            case 'last_7_days':  $start = (clone $now)->modify('-7 days'); break;
            case 'last_30_days': $start = (clone $now)->modify('-30 days'); break;
            case 'this_week':    $start = $day0((clone $now)->modify('monday this week')); break;
            case 'last_week':    $start = $day0((clone $now)->modify('monday last week'));
                                 $end   = $day1((clone $now)->modify('sunday last week')); break;
            case 'this_month':   $start = $day0((clone $now)->modify('first day of this month')); break;
            case 'last_month':   $start = $day0((clone $now)->modify('first day of last month'));
                                 $end   = $day1((clone $now)->modify('last day of last month')); break;
            case 'this_year':    $start = $day0((clone $now)->setDate((int) $now->format('Y'), 1, 1)); break;
            case 'since_last_report':
                $from = !empty($ctx['last_sent_at']) ? $ctx['last_sent_at'] : ($ctx['created_at'] ?? null);
                $start = $from ? self::parseDate($from, 'window start') : null;
                break;
            case 'custom':
                $start = !empty($dw['start']) ? $day0(self::parseDate($dw['start'], 'start date')) : null;
                $end   = !empty($dw['end'])   ? $day1(self::parseDate($dw['end'], 'end date'))     : null;
                if ($start && $end && $start > $end) throw new RbQueryException('Start date is after end date.');
                break;
            case 'all_time':
                $end = null;
        }
        return [$start, $end];
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function filterSql(array $f, $op, $value) {
        $type = $f['type'];
        if (!is_string($op) || !in_array($op, self::OPS_BY_TYPE[$type], true)) {
            throw new RbQueryException("Filter '" . self::clip($op) . "' isn't available for '{$f['label']}'.");
        }
        $e = $f['expr'];
        switch ($op) {
            case 'is_null':  return ["$e IS NULL", []];
            case 'not_null': return ["$e IS NOT NULL", []];
            case 'in':
            case 'not_in':
                $vals = is_array($value) ? array_values($value) : [$value];
                if (empty($vals)) throw new RbQueryException("Pick at least one value for '{$f['label']}'.");
                if (count($vals) > self::MAX_IN_VALUES) throw new RbQueryException("Too many values for '{$f['label']}'.");
                $vals = array_map(function ($v) use ($f) { return self::castValue($f, $v); }, $vals);
                $ph = implode(', ', array_fill(0, count($vals), '?'));
                return ["$e " . ($op === 'in' ? 'IN' : 'NOT IN') . " ($ph)", $vals];
            case 'between':
                if (!is_array($value) || count($value) !== 2) throw new RbQueryException("'Between' needs two values for '{$f['label']}'.");
                $value = array_values($value);
                return ["$e BETWEEN ? AND ?", [self::castValue($f, $value[0]), self::castValue($f, $value[1])]];
            case 'contains':
            case 'not_contains':
            case 'starts_with':
                $v = self::likeEscape(self::castValue($f, $value));
                $pattern = $op === 'starts_with' ? "$v%" : "%$v%";
                return ["$e " . ($op === 'not_contains' ? 'NOT LIKE' : 'LIKE') . " ? ESCAPE '!'", [$pattern]];
            default:
                return ["$e " . self::COMPARE_SQL[$op] . ' ?', [self::castValue($f, $value)]];
        }
    }

    private static function castValue(array $f, $v) {
        if (!is_scalar($v)) throw new RbQueryException("Invalid value for '{$f['label']}'.");
        switch ($f['type']) {
            case 'number':
                if (!is_numeric($v)) throw new RbQueryException("'{$f['label']}' needs a number.");
                return $v + 0;
            case 'date':
                return self::parseDate($v, $f['label'])->format('Y-m-d');
            case 'datetime':
                return self::parseDate($v, $f['label'])->format('Y-m-d H:i:s');
            case 'bool':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            case 'enum':
                if (is_array($f['options'])) {
                    $allowed = array_map('strval', array_keys($f['options']));
                    if (!in_array((string) $v, $allowed, true)) {
                        throw new RbQueryException("'" . self::clip($v) . "' isn't a valid choice for '{$f['label']}'.");
                    }
                }
                return (string) $v;
            default:
                $v = (string) $v;
                if (strlen($v) > self::MAX_TEXT_LEN) throw new RbQueryException("Value for '{$f['label']}' is too long.");
                return $v;
        }
    }

    private static function parseDate($v, $what) {
        if ($v instanceof \DateTime) return clone $v;
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v)) {
            $d = \DateTime::createFromFormat('!Y-m-d', $v)
              ?: \DateTime::createFromFormat('Y-m-d H:i:s', $v)
              ?: \DateTime::createFromFormat('Y-m-d H:i', $v);
            if ($d) return $d;
        }
        throw new RbQueryException("'$what' needs a date like 2026-01-31.");
    }

    // '!' is the LIKE escape character (portable across MySQL/SQLite).
    private static function likeEscape($v) {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v);
    }

    private static function bucketExpr($expr, $bucket, $dialect) {
        if ($dialect === 'sqlite') {
            switch ($bucket) {
                case 'day':   return "date($expr)";
                case 'week':  return "date($expr, '-6 days', 'weekday 1')";
                case 'month': return "strftime('%Y-%m', $expr)";
                case 'year':  return "strftime('%Y', $expr)";
            }
        }
        switch ($bucket) {
            case 'day':   return "DATE($expr)";
            case 'week':  return "DATE(DATE_SUB($expr, INTERVAL WEEKDAY($expr) DAY))"; // Monday of that week
            case 'month': return "DATE_FORMAT($expr, '%Y-%m')";
            case 'year':  return "DATE_FORMAT($expr, '%Y')";
        }
        throw new RbQueryException('Unknown bucket.');
    }

    // Only joins a query actually needs, with their prerequisites first.
    private static function joinSql(array $ds, array $needed) {
        $ordered = [];
        $add = function ($j) use (&$add, &$ordered, $ds) {
            if (isset($ordered[$j])) return;
            foreach ($ds['joins'][$j]['requires'] as $req) $add($req);
            $ordered[$j] = true;
        };
        foreach (array_unique($needed) as $j) $add($j);
        $sql = '';
        foreach (array_keys($ordered) as $j) {
            $jd = $ds['joins'][$j];
            $sql .= " {$jd['type']} JOIN " . self::q($jd['table']) . " $j ON {$jd['on']}";
        }
        return $sql;
    }

    private static function listOf($v, $name) {
        if ($v === null || $v === '') return [];
        if (!is_array($v)) throw new RbQueryException("'$name' must be a list.");
        return array_values($v);
    }

    private static function q($ident) {
        return '`' . $ident . '`';
    }

    // For error messages: never echo back a long or non-scalar value.
    private static function clip($v) {
        $s = is_scalar($v) ? (string) $v : gettype($v);
        return htmlspecialchars(mb_substr($s, 0, 40), ENT_QUOTES, 'UTF-8');
    }
}
}
