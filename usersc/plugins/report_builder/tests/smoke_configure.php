<?php
/**
 * Smoke test for the plugin's admin page (configure.php) with stubbed
 * UserSpice globals: every action, settings save rules, escaping.
 *   php usersc/plugins/report_builder/tests/smoke_configure.php
 * Separate from run_tests.php because it stubs Input/Token/Redirect/email().
 */
if (PHP_SAPI !== 'cli') die();
$P = dirname(__DIR__);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException("$str @ " . basename($file) . ":$line"); });
require "$P/tests/fake_userspice.php";
rb_fixture_db('sqlite::memory:');
DB::$pdo->exec("INSERT INTO containers (id,container_number,type,status,customer_id,created_by,created_at,updated_at) VALUES (1,'X1','inbound','completed',1,1,'2026-10-06 09:00:00','2026-10-06 09:00:00')");
require "$P/functions.php";
function getUserWarehouseIds($id) { return []; }
$cfgFile = sys_get_temp_dir() . '/smoke_cfg.php';
file_put_contents($cfgFile, '<?php return ["brand" => "Smoke", "editor_url" => "usersc/reports_builder.php"];');
RbReports::$configFile = $cfgFile;
RbReports::resetConfig();
RbMail::$transport = function ($url, $headers, $body) { $GLOBALS['http'][] = [$url, json_decode($body, true)]; return [200, '{"results":{"id":"1"}}']; };
function email($to, $s, $b) { $GLOBALS['sent'][] = [[$to], $s]; return true; }

class Input { static function get($k) { $v = $_POST[$k] ?? $_GET[$k] ?? ''; return is_string($v) ? htmlspecialchars(trim($v), ENT_QUOTES) : $v; } }
class Token { static function check($t) { return $t === 'tok'; } }
class Redirect { static function to($u) { throw new Exception("REDIRECT $u"); } }
function tokenHere() { return '<input type="hidden" name="csrf" value="tok">'; }
function pluginActive($n, $b = false) { return true; }
class U { function data() { return (object) ['id' => 1, 'email' => 'dan@example.com']; } }
$user = new U; $master_account = [1]; $us_url_root = '/'; $abs_us_root = '/x'; $db = DB::getInstance();

function page($get, $post = []) {
    global $user, $master_account, $us_url_root, $abs_us_root, $db;
    $_GET = $get + ['view' => 'plugins_config', 'plugin' => 'report_builder']; $_POST = $post;
    ob_start();
    try { include dirname(__DIR__) . '/configure.php'; }
    catch (Exception $e) { if (strpos($e->getMessage(), 'REDIRECT') !== 0) { ob_end_clean(); throw $e; } ob_end_clean(); return $e->getMessage(); }
    return ob_get_clean();
}
$ok = function ($cond, $what) { echo ($cond ? "  ok   " : "  FAIL ") . "$what\n"; if (!$cond) $GLOBALS['fail'] = 1; };

$html = page([]);                                  $ok(strpos($html, 'No reports yet') !== false, 'empty list renders');
$ok(strpos($html, 'Daily Digest') !== false, 'preset offered');
$red = page([], ['csrf' => 'tok', 'action' => 'create_preset', 'preset' => 'daily_digest']);
$ok(strpos($red, 'rb_edit=1') !== false && strpos($red, 'rb_msg=created') !== false, "create from preset redirects ($red)");
$html = page(['rb_edit' => 1, 'rb_preview' => 1, 'rb_msg' => 'created']);
$ok(strpos($html, 'Report created (paused)') !== false, 'created message');
$ok(strpos($html, 'perm:3 | Supervisors') !== false, 'recipients text');
$ok(strpos($html, 'Container Tracking: 1 Container Awaiting Review') !== false, 'preview subject');
$ok(strpos($html, 'srcdoc=') !== false && strpos($html, 'sandbox=""') !== false, 'sandboxed preview iframe');
$ok(strpos($html, 'Daily at 6 AM') !== false, 'schedule text');
$layout = json_encode(json_decode((string) RbReports::get(1)->layout_json, true));
$html = page(['rb_edit' => 1], ['csrf' => 'tok', 'action' => 'save', 'report_id' => 1, 'name' => 'Digest & Co', 'description' => '', 'schedule_frequency' => 'weekly',
    'schedule_day_of_week' => '1', 'schedule_day_of_month' => '1', 'schedule_hour' => '7', 'scope_mode' => 'recipient', 'recipients' => "perm:3 | Supervisors\nboss@client.example | Client boss\nbad-email", 'layout_json' => $layout]);
