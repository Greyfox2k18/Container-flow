<?php
/**
 * Report Builder — scheduled sends. Command line only.
 *
 * Sends every active report that is due this hour. Safe to run every 15–60
 * minutes: a report only sends once per day/week/month (last_sent_at).
 *
 *   0 * * * * php /var/www/container-flow.com/html/usersc/plugins/report_builder/cron/run.php >> /var/www/container-flow.com/html/usersc/logs/report_builder.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once dirname(__DIR__, 4) . '/users/init.php';
require_once dirname(__DIR__) . '/functions.php';

$start = date('Y-m-d H:i:s');
try {
    $n = RbReports::runDue();
    echo "$start — report_builder: $n report(s) run\n";
} catch (\Throwable $e) {
    echo "$start — report_builder FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
