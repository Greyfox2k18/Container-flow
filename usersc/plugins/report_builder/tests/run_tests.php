<?php
/**
 * Report Builder — stage 1 tests (dataset registry + safe query builder).
 *
 * Runs from the command line only, against an in-memory SQLite copy of the
 * Container Flow tables — no UserSpice or MySQL needed:
 *
 *   php usersc/plugins/report_builder/tests/run_tests.php
 *
 * MySQL-only SQL (CONCAT_WS, DATE_FORMAT) is checked as generated SQL text
 * rather than executed.
 */
if (PHP_SAPI !== 'cli') die();

require __DIR__ . '/../assets/includes/rb_registry.php';
require __DIR__ . '/../assets/includes/rb_query.php';

// ── tiny harness ────────────────────────────────────────────────────────────
$passed = 0; $failed = 0;
function test($name, callable $fn) {
    global $passed, $failed;
    try { $fn(); $passed++; echo "  ok   $name\n"; }
    catch (\Throwable $e) { $failed++; echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
function check($cond, $msg = 'assertion failed') { if (!$cond) throw new \Exception($msg); }
function eq($expected, $actual, $msg = '') {
    if ($expected !== $actual) throw new \Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function throws($class, callable $fn, $contains = null) {
    try { $fn(); } catch (\Throwable $e) {
        if (!($e instanceof $class)) throw new \Exception("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        if ($contains !== null && stripos($e->getMessage(), $contains) === false) throw new \Exception("message '{$e->getMessage()}' lacks '$contains'");
        return;
    }
    throw new \Exception("expected $class, nothing thrown");
}

// ── fixture database ────────────────────────────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("
CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE warehouses (id INTEGER PRIMARY KEY, name TEXT, sort_order INT DEFAULT 0);
CREATE TABLE users (id INTEGER PRIMARY KEY, fname TEXT, lname TEXT);
CREATE TABLE containers (
  id INTEGER PRIMARY KEY, container_number TEXT, type TEXT, status TEXT, customer_id INT, warehouse_id INT,
  carrier TEXT, shipment_number TEXT, seal_number TEXT, po_bol_number TEXT, piece_count INT,
  receipt_ship_date TEXT, notes TEXT, created_by INT, created_at TEXT, updated_at TEXT, archived_at TEXT);
INSERT INTO customers VALUES (1,'Acme Imports'),(2,'Beta Foods');
INSERT INTO warehouses VALUES (1,'North',0),(2,'South',1);
INSERT INTO users VALUES (1,'Dan','R');
INSERT INTO containers (id,container_number,type,status,customer_id,warehouse_id,carrier,piece_count,receipt_ship_date,notes,created_by,created_at) VALUES
 (1,'MSCU1000001','inbound','pending',    1,1,'ABC Trucking',100,'2026-10-06','50% damaged',1,'2026-10-06 09:00:00'),
 (2,'MSCU1000002','inbound','completed',  1,2,'ABC Trucking', 50,'2026-10-01',NULL,         1,'2026-10-01 10:00:00'),
 (3,'TGHU2000003','outbound','in_progress',2,1,'XYZ Freight', 20,'2026-09-15',NULL,         1,'2026-09-15 08:00:00'),
 (4,'TGHU2000004','outbound','reviewed',   2,NULL,'XYZ Freight',30,'2026-09-02',NULL,       1,'2026-09-02 08:00:00'),
 (5,'O''BRIEN-5','inbound','pending',      NULL,2,NULL,         NULL,NULL,'it''s 100_% fine',1,'2026-10-07 07:00:00');
");
$sqlite = function ($sql, array $params) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$now = new DateTime('2026-10-07 12:00:00');

// Stub of Container Flow's warehouse-tag lookup: user 7 is tagged North only, user 8 untagged.
function getUserWarehouseIds($user_id) { return $user_id == 7 ? [1] : []; }

RbRegistry::addDir(dirname(__DIR__, 3) . '/report_datasets');
$run = function (array $spec, array $ctx = []) use ($sqlite, $now) {
    return RbQuery::run('containers', $spec, $ctx + ['now' => $now], $sqlite, 'sqlite');
};
$ids = function (array $res) { return array_map('intval', array_column($res['rows'], 'id')); };

echo "Registry\n";

test('containers dataset loads from usersc/report_datasets/', function () {
    $ds = RbRegistry::get('containers');
    check($ds !== null, 'not registered');
    eq('c', $ds['alias']);
    eq('cu.name', $ds['fields']['customer']['expr']);
    check($ds['fields']['piece_count']['aggregatable']);
    check(!$ds['fields']['notes']['groupable']);
});

test('a new dataset is one config file, no builder changes', function () use ($pdo) {
    $dir = sys_get_temp_dir() . '/rb_ds_' . getmypid();
    @mkdir($dir);
    file_put_contents("$dir/warehouses_list.php", '<?php rb_register_dataset("warehouses_list", [
        "table" => "warehouses", "alias" => "w",
        "fields" => ["name" => ["type" => "text"], "sort_order" => ["type" => "number"]],
    ]);');
    RbRegistry::addDir($dir);
    $res = RbQuery::run('warehouses_list', ['sort' => [['key' => 'name', 'dir' => 'desc']]], [], function ($s, $p) use ($pdo) {
        $st = $pdo->prepare($s); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC);
    }, 'sqlite');
    eq(['South', 'North'], array_column($res['rows'], 'name'));
    unlink("$dir/warehouses_list.php"); rmdir($dir);
});

test('bad dataset configs are rejected', function () {
    throws('RbConfigException', function () { rb_register_dataset('Bad-Key', ['table' => 't', 'fields' => ['a' => []]]); });
    throws('RbConfigException', function () { rb_register_dataset('x', ['table' => 't; DROP', 'fields' => ['a' => []]]); });
    throws('RbConfigException', function () { rb_register_dataset('x', ['table' => 't', 'fields' => ['a' => ['type' => 'blob']]]); });
    throws('RbConfigException', function () { rb_register_dataset('x', ['table' => 't', 'fields' => ['a' => ['join' => 'nope']]]); });
    throws('RbConfigException', function () { rb_register_dataset('x', ['table' => 't', 'fields' => ['a' => []], 'default_date_field' => 'a']); });
});

test('a broken dataset file is reported, others still load', function () {
    $dir = sys_get_temp_dir() . '/rb_bad_' . getmypid();
    @mkdir($dir);
    file_put_contents("$dir/broken.php", '<?php rb_register_dataset("broken", ["table" => "x y", "fields" => ["a" => []]]);');
    RbRegistry::addDir($dir);
    check(isset(RbRegistry::errors()['broken.php']), 'error not recorded');
    check(RbRegistry::get('containers') !== null, 'containers lost');
    unlink("$dir/broken.php"); rmdir($dir);
});

echo "Query builder\n";

test('default fields, only needed joins', function () use ($run) {
    $q = RbQuery::build(RbRegistry::get('containers'), ['fields' => ['container_number', 'status']]);
    check(strpos($q['sql'], 'JOIN') === false, 'joined tables it did not need: ' . $q['sql']);
    $q = RbQuery::build(RbRegistry::get('containers'), []);
    check(strpos($q['sql'], 'LEFT JOIN `customers` cu') !== false);
    check(strpos($q['sql'], '`users`') === false);
    eq(5, count($run([])['rows']));
});

test('eq / in / neq filters', function () use ($run, $ids) {
    eq([1, 5], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'pending']], 'sort' => ['id']])));
    eq([2, 3], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'status', 'op' => 'in', 'value' => ['completed', 'in_progress']]], 'sort' => ['id']])));
    eq([3, 4], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'customer', 'op' => 'eq', 'value' => 'Beta Foods']], 'sort' => ['id']])));
    eq([1, 2, 3], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'status', 'op' => 'neq', 'value' => 'reviewed'], ['field' => 'customer_id', 'op' => 'not_null']], 'sort' => ['id']])));
});

