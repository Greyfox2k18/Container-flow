<?php
/**
 * Report Builder — dataset registry.
 *
 * A dataset is the only thing the report builder can query. Each project
 * registers its datasets from a PHP file in usersc/report_datasets/ (one
 * file per dataset, loaded lazily on first use), e.g.
 *
 *   rb_register_dataset('containers', [
 *       'label'  => 'Containers',
 *       'table'  => 'containers',
 *       'alias'  => 'c',
 *       'joins'  => ['cu' => ['table' => 'customers', 'on' => 'cu.id = c.customer_id']],
 *       'fields' => [
 *           'status'   => ['label' => 'Status', 'type' => 'enum', 'options' => ['pending' => 'Pending']],
 *           'customer' => ['label' => 'Client', 'type' => 'text', 'expr' => 'cu.name', 'join' => 'cu'],
 *       ],
 *       'scope'  => function (array $ctx) { return null; },
 *   ]);
 *
 * Table names, join conditions and field expressions are written by the
 * developer in that config file and are trusted SQL. Nothing a report
 * builder user types ever becomes SQL — reports refer to fields only by
 * their registered key, and values are always bound parameters (see
 * rb_query.php).
 *
 * Field options:
 *   label        Shown in the builder. Defaults to the key.
 *   type         text | number | date | datetime | enum | bool
 *   expr         SQL expression. Defaults to "<alias>.<key>".
 *   join         Join alias (or list of aliases) the expression needs.
 *   options      enum only: [value => label] array, or a callable returning one.
 *   filterable   default true
 *   sortable     default true
 *   groupable    default true for text/enum/bool/date/datetime
 *   aggregatable default true for number (sum/avg); count/min/max work on any field
 *   display      optional rendering hint: 'mono' (fixed-width, e.g. container numbers)
 *
 * Scope callback: receives the run context (['user_id' => int, ...]) and
 * returns null for "no restriction", false for "no rows at all", or
 * ['where' => 'sql with ? placeholders', 'params' => [...], 'joins' => [...]].
 */
if (count(get_included_files()) == 1) die();

if (!class_exists('RbConfigException')) {
    /** Thrown for a mistake in a dataset config file (developer error). */
    class RbConfigException extends \InvalidArgumentException {}
}

