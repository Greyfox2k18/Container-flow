<?php
/**
 * Stage 4 tests: built-in UserSpice datasets (users, user_logs), dataset
 * access control, project overrides, the User Activity preset.
 * Included by run_tests.php.
 */
if (PHP_SAPI !== 'cli') die();

echo "Built-in datasets & access\n";

$P = dirname(__DIR__);
$projectDir = dirname(__DIR__, 3) . '/report_datasets';
$builtinDir = $P . '/assets/datasets';
$reloadRegistry = function (array $extraProjectDirs = []) use ($projectDir, $builtinDir) {
    RbRegistry::reset();
    RbRegistry::addDir($projectDir);
    foreach ($extraProjectDirs as $d) RbRegistry::addDir($d);
    RbRegistry::addDir($builtinDir, true);
};
$cfg4 = sys_get_temp_dir() . '/rb_cfg4_' . getmypid() . '.php';
$writeCfg = function ($builtin = true) use ($cfg4) {
    file_put_contents($cfg4, '<?php return [
        "brand" => "Container Flow", "base_url" => "https://container-flow.com",
        "builtin_datasets" => ' . ($builtin ? 'true' : 'false') . ',
        "mailer" => function (array $to, $subject, $html, array $att) { $GLOBALS["rb_sent"][] = compact("to", "subject", "html", "att"); return ["success" => true]; },
        "can_build"   => function ($u) { return in_array($u, [1, 7]); },
        "can_unscope" => function ($u) { return $u == 1; },
    ];');
    RbReports::$configFile = $cfg4;
    RbReports::resetConfig();
};
$writeCfg();
$reloadRegistry();

