<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';

ob_end_clean();
header('Content-Type: application/json');

if (!$user->isLoggedIn()) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }

$db = DB::getInstance();

// ── Date range ────────────────────────────────────────────────────────────────
$preset = $_REQUEST['preset'] ?? '30days';
$from   = trim($_REQUEST['from'] ?? '');
$to     = trim($_REQUEST['to']   ?? '');

if ($from && $to) {
    $date_from = $from . ' 00:00:00';
    $date_to   = $to   . ' 23:59:59';
} else {
    $date_to = date('Y-m-d 23:59:59');
    switch ($preset) {
        case '7days':  $date_from = date('Y-m-d 00:00:00', strtotime('-7 days'));  break;
        case '90days': $date_from = date('Y-m-d 00:00:00', strtotime('-90 days')); break;
        case 'year':   $date_from = date('Y-01-01 00:00:00');                       break;
        case 'all':    $date_from = '2000-01-01 00:00:00';                          break;
        default:       $date_from = date('Y-m-d 00:00:00', strtotime('-30 days'));  break;
    }
}

$use_weekly = (strtotime($date_to) - strtotime($date_from)) > (45 * 86400);

// ── Summary ───────────────────────────────────────────────────────────────────
$summary_rows = $db->query(
    "SELECT status, type, COUNT(*) as cnt FROM containers WHERE created_at BETWEEN ? AND ? GROUP BY status, type",
    [$date_from, $date_to]
)->results() ?: [];

$summary = ['total'=>0,'pending'=>0,'in_progress'=>0,'completed'=>0,'reviewed'=>0,'inbound'=>0,'outbound'=>0];
foreach ($summary_rows as $r) { $summary['total'] += $r->cnt; $summary[$r->status] = ($summary[$r->status]??0) + $r->cnt; $summary[$r->type] = ($summary[$r->type]??0) + $r->cnt; }

// Avg turnaround
$ta_raw = $db->query(
    "SELECT c.id, c.created_at,
     MIN(CASE WHEN cal.action='status_changed' AND cal.details LIKE '%to reviewed%' THEN cal.created_at END) AS reviewed_at
     FROM containers c LEFT JOIN container_activity_log cal ON cal.container_id = c.id
     WHERE c.status='reviewed' AND c.created_at BETWEEN ? AND ?
     GROUP BY c.id, c.created_at",
    [$date_from, $date_to]
)->results() ?: [];

$ta_hours = [];
foreach ($ta_raw as $r) {
    if (!$r->reviewed_at) continue;
    $diff = (strtotime($r->reviewed_at) - strtotime($r->created_at)) / 3600;
    if ($diff > 0) $ta_hours[] = $diff;
}
$avg_hours = count($ta_hours) ? array_sum($ta_hours)/count($ta_hours) : null;
$summary['avg_turnaround_days'] = $avg_hours !== null ? round($avg_hours/24, 1) : null;
$summary['reviewed_with_timing'] = count($ta_hours);

// ── Volume over time ──────────────────────────────────────────────────────────
$vol_rows = $db->query(
    $use_weekly
        ? "SELECT DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY)) AS period, COUNT(*) AS cnt FROM containers WHERE created_at BETWEEN ? AND ? GROUP BY period ORDER BY period"
        : "SELECT DATE(created_at) AS period, COUNT(*) AS cnt FROM containers WHERE created_at BETWEEN ? AND ? GROUP BY period ORDER BY period",
    [$date_from, $date_to]
)->results() ?: [];
$volume = array_map(fn($r)=>['date'=>$r->period,'count'=>(int)$r->cnt], $vol_rows);

// ── By status ─────────────────────────────────────────────────────────────────
$by_status = array_map(fn($r)=>['status'=>$r->status,'count'=>(int)$r->cnt],
    $db->query("SELECT status, COUNT(*) AS cnt FROM containers WHERE created_at BETWEEN ? AND ? GROUP BY status", [$date_from,$date_to])->results() ?: []);

// ── By type ───────────────────────────────────────────────────────────────────
$by_type = array_map(fn($r)=>['type'=>$r->type,'count'=>(int)$r->cnt],
    $db->query("SELECT type, COUNT(*) AS cnt FROM containers WHERE created_at BETWEEN ? AND ? GROUP BY type", [$date_from,$date_to])->results() ?: []);

// ── Top clients (chart) ───────────────────────────────────────────────────────
$by_client = array_map(fn($r)=>['name'=>$r->name,'count'=>(int)$r->cnt],
    $db->query("SELECT COALESCE(cu.name,'(No client)') AS name, COUNT(*) AS cnt FROM containers c LEFT JOIN customers cu ON cu.id=c.customer_id WHERE c.created_at BETWEEN ? AND ? GROUP BY cu.id, cu.name ORDER BY cnt DESC LIMIT 10", [$date_from,$date_to])->results() ?: []);