if (!class_exists('RbRegistry')) {
class RbRegistry {
    const KEY_RE        = '/^[a-z][a-z0-9_]{0,63}$/';
    const TABLE_RE      = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
    const FIELD_TYPES   = ['text', 'number', 'date', 'datetime', 'enum', 'bool'];
    const GROUPABLE_BY_DEFAULT = ['text', 'enum', 'bool', 'date', 'datetime'];
    const DISPLAYS      = ['mono'];

    private static $datasets = [];
    private static $dirs     = [];
    private static $loaded   = false;
    private static $errors   = [];   // file => message, for dataset files that failed to load

    /** Add a folder of dataset config files. Safe to call more than once. */
    public static function addDir($dir) {
        $dir = rtrim($dir, '/\\');
        if (!in_array($dir, self::$dirs, true)) {
            self::$dirs[]  = $dir;
            self::$loaded  = false;
        }
    }

    public static function register($key, array $def) {
        self::$datasets[$key] = self::normalize($key, $def);
    }

    public static function all() {
        self::load();
        return self::$datasets;
    }

    public static function get($key) {
        self::load();
        return self::$datasets[$key] ?? null;
    }

    /** [filename => error message] for dataset files that failed to load. */
    public static function errors() {
        self::load();
        return self::$errors;
    }

    /** Tests only. */
    public static function reset() {
        self::$errors   = [];
        self::$datasets = [];
        self::$dirs     = [];
        self::$loaded   = false;
    }

    private static function load() {
        if (self::$loaded) return;
        self::$loaded = true;
        foreach (self::$dirs as $dir) {
            $files = glob($dir . '/*.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                // One broken dataset file shouldn't take the others down.
                try {
                    self::includeFile($file);
                } catch (\Throwable $e) {
                    self::$errors[basename($file)] = $e->getMessage();
                    error_log('Report builder: dataset file ' . $file . ' failed: ' . $e->getMessage());
                }
            }
        }
    }

    // Separate method so a dataset file can't clobber load()'s variables.
    private static function includeFile($file) {
        include_once $file;
    }

    private static function normalize($key, array $def) {
        $where = "dataset '$key'";
        if (!preg_match(self::KEY_RE, (string) $key)) {
            throw new RbConfigException("Invalid dataset key '$key' — use lowercase letters, digits and _.");
        }
        if (empty($def['table']) || !preg_match(self::TABLE_RE, $def['table'])) {
            throw new RbConfigException("$where: 'table' is missing or not a plain table name.");
        }
        $alias = $def['alias'] ?? 't';
        if (!preg_match(self::KEY_RE, $alias)) {
            throw new RbConfigException("$where: invalid alias '$alias'.");
        }

        $joins = [];
        foreach (($def['joins'] ?? []) as $jalias => $j) {
            if (!preg_match(self::KEY_RE, (string) $jalias) || $jalias === $alias) {
                throw new RbConfigException("$where: invalid join alias '$jalias'.");
            }
            if (empty($j['table']) || !preg_match(self::TABLE_RE, $j['table']) || empty($j['on'])) {
                throw new RbConfigException("$where: join '$jalias' needs a plain 'table' and an 'on' condition.");
            }
            $type = strtoupper($j['type'] ?? 'LEFT');
            if (!in_array($type, ['LEFT', 'INNER'], true)) {
                throw new RbConfigException("$where: join '$jalias' type must be LEFT or INNER.");
            }
            $joins[$jalias] = [
                'table'    => $j['table'],
                'on'       => $j['on'],
                'type'     => $type,
                'requires' => (array) ($j['requires'] ?? []),
            ];
        }
        foreach ($joins as $jalias => $j) {
            foreach ($j['requires'] as $req) {
                if (!isset($joins[$req])) throw new RbConfigException("$where: join '$jalias' requires unknown join '$req'.");
            }
        }

        if (empty($def['fields']) || !is_array($def['fields'])) {
            throw new RbConfigException("$where: at least one field is required.");
        }
        $fields = [];
        foreach ($def['fields'] as $fkey => $f) {
            if (!preg_match(self::KEY_RE, (string) $fkey)) {
                throw new RbConfigException("$where: invalid field key '$fkey'.");
            }
            $type = $f['type'] ?? 'text';
            if (!in_array($type, self::FIELD_TYPES, true)) {
                throw new RbConfigException("$where: field '$fkey' has unknown type '$type'.");
            }
            $fjoins = (array) ($f['join'] ?? []);
            foreach ($fjoins as $ja) {
                if (!isset($joins[$ja])) throw new RbConfigException("$where: field '$fkey' uses unknown join '$ja'.");
            }
            $options = $f['options'] ?? null;
            if ($options !== null && !is_array($options) && !is_callable($options)) {
                throw new RbConfigException("$where: field '$fkey' options must be an array or callable.");
            }
            $display = $f['display'] ?? null;
            if ($display !== null && !in_array($display, self::DISPLAYS, true)) {
                throw new RbConfigException("$where: field '$fkey' has unknown display '$display'.");
            }
            $fields[$fkey] = [
                'key'          => $fkey,
                'label'        => $f['label'] ?? ucwords(str_replace('_', ' ', $fkey)),
                'type'         => $type,
                'expr'         => $f['expr'] ?? ($alias . '.' . $fkey),
                'joins'        => $fjoins,
                'options'      => $options,
                'filterable'   => (bool) ($f['filterable'] ?? true),
                'sortable'     => (bool) ($f['sortable'] ?? true),
                'groupable'    => (bool) ($f['groupable'] ?? in_array($type, self::GROUPABLE_BY_DEFAULT, true)),
                'aggregatable' => (bool) ($f['aggregatable'] ?? ($type === 'number')),
                'display'      => $display,
            ];
        }

        $date_field = $def['default_date_field'] ?? null;
        if ($date_field !== null && (!isset($fields[$date_field]) || !in_array($fields[$date_field]['type'], ['date', 'datetime'], true))) {
            throw new RbConfigException("$where: default_date_field '$date_field' must be a date/datetime field.");
        }
        $default_fields = $def['default_fields'] ?? array_slice(array_keys($fields), 0, 6);
        foreach ($default_fields as $df) {
            if (!isset($fields[$df])) throw new RbConfigException("$where: default_fields has unknown field '$df'.");
        }
        if (isset($def['scope']) && !is_callable($def['scope'])) {
            throw new RbConfigException("$where: scope must be callable.");
        }

        return [
            'key'                => $key,
            'label'              => $def['label'] ?? ucwords(str_replace('_', ' ', $key)),
            'description'        => $def['description'] ?? '',
            'table'              => $def['table'],
            'alias'              => $alias,
            'joins'              => $joins,
            'fields'             => $fields,
            'default_date_field' => $date_field,
            'default_fields'     => array_values($default_fields),
            'scope'              => $def['scope'] ?? null,
        ];
    }

    /**
     * Resolve an enum field's options to a [value => label] array.
     * Returns null for fields without a fixed option list.
     */
    public static function fieldOptions(array $field) {
        $o = $field['options'];
        if ($o === null) return null;
        if (is_callable($o)) {
            try {
                $o = call_user_func($o);
            } catch (\Throwable $e) {
                error_log('Report builder: options callback failed for ' . $field['key'] . ': ' . $e->getMessage());
                return [];
            }
        }
        return is_array($o) ? $o : [];
    }
}
}

if (!function_exists('rb_register_dataset')) {
    function rb_register_dataset($key, array $def) {
        RbRegistry::register($key, $def);
    }
}
