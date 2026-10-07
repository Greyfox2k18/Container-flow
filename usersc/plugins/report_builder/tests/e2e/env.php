<?php
// Fake UserSpice environment for the editor e2e test. Dev only — see run_e2e.sh.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) die();
$R = dirname(__DIR__, 5);
$work = getenv('RB_E2E_DIR') ?: sys_get_temp_dir() . '/rb_e2e';
@mkdir($work, 0777, true);
require "$R/usersc/plugins/report_builder/tests/fake_userspice.php";
$dbFile = $work . '/e2e.db';
if (!is_file($dbFile)) {
    rb_fixture_db("sqlite:$dbFile");
    $st = DB::$pdo->prepare('INSERT INTO containers (id,container_number,type,status,customer_id,warehouse_id,carrier,piece_count,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,1,?,?)');
    foreach ([
        [1,'MSCU1000001','inbound','completed',1,1,'ABC Trucking',100,'2026-10-06 09:00:00','2026-10-06 15:30:00'],
        [2,'MSCU1000002','outbound','in_progress',2,2,'XYZ Freight',40,'2026-10-05 10:00:00','2026-10-06 11:00:00'],
        [3,'TGHU2000003','inbound','pending',1,1,'ABC Trucking',20,'2026-10-07 08:00:00','2026-10-07 08:00:00'],
        [4,'TGHU2000004','inbound','pending',2,null,'XYZ Freight',30,'2026-10-07 09:00:00','2026-10-07 09:00:00'],
    ] as $c) $st->execute($c);
} else {
    DB::connect("sqlite:$dbFile");
}
function getUserWarehouseIds($id) { return []; }
$master_account = [1];
$us_url_root = '/';
$abs_us_root = $R;
require "$R/usersc/plugins/report_builder/functions.php";
RbReports::$configFile = __DIR__ . '/config.php';
$GLOBALS['rb_e2e_work'] = $work;
class Token { static function generate() { return 'tok123'; } static function check($t) { return $t === 'tok123'; } }
class Input { static function get($k) { $v = $_POST[$k] ?? $_GET[$k] ?? ''; return is_string($v) ? htmlspecialchars(trim($v), ENT_QUOTES) : $v; } }
class U { function data() { return (object) ['id' => 1, 'email' => 'dan@example.com']; } function isLoggedIn() { return true; } }
$user = new U;
