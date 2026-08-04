<?php
/**
 * Trigger Digest — Container Tracking System
 * Runs the daily digest on demand from the settings page.
 */
// Catch fatal errors and return JSON instead of HTML
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'PHP Fatal Error: ' . $err['message'] . ' in ' . basename($err['file']) . ' line ' . $err['line'],
        ]);
    }
});

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/sparkpost_email.php';

ob_end_clean();
header('Content-Type: application/json');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

try {
    $supervisors = getSupervisorEmails();

    if (empty($supervisors)) {
        echo json_encode(['success' => false, 'message' => 'No supervisors found — check permission ID 3 is assigned in UserSpice']);
        exit;
    }

    $to_emails = array_values(array_filter(
        array_map(fn($s) => trim($s->email), $supervisors),
        fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
    ));

    if (empty($to_emails)) {
        echo json_encode(['success' => false, 'message' => 'Supervisors found but no valid email addresses']);
        exit;
    }

    $db   = DB::getInstance();
    $open = $db->query(
        "SELECT c.*, cu.name AS customer_name FROM containers c
         LEFT JOIN customers cu ON cu.id = c.customer_id
         WHERE c.status IN ('pending','in_progress','completed')
         ORDER BY FIELD(c.status,'completed','in_progress','pending'), c.updated_at ASC"
    )->results() ?: [];

    $bs = ['completed' => [], 'in_progress' => [], 'pending' => []];
    foreach ($open as $c) { if (isset($bs[$c->status])) $bs[$c->status][] = $c; }

    $awaiting = count($bs['completed']);
    $active   = count($bs['in_progress']);
    $pending  = count($bs['pending']);
    $total    = count($open);

    $base = rtrim(getContainerSetting('site_url', CONTAINER_SITE_URL), '/');

    // Build email HTML
    $html  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:660px;margin:0 auto;color:#1f2937;">';
    $html .= '<div style="background:#1e3a5f;padding:24px 32px;">';
    $html .= '<p style="margin:0 0 2px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7aafd4;">Container Flow</p>';
    $html .= '<h1 style="margin:0 0 4px;font-size:22px;font-weight:700;color:#fff;">Container Tracking</h1>';
    $html .= '<p style="margin:0;font-size:13px;color:#9bbdd6;">Daily Digest &mdash; ' . date('l, F j, Y') . ' (manually triggered)</p>';
    $html .= '</div>';

    $html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:20px 32px;">';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>';
    foreach ([
        ['Awaiting Review', $awaiting, '#dc2626'],
        ['In Progress',     $active,   '#1e3a5f'],
        ['Pending',         $pending,  '#6b7280'],
        ['Total Open',      $total,    '#374151'],
    ] as [$l, $n, $col]) {
        $html .= '<td style="text-align:center;padding:12px 8px;border-top:3px solid ' . $col . ';">';
        $html .= '<div style="font-size:28px;font-weight:700;color:' . $col . ';">' . $n . '</div>';
        $html .= '<div style="font-size:10px;color:#6b7280;text-transform:uppercase;margin-top:3px;">' . $l . '</div></td>';
    }
    $html .= '</tr></table></div>';

    $html .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:24px 32px;">';

    if (empty($open)) {
        $html .= '<p style="text-align:center;color:#15803d;font-weight:600;padding:12px 0;">No open containers &mdash; all caught up.</p>';
    } else {
        $sections = [
            ['Awaiting Review', $bs['completed'],   'updated_at', 'Completed'],
            ['In Progress',     $bs['in_progress'], 'updated_at', 'Updated'],
            ['Pending',         $bs['pending'],      'created_at', 'Created'],
        ];
        foreach ($sections as [$heading, $rows, $dc, $dl]) {
            if (empty($rows)) continue;
            $html .= '<h3 style="margin:0 0 10px;font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">' . $heading . '</h3>';
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin-bottom:18px;">';
            $html .= '<tr style="background:#f8fafc;"><th style="text-align:left;padding:7px 8px;color:#6b7280;font-size:11px;">Container</th><th style="text-align:left;padding:7px 8px;color:#6b7280;font-size:11px;">Client</th><th style="text-align:left;padding:7px 8px;color:#6b7280;font-size:11px;">' . $dl . '</th></tr>';
            foreach ($rows as $i => $c) {
                $bg  = $i % 2 === 0 ? '#fff' : '#f8fafc';
                $dv  = !empty($c->$dc) ? date('M j g:i A', strtotime($c->$dc)) : '—';
                $html .= '<tr style="background:' . $bg . ';border-top:1px solid #f1f5f9;">';
                $html .= '<td style="padding:7px 8px;font-weight:700;font-family:Courier New,monospace;font-size:12px;">' . htmlspecialchars($c->container_number) . '</td>';
                $html .= '<td style="padding:7px 8px;">' . htmlspecialchars($c->customer_name ?? '—') . '</td>';
                $html .= '<td style="padding:7px 8px;color:#6b7280;">' . $dv . '</td></tr>';
            }
            $html .= '</table>';
        }
    }

    $html .= '<div style="margin:8px 0;">';
    $html .= '<a href="' . htmlspecialchars($base . '/usersc/container_dashboard.php') . '" style="display:inline-block;background:#1e3a5f;color:#fff;padding:10px 22px;text-decoration:none;font-weight:700;font-size:13px;margin-right:8px;">Open Dashboard</a>';
    $html .= '<a href="' . htmlspecialchars($base . '/usersc/container_dashboard_pro.php') . '" style="display:inline-block;background:#fff;color:#1e3a5f;border:1px solid #1e3a5f;padding:9px 22px;text-decoration:none;font-weight:700;font-size:13px;">Advanced View</a>';
    $html .= '</div></div>';
    $html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:10px 32px;">';
    $html .= '<p style="margin:0;font-size:11px;color:#9ca3af;">Manually triggered &middot; Container Flow</p></div></div>';

    if ($awaiting > 0)    $subject = 'Container Tracking: ' . $awaiting . ' Container' . ($awaiting !== 1 ? 's' : '') . ' Awaiting Review — ' . date('M j, Y');
    elseif ($total > 0)   $subject = 'Container Tracking Daily Digest — ' . $total . ' Open Container' . ($total !== 1 ? 's' : '') . ' — ' . date('M j, Y');
    else                  $subject = 'Container Tracking Daily Digest — All Clear — ' . date('M j, Y');

    $result = sendSparkPostEmail($to_emails, $subject, $html);

    echo json_encode([
        'success'    => $result['success'],
        'sent'       => $result['success'],
        'message'    => $result['success']
            ? 'Digest sent to ' . implode(', ', $to_emails)
            : ($result['message'] ?? 'SparkPost/Postmark error'),
        'recipients' => $to_emails,
        'stats'      => ['awaiting' => $awaiting, 'active' => $active, 'pending' => $pending],
    ]);

} catch (\Throwable $e) {
    error_log('trigger_digest.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ' line ' . $e->getLine(),
    ]);
}
