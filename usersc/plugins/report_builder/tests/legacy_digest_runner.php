<?php
/**
 * Runs the ORIGINAL usersc/cron/daily_digest.php, unmodified, against a
 * SQLite fixture and captures the email it would send. Used by the
 * "digest from blocks matches the current digest" test.
 *
 *   php legacy_digest_runner.php <sqlite file> <output json file>
 *
 * Runs in its own process because the legacy script declares a global
 * function and calls exit().
 */
if (PHP_SAPI !== 'cli' || $argc < 3) die("usage: php legacy_digest_runner.php <db> <out>\n");
[$_, $dbFile, $outFile] = $argv;

$root = sys_get_temp_dir() . '/rb_legacy_' . getmypid();
@mkdir("$root/users", 0777, true);
@mkdir("$root/usersc/cron", 0777, true);
@mkdir("$root/usersc/includes", 0777, true);
copy(dirname(__DIR__, 3) . '/cron/daily_digest.php', "$root/usersc/cron/daily_digest.php");

$fake = var_export(__DIR__ . '/fake_userspice.php', true);
file_put_contents("$root/users/init.php", "<?php
\$abs_us_root = " . var_export($root, true) . ";
\$us_url_root = '/';
require $fake;
DB::connect(" . var_export("sqlite:$dbFile", true) . ");
");
file_put_contents("$root/usersc/includes/container_functions.php", "<?php
define('CONTAINER_SITE_URL', 'https://container-flow.com');
function getSupervisorEmails() {
    \$ids = array_map('intval', array_column((array) fetchPermissionUsers(3), 'user_id'));
    \$ph = implode(',', array_fill(0, count(\$ids), '?'));
    return DB::getInstance()->query(\"SELECT id,email,fname,lname FROM users WHERE id IN (\$ph) AND active=1 AND email IS NOT NULL AND email!='' ORDER BY lname,fname\", \$ids)->results();
}
");
file_put_contents("$root/usersc/includes/sparkpost_email.php", "<?php
function sendSparkPostEmail(\$to, \$subject, \$html) {
    file_put_contents(" . var_export($outFile, true) . ", json_encode(['to' => \$to, 'subject' => \$subject, 'html' => \$html]));
    return ['success' => true];
}
");

register_shutdown_function(function () use ($root) {
    foreach (["usersc/cron/daily_digest.php", "usersc/includes/container_functions.php", "usersc/includes/sparkpost_email.php", "users/init.php"] as $f) @unlink("$root/$f");
    foreach (["usersc/cron", "usersc/includes", "usersc", "users", ""] as $d) @rmdir("$root/$d");
});

ob_start();
require "$root/usersc/cron/daily_digest.php";
ob_end_clean();