test('number comparisons and between', function () use ($run, $ids) {
    eq([1, 2], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'piece_count', 'op' => 'gte', 'value' => '50']], 'sort' => ['id']])));
    eq([3, 4], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'piece_count', 'op' => 'between', 'value' => [20, 30]]], 'sort' => ['id']])));
});

test('contains escapes % and _ (they match literally)', function () use ($run, $ids) {
    eq([1], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'notes', 'op' => 'contains', 'value' => '50%']]])));
    eq([5], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'notes', 'op' => 'contains', 'value' => '100_%']]])));
    eq([5], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'notes', 'op' => 'contains', 'value' => '_']]])), 'unescaped _ would also match row 1');
    eq([3, 4], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'container_number', 'op' => 'starts_with', 'value' => 'TGHU']], 'sort' => ['id']])));
});

test('is_null', function () use ($run, $ids) {
    eq([5], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'customer_id', 'op' => 'is_null']]])));
});

test('group by client with count and sum, sorted by aggregate', function () use ($run) {
    $res = $run([
        'group_by'   => ['customer'],
        'aggregates' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'piece_count']],
        'filters'    => [['field' => 'customer_id', 'op' => 'not_null']],
        'sort'       => [['key' => 'sum__piece_count', 'dir' => 'desc']],
    ]);
    check($res['grouped']);
    eq(['customer', 'count__all', 'sum__piece_count'], array_keys($res['columns']));
    eq('Acme Imports', $res['rows'][0]['customer']);
    eq(2, (int) $res['rows'][0]['count__all']);
    eq(150, (int) $res['rows'][0]['sum__piece_count']);
    eq(50, (int) $res['rows'][1]['sum__piece_count']);
});

