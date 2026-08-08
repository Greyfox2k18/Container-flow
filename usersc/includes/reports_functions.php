<?php
/**
 * Automated Reports — Container Tracking System
 * Lets a supervisor build reusable report definitions (who gets what data,
 * on what schedule and/or status-change event) without writing code.
 * Run 07_reports_migration.sql once before using this.
 *
 * Two delivery paths:
 *   - Scheduled: usersc/cron/reports_cron.php, run every 15-60 min by
 *     cron, checks isScheduledReportDue() for each active report.
 *   - Event: hooked into updateContainer() in container_functions.php —
 *     any status change anywhere in the app runs sendEventTriggeredReports()
 *     automatically, so nothing else needs to call it directly.
 */

// ── CRUD ─────────────────────────────────────────────────────────────────────

function getReportDefinitions($active_only = false) {
    try {
        $sql = "SELECT * FROM report_definitions" . ($active_only ? " WHERE active = 1" : "") . " ORDER BY name ASC";
        return DB::getInstance()->query($sql)->results() ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function getReportById($report_id) {
    if (!$report_id) return null;
    try {
        return DB::getInstance()->query("SELECT * FROM report_definitions WHERE id = ?", [$report_id])->first();
    } catch (\Throwable $e) {
        return null;
    }
}

function getReportRecipients($report_id) {
    try {
        return DB::getInstance()->query("SELECT * FROM report_recipients WHERE report_id = ? ORDER BY email ASC", [$report_id])->results() ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * $recipient_emails is a flat array of email address strings.
 */
function saveReportRecipients($report_id, $recipient_emails) {
    $db = DB::getInstance();
    $db->query("DELETE FROM report_recipients WHERE report_id = ?", [$report_id]);
    foreach ($recipient_emails as $email) {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $db->insert('report_recipients', ['report_id' => $report_id, 'email' => $email]);
    }
}

function createReportDefinition($data, $recipient_emails) {
    $db = DB::getInstance();
    $insert = $db->insert('report_definitions', $data);
    if (!$insert) return false;
    $report_id = $db->lastId();
    saveReportRecipients($report_id, $recipient_emails);
    return $report_id;
}

function updateReportDefinition($report_id, $data, $recipient_emails) {
    $db = DB::getInstance();
    $result = $db->update('report_definitions', $report_id, $data);
    saveReportRecipients($report_id, $recipient_emails);
    return $result;
}

function deleteReportDefinition($report_id) {
    $db = DB::getInstance();
    $db->query("DELETE FROM report_recipients WHERE report_id = ?", [$report_id]);
    $db->query("DELETE FROM report_run_log WHERE report_id = ?", [$report_id]);
    return $db->delete('report_definitions', $report_id);
}

function logReportRun($report_id, $trigger_type, $container_id, $container_count, $recipient_count, $success, $error_message = null) {
    try {
        DB::getInstance()->insert('report_run_log', [
            'report_id'       => $report_id,
            'trigger_type'    => $trigger_type,
            'container_id'    => $container_id,
            'container_count' => $container_count,
            'recipient_count' => $recipient_count,
            'success'         => $success ? 1 : 0,
            'error_message'   => $error_message,
        ]);
    } catch (\Throwable $e) {
        error_log('logReportRun failed: ' . $e->getMessage());
    }
}

function getReportRunLog($report_id, $limit = 20) {
    try {
        return DB::getInstance()->query(
            "SELECT * FROM report_run_log WHERE report_id = ? ORDER BY run_at DESC LIMIT ?",
            [$report_id, (int) $limit]
        )->results() ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

// ── Content: which containers match a report, and the date window ──────────

/**
 * Returns [start_datetime_string|null, end_datetime_string|null] for a
 * scheduled report's content window. null start = no lower bound.
 */
function getReportDateRangeBounds($report) {
    $now = new \DateTime();
    $end = $now->format('Y-m-d H:i:s');

    switch ($report->date_range) {
        case 'today':
            $start = (clone $now)->setTime(0, 0)->format('Y-m-d H:i:s');
            break;
        case 'yesterday':
            $y = (clone $now)->modify('-1 day');
            $start = (clone $y)->setTime(0, 0)->format('Y-m-d H:i:s');
            $end   = (clone $y)->setTime(23, 59, 59)->format('Y-m-d H:i:s');
            break;
        case 'last_7_days':
            $start = (clone $now)->modify('-7 days')->format('Y-m-d H:i:s');
            break;
        case 'last_30_days':
            $start = (clone $now)->modify('-30 days')->format('Y-m-d H:i:s');
            break;
        case 'this_month':
            $start = (clone $now)->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s');
            break;
        case 'last_month':
            $start = (clone $now)->modify('first day of last month')->setTime(0, 0)->format('Y-m-d H:i:s');
            $end   = (clone $now)->modify('last day of last month')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
            break;
        case 'since_last_report':
            $start = $report->last_sent_at ?: $report->created_at;
            break;
        case 'all_time':
        default:
            $start = null;
    }

    return [$start, $end];
}

/**
 * Containers matching a report's content filters, for scheduled sends.
 * Does NOT apply the logged-in-user warehouse restriction from
 * getAllContainers() — a scheduled report runs outside any user session
 * and should send exactly what it was configured to send.
 */
function getContainersForReport($report, $window_start = null, $window_end = null) {
    $db = DB::getInstance();
    $sql = "SELECT c.*, cu.name AS customer_name, wh.name AS warehouse_name
            FROM containers c
            LEFT JOIN customers cu ON c.customer_id = cu.id
            LEFT JOIN warehouses wh ON c.warehouse_id = wh.id
            WHERE 1=1";
    $params = [];

    if (!empty($report->filter_type)) {
        $sql .= " AND c.type = ?";
        $params[] = $report->filter_type;
    }
    if (!empty($report->filter_status)) {
        $sql .= " AND c.status = ?";
        $params[] = $report->filter_status;
    }
    if (!empty($report->filter_customer_id)) {
        $sql .= " AND c.customer_id = ?";
        $params[] = $report->filter_customer_id;
    }
    if (!empty($report->filter_warehouse_id)) {
        $sql .= " AND c.warehouse_id = ?";
        $params[] = $report->filter_warehouse_id;
    }
    if (!empty($report->filter_carrier)) {
        $sql .= " AND c.carrier = ?";
        $params[] = $report->filter_carrier;
    }
    if ($window_start) {
        $sql .= " AND c.created_at >= ?";
        $params[] = $window_start;
    }
    if ($window_end) {
        $sql .= " AND c.created_at <= ?";
        $params[] = $window_end;
    }

    $sql .= " ORDER BY c.created_at DESC";

    return $db->query($sql, $params)->results() ?: [];
}

/**
 * Whether a single container matches a report's content filters — used
 * for event-triggered sends (does the container that just changed status
 * actually belong to this report's audience?).
 */
function containerMatchesReportFilters($report, $container) {
    if (!empty($report->filter_type) && $container->type !== $report->filter_type) return false;
    if (!empty($report->filter_customer_id) && (int) $container->customer_id !== (int) $report->filter_customer_id) return false;
    if (!empty($report->filter_warehouse_id) && (int) $container->warehouse_id !== (int) $report->filter_warehouse_id) return false;
    if (!empty($report->filter_carrier) && strcasecmp((string) $container->carrier, (string) $report->filter_carrier) !== 0) return false;
    return true;
}

// ── Rendering ────────────────────────────────────────────────────────────────

function renderReportHtmlTable($report, $containers) {
    $rows = '';
    foreach ($containers as $c) {
        $rows .= '<tr style="border-bottom:1px solid #f3f4f6;">'
            . '<td style="padding:8px 10px;font-family:Courier New,monospace;font-weight:700;">' . htmlspecialchars($c->container_number) . '</td>'
            . '<td style="padding:8px 10px;">' . htmlspecialchars(ucfirst($c->type)) . '</td>'
            . '<td style="padding:8px 10px;">' . htmlspecialchars(ucfirst($c->status)) . '</td>'
            . '<td style="padding:8px 10px;">' . htmlspecialchars($c->customer_name ?? '-') . '</td>'
            . '<td style="padding:8px 10px;">' . htmlspecialchars($c->warehouse_name ?? '-') . '</td>'
            . '<td style="padding:8px 10px;">' . htmlspecialchars($c->carrier ?? '-') . '</td>'
            . '<td style="padding:8px 10px;">' . ($c->receipt_ship_date ? date('M d, Y', strtotime($c->receipt_ship_date)) : '-') . '</td>'
            . '</tr>';
    }

    if (empty($containers)) {
        $rows = '<tr><td colspan="7" style="padding:16px;text-align:center;color:#9ca3af;">No containers matched this report.</td></tr>';
    }

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:820px;margin:0 auto;color:#1f2937;">'
        . '<div style="background:#1e3a5f;padding:20px 26px;">'
        . '<h2 style="margin:0 0 4px;font-size:18px;font-weight:700;color:#fff;">' . htmlspecialchars($report->name) . '</h2>'
        . '<p style="margin:0;font-size:12px;color:#9bbdd6;">' . date('l, F j, Y') . ' — ' . count($containers) . ' container(s)</p>'
        . '</div>'
        . '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:16px 20px;">'
        . '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
        . '<thead><tr style="border-bottom:2px solid #e2e8f0;text-align:left;color:#6b7280;">'
        . '<th style="padding:8px 10px;">Container #</th><th style="padding:8px 10px;">Type</th>'
        . '<th style="padding:8px 10px;">Status</th><th style="padding:8px 10px;">Client</th>'
        . '<th style="padding:8px 10px;">Warehouse</th><th style="padding:8px 10px;">Carrier</th>'
        . '<th style="padding:8px 10px;">Date</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '</div>'
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:10px 26px;">'
        . '<p style="margin:0;font-size:11px;color:#9ca3af;">Automated report from Container Flow</p>'
        . '</div></div>';

    return $html;
}

/**
 * Returns an attachment array (matching sendSparkPostEmail's expected
 * ['name'=>..,'data'=>base64,'type'=>..] shape), or null if there's
 * nothing to attach.
 */
function renderReportCsvAttachment($containers) {
    if (empty($containers)) return null;

    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['Container #', 'Type', 'Status', 'Client', 'Warehouse', 'Carrier', 'Seal #', 'Shipment #', 'PO/BOL', 'Piece Count', 'Date', 'Created At']);
    foreach ($containers as $c) {
        fputcsv($fh, [
            $c->container_number,
            ucfirst($c->type),
            ucfirst($c->status),
            $c->customer_name ?? '',
            $c->warehouse_name ?? '',
            $c->carrier ?? '',
            $c->seal_number ?? '',
            $c->shipment_number ?? '',
            $c->po_bol_number ?? '',
            $c->piece_count ?? '',
            $c->receipt_ship_date ? date('Y-m-d', strtotime($c->receipt_ship_date)) : '',
            $c->created_at,
        ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);

    return [
        'name' => 'report_' . date('Y-m-d') . '.csv',
        'data' => base64_encode($csv),
        'type' => 'text/csv',
    ];
}

// ── Sending ──────────────────────────────────────────────────────────────────

function sendReportEmail($report, $containers, $trigger_type, $trigger_container_id = null) {
    $recipients = array_column(getReportRecipients($report->id), 'email');
    if (empty($recipients)) {
        logReportRun($report->id, $trigger_type, $trigger_container_id, count($containers), 0, false, 'No recipients configured');
        return ['sent' => false, 'reason' => 'No recipients configured'];
    }

    $html = renderReportHtmlTable($report, $containers);
    $attachments = [];
    if (in_array($report->format, ['csv_attachment', 'both'], true)) {
        $att = renderReportCsvAttachment($containers);
        if ($att) $attachments[] = $att;
    }

    $subject = $report->name . ' — ' . date('M j, Y');
    $result = sendSparkPostEmail($recipients, $subject, $html, null, null, $attachments);

    logReportRun($report->id, $trigger_type, $trigger_container_id, count($containers), count($recipients), $result['success'], $result['success'] ? null : ($result['message'] ?? 'Unknown error'));

    if ($result['success']) {
        DB::getInstance()->update('report_definitions', $report->id, ['last_sent_at' => date('Y-m-d H:i:s')]);
    }

    return ['sent' => $result['success'], 'reason' => $result['message'] ?? ''];
}

/**
 * Called from updateContainer() whenever a container's status changes.
 * Fires any active event-triggered report whose trigger_status matches
 * the new status and whose filters match this container. Fails silently
 * (logs only) so a reporting bug never blocks the actual status update.
 */
function sendEventTriggeredReports($container_id, $new_status) {
    try {
        $reports = getReportDefinitions(true);
        $matching = array_filter($reports, fn($r) =>
            in_array($r->delivery_mode, ['event', 'both'], true) &&
            $r->trigger_status === $new_status
        );
        if (empty($matching)) return;

        $container = getContainerById($container_id);
        if (!$container) return;

        $customer = $container->customer_id ? getCustomerById($container->customer_id) : null;
        $warehouse = $container->warehouse_id ? getWarehouseById($container->warehouse_id) : null;
        $container->customer_name = $customer ? $customer->name : null;
        $container->warehouse_name = $warehouse ? $warehouse->name : null;

        foreach ($matching as $report) {
            if (!containerMatchesReportFilters($report, $container)) continue;
            sendReportEmail($report, [$container], 'event', $container_id);
        }
    } catch (\Throwable $e) {
        error_log('sendEventTriggeredReports failed: ' . $e->getMessage());
    }
}

/**
 * Whether a scheduled report is due to run right now. Uses last_sent_at
 * so it's safe to call this from a cron job running every 15-60 minutes —
 * it won't double-send within the same period.
 */
function isScheduledReportDue($report, \DateTime $now = null) {
    if (!in_array($report->delivery_mode, ['scheduled', 'both'], true)) return false;
    if (empty($report->schedule_frequency)) return false;

    $now = $now ?: new \DateTime();
    $hour = (int) $report->schedule_hour;

    // Must be within the send hour (cron runs every 15-60 min, so check the hour, not the exact minute)
    if ((int) $now->format('G') !== $hour) return false;

    $last_sent = $report->last_sent_at ? new \DateTime($report->last_sent_at) : null;

    switch ($report->schedule_frequency) {
        case 'daily':
            return !$last_sent || $last_sent->format('Y-m-d') !== $now->format('Y-m-d');

        case 'weekly':
            if ((int) $now->format('w') !== (int) $report->schedule_day_of_week) return false;
            return !$last_sent || $last_sent->format('Y-m-d') !== $now->format('Y-m-d');

        case 'monthly':
            $target_day = (int) $report->schedule_day_of_month;
            $today_day  = (int) $now->format('j');
            $last_day_of_month = (int) $now->format('t');
            // If the target day doesn't exist this month (e.g. 31st in Feb),
            // treat the last day of the month as the trigger day instead.
            $effective_day = min($target_day, $last_day_of_month);
            if ($today_day !== $effective_day) return false;
            return !$last_sent || $last_sent->format('Y-m') !== $now->format('Y-m');

        default:
            return false;
    }
}

/**
 * Entry point for the cron job — checks every active scheduled report
 * and sends the ones that are due.
 */
function runScheduledReports() {
    $now = new \DateTime();
    $reports = getReportDefinitions(true);
    $sent = 0;

    foreach ($reports as $report) {
        if (!isScheduledReportDue($report, $now)) continue;

        [$start, $end] = getReportDateRangeBounds($report);
        $containers = getContainersForReport($report, $start, $end);
        sendReportEmail($report, $containers, 'scheduled', null);
        $sent++;
    }

    return $sent;
}
