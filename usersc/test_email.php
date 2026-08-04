<?php
/**
 * Email Test & Diagnostics — Container Tracking System
 *
 * Hit this in a browser to verify SparkPost is configured and supervisors
 * are found correctly before relying on the real digest/notifications.
 *
 * URL: https://container-flow.com/usersc/cron/test_email.php?token=YOUR_CRON_SECRET_HERE
 *
 * Change the token below to match the one in daily_digest.php.
 * DELETE or disable this file once you've confirmed everything works.
 */

$cron_secret = 'YOUR_CRON_SECRET_HERE'; // must match daily_digest.php

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/sparkpost_email.php';

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_GET['token']) || $_GET['token'] !== $cron_secret) {
    http_response_code(403);
    exit('403 Forbidden — add ?token=YOUR_CRON_SECRET_HERE to the URL');
}

header('Content-Type: text/html; charset=utf-8');

function row($label, $value, $ok = null) {
    $icon  = $ok === null ? '⚪' : ($ok ? '✅' : '❌');
    $color = $ok === null ? '#666' : ($ok ? '#15803d' : '#b91c1c');
    echo '<tr>';
    echo '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#555;white-space:nowrap;">' . htmlspecialchars($label) . '</td>';
    echo '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;color:' . $color . ';font-weight:600;">' . $icon . ' ' . htmlspecialchars($value) . '</td>';
    echo '</tr>';
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Email Test — Container Flow</title>
<style>
body { font-family: Arial, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; color: #1f2937; }
h2 { color: #0067b8; }
table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 24px; }
th { background: #f8f9fa; padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #6b7280; letter-spacing: .05em; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.btn { display: inline-block; background: #0067b8; color: #fff; padding: 10px 22px; border-radius: 6px; text-decoration: none; font-weight: 600; margin-top: 8px; }
pre { background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px; font-size: 13px; overflow-x: auto; white-space: pre-wrap; }
</style>
</head>
<body>

<h2>📧 Email Diagnostics — Container Flow</h2>
<p style="color:#666;font-size:13px;">Run at: <strong><?php echo date('Y-m-d H:i:s'); ?></strong></p>

<?php

// ── 1. Constants ─────────────────────────────────────────────────────────────
echo '<div class="card"><h3 style="margin:0 0 14px;">1. Configuration</h3>';
echo '<table><tr><th>Setting</th><th>Value</th></tr>';
row('CONTAINER_SITE_URL', defined('CONTAINER_SITE_URL') ? CONTAINER_SITE_URL : '(not defined)', defined('CONTAINER_SITE_URL'));
row('SparkPost includes loaded', function_exists('sendSparkPostEmail') ? 'Yes' : 'No', function_exists('sendSparkPostEmail'));
echo '</table></div>';

// ── 2. Supervisor lookup ──────────────────────────────────────────────────────
echo '<div class="card"><h3 style="margin:0 0 14px;">2. Supervisor Lookup</h3>';

$db = DB::getInstance();
$all_users = $db->query("SELECT id, email, fname, lname FROM users WHERE active = 1 AND email IS NOT NULL AND email != ''")->results() ?: [];
echo '<p style="color:#666;font-size:13px;margin:0 0 12px;">Total active users with emails: <strong>' . count($all_users) . '</strong></p>';

// fetchPermissionUsers() — UserSpice native, safe in cron context
$perm_rows = [];
$perm_err  = '';
try {
    $perm_rows = fetchPermissionUsers(3); // Permission ID 3 = supervisor
} catch (\Throwable $e) {
    $perm_err = $e->getMessage();
}

echo '<p style="font-size:13px;font-weight:600;margin:0 0 6px;">fetchPermissionUsers(3) raw result</p>';
if ($perm_err) {
    echo '<p style="color:#b91c1c;font-size:13px;">❌ Error: ' . htmlspecialchars($perm_err) . '</p>';
} elseif (empty($perm_rows)) {
    echo '<p style="color:#b91c1c;font-size:13px;">❌ 0 rows — no users have permission ID 3 assigned in UserSpice.</p>';
} else {
    $ids = array_map('intval', array_column((array)$perm_rows, 'user_id'));
    echo '<p style="color:#15803d;font-size:13px;">✅ ' . count($perm_rows) . ' user(s) have permission 3. User IDs: ' . implode(', ', $ids) . '</p>';
}

// Resolved supervisors with emails
$supervisors = getSupervisorEmails();
echo '<p style="font-size:13px;font-weight:600;margin:12px 0 6px;">getSupervisorEmails() — resolved with email addresses</p>';
if (empty($supervisors)) {
    echo '<p style="color:#b91c1c;font-size:13px;">❌ No supervisors with valid emails found.</p>';
} else {
    echo '<table><tr><th>Name</th><th>Email</th></tr>';
    foreach ($supervisors as $s) {
        echo '<tr><td style="padding:7px 12px;border-bottom:1px solid #f0f0f0;">' . htmlspecialchars($s->fname . ' ' . $s->lname) . '</td>';
        echo '<td style="padding:7px 12px;border-bottom:1px solid #f0f0f0;color:#15803d;font-weight:600;">✅ ' . htmlspecialchars($s->email) . '</td></tr>';
    }
    echo '</table>';
}

echo '</div>';

// ── 3. Send test email ────────────────────────────────────────────────────────
echo '<div class="card"><h3 style="margin:0 0 14px;">3. Send Test Email</h3>';

$to_emails = array_values(array_filter(
    array_map(fn($s) => trim($s->email), $supervisors),
    fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
));

if (empty($to_emails)) {
    echo '<p style="color:#b91c1c;">❌ No valid supervisor emails to send to. Fix the supervisor lookup above first.</p>';
} else {
    $subject  = '✅ Test Email — Container Flow (' . date('H:i:s') . ')';
    $html_body = '
<div style="font-family:Arial,sans-serif;max-width:580px;margin:0 auto;color:#1f2937;">
  <div style="background:linear-gradient(135deg,#0067b8,#1e40af);color:#fff;padding:20px 24px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:18px;">✅ Container Flow — Test Email</h2>
  </div>
  <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;padding:20px 24px;">
    <p>This is a test email sent from <strong>container-flow.com</strong> at <strong>' . date('Y-m-d H:i:s') . '</strong>.</p>
    <p>If you received this, SparkPost is configured correctly and supervisor email lookup is working.</p>
    <p style="margin-top:20px;">
      <a href="' . htmlspecialchars(CONTAINER_SITE_URL . '/usersc/container_dashboard.php') . '"
         style="display:inline-block;background:#0067b8;color:#fff;padding:10px 22px;border-radius:6px;text-decoration:none;font-weight:600;">
        Open Dashboard
      </a>
    </p>
  </div>
  <div style="background:#f9fafb;border:1px solid #e5e7eb;border-top:none;padding:10px 24px;border-radius:0 0 8px 8px;">
    <p style="margin:0;font-size:11px;color:#9ca3af;text-align:center;">Container Flow &middot; Automated test</p>
  </div>
</div>';

    echo '<p style="color:#666;font-size:13px;margin:0 0 12px;">Sending to: <strong>' . htmlspecialchars(implode(', ', $to_emails)) . '</strong></p>';

    $result = sendSparkPostEmail($to_emails, $subject, $html_body);

    echo '<table><tr><th>Result</th><th>Detail</th></tr>';
    row('Sent',           $result['success'] ? 'Yes' : 'No', $result['success']);
    row('Message',        $result['message'] ?? '(none)');
    if (!empty($result['transmission_id'])) {
        row('Transmission ID', $result['transmission_id']);
    }
    echo '</table>';

    if ($result['success']) {
        echo '<p style="color:#15803d;font-weight:600;margin-top:12px;">✅ Email sent — check your inbox. If it doesn\'t arrive within 2 minutes check your SparkPost dashboard for bounce/rejection details.</p>';
    } else {
        echo '<p style="color:#b91c1c;font-weight:600;margin-top:12px;">❌ SparkPost returned an error. Common causes: API key not set in sparkpost_email.php, sending domain not verified, or invalid from address.</p>';
    }
}

echo '</div>';

// ── 4. Raw SparkPost config peek ──────────────────────────────────────────────
echo '<div class="card"><h3 style="margin:0 0 10px;">4. Quick Checks</h3><ul style="font-size:13px;color:#555;line-height:2;">';
echo '<li>SparkPost API key placeholder still set? ';
// Read the sparkpost file and check
$sp_content = file_get_contents($abs_us_root . $us_url_root . 'usersc/includes/sparkpost_email.php');
$has_placeholder = strpos($sp_content, 'YOUR_SPARKPOST_API_KEY_HERE') !== false;
echo $has_placeholder ? '<strong style="color:#b91c1c;">❌ Yes — you still need to set your real API key in sparkpost_email.php</strong>' : '<strong style="color:#15803d;">✅ No — key has been replaced</strong>';
echo '</li>';
echo '<li>PHP version: <strong>' . PHP_VERSION . '</strong></li>';
echo '<li>Running as: <strong>' . get_current_user() . '</strong></li>';
echo '</ul></div>';

echo '<p style="color:#999;font-size:12px;margin-top:24px;">🔒 Delete or rename this file once you\'re done testing.</p>';
?>

</body>
</html>