test('group by month bucket', function () use ($run) {
    $res = $run(['group_by' => [['field' => 'created_at', 'bucket' => 'month']], 'aggregates' => [['fn' => 'count']], 'sort' => ['created_at__month']]);
    eq([['created_at__month' => '2026-09', 'count__all' => 2], ['created_at__month' => '2026-10', 'count__all' => 3]],
       array_map(function ($r) { return ['created_at__month' => $r['created_at__month'], 'count__all' => (int) $r['count__all']]; }, $res['rows']));
});

test('aggregates without group_by give one totals row', function () use ($run) {
    $res = $run(['aggregates' => [['fn' => 'count'], ['fn' => 'count_distinct', 'field' => 'customer'], ['fn' => 'max', 'field' => 'created_at']]]);
    eq(1, count($res['rows']));
    eq(5, (int) $res['rows'][0]['count__all']);
    eq(2, (int) $res['rows'][0]['count_distinct__customer']);
    eq('2026-10-07 07:00:00', $res['rows'][0]['max__created_at']);
});

test('date windows', function () use ($run, $ids) {
    eq([1, 2, 5], $ids($run(['fields' => ['id'], 'date_window' => ['range' => 'last_7_days'], 'sort' => ['id']])));
    eq([5],       $ids($run(['fields' => ['id'], 'date_window' => ['range' => 'today']])));
    eq([1],       $ids($run(['fields' => ['id'], 'date_window' => ['range' => 'yesterday']])));
    eq([3, 4],    $ids($run(['fields' => ['id'], 'date_window' => ['range' => 'last_month'], 'sort' => ['id']])));
    eq([1, 5],    $ids($run(['fields' => ['id'], 'date_window' => ['range' => 'since_last_report'], 'sort' => ['id']], ['last_sent_at' => '2026-10-05 00:00:00'])));
    eq([2, 3],    $ids($run(['fields' => ['id'], 'date_window' => ['field' => 'receipt_ship_date', 'range' => 'custom', 'start' => '2026-09-10', 'end' => '2026-10-01'], 'sort' => ['id']])));
    eq(5, count($run(['fields' => ['id'], 'date_window' => ['range' => 'all_time']])['rows']));
});

test('dateBounds matches the original getReportDateRangeBounds ranges', function () use ($now) {
    $f = function ($r) use ($now) { [$s, $e] = RbQuery::dateBounds(['range' => $r], ['now' => $now]); return [$s ? $s->format('Y-m-d H:i:s') : null, $e ? $e->format('Y-m-d H:i:s') : null]; };
    eq(['2026-10-07 00:00:00', '2026-10-07 12:00:00'], $f('today'));
    eq(['2026-09-30 12:00:00', '2026-10-07 12:00:00'], $f('last_7_days'));
    eq(['2026-10-01 00:00:00', '2026-10-07 12:00:00'], $f('this_month'));
    eq(['2026-09-01 00:00:00', '2026-09-30 23:59:59'], $f('last_month'));
    eq(['2026-10-05 00:00:00', '2026-10-07 12:00:00'], $f('this_week'));
    eq([null, null], $f('all_time'));
});

test('warehouse scope: tagged user sees own warehouse + unassigned', function () use ($run, $ids) {
    eq([1, 3, 4], $ids($run(['fields' => ['id'], 'sort' => ['id']], ['user_id' => 7])));
    eq(5, count($run(['fields' => ['id']], ['user_id' => 8])['rows']), 'untagged user unrestricted');
    eq(5, count($run(['fields' => ['id']], ['user_id' => 7, 'unscoped' => true])['rows']), 'unscoped bypass');
    // Scope applies to grouped queries too.
    $res = $run(['aggregates' => [['fn' => 'count']]], ['user_id' => 7]);
    eq(3, (int) $res['rows'][0]['count__all']);
});

test('limit is clamped', function () {
    $ds = RbRegistry::get('containers');
    check(substr(RbQuery::build($ds, ['limit' => 999999])['sql'], -11) === 'LIMIT 10000');
    check(substr(RbQuery::build($ds, ['limit' => -5])['sql'], -7) === 'LIMIT 1');
    check(substr(RbQuery::build($ds, ['limit' => '3; DROP TABLE x'])['sql'], -7) === 'LIMIT 3');
});

echo "Injection / validation\n";

test('user values only ever appear as bound params', function () use ($run) {
    $evil = "x' OR '1'='1' --";
    $spec = ['fields' => ['id'], 'filters' => [
        ['field' => 'customer', 'op' => 'eq', 'value' => $evil],
        ['field' => 'carrier', 'op' => 'contains', 'value' => $evil],
        ['field' => 'container_number', 'op' => 'in', 'value' => [$evil, 'a']],
    ]];
    $q = RbQuery::build(RbRegistry::get('containers'), $spec);
    check(strpos($q['sql'], 'OR') === false, 'value leaked into SQL: ' . $q['sql']);
    check(in_array($evil, $q['params'], true));
    eq(0, count($run($spec)['rows']));
});