$ok(strpos($html, 'Saved.') !== false, 'save ok');
$ok(RbReports::get(1)->name === 'Digest & Co', 'name stored raw (not double-escaped): ' . RbReports::get(1)->name);
$ok(strpos($html, 'Invalid email &#039;bad-email&#039;') !== false, 'bad recipient reported');
$ok(RbReports::get(1)->schedule_frequency === 'weekly' && (int) RbReports::get(1)->schedule_hour === 7, 'schedule saved');
$html = page(['rb_edit' => 1], ['csrf' => 'tok', 'action' => 'save', 'report_id' => 1, 'name' => 'X', 'scope_mode' => 'none', 'recipients' => '', 'layout_json' => '{"blocks":[{"type":"table","dataset":"nope"}]}']);
$ok(strpos($html, 'Layout problem') !== false && RbReports::get(1)->name === 'Digest & Co', 'broken layout refused, nothing saved');
$html = page(['rb_edit' => 1], ['csrf' => 'tok', 'action' => 'save', 'report_id' => 1, 'name' => 'X', 'layout_json' => '{not json']);
$ok(strpos($html, 'not valid JSON') !== false, 'bad JSON refused');
$html = page([], ['csrf' => 'tok', 'action' => 'send_test', 'report_id' => 1]);
$ok(strpos($html, 'Test sent to dan@example.com') !== false && $GLOBALS['sent'][0][0] === ['dan@example.com'], 'send test to me');
$html = page([], ['csrf' => 'tok', 'action' => 'toggle', 'report_id' => 1]);
$ok(strpos($html, 'Report activated') !== false && RbReports::get(1)->active == 1, 'activate');
$html = page([], ['csrf' => 'tok', 'action' => 'check_datasets']);
$ok(strpos($html, '>OK<') !== false, 'dataset check');
$html = page([], ['csrf' => 'tok', 'action' => 'delete', 'report_id' => 1]);
$ok(strpos($html, 'Report deleted') !== false && !RbReports::get(1), 'delete');
// ── settings
$html = page([], ['csrf' => 'tok', 'action' => 'save_settings', 'mail_provider' => 'sparkpost', 'sparkpost_api_key' => 'SECRETKEY1234', 'sparkpost_region' => 'us',
    'from_email' => 'reports@site.example', 'from_name' => 'Reports', 'reply_to' => '', 'base_url' => 'https://site.example/', 'brand' => 'Acme', 'primary_color' => '#112233',
    'build_perms' => ['2', '3'], 'unscope_perms' => ['2'], 'build_perms_present' => 1, 'send_perms_present' => 1, 'unscope_perms_present' => 1]);
$ok(strpos($html, 'Settings saved.') !== false, 'settings saved');
$st = RbReports::settings();
$ok($st['sparkpost_api_key'] === 'SECRETKEY1234' && $st['build_perms'] === '2,3' && $st['base_url'] === 'https://site.example', 'values stored (trailing slash trimmed)');
$ok(strpos($html, 'SECRETKEY1234') === false && strpos($html, 'ends in 1234') !== false, 'key never echoed, only last 4');
$ok(strpos($html, 'value="3" selected') !== false, 'perm multi-select shows saved');
$ok(strpos($html, 'name="brand" value="Smoke" disabled') !== false, 'brand from file: shows the value in effect, locked');
$ok(strpos($html, 'title="Set in usersc/report_builder_config.php"') !== false, 'file-set fields marked');
page([], ['csrf' => 'tok', 'action' => 'save_settings', 'mail_provider' => 'sparkpost', 'sparkpost_api_key' => '', 'base_url' => 'https://site.example']);
$ok(RbReports::settings()['sparkpost_api_key'] === 'SECRETKEY1234', 'blank key field keeps the saved key');
$ok(RbReports::settings()['from_name'] === 'Reports' && RbReports::settings()['build_perms'] === '2,3', 'fields not posted are left alone');
page([], ['csrf' => 'tok', 'action' => 'save_settings', 'send_perms_present' => '1']);
$ok(RbReports::settings()['send_perms'] === '', 'clearing a multi-select saves "none"');
$html = page([], ['csrf' => 'tok', 'action' => 'save_settings', 'mail_provider' => 'sparkpost', 'from_email' => 'nope', 'base_url' => 'ftp://x']);
$ok(strpos($html, 'isn&#039;t a valid email') !== false && strpos($html, 'must start with http') !== false, 'validation');
$html = page([], ['csrf' => 'tok', 'action' => 'test_email']);
$ok(strpos($html, 'Test email sent to dan@example.com') !== false && $GLOBALS['http'][0][1]['content']['from']['email'] === 'reports@site.example', 'test email via SparkPost settings');
$ok(strpos($html, 'Open the report editor') !== false && strpos($html, 'usersc/reports_builder.php') !== false, 'editor link');

@unlink($cfgFile);
echo empty($GLOBALS['fail']) ? "smoke OK\n" : "smoke FAILED\n";
exit(empty($GLOBALS['fail']) ? 0 : 1);
