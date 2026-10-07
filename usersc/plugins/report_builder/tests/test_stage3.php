<?php
/**
 * Stage 3 tests: the editor API (RbApi) — permissions, save/load, validation,
 * scope rules. Included by run_tests.php.
 */
if (PHP_SAPI !== 'cli') die();

require_once __DIR__ . '/../assets/includes/rb_api.php';

echo "Editor API\n";

// Users: 1 = full access (Dan R), 7 = supervisor tagged to North only, 2 = not a builder.
$GLOBALS['master_account'] = [];
$cfg3 = sys_get_temp_dir() . '/rb_cfg3_' . getmypid() . '.php';
file_put_contents($cfg3, '<?php return [
    "brand" => "Container Flow", "base_url" => "https://container-flow.com",
    "mailer" => function (array $to, $subject, $html, array $att) { $GLOBALS["rb_sent"][] = compact("to", "subject"); return ["success" => true, "message" => "Sent via Test"]; },
    "can_build"   => function ($u) { return in_array($u, [1, 7]); },
    "can_send"    => function ($u) { return $u == 1; },
    "can_unscope" => function ($u) { return $u == 1; },
];');
RbReports::$configFile = $cfg3;
RbReports::resetConfig();

rb_fixture_db('sqlite::memory:');
DB::$pdo->exec("INSERT INTO users VALUES (7,'nora@example.com','Nora','North',1)");
rb_seed([
    [1, 'N1', 'inbound',  'pending',   1, 1, null, 1, '2026-10-06 08:00:00', '2026-10-06 08:00:00'],
    [2, 'S1', 'inbound',  'pending',   2, 2, null, 1, '2026-10-06 08:00:00', '2026-10-06 08:00:00'],
    [3, 'U1', 'outbound', 'completed', 1, null, null, 1, '2026-10-06 08:00:00', '2026-10-06 08:00:00'],
]);
$countLayout = ['metrics' => ['n' => ['dataset' => 'containers']], 'blocks' => [['type' => 'text', 'body' => 'n={n}']]];
$report = function (array $extra = []) {
    return array_merge(['name' => 'API test', 'scope_mode' => 'creator', 'schedule_frequency' => 'daily', 'schedule_hour' => 6], $extra);
};

test('non-builders are refused for every action', function () {
    foreach (['meta', 'list', 'load', 'preview', 'save', 'toggle', 'delete', 'send_now', 'nope'] as $a) {
        $r = RbApi::handle($a, ['id' => 1], 2);
        check(!$r['ok'] && stripos($r['error'], 'permission') !== false, "$a: " . json_encode($r));
    }
    $r = RbApi::handle('meta', [], 0);
    check(!$r['ok'], 'logged-out user');
});

test('meta: datasets, ordered enum options, permissions, users, presets, flags', function () {
    $m = RbApi::handle('meta', [], 1);
    check($m['ok'], json_encode($m));
    $ds = $m['datasets'][0];
    eq('containers', $ds['key']);
    $status = array_values(array_filter($ds['fields'], function ($f) { return $f['key'] === 'status'; }))[0];
    eq(['pending', 'in_progress', 'completed', 'reviewed'], array_column($status['options'], 'value'));
    $cust = array_values(array_filter($ds['fields'], function ($f) { return $f['key'] === 'customer_id'; }))[0];
    eq(['Acme Imports', 'Beta & Sons <Foods>'], array_column($cust['options'], 'label'), 'callable options resolved');
    eq('Supervisor', $m['permissions'][2]['name']);
    check(!in_array('old@example.com', array_column($m['users'], 'email')), 'inactive user listed');
    eq('daily_digest', $m['presets'][0]['key']);
    check($m['can_send'] && $m['can_unscope']);
    $m7 = RbApi::handle('meta', [], 7);
    check(!$m7['can_send'] && !$m7['can_unscope']);
});

test('save → load round trip (layout, schedule, recipients + notes)', function () use ($countLayout, $report) {
    $layout = $countLayout;
    $layout['blocks'][] = ['type' => 'table', 'dataset' => 'containers', 'title' => 'Open', 'query' => ['fields' => ['container_number', 'customer'], 'filters' => [['field' => 'status', 'op' => 'in', 'value' => ['pending']]]], 'labels' => ['customer' => 'Client']];
    $r = RbApi::handle('save', ['report' => $report(['description' => 'Desc', 'attach_csv' => true]), 'layout' => $layout, 'recipients' => [
        ['kind' => 'email', 'email' => 'ops@acme.example', 'note' => 'Acme ops'],
        ['kind' => 'permission', 'permission_id' => 3, 'note' => 'Supervisors'],
    ]], 1);
    check($r['ok'], json_encode($r));
    $l = RbApi::handle('load', ['id' => $r['id']], 1);
    check($l['ok']);
    eq($layout, $l['layout']);
    eq('Desc', $l['report']['description']);
    eq(true, $l['report']['attach_csv']);
    eq('daily', $l['report']['schedule_frequency']);
    eq(['ops@acme.example', null], array_column($l['recipients'], 'email'));
    eq(['Acme ops', 'Supervisors'], array_column($l['recipients'], 'note'));
    // Update keeps the id and creator.
    $r2 = RbApi::handle('save', ['id' => $r['id'], 'report' => $report(['name' => 'Renamed']), 'layout' => $layout, 'recipients' => []], 7);
    check($r2['ok'] && $r2['id'] === $r['id'], json_encode($r2));
    eq(1, (int) RbReports::get($r['id'])->created_by);
    eq('Renamed', RbReports::get($r['id'])->name);
});