test('a quote in data is matched safely', function () use ($run, $ids) {
    eq([5], $ids($run(['fields' => ['id'], 'filters' => [['field' => 'container_number', 'op' => 'eq', 'value' => "O'BRIEN-5"]]])));
});

test('unknown field keys are rejected everywhere', function () {
    $ds = RbRegistry::get('containers');
    $bad = 'id FROM users; --';
    throws('RbQueryException', function () use ($ds, $bad) { RbQuery::build($ds, ['fields' => [$bad]]); }, 'unknown field');
    throws('RbQueryException', function () use ($ds, $bad) { RbQuery::build($ds, ['filters' => [['field' => $bad, 'op' => 'eq', 'value' => 1]]]); });
    throws('RbQueryException', function () use ($ds, $bad) { RbQuery::build($ds, ['group_by' => [$bad]]); });
    throws('RbQueryException', function () use ($ds, $bad) { RbQuery::build($ds, ['aggregates' => [['fn' => 'sum', 'field' => $bad]]]); });
    throws('RbQueryException', function () use ($ds, $bad) { RbQuery::build($ds, ['sort' => [['key' => $bad]]]); });
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['fields' => [['id']]]); });
    throws('RbQueryException', function () { RbQuery::run('users', []); }, 'unknown dataset');
});

test('operators, functions, directions, buckets are whitelisted', function () {
    $ds = RbRegistry::get('containers');
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['filters' => [['field' => 'carrier', 'op' => '= 1 OR 1=1 --', 'value' => 'x']]]); });
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['filters' => [['field' => 'piece_count', 'op' => 'contains', 'value' => '1']]]); }, "isn't available");
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['aggregates' => [['fn' => 'SLEEP(5)']]]); });
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['sort' => [['key' => 'id', 'dir' => 'desc; DROP TABLE containers']]]); });
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['group_by' => [['field' => 'created_at', 'bucket' => "hour')"]]]); });
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['date_window' => ['range' => 'forever']]); });
});

test('values are type-checked', function () {
    $ds = RbRegistry::get('containers');
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['filters' => [['field' => 'piece_count', 'op' => 'gt', 'value' => '1 OR 1']]]); }, 'number');
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'deleted']]]); }, 'valid choice');
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['filters' => [['field' => 'created_at', 'op' => 'gt', 'value' => 'yesterday']]]); }, 'date');
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['filters' => [['field' => 'carrier', 'op' => 'eq', 'value' => ['a' => 1]]]]); });
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['filters' => [['field' => 'carrier', 'op' => 'in', 'value' => []]]]); });
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['date_window' => ['range' => 'custom', 'start' => '2026-02-01', 'end' => '2026-01-01']]); }, 'after');
});

test('field capability flags are enforced', function () {
    $ds = RbRegistry::get('containers');
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['group_by' => ['notes']]); }, 'grouped');
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['aggregates' => [['fn' => 'sum', 'field' => 'carrier']]]); });
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['sort' => [['key' => 'notes']]]); }, 'sorted');
    throws('RbQueryException', function () use ($ds) { RbQuery::build($ds, ['group_by' => ['customer'], 'sort' => [['key' => 'carrier']]]); }, 'shown column');
});

test('error messages escape echoed input', function () {
    $ds = RbRegistry::get('containers');
    try { RbQuery::build($ds, ['fields' => ['<script>alert(1)</script>']]); check(false); }
    catch (RbQueryException $e) { check(strpos($e->getMessage(), '<script>') === false, $e->getMessage()); }
});

echo "MySQL dialect\n";

test('MySQL SQL text for buckets and computed fields', function () {
    $ds = RbRegistry::get('containers');
    $q = RbQuery::build($ds, ['group_by' => [['field' => 'created_at', 'bucket' => 'month'], 'created_by_name'], 'aggregates' => [['fn' => 'count']]]);
    eq("SELECT DATE_FORMAT(c.created_at, '%Y-%m') AS `created_at__month`, CONCAT_WS(' ', u.fname, u.lname) AS `created_by_name`, COUNT(*) AS `count__all` FROM `containers` c LEFT JOIN `users` u ON u.id = c.created_by GROUP BY DATE_FORMAT(c.created_at, '%Y-%m'), CONCAT_WS(' ', u.fname, u.lname) LIMIT 5000", $q['sql']);
    $q = RbQuery::build($ds, ['group_by' => [['field' => 'created_at', 'bucket' => 'week']], 'aggregates' => [['fn' => 'count']]]);
    check(strpos($q['sql'], 'WEEKDAY(c.created_at)') !== false);
});

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
