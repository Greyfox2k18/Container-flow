<?php
/**
 * Report Builder — scheduled sends. Command line only.
 *
 * Sends every active report that is due this hour. Safe to run every 15–60
 * minutes: a report only sends once per day/week/month (last_sent_at).
 *
 *   0 * * * * /usr/bin/php /var/www/container-flow.com/html/usersc/plugins/report_builder/cron/run.php >> /var/www/container-flow.com/html/usersc/logs/report_builder.log 2>&1
 *
 * Diagnostics (run as the cron user, e.g. sudo -u www-data php .../run.php --list):
 *   --list                         every report: active, schedule, due now?, resolved recipients
 *   --test=ID --to=you@example.com send report ID only to that address, print the mailer's reply
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once dirname(__DIR__, 4) . '/users/init.php';
require_once dirname(__DIR__) . '/functions.php';

$args = getopt('', ['list', 'test:', 'to:']);
$start = date('Y-m-d H:i:s');

try {
    if (isset($args['list'])) {
        $now = new DateTime();
        echo "Server time: " . $now->format('Y-m-d H:i T') . "\n";
        $reports = RbReports::all();
        if (!$reports) echo "No reports.\n";
        foreach ($reports as $r) {
            echo "\n#{$r->id} {$r->name}\n";
            echo "  active: " . ($r->active ? 'yes' : 'NO') . "   schedule: " . ($r->schedule_frequency ?: 'none')
               . " at " . sprintf('%02d:00', $r->schedule_hour) . "   scope: {$r->scope_mode}\n";
            echo "  last sent: " . ($r->last_sent_at ?: 'never') . "   due right now: " . (RbReports::isDue($r, $now) ? 'YES' : 'no') . "\n";
            $people = RbReports::resolveRecipients($r->id);
            echo "  recipients (" . count($people) . "):" . ($people ? '' : ' NONE — check perm IDs / user emails / active flags') . "\n";
            foreach ($people as $p) echo "    {$p['email']}" . ($p['name'] ? " ({$p['name']})" : '') . ($p['note'] ? " — {$p['note']}" : '') . "\n";
            foreach (RbReports::runLog($r->id, 3) as $l) {
                echo "  run {$l->run_at} {$l->trigger_type}: " . ($l->success ? 'OK' : 'FAILED ' . $l->error_message) . " ({$l->recipient_count} recipients, {$l->row_count} rows)\n";
            }
        }
        exit(0);
    }

    if (isset($args['test'])) {
        $r = RbReports::get((int) $args['test']);
        $to = $args['to'] ?? '';
        if (!$r) { echo "No report #{$args['test']}.\n"; exit(1); }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { echo "Give --to=you@example.com\n"; exit(1); }
        $out = RbReports::render($r, RbReports::scopeCtx($r, (int) $r->created_by));
        echo "Subject: {$out['subject']}\nRows: {$out['row_count']}   HTML: " . strlen($out['html']) . " bytes\n";
        $res = RbReports::run($r, 'test', ['only_to' => ['email' => $to, 'user_id' => null]]);
        echo "Mailer result: " . ($res['success'] ? 'SUCCESS' : 'FAILED') . " — {$res['message']}\n";
        echo $res['success']
            ? "Accepted by the email service. If it doesn't arrive, check spam and the SparkPost/Postmark activity log for $to.\n"
            : "Not sent.\n";
        exit($res['success'] ? 0 : 1);
    }

    $n = RbReports::runDue();
    echo "$start — report_builder: $n report(s) run\n";
} catch (\Throwable $e) {
    echo "$start — report_builder FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
