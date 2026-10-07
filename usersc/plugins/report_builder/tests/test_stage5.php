<?php
/**
 * Stage 5 tests: built-in mail senders + plugin settings, and chart blocks.
 * Included by run_tests.php.
 */
if (PHP_SAPI !== 'cli') die();

require_once __DIR__ . '/../assets/includes/rb_mail.php';
require_once __DIR__ . '/../assets/includes/rb_chart.php';

if (!function_exists('email')) {
    function email($to, $subject, $body) { $GLOBALS['us_email'][] = compact('to', 'subject', 'body'); return empty($GLOBALS['us_email_fail']); }
}

echo "Mail senders & settings\n";

$calls = [];
RbMail::$transport = function ($url, $headers, $body) use (&$calls) {
    $calls[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
    if (!empty($GLOBALS['http_fail'])) return [401, '{"errors":[{"message":"Forbidden"}],"ErrorCode":10,"Message":"Bad token"}'];
    return [200, strpos($url, 'postmark') ? '{"ErrorCode":0}' : '{"results":{"id":"1"}}'];
};
$png = "\x89PNG fake";
$att = [['name' => 'r.csv', 'content' => "a,b\n", 'type' => 'text/csv'], ['cid' => 'rbchart1_x', 'name' => 'c.png', 'content' => $png, 'type' => 'image/png', 'inline' => true]];

test('SparkPost: payload, inline images, attachments, EU host, errors', function () use (&$calls, $att, $png) {
    $calls = [];
    $s = ['provider' => 'sparkpost', 'sparkpost_api_key' => 'KEY', 'sparkpost_region' => 'eu', 'from_email' => 'r@x.example', 'from_name' => 'Reports', 'reply_to' => 'ops@x.example'];
    $r = RbMail::send($s, ['a@x.example', 'bad', 'b@x.example'], 'Subj', '<img src="cid:rbchart1_x">', $att);
    check($r['success'], json_encode($r));
    eq('https://api.eu.sparkpost.com/api/v1/transmissions', $calls[0]['url']);
    check(in_array('Authorization: KEY', $calls[0]['headers'], true));
    $b = $calls[0]['body'];
    eq([['address' => ['email' => 'a@x.example']], ['address' => ['email' => 'b@x.example']]], $b['recipients']);
    eq(['email' => 'r@x.example', 'name' => 'Reports'], $b['content']['from']);
    eq('ops@x.example', $b['content']['reply_to']);
    eq([['name' => 'rbchart1_x', 'type' => 'image/png', 'data' => base64_encode($png)]], $b['content']['inline_images']);
    eq('r.csv', $b['content']['attachments'][0]['name']);
    $GLOBALS['http_fail'] = true;
    $r = RbMail::send($s, ['a@x.example'], 'S', 'h');
    unset($GLOBALS['http_fail']);
    check(!$r['success'] && $r['message'] === 'SparkPost: Forbidden', $r['message']);
    $r = RbMail::send(['provider' => 'sparkpost'] + $s + ['sparkpost_api_key' => ''], ['a@x.example'], 'S', 'h');
    check(!RbMail::send(array_merge($s, ['sparkpost_api_key' => '']), ['a@x.example'], 'S', 'h')['success'], 'missing key');
});

test('Postmark: one message per recipient, ContentID for inline images', function () use (&$calls, $att) {
    $calls = [];
    $s = ['provider' => 'postmark', 'postmark_token' => 'TOK', 'from_email' => 'r@x.example', 'from_name' => 'Re"ports'];
    $r = RbMail::send($s, ['a@x.example', 'b@x.example'], 'Subj', 'h', $att);
    check($r['success'], json_encode($r));
    eq(2, count($calls));
    eq('b@x.example', $calls[1]['body']['To']);
    eq('"Reports" <r@x.example>', $calls[0]['body']['From']);
    check(in_array('X-Postmark-Server-Token: TOK', $calls[0]['headers'], true));
    eq('cid:rbchart1_x', $calls[0]['body']['Attachments'][1]['ContentID']);
    check(!isset($calls[0]['body']['Attachments'][0]['ContentID']));
});

test('UserSpice email(): one per recipient, reports dropped attachments', function () use ($att) {
    $GLOBALS['us_email'] = [];
    $r = RbMail::send(['provider' => 'userspice'], ['a@x.example', 'b@x.example'], 'S', 'h', $att);
    check($r['success'] && strpos($r['message'], '1 CSV attachment') !== false, $r['message']);
    eq(2, count($GLOBALS['us_email']));
    check(!RbMail::supportsInline(['provider' => 'userspice']) && RbMail::supportsInline(['provider' => 'sparkpost']));
});

test('settings page values, config file precedence, permission levels', function () {
    $GLOBALS['master_account'] = [];
    rb_fixture_db('sqlite::memory:');
    DB::$pdo->exec("INSERT INTO user_permission_matches VALUES (1,2),(2,5)");
    $cfg = sys_get_temp_dir() . '/rb_cfg5_' . getmypid() . '.php';
    file_put_contents($cfg, '<?php return [];');
    RbReports::$configFile = $cfg;
    RbReports::resetConfig();
    eq('userspice', RbReports::mailSettings()['provider'], 'default');
    check(RbReports::canBuild(1) && !RbReports::canBuild(2), 'default build perm = 2 (Administrator)');
    RbReports::saveSettings(['mail_provider' => 'sparkpost', 'sparkpost_api_key' => 'K', 'from_email' => 'a@b.example', 'base_url' => 'https://site.example',
                             'build_perms' => '5', 'brand' => 'Acme', 'bogus' => 'ignored']);
    eq('sparkpost', RbReports::mailSettings()['provider']);
    eq('K', RbReports::mailSettings()['sparkpost_api_key']);
    eq('https://site.example', RbReports::baseUrl());
    eq('Acme', RbReports::config()['brand']);
    check(RbReports::canBuild(2) && !RbReports::canBuild(1), 'build perm from settings');
    eq(1, (int) DB::$pdo->query("SELECT COUNT(*) FROM plg_rb_settings WHERE name = 'mail_provider'")->fetchColumn(), 'upsert, no duplicates');
    RbReports::saveSettings(['mail_provider' => 'postmark']);
    eq(1, (int) DB::$pdo->query("SELECT COUNT(*) FROM plg_rb_settings WHERE name = 'mail_provider'")->fetchColumn());
    check(!DB::$pdo->query("SELECT COUNT(*) FROM plg_rb_settings WHERE name = 'bogus'")->fetchColumn(), 'unknown keys ignored');
    // The project config file wins over the settings page.
    file_put_contents($cfg, '<?php return ["brand" => "FromFile", "mail" => function () { return ["provider" => "sparkpost", "sparkpost_api_key" => "FILEKEY"]; }, "can_build" => function ($u) { return $u == 1; }];');
    RbReports::resetConfig();
    eq('FromFile', RbReports::config()['brand']);
    eq('FILEKEY', RbReports::mailSettings()['sparkpost_api_key']);
    eq('a@b.example', RbReports::mailSettings()['from_email'], 'unset mail keys still come from settings');
    check(RbReports::canBuild(1) && !RbReports::canBuild(2));
    eq(['brand', 'mail', 'can_build'], RbReports::config()['from_file']);
    @unlink($cfg);
});

echo "Charts\n";

$cfgC = sys_get_temp_dir() . '/rb_cfgc_' . getmypid() . '.php';
file_put_contents($cfgC, '<?php return ["brand" => "Container Flow", "base_url" => "https://container-flow.com"];');
RbReports::$configFile = $cfgC;
RbReports::resetConfig();
rb_fixture_db('sqlite::memory:');
$rows = [];
$id = 0;
// Oct 1–7: inbound every day except Oct 4 (a gap), outbound on 3 days; 8 clients.
foreach (['2026-10-01' => [3, 1], '2026-10-02' => [1, 0], '2026-10-03' => [2, 2], '2026-10-05' => [4, 0], '2026-10-06' => [1, 1], '2026-10-07' => [2, 0]] as $d => [$in, $out]) {
    for ($i = 0; $i < $in; $i++)  $rows[] = [++$id, "IN$id", 'inbound', 'pending', ($id % 8) + 1, 1, null, $id, "$d 09:00:00", "$d 09:00:00"];
    for ($i = 0; $i < $out; $i++) $rows[] = [++$id, "OUT$id", 'outbound', 'completed', ($id % 8) + 1, 1, null, $id, "$d 10:00:00", "$d 10:00:00"];
}
rb_seed($rows);
DB::$pdo->exec("INSERT INTO customers VALUES (3,'C3'),(4,'C4'),(5,'C5'),(6,'C6'),(7,'C7'),(8,'C8')");
$chart = function (array $b, $mode = 'web') {
    return RbRender::render(['blocks' => [$b + ['type' => 'chart', 'dataset' => 'containers']]],
        ['now' => new DateTime('2026-10-07 12:00:00'), 'dialect' => 'sqlite', 'mode' => $mode, 'report_name' => 'T']);
};

test('line chart by day, split by type: PNG, legend, gap filled, table, CSV', function () use ($chart) {
    $o = $chart(['chart_type' => 'line', 'title' => 'Per day', 'query' => ['group_by' => [['field' => 'created_at', 'bucket' => 'day'], 'type'], 'aggregates' => [['fn' => 'count']]]]);
    eq(1, count($o['images']));
    $info = getimagesizefromstring($o['images'][0]['content']);
    eq([1192, 560, 'image/png'], [$info[0], $info[1], $info['mime']], 'PNG at 2× of 596×280');
    check(strpos($o['html'], 'src="data:image/png;base64,') !== false, 'web mode embeds data URI');
    $t = rb_text($o['html']);
    check(strpos($t, 'Inbound Outbound') !== false, 'legend: ' . $t);
    check(strpos($t, 'Created (day) Inbound Outbound Oct 1 3 1 Oct 2 1 0 Oct 3 2 2 Oct 4 0 0 Oct 5 4 0') !== false, 'table with Oct 4 filled: ' . $t);
    check(preg_match('/alt="Line chart of Count by Created \(day\), 2 series\. Highest: Oct 5 \(4\)/', $o['html']) === 1, 'alt text');
    eq("\"Created (day)\",Inbound,Outbound\n\"Oct 1\",3,1", implode("\n", array_slice(explode("\n", $o['attachments'][0]['content']), 0, 2)));
});

test('date range on the X field spans the whole window; counts get whole-number ticks', function () use ($chart) {
    $o = $chart(['chart_type' => 'column', 'query' => ['group_by' => [['field' => 'created_at', 'bucket' => 'day']], 'aggregates' => [['fn' => 'count']],
        'date_window' => ['range' => 'last_7_days']]]);
    $t = rb_text($o['html']);
    check(strpos($t, 'Sep 30 0 Oct 1 4 Oct 2 1 Oct 3 4 Oct 4 0 Oct 5 4 Oct 6 2 Oct 7 2') !== false, $t);
    eq([0.0, 2.0, 0.5], array_map('floatval', RbChart::niceScale(0, 2)), 'raw scale');
    eq([0.0, 2.0, 1.0], array_map('floatval', RbChart::niceScale(0, 2, true)), 'whole numbers');
    $o = $chart(['chart_type' => 'column', 'query' => ['group_by' => [['field' => 'created_at', 'bucket' => 'week']], 'aggregates' => [['fn' => 'count']],
        'date_window' => ['range' => 'custom', 'start' => '2026-09-14', 'end' => '2026-10-07']]]);
    check(strpos(rb_text($o['html']), 'Sep 14 0 Sep 21 0 Sep 28 9 Oct 5 8') !== false, rb_text($o['html']));
});

test('email mode references the image by cid', function () use ($chart) {
    $o = $chart(['chart_type' => 'column', 'query' => ['group_by' => ['type'], 'aggregates' => [['fn' => 'count']]]], 'email');
    $cid = $o['images'][0]['cid'];
    check(preg_match('/^rbchart1_[0-9a-f]{10}$/', $cid) === 1, $cid);
    check(strpos($o['html'], 'src="cid:' . $cid . '"') !== false && strpos($o['html'], 'data:image') === false);
    check(strpos(rb_text($o['html']), 'Inbound Outbound') === false, 'no legend for one series');
});

test('bar chart is pure HTML, sorted biggest first, top 15 + Other', function () use ($chart) {
    $o = $chart(['chart_type' => 'bar', 'query' => ['group_by' => ['customer'], 'aggregates' => [['fn' => 'count']]]]);
    eq(0, count($o['images']));
    check(strpos($o['html'], 'background:#2a78d6') !== false, 'bars drawn');
    $t = rb_text($o['html']);
    check(strpos($t, 'Beta & Sons <Foods> 3 ') === 0, 'biggest first: ' . $t);
    check(strpos($t, 'Client Count Beta & Sons <Foods> 3') !== false, 'table twin');
    check(strpos($o['html'], '<img') === false);
    // 17 containers, one bar each → 14 + "Other" (3), with a note.
    rb_register_dataset('ctest', ['table' => 'containers', 'alias' => 'c', 'fields' => ['container_number' => ['type' => 'text']]]);
    $o = $chart(['chart_type' => 'bar', 'dataset' => 'ctest', 'query' => ['group_by' => ['container_number'], 'aggregates' => [['fn' => 'count']]]]);
    $t = rb_text($o['html']);
    check(strpos($t, 'Other 3') !== false && strpos($t, '3 more grouped as Other.') !== false, $t);
    eq(15, substr_count($o['html'], 'background:#2a78d6'));
    throws('RbQueryException', function () use ($chart) { $chart(['chart_type' => 'bar', 'query' => ['group_by' => ['customer', 'type'], 'aggregates' => [['fn' => 'count']]]]); }, 'one series');
});

test('more than 6 series fold into Other (additive) or are dropped with a note', function () use ($chart) {
    $o = $chart(['chart_type' => 'column', 'query' => ['group_by' => ['type', 'customer'], 'aggregates' => [['fn' => 'count']]]]);
    $t = rb_text($o['html']);
    check(substr_count($o['html'], 'border-radius:2px;background:') === 6 && strpos($t, 'Other') !== false, 'legend has 6 entries incl Other: ' . $t);
    $o = $chart(['chart_type' => 'column', 'query' => ['group_by' => ['type', 'customer'], 'aggregates' => [['fn' => 'max', 'field' => 'piece_count']]]]);
    check(strpos(rb_text($o['html']), 'smaller series not shown') !== false && strpos(rb_text($o['html']), 'Other') === false);
});

test('chart validation messages', function () use ($chart) {
    throws('RbQueryException', function () use ($chart) { $chart(['chart_type' => 'line', 'query' => ['aggregates' => [['fn' => 'count']]]]); }, 'X-axis');
    throws('RbQueryException', function () use ($chart) { $chart(['chart_type' => 'line', 'query' => ['group_by' => ['type'], 'aggregates' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'piece_count']]]]); }, 'exactly one value');
    throws('RbQueryException', function () use ($chart) { $chart(['chart_type' => 'line', 'query' => ['group_by' => ['notes'], 'aggregates' => [['fn' => 'count']]]]); }, 'grouped');
});

