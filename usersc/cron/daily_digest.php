<?php
/**
 * Daily Digest Email — Container Tracking System
 * Always sends — even "all clear" is useful daily confirmation.
 *
 * Cron: 0 6 * * * php /var/www/container-flow.com/html/usersc/cron/daily_digest.php >> /var/www/container-flow.com/html/usersc/logs/daily_digest.log 2>&1
 */
require_once __DIR__ . '/../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/sparkpost_email.php';

if (PHP_SAPI !== 'cli') {
    $cron_secret = 'YOUR_CRON_SECRET_HERE';
    if (!isset($_GET['token']) || $_GET['token'] !== $cron_secret) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/plain');
}

$now = date('Y-m-d H:i:s');
echo "{$now} - Daily digest starting...\n";

$supervisors = getSupervisorEmails();
echo "{$now} - Supervisors found: " . count($supervisors) . "\n";
foreach ($supervisors as $s) { echo "          -> {$s->fname} {$s->lname} <{$s->email}>\n"; }

if (empty($supervisors)) { echo "{$now} - No supervisors found.\n"; exit(1); }

$db   = DB::getInstance();
$open = $db->query(
    "SELECT c.*, cu.name AS customer_name FROM containers c
     LEFT JOIN customers cu ON cu.id = c.customer_id
     WHERE c.status IN ('pending','in_progress','completed')
     ORDER BY FIELD(c.status,'completed','in_progress','pending'), c.updated_at ASC"
)->results() ?: [];

$by_status = ['completed'=>[],'in_progress'=>[],'pending'=>[]];
foreach ($open as $c) $by_status[$c->status][] = $c;
$awaiting = count($by_status['completed']);
$active   = count($by_status['in_progress']);
$pending  = count($by_status['pending']);
$total    = count($open);

echo "{$now} - Open: {$total} ({$awaiting} awaiting review, {$active} in progress, {$pending} pending)\n";

// Always send — no skip condition
$base = rtrim(CONTAINER_SITE_URL, '/');

function buildDigestSection($rows, $heading, $date_col, $date_label, $callout='') {
    if (empty($rows)) return '';
    $out = $callout ? '<div style="border-left:3px solid #dc2626;background:#fef2f2;padding:10px 14px;margin-bottom:14px;"><p style="margin:0;font-size:13px;color:#991b1b;font-weight:600;">' . $callout . '</p></div>' : '';
    $out .= '<h3 style="margin:0 0 12px;font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">' . $heading . '</h3>';
    $out .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-bottom:24px;">';
    $out .= '<tr style="background:#f8fafc;"><th style="text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;font-size:11px;text-transform:uppercase;">Container</th><th style="text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;font-size:11px;text-transform:uppercase;">Client</th><th style="text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;font-size:11px;text-transform:uppercase;">Type</th><th style="text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;font-size:11px;text-transform:uppercase;">' . $date_label . '</th></tr>';
    foreach ($rows as $i => $c) {
        $bg = $i%2===0?'#fff':'#f8fafc';
        $dv = !empty($c->$date_col) ? date('M j, g:i A',strtotime($c->$date_col)) : '—';
        $out .= '<tr style="background:'.$bg.';border-top:1px solid #f1f5f9;"><td style="padding:8px 10px;font-weight:700;font-family:Courier New,monospace;font-size:12px;">'.htmlspecialchars($c->container_number).'</td><td style="padding:8px 10px;">'.htmlspecialchars($c->customer_name??'—').'</td><td style="padding:8px 10px;">'.ucfirst($c->type).'</td><td style="padding:8px 10px;color:#6b7280;">'.$dv.'</td></tr>';
    }
    return $out . '</table>';
}

$html  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:660px;margin:0 auto;color:#1f2937;">';
$html .= '<div style="background:#1e3a5f;padding:24px 32px;"><p style="margin:0 0 2px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7aafd4;">Container Flow</p><h1 style="margin:0 0 4px;font-size:22px;font-weight:700;color:#fff;">Container Tracking</h1><p style="margin:0;font-size:13px;color:#9bbdd6;">Daily Digest &mdash; ' . date('l, F j, Y') . '</p></div>';
$html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:20px 32px;"><table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>';
foreach ([['Awaiting Review',$awaiting,'#dc2626'],['In Progress',$active,'#1e3a5f'],['Pending',$pending,'#6b7280'],['Total Open',$total,'#374151']] as [$l,$n,$col]) {
    $html .= '<td style="text-align:center;padding:12px 8px;border-top:3px solid '.$col.';"><div style="font-size:30px;font-weight:700;color:'.$col.';line-height:1;">'.$n.'</div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-top:4px;">'.$l.'</div></td>';
}
$html .= '</tr></table></div>';
$html .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:28px 32px;">';

if (!empty($by_status['completed'])) {
    $callout = $awaiting===1 ? '1 container is ready for review and waiting to have photos sent to the client.' : $awaiting.' containers are ready for review and waiting to have photos sent to clients.';
    $html .= buildDigestSection($by_status['completed'],'Awaiting Review','updated_at','Completed',$callout);
}
$html .= buildDigestSection($by_status['in_progress'],'In Progress','updated_at','Last Updated');
$html .= buildDigestSection($by_status['pending'],'Pending','created_at','Created');

if (empty($open)) $html .= '<p style="text-align:center;color:#15803d;font-size:14px;padding:12px 0;font-weight:600;">No open containers &mdash; all caught up.</p>';

$html .= '<div style="margin:8px 0 4px;"><a href="'.htmlspecialchars($base.'/usersc/container_dashboard.php').'" style="display:inline-block;background:#1e3a5f;color:#fff;padding:11px 24px;text-decoration:none;font-weight:700;font-size:13px;margin-right:10px;">Open Dashboard</a><a href="'.htmlspecialchars($base.'/usersc/container_dashboard_pro.php').'" style="display:inline-block;background:#fff;color:#1e3a5f;border:1px solid #1e3a5f;padding:10px 24px;text-decoration:none;font-weight:700;font-size:13px;">Advanced View</a></div>';
$html .= '</div><div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:12px 32px;"><p style="margin:0;font-size:11px;color:#9ca3af;">Automated daily digest from Container Flow</p></div></div>';

if ($awaiting > 0) {
    $subject = 'Container Tracking: '.$awaiting.' Container'.($awaiting!==1?'s':'').' Awaiting Review — '.date('M j, Y');
} elseif ($total > 0) {
    $subject = 'Container Tracking Daily Digest — '.$total.' Open Container'.($total!==1?'s':'').' — '.date('M j, Y');
} else {
    $subject = 'Container Tracking Daily Digest — All Clear — '.date('M j, Y');
}

$to_emails = array_values(array_filter(array_map(fn($s)=>trim($s->email),$supervisors),fn($e)=>filter_var($e,FILTER_VALIDATE_EMAIL)));
if (empty($to_emails)) { echo "{$now} - No valid emails.\n"; exit(1); }

$result = sendSparkPostEmail($to_emails, $subject, $html);
if ($result['success']) {
    echo "{$now} - Sent to: ".implode(', ',$to_emails)."\n";
    echo "          Subject: {$subject}\n";
} else {
    echo "{$now} - FAILED: ".($result['message']??'Unknown error')."\n";
    exit(1);
}