rb_fixture_db('sqlite::memory:');
DB::$pdo->exec("
    UPDATE users SET username = 'dan', logins = 42, last_login = '2026-10-07 08:00:00', join_date = '2025-01-01 00:00:00' WHERE id = 1;
    UPDATE users SET username = 'sue', logins = 3,  last_login = '2026-08-01 08:00:00', join_date = '2025-06-01 00:00:00' WHERE id = 2;
    INSERT INTO users (id,email,fname,lname,active,username,logins,last_login) VALUES (7,'nora@example.com','Nora','North',1,'nora',9,'2026-10-06 17:00:00');
    INSERT INTO user_permission_matches VALUES (1,2);
    INSERT INTO logs (user_id,logdate,logtype,lognote,ip) VALUES
      (1,'2026-10-06 08:00:00','login','User logged in.','10.0.0.1'),
      (7,'2026-10-06 17:00:00','login','User logged in.','10.0.0.7'),
      (7,'2026-10-07 09:00:00','Login Fail','Bad password','10.0.0.7'),
      (1,'2026-10-07 10:00:00','USPlugins','report_builder installed','10.0.0.1'),
      (2,'2026-08-01 08:00:00','login','User logged in.','10.0.0.2');
");
rb_seed([[1, 'N1', 'inbound', 'pending', 1, 1, null, 1, '2026-10-06 08:00:00', '2026-10-06 08:00:00']]);

test('built-in users/user_logs load after project datasets', function () {
    eq(['containers', 'user_logs', 'users'], array_keys(RbRegistry::all()));   // project first, then built-ins A–Z by file
    eq('Users', RbRegistry::get('users')['label']);
    eq([], RbRegistry::errors());
});

test('a project dataset with the same key replaces the built-in one', function () use ($reloadRegistry) {
    $dir = sys_get_temp_dir() . '/rb_override_' . getmypid();
    @mkdir($dir);
    file_put_contents("$dir/users.php", '<?php rb_register_dataset("users", ["label" => "Staff", "table" => "users", "alias" => "u", "fields" => ["email" => []]]);');
    $reloadRegistry([$dir]);
    eq('Staff', RbRegistry::get('users')['label']);
    unlink("$dir/users.php"); rmdir($dir);
    $reloadRegistry();
    eq('Users', RbRegistry::get('users')['label']);
});

test("'builtin_datasets' => false turns them off", function () use ($reloadRegistry, $writeCfg) {
    $writeCfg(false);
    $reloadRegistry();
    eq(['containers'], array_keys(RbRegistry::all()));
    $writeCfg(true);
    $reloadRegistry();
    eq(3, count(RbRegistry::all()));
});

test('users and user_logs datasets query correctly', function () {
    $r = RbQuery::run('users', ['fields' => ['name', 'username', 'logins'], 'sort' => [['key' => 'logins', 'dir' => 'desc']]], [], null, 'sqlite');
    eq(['Dan R', 'Nora North', 'Sue North', 'Old Sup'], array_column($r['rows'], 'name'));
    $r = RbQuery::run('user_logs', ['group_by' => ['user_name'], 'aggregates' => [['fn' => 'count']],
        'filters' => [['field' => 'logtype', 'op' => 'contains', 'value' => 'login']], 'sort' => [['key' => 'user_name']]], [], null, 'sqlite');
    eq([['user_name' => 'Dan R', 'count__all' => 1], ['user_name' => 'Nora North', 'count__all' => 2], ['user_name' => 'Sue North', 'count__all' => 1]],
       array_map(function ($x) { return ['user_name' => $x['user_name'], 'count__all' => (int) $x['count__all']]; }, $r['rows']));
});

test('only admins see and can use the user datasets', function () {
    check(RbRegistry::canAccess('users', 1) && RbRegistry::canAccess('user_logs', 1), 'admin (perm 2)');
    check(!RbRegistry::canAccess('users', 7) && !RbRegistry::canAccess('user_logs', 7), 'supervisor');
    check(RbRegistry::canAccess('containers', 7), 'containers open to builders');
    eq(['containers', 'user_logs', 'users'], array_column(RbApi::handle('meta', [], 1)['datasets'], 'key'));
    eq(['containers'], array_column(RbApi::handle('meta', [], 7)['datasets'], 'key'));
    $layout = ['blocks' => [['type' => 'table', 'dataset' => 'users', 'query' => ['fields' => ['email']]]]];
    foreach (['preview', 'save'] as $a) {
        $r = RbApi::handle($a, ['report' => ['name' => 'x'], 'layout' => $layout], 7);
        check(!$r['ok'] && stripos($r['error'], "don't have access") !== false, "$a: " . json_encode($r));
    }
    // Sneaking it in through a metric or report filter is caught too.
    $r = RbApi::handle('preview', ['report' => ['name' => 'x'], 'layout' => ['metrics' => ['n' => ['dataset' => 'user_logs']]]], 7);
    check(!$r['ok'], 'metric on user_logs');
});

test("an admin's user-data report is invisible to other builders", function () {
    $GLOBALS['rb_sent'] = [];
    $made = RbApi::handle('create_preset', ['preset' => 'user_activity'], 1);
    check($made['ok'], json_encode($made));
    $id = $made['id'];
    check(in_array($id, array_column(RbApi::handle('list', [], 1)['reports'], 'id')), 'admin sees it');
    check(!in_array($id, array_column(RbApi::handle('list', [], 7)['reports'], 'id')), 'supervisor sees it in list');
    foreach (['load', 'duplicate', 'toggle', 'delete', 'send_test', 'send_now', 'run_log'] as $a) {
        $r = RbApi::handle($a, ['id' => $id], 7);
        check(!$r['ok'] && $r['error'] === 'Report not found.', "$a: " . json_encode($r));
    }
    $r = RbApi::handle('preview', ['id' => $id, 'report' => ['name' => 'x'], 'layout' => ['blocks' => []]], 7);
    check(!$r['ok'], 'preview by id');
    $r = RbApi::handle('save', ['id' => $id, 'report' => ['name' => 'hijack'], 'layout' => ['blocks' => []]], 7);
    check(!$r['ok'] && RbReports::get($id)->name === 'User Activity', 'overwrite');
    eq([], $GLOBALS['rb_sent']);
    check(RbReports::get($id) !== null, 'still exists');
});

test('User Activity preset renders the right numbers', function () {
    $GLOBALS['rb_sent'] = [];
    $id = RbApi::handle('create_preset', ['preset' => 'user_activity'], 1)['id'];
    $r = RbReports::get($id);
    eq('weekly', $r->schedule_frequency);
    eq(1, (int) $r->schedule_day_of_week);
    $res = RbReports::run($r, 'manual', ['now' => new DateTime('2026-10-07 12:00:00')]);
    check($res['success'], $res['message']);
    $sent = $GLOBALS['rb_sent'][0];
    eq(['dan@example.com'], $sent['to'], 'Administrators (perm 2)');
    eq('User activity — 2 users signed in this week — Oct 7, 2026', $sent['subject']);
    $t = rb_text($sent['html']);
    check(strpos($t, '3 Active Users 2 Signed In This Week 3 Login Events 4 All Log Events') !== false, $t);
    check(strpos($t, 'Dan R dan@example.com 42') !== false && strpos($t, 'Sue North') === false, 'who signed in table');
    check(preg_match('/Activity by type Type Events Users login 2 2 Login Fail 1 1 USPlugins 1 1 /', $t) === 1, $t);
    check(strpos($t, 'Day Events 2026-10-06 2 2026-10-07 2') !== false, 'by day');
    eq(3, count($sent['att']), 'CSV per table');
});

@unlink($cfg4);