test('show_table off, hide_if_empty, empty text', function () use ($chart) {
    $o = $chart(['chart_type' => 'column', 'show_table' => false, 'query' => ['group_by' => ['type'], 'aggregates' => [['fn' => 'count']]]]);
    check(strpos($o['html'], '<th') === false, 'no table');
    $none = ['chart_type' => 'column', 'query' => ['group_by' => ['type'], 'aggregates' => [['fn' => 'count']], 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'reviewed']]]];
    eq(0, count($chart($none + ['hide_if_empty' => true])['images']));
    check(strpos(rb_text($chart($none + ['empty_text' => 'Quiet week'])['html']), 'Quiet week') !== false);
});

test('send: SparkPost gets inline images; UserSpice email gets embedded images', function () use (&$calls) {
    $layout = json_encode(['blocks' => [['type' => 'chart', 'dataset' => 'containers', 'chart_type' => 'column', 'query' => ['group_by' => ['type'], 'aggregates' => [['fn' => 'count']]]]]]);
    $id = RbReports::save(['name' => 'Chart mail', 'layout_json' => $layout, 'created_by' => 1, 'scope_mode' => 'none']);
    RbReports::saveRecipients($id, [['kind' => 'email', 'email' => 'a@x.example']]);
    RbReports::saveSettings(['mail_provider' => 'sparkpost', 'sparkpost_api_key' => 'K']);
    $calls = [];
    $r = RbReports::run(RbReports::get($id), 'manual');
    check($r['success'], $r['message']);
    $c = $calls[0]['body']['content'];
    eq(1, count($c['inline_images']));
    check(strpos($c['html'], 'cid:' . $c['inline_images'][0]['name']) !== false, 'html references the inline image');
    check(!isset($c['attachments']), 'CSV off → no attachments');
    RbReports::saveSettings(['mail_provider' => 'userspice']);
    $GLOBALS['us_email'] = [];
    $r = RbReports::run(RbReports::get($id), 'manual');
    check($r['success'], $r['message']);
    check(strpos($GLOBALS['us_email'][0]['body'], 'src="data:image/png;base64,') !== false && strpos($GLOBALS['us_email'][0]['body'], 'cid:') === false);
});

