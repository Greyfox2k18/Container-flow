<?php
/**
 * Scheduled Reports Cron — Container Tracking System
 *
 * Checks every active report with scheduled (or "both") delivery and
 * sends the ones due this hour. Safe to run frequently — each report
 * only sends once per its own period, tracked via last_sent_at.
 *
 * Suggested crontab entry (runs at the top of every hour; adjust the
 * path to match your actual deployment path):
 *
 *   0 * * * * php /var/www/container-flow.com/html/usersc/cron/reports_cron.php >> /var/www/container-flow.com/html/usersc/logs/reports_cron.log 2>&1
 *
 * If you want finer-than-hourly precision you can run it every 15
 * minutes instead (isScheduledReportDue() checks the hour, not the
 * exact minute, so running more often than hourly doesn't cause
 * duplicate sends — it just catches the due hour sooner). See the
 * crontab example at the bottom of this file for that syntax.
 */

require_once __DIR__ . '/../../users/init.php';
require_once __DIR__ . '/../includes/container_functions.php';

$sent = runScheduledReports();
echo date('Y-m-d H:i:s') . " — reports_cron: {$sent} report(s) sent\n";

// Every-15-minutes crontab line, if you want finer precision than hourly:
// (Every 15 min) 0/15 * * * * php /var/www/container-flow.com/html/usersc/cron/reports_cron.php >> /var/www/container-flow.com/html/usersc/logs/reports_cron.log 2>&1