// ── Top carriers ─────────────────────────────────────────────────────────────
$by_carrier = array_map(fn($r)=>['carrier'=>$r->carrier,'count'=>(int)$r->cnt],
    $db->query("SELECT COALESCE(NULLIF(carrier,''),'(None)') AS carrier, COUNT(*) AS cnt FROM containers WHERE created_at BETWEEN ? AND ? GROUP BY carrier ORDER BY cnt DESC LIMIT 10", [$date_from,$date_to])->results() ?: []);

// ── Avg turnaround per client (chart) ─────────────────────────────────────────
$ta_client = array_map(fn($r)=>['customer'=>$r->customer,'avg_days'=>round((float)$r->avg_days,1),'count'=>(int)$r->cnt],
    $db->query(
        "SELECT COALESCE(cu.name,'(No client)') AS customer, COUNT(*) AS cnt,
         AVG(TIMESTAMPDIFF(HOUR, c.created_at, COALESCE((SELECT MIN(cal.created_at) FROM container_activity_log cal WHERE cal.container_id=c.id AND cal.action='status_changed' AND cal.details LIKE '%to reviewed%'), c.updated_at)))/24 AS avg_days
         FROM containers c LEFT JOIN customers cu ON cu.id=c.customer_id
         WHERE c.status='reviewed' AND c.created_at BETWEEN ? AND ?
         GROUP BY cu.id, cu.name HAVING avg_days > 0 ORDER BY avg_days ASC",
        [$date_from,$date_to]
    )->results() ?: []);

// ── Detailed per-client table ─────────────────────────────────────────────────
$client_detail = $db->query(
    "SELECT
        COALESCE(cu.name,'(No client)') AS client,
        COUNT(*) AS total,
        SUM(CASE WHEN c.status='reviewed' THEN 1 ELSE 0 END) AS reviewed,
        SUM(CASE WHEN c.status IN ('pending','in_progress','completed') THEN 1 ELSE 0 END) AS open,
        SUM(CASE WHEN c.type='inbound' THEN 1 ELSE 0 END) AS inbound,
        SUM(CASE WHEN c.type='outbound' THEN 1 ELSE 0 END) AS outbound,
        AVG(CASE WHEN c.status='reviewed' THEN
            TIMESTAMPDIFF(HOUR, c.created_at, COALESCE(
                (SELECT MIN(cal.created_at) FROM container_activity_log cal
                 WHERE cal.container_id=c.id AND cal.action='status_changed' AND cal.details LIKE '%to reviewed%'),
                c.updated_at))
            ELSE NULL END)/24 AS avg_turnaround_days,
        MAX(c.created_at) AS last_container
     FROM containers c
     LEFT JOIN customers cu ON cu.id = c.customer_id
     WHERE c.created_at BETWEEN ? AND ?
     GROUP BY cu.id, cu.name
     ORDER BY total DESC",
    [$date_from, $date_to]
)->results() ?: [];

$client_table = array_map(fn($r) => [
    'client'              => $r->client,
    'total'               => (int)$r->total,
    'reviewed'            => (int)$r->reviewed,
    'open'                => (int)$r->open,
    'inbound'             => (int)$r->inbound,
    'outbound'            => (int)$r->outbound,
    'completion_rate'     => $r->total > 0 ? round(($r->reviewed / $r->total) * 100) : 0,
    'avg_turnaround_days' => $r->avg_turnaround_days !== null ? round((float)$r->avg_turnaround_days, 1) : null,
    'last_container'      => $r->last_container,
], $client_detail);

// ── Trend vs prior period ─────────────────────────────────────────────────────
$range_sec   = strtotime($date_to) - strtotime($date_from);
$prior_from  = date('Y-m-d H:i:s', strtotime($date_from) - $range_sec);
$prior_to    = date('Y-m-d H:i:s', strtotime($date_from) - 1);
$prior_count = (int)($db->query("SELECT COUNT(*) AS cnt FROM containers WHERE created_at BETWEEN ? AND ?", [$prior_from,$prior_to])->first()->cnt ?? 0);
$trend_pct   = $prior_count > 0 ? round((($summary['total'] - $prior_count)/$prior_count)*100, 1) : null;

echo json_encode([
    'success'        => true,
    'date_from'      => substr($date_from,0,10),
    'date_to'        => substr($date_to,0,10),
    'use_weekly'     => $use_weekly,
    'summary'        => $summary,
    'prior_count'    => $prior_count,
    'trend_pct'      => $trend_pct,
    'volume'         => $volume,
    'by_status'      => $by_status,
    'by_type'        => $by_type,
    'by_client'      => $by_client,
    'by_carrier'     => $by_carrier,
    'turnaround_by_client' => $ta_client,
    'client_table'   => $client_table,
]);