test('In Progress preset renders: tiles, bar chart, grouped list, 30-day column chart', function () use (&$calls) {
    RbReports::saveSettings(['mail_provider' => 'sparkpost', 'sparkpost_api_key' => 'K']);
    DB::$pdo->exec("UPDATE containers SET status = 'in_progress' WHERE id IN (1, 2, 4)");
    DB::$pdo->exec("INSERT INTO users (id,email,fname,lname,active) VALUES (9,'sup@x.example','Sam','Sup',1); INSERT INTO user_permission_matches VALUES (9,3)");
    $id = RbReports::createFromPreset(RbReports::presets()['in_progress'], 1);
    $calls = [];
    $r = RbReports::run(RbReports::get($id), 'manual', ['now' => new DateTime('2026-10-07 12:00:00')]);
    check($r['success'], $r['message']);
    $c = $calls[0]['body']['content'];
    eq('In progress: 3 containers for 3 clients — Oct 7, 2026', $c['subject']);
    eq(1, count($c['inline_images']), 'one PNG (the bar chart is HTML)');
    $t = rb_text($c['html']);
    check(strpos($t, '3 In Progress 3 Clients') !== false, $t);
    check(strpos($t, 'In progress by client') !== false && strpos($t, 'New containers — last 30 days') !== false, $t);
    check(strpos($t, 'Container Type Warehouse Assigned Carrier Last Updated') !== false, 'grouped list columns');
});

RbMail::$transport = null;
@unlink($cfgC);