test('save refuses broken layouts, bad emails and empty names — nothing written', function () use ($countLayout, $report) {
    $before = count(RbReports::all());
    $r = RbApi::handle('save', ['report' => $report(), 'layout' => ['blocks' => [['type' => 'table', 'dataset' => 'containers', 'query' => ['fields' => ['password']]]]]], 1);
    check(!$r['ok'] && stripos($r['error'], 'unknown field') !== false, json_encode($r));
    $r = RbApi::handle('save', ['report' => $report(), 'layout' => $countLayout, 'recipients' => [['kind' => 'email', 'email' => 'nope']]], 1);
    check(!$r['ok'] && stripos($r['error'], 'valid email') !== false, json_encode($r));
    $r = RbApi::handle('save', ['report' => $report(['name' => '  ']), 'layout' => $countLayout], 1);
    check(!$r['ok'] && stripos($r['error'], 'name') !== false);
    $r = RbApi::handle('save', ['report' => $report(), 'layout' => 'not an object'], 1);
    check(!$r['ok']);
    eq($before, count(RbReports::all()));
});

test('preview returns HTML, subject, metrics; errors are readable', function () use ($countLayout, $report) {
    $p = RbApi::handle('preview', ['report' => $report(['scope_mode' => 'none']), 'layout' => $countLayout], 1);
    check($p['ok'], json_encode($p));
    check(strpos($p['html'], 'n=3') !== false);
    eq(['n' => 3], $p['metrics']);
    $p = RbApi::handle('preview', ['report' => $report(), 'layout' => ['blocks' => [['type' => 'chart']]]], 1);
    check(!$p['ok'] && stripos($p['error'], 'unknown block') !== false);
});

test('a restricted builder always previews within their own warehouses', function () use ($countLayout, $report) {
    $p = RbApi::handle('preview', ['report' => $report(['scope_mode' => 'none']), 'layout' => $countLayout], 7);
    check($p['ok'], json_encode($p));
    eq(2, $p['metrics']['n']);   // North (N1) + unassigned (U1), not South
});

test('only full-access users can create, activate or keep unrestricted reports', function () use ($countLayout, $report) {
    $r = RbApi::handle('save', ['report' => $report(['scope_mode' => 'none']), 'layout' => $countLayout], 7);
    check(!$r['ok'] && stripos($r['error'], 'restrictions') !== false, json_encode($r));
    $ok = RbApi::handle('save', ['report' => $report(['scope_mode' => 'none']), 'layout' => $countLayout], 1);
    check($ok['ok']);
    $t = RbApi::handle('toggle', ['id' => $ok['id']], 7);
    check(!$t['ok'], 'restricted user activated an unscoped report');
    $dup = RbApi::handle('duplicate', ['id' => $ok['id']], 7);
    eq('creator', RbReports::get($dup['id'])->scope_mode, 'copy made by restricted user is scoped');
    $pre = RbApi::handle('create_preset', ['preset' => 'daily_digest'], 7);
    eq('recipient', RbReports::get($pre['id'])->scope_mode, 'unscoped preset downgraded for restricted user');
    eq(0, (int) RbReports::get($pre['id'])->active);
});

test('send permissions: test-to-me for builders, send-now needs can_send', function () use ($countLayout, $report) {
    $r = RbApi::handle('save', ['report' => $report(), 'layout' => $countLayout, 'recipients' => [['kind' => 'email', 'email' => 'x@y.example']]], 1);
    $GLOBALS['rb_sent'] = [];
    $t = RbApi::handle('send_test', ['id' => $r['id']], 7);
    check($t['ok'] && $t['message'] === 'Test sent to nora@example.com.', json_encode($t));
    eq(['nora@example.com'], $GLOBALS['rb_sent'][0]['to']);
    $n = RbApi::handle('send_now', ['id' => $r['id']], 7);
    check(!$n['ok'] && stripos($n['error'], 'permission') !== false);
    $n = RbApi::handle('send_now', ['id' => $r['id']], 1);
    check($n['ok'] && strpos($n['message'], 'Sent via Test') !== false, json_encode($n));
    eq(['x@y.example'], $GLOBALS['rb_sent'][1]['to']);
});

test('list, duplicate, toggle, delete', function () use ($countLayout, $report) {
    $r = RbApi::handle('save', ['report' => $report(['name' => 'Zed']), 'layout' => $countLayout, 'recipients' => [['kind' => 'email', 'email' => 'z@y.example', 'note' => 'n']]], 1);
    $d = RbApi::handle('duplicate', ['id' => $r['id']], 1);
    eq('Copy of Zed', RbReports::get($d['id'])->name);
    eq(['z@y.example'], array_column(RbApi::handle('load', ['id' => $d['id']], 1)['recipients'], 'email'));
    $t = RbApi::handle('toggle', ['id' => $r['id']], 1);
    check($t['ok'] && $t['active'] === true);
    $list = RbApi::handle('list', [], 1)['reports'];
    $zed = array_values(array_filter($list, function ($x) { return $x['name'] === 'Zed'; }))[0];
    check($zed['active'] && $zed['recipients'] === 1 && $zed['schedule'] === 'Daily at 6 AM', json_encode($zed));
    check(RbApi::handle('delete', ['id' => $r['id']], 1)['ok']);
    check(!RbApi::handle('load', ['id' => $r['id']], 1)['ok']);
});

@unlink($cfg3);
