<?php
/**
 * Stage 2 tests: block renderer, report storage/delivery, and the daily
 * digest preset compared against the original cron/daily_digest.php.
 * Included by run_tests.php (uses its test()/check()/eq()/throws() helpers).
 */
if (PHP_SAPI !== 'cli') die();

require_once __DIR__ . '/fake_userspice.php';
require_once __DIR__ . '/../assets/includes/rb_render.php';
require_once __DIR__ . '/../assets/includes/rb_reports.php';

$GLOBALS['rb_sent'] = [];
$cfgFile = sys_get_temp_dir() . '/rb_cfg_' . getmypid() . '.php';
file_put_contents($cfgFile, '<?php return [
    "brand" => "Container Flow",
    "base_url" => "https://container-flow.com",
    "mailer" => function (array $to, $subject, $html, array $att) {
        $GLOBALS["rb_sent"][] = compact("to", "subject", "html", "att");
        return ["success" => empty($GLOBALS["rb_mail_fail"]), "message" => "smtp down"];
    },
];');
RbReports::$configFile = $cfgFile;
RbReports::resetConfig();

function rb_text($html) {
    $t = preg_replace('/<[^>]+>/', ' ', $html);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}
function rb_hrefs($html) {
    preg_match_all('/href="([^"]*)"/', $html, $m);
    return array_map('html_entity_decode', $m[1]);
}
function rb_seed(array $containers) {
    DB::$pdo->exec('DELETE FROM containers');
    $st = DB::$pdo->prepare('INSERT INTO containers (id,container_number,type,status,customer_id,warehouse_id,carrier,piece_count,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,1,?,?)');
    foreach ($containers as $c) $st->execute($c);
}
function rb_render_layout(array $layout, array $ctx = []) {
    return RbRender::render($layout, ['ctx' => $ctx, 'report_name' => 'Test', 'brand' => 'Container Flow',
        'base_url' => 'https://container-flow.com', 'now' => new DateTime('2026-10-07 12:00:00'), 'dialect' => 'sqlite']);
}

$digestScenarios = [
    'one awaiting + others' => [
        [1, 'MSCU1000001', 'inbound',  'completed',   1, 1, 'ABC', 10, '2026-10-01 08:00:00', '2026-10-06 15:30:00'],
        [2, 'MSCU1000002', 'outbound', 'in_progress', 2, 2, 'XYZ', 20, '2026-10-02 08:00:00', '2026-10-05 09:05:00'],
        [3, 'TGHU2000003', 'inbound',  'pending',  null, 1, null, null,'2026-10-03 07:00:00', '2026-10-03 07:00:00'],
        [4, 'TGHU2000004', 'inbound',  'pending',     1, 2, 'ABC', 5,  '2026-10-04 07:00:00', '2026-10-02 07:00:00'],
        [5, 'OLD0000005',  'outbound', 'reviewed',    1, 1, 'ABC', 5,  '2026-09-01 07:00:00', '2026-09-02 07:00:00'],
    ],
    'several awaiting' => [
        [1, 'A1', 'inbound',  'completed', 1, 1, null, null, '2026-10-01 08:00:00', '2026-10-06 10:00:00'],
        [2, 'A2', 'outbound', 'completed', 2, 1, null, null, '2026-10-01 08:00:00', '2026-10-04 10:00:00'],
        [3, 'A3', 'inbound',  'completed', 1, 2, null, null, '2026-10-01 08:00:00', '2026-10-05 10:00:00'],
    ],
    'open but none awaiting' => [
        [1, 'P1', 'inbound', 'pending', 1, 1, null, null, '2026-10-01 08:00:00', '2026-10-01 08:00:00'],
    ],
    'all clear' => [
        [1, 'R1', 'inbound', 'reviewed', 1, 1, null, null, '2026-10-01 08:00:00', '2026-10-01 08:00:00'],
    ],
];

echo "Daily digest: blocks vs original cron/daily_digest.php\n";

$preset = json_decode(file_get_contents(dirname(__DIR__, 3) . '/report_presets/daily_digest.json'), true);
foreach ($digestScenarios as $name => $rows) {
    test("digest matches original — $name", function () use ($rows, $preset) {
        $dbFile = tempnam(sys_get_temp_dir(), 'rbdb');
        $outFile = tempnam(sys_get_temp_dir(), 'rbout');
        rb_fixture_db("sqlite:$dbFile");
        rb_seed($rows);

        // Original script, unmodified, in its own process.
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/legacy_digest_runner.php') . ' '
            . escapeshellarg($dbFile) . ' ' . escapeshellarg($outFile) . ' 2>&1', $o, $code);
        $legacy = json_decode((string) file_get_contents($outFile), true);
        check(is_array($legacy), 'legacy digest did not send: ' . implode("\n", $o));

        // Block version, through the real save → resolve recipients → render → mailer path.
        $GLOBALS['rb_sent'] = [];
        $id = RbReports::createFromPreset($preset, 1);
        $res = RbReports::run(RbReports::get($id), 'manual');
        check($res['success'], $res['message']);
        eq(1, count($GLOBALS['rb_sent']));
        $new = $GLOBALS['rb_sent'][0];

        eq($legacy['subject'], $new['subject'], 'subject');
        eq(rb_text($legacy['html']), rb_text($new['html']), 'visible text');
        eq(rb_hrefs($legacy['html']), rb_hrefs($new['html']), 'links');
        $sorted = function ($a) { sort($a); return $a; };
        eq($sorted($legacy['to']), $sorted($new['to']), 'recipients');

        @unlink($dbFile); @unlink($outFile);
    });
}

echo "Renderer\n";

rb_fixture_db('sqlite::memory:');
rb_seed([
    [1, 'MSCU1', 'inbound',  'pending',     1, 1, 'ABC', 10, '2026-10-06 08:00:00', '2026-10-06 08:00:00'],
    [2, 'MSCU2', 'outbound', 'completed',   2, 2, 'XYZ', 20, '2026-10-05 08:00:00', '2026-10-05 08:00:00'],
    [3, 'MSCU3', 'inbound',  'in_progress', 1, 2, 'ABC', 30, '2026-09-05 08:00:00', '2026-09-05 08:00:00'],
    [4, '=HYPERLINK("x")', 'inbound', 'pending', 2, 1, null, 1, '2026-10-07 08:00:00', '2026-10-07 08:00:00'],
]);

test('layout text is escaped everywhere', function () {
    $x = '<script>alert(1)</script>';
    $out = rb_render_layout([
        'metrics' => ['n' => ['dataset' => 'containers']],
        'blocks' => [
            ['type' => 'header', 'eyebrow' => $x, 'title' => $x, 'subtitle' => $x],
            ['type' => 'summary_tiles', 'tiles' => [['label' => $x, 'metric' => 'n', 'color' => 'red;background:url(x)']]],
            ['type' => 'text', 'body' => $x],
            ['type' => 'table', 'title' => $x, 'dataset' => 'containers', 'query' => ['fields' => ['customer']], 'labels' => ['customer' => $x]],
        ],
        'subject' => [['text' => $x]],
    ]);
    check(strpos($out['html'], '<script>') === false, 'unescaped script');
    check(strpos($out['html'], 'url(x)') === false, 'colour injection');
    check(strpos($out['html'], 'Beta &amp; Sons &lt;Foods&gt;') !== false, 'data not escaped');
    eq($x, $out['subject'], 'subject is plain text (mailer escapes)');
    eq('a b', rb_render_layout(['subject' => [['text' => "a\r\nb"]]])['subject'], 'no line breaks in subject');
});

test('button URLs: relative → site URL, unsafe schemes dropped', function () {
    $out = rb_render_layout(['blocks' => [['type' => 'buttons', 'buttons' => [
        ['label' => 'A', 'url' => 'usersc/container_dashboard.php?x=1'],
        ['label' => 'B', 'url' => 'javascript:alert(1)'],
        ['label' => 'C', 'url' => '//evil.example/x'],
        ['label' => 'D', 'url' => 'https://example.com/a'],
        ['label' => 'E', 'url' => 'data:text/html,hi'],
    ]]]]);
    eq(['https://container-flow.com/usersc/container_dashboard.php?x=1', 'https://example.com/a'], rb_hrefs($out['html']));
});

test('tokens and plurals', function () {
    $out = rb_render_layout([
        'metrics' => ['pending' => ['dataset' => 'containers', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'pending']]],
                      'done' => ['dataset' => 'containers', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'completed']]]],
        'blocks' => [['type' => 'text', 'body' => "{pending} item{s:pending}, {done} item{s:done} on {date_short} for {brand} {unknown}"]],
    ]);
    check(strpos(rb_text($out['html']), '2 items, 1 item on Oct 7, 2026 for Container Flow {unknown}') !== false, rb_text($out['html']));
});

test('show_if hides blocks', function () {
    $layout = ['metrics' => ['n' => ['dataset' => 'containers', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'reviewed']]]],
               'blocks' => [['type' => 'text', 'body' => 'SHOWN', 'show_if' => ['metric' => 'n', 'op' => 'gt', 'value' => 0]],
                            ['type' => 'text', 'body' => 'NONE', 'show_if' => ['metric' => 'n', 'op' => 'eq', 'value' => 0]]]];
    $t = rb_text(rb_render_layout($layout)['html']);
    check(strpos($t, 'SHOWN') === false && strpos($t, 'NONE') !== false, $t);
});

test('table formatting: enum labels, dates, numbers, empty text', function () {
    $out = rb_render_layout(['blocks' => [
        ['type' => 'table', 'dataset' => 'containers', 'query' => ['fields' => ['container_number', 'status', 'created_at', 'piece_count'], 'filters' => [['field' => 'id', 'op' => 'eq', 'value' => 3]]]],
        ['type' => 'table', 'dataset' => 'containers', 'query' => ['filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'reviewed']]], 'empty_text' => 'Nothing reviewed'],
        ['type' => 'table', 'dataset' => 'containers', 'hide_if_empty' => true, 'title' => 'HIDDEN', 'query' => ['filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'reviewed']]]],
    ]]);
    $t = rb_text($out['html']);
    check(strpos($t, 'MSCU3 In Progress Sep 5, 8:00 AM 30') !== false, $t);
    check(strpos($t, 'Nothing reviewed') !== false && strpos($t, 'HIDDEN') === false, $t);
    check(strpos($out['html'], 'font-family:Courier New') !== false, 'mono display hint');
    eq(1, $out['row_count']);
});

test('grouped table follows enum option order with counts', function () {
    $out = rb_render_layout(['blocks' => [['type' => 'grouped_table', 'dataset' => 'containers', 'group_field' => 'status',
        'query' => ['fields' => ['container_number'], 'sort' => [['key' => 'container_number', 'dir' => 'asc']]]]]]);
    $t = rb_text($out['html']);
    check(preg_match('/Pending \(2\).*In Progress \(1\).*Completed \(1\)/', $t) === 1, $t);
    check(strpos($out['attachments'][0]['content'], "Status,\"Container #\"") === 0, $out['attachments'][0]['content']);
});

test('grouped tables by client (A–Z)', function () {
    $t = rb_text(rb_render_layout(['blocks' => [['type' => 'grouped_table', 'dataset' => 'containers', 'group_field' => 'customer', 'query' => ['fields' => ['container_number']]]]])['html']);
    check(preg_match('/Acme Imports \(2\).*Beta & Sons <Foods> \(2\)/', $t) === 1, $t);
});

test('CSV attachment: values, formula guard, opt-out', function () {
    $out = rb_render_layout(['blocks' => [
        ['type' => 'table', 'title' => 'All Containers', 'dataset' => 'containers', 'query' => ['fields' => ['container_number', 'customer', 'piece_count'], 'sort' => ['id']]],
        ['type' => 'table', 'title' => 'No CSV', 'csv' => false, 'dataset' => 'containers'],
    ]]);
    eq(1, count($out['attachments']));
    eq('all_containers_2026-10-07.csv', $out['attachments'][0]['name']);
    $lines = explode("\n", trim($out['attachments'][0]['content']));
    eq('"Container #",Client,"Piece Count"', $lines[0]);
    eq('MSCU1,"Acme Imports",10', $lines[1]);
    eq('"\'=HYPERLINK(""x"")","Beta & Sons <Foods>",1', $lines[4]);
});

test('summary block via aggregates table', function () {
    $out = rb_render_layout(['blocks' => [['type' => 'table', 'dataset' => 'containers',
        'query' => ['group_by' => ['customer'], 'aggregates' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'piece_count']], 'sort' => [['key' => 'customer']]]]]]);
    check(strpos(rb_text($out['html']), 'Client Count Total Piece Count Acme Imports 2 40 Beta & Sons <Foods> 2 21') !== false, rb_text($out['html']));
});

test('report-level filters apply to every block and metric on that dataset', function () {
    $out = rb_render_layout([
        'report_filters' => [['dataset' => 'containers', 'field' => 'customer_id', 'op' => 'in', 'value' => [1]]],
        'metrics' => ['n' => ['dataset' => 'containers']],
        'blocks' => [['type' => 'text', 'body' => 'n={n}'], ['type' => 'table', 'dataset' => 'containers', 'query' => ['fields' => ['container_number']]]],
    ]);
    $t = rb_text($out['html']);
    check(strpos($t, 'n=2') !== false && strpos($t, 'MSCU2') === false, $t);
});

test('scope applies inside the renderer', function () {
    $out = rb_render_layout(['metrics' => ['n' => ['dataset' => 'containers']], 'blocks' => [['type' => 'text', 'body' => '{n}']]], ['user_id' => 7]);
    eq('2', rb_text($out['html'])); // user 7 = North (warehouse 1) only
});

test('invalid layouts give readable errors', function () {
    throws('RbQueryException', function () { rb_render_layout(['blocks' => [['type' => 'iframe']]]); }, 'unknown block');
    throws('RbQueryException', function () { rb_render_layout(['blocks' => [['type' => 'table', 'dataset' => 'nope']]]); }, 'unknown dataset');
    throws('RbQueryException', function () { rb_render_layout(['blocks' => [['type' => 'summary_tiles', 'tiles' => [['metric' => 'x']]]]]); }, 'unknown metric');
    throws('RbQueryException', function () { rb_render_layout(['blocks' => [['type' => 'text', 'body' => 'x', 'show_if' => ['metric' => 'x']]]]); });
    throws('RbQueryException', function () { rb_render_layout(['blocks' => [['type' => 'grouped_table', 'dataset' => 'containers', 'group_field' => 'notes']]]); }, 'groupable');
    throws('RbQueryException', function () { rb_render_layout(['metrics' => ['Bad Name' => ['dataset' => 'containers']]]); });
});

echo "Reports: storage, schedule, delivery\n";

test('isDue: daily/weekly/monthly, hour match, no double send', function () {
    $r = (object) ['active' => 1, 'schedule_frequency' => 'daily', 'schedule_hour' => 6, 'schedule_day_of_week' => 3, 'schedule_day_of_month' => 31, 'last_sent_at' => null];
    $wed6 = new DateTime('2026-10-07 06:20:00'); // a Wednesday
    check(RbReports::isDue($r, $wed6));
    check(!RbReports::isDue($r, new DateTime('2026-10-07 07:00:00')), 'wrong hour');
    $r->last_sent_at = '2026-10-07 06:01:00';
    check(!RbReports::isDue($r, $wed6), 'already sent today');
    $r->last_sent_at = '2026-10-06 06:01:00';
    check(RbReports::isDue($r, $wed6));
    $r->schedule_frequency = 'weekly';
    check(RbReports::isDue($r, $wed6));
    $r->schedule_day_of_week = 1;
    check(!RbReports::isDue($r, $wed6), 'not Monday');
    $r->schedule_frequency = 'monthly';
    check(RbReports::isDue($r, new DateTime('2026-02-28 06:00:00')), 'day 31 → last day of Feb');
    check(!RbReports::isDue($r, new DateTime('2026-10-30 06:00:00')));
    $r->active = 0;
    check(!RbReports::isDue($r, new DateTime('2026-10-31 06:00:00')), 'inactive');
});

test('recipients: emails (non-users OK), users, permissions; inactive skipped; de-duped; notes kept', function () {
    $id = RbReports::save(['name' => 'R', 'layout_json' => '{}', 'created_by' => 1]);
    $problems = RbReports::saveRecipients($id, [
        ['kind' => 'email', 'email' => 'client@acme.example', 'note' => 'Acme ops manager'],
        ['kind' => 'email', 'email' => 'not-an-email'],
        ['kind' => 'permission', 'permission_id' => 3, 'note' => 'Supervisors'],
        ['kind' => 'user', 'user_id' => 1, 'note' => 'dup of a supervisor'],
    ]);
    eq(1, count($problems));
    $got = RbReports::resolveRecipients($id);
    eq(['client@acme.example', 'sue@example.com', 'dan@example.com'], array_column($got, 'email')); // users sorted by last name
    eq('Acme ops manager', $got[0]['note']);
    eq(null, $got[0]['user_id']);
    eq(2, $got[1]['user_id']);
});

test('scope_mode=recipient sends each user their own warehouses', function () {
    $GLOBALS['rb_sent'] = [];
    $layout = json_encode(['metrics' => ['n' => ['dataset' => 'containers']], 'subject' => [['text' => '{n} containers']], 'blocks' => [['type' => 'text', 'body' => '{n}']]]);
    $id = RbReports::save(['name' => 'Scoped', 'layout_json' => $layout, 'scope_mode' => 'recipient', 'created_by' => 1]);
    RbReports::saveRecipients($id, [['kind' => 'user', 'user_id' => 7], ['kind' => 'user', 'user_id' => 1], ['kind' => 'email', 'email' => 'x@y.example']]);
    DB::$pdo->exec("INSERT INTO users (id,email,fname,lname,active) VALUES (7,'north@example.com','Nora','North',1)");
    $res = RbReports::run(RbReports::get($id), 'manual');
    check($res['success'], $res['message']);
    $by = [];
    foreach ($GLOBALS['rb_sent'] as $s) $by[implode(',', $s['to'])] = $s['subject'];
    eq(['north@example.com' => '2 containers', 'dan@example.com' => '4 containers', 'x@y.example' => '4 containers'], $by);
});

test('test send: only to me, [TEST] subject, no last_sent_at; failures logged', function () {
    $GLOBALS['rb_sent'] = [];
    $id = RbReports::save(['name' => 'T', 'layout_json' => json_encode(['blocks' => [['type' => 'text', 'body' => 'hi']]]), 'created_by' => 1]);
    RbReports::saveRecipients($id, [['kind' => 'email', 'email' => 'a@b.example']]);
    $res = RbReports::run(RbReports::get($id), 'test', ['only_to' => ['email' => 'me@example.com', 'user_id' => 1]]);
    check($res['success']);
    eq(['me@example.com'], $GLOBALS['rb_sent'][0]['to']);
    check(strpos($GLOBALS['rb_sent'][0]['subject'], '[TEST] ') === 0);
    eq(null, RbReports::get($id)->last_sent_at);

    $GLOBALS['rb_mail_fail'] = true;
    $res = RbReports::run(RbReports::get($id), 'manual');
    unset($GLOBALS['rb_mail_fail']);
    check(!$res['success'] && $res['message'] === 'smtp down', $res['message']);
    $log = RbReports::runLog($id);
    eq([0, 1], array_map(function ($l) { return (int) $l->success; }, $log));
    eq('smtp down', $log[0]->error_message);
});

test('runDue sends due reports once and stamps last_sent_at', function () {
    $GLOBALS['rb_sent'] = [];
    DB::$pdo->exec('UPDATE plg_rb_reports SET active = 0');
    $id = RbReports::save(['name' => 'Due', 'active' => 1, 'schedule_frequency' => 'daily', 'schedule_hour' => 6,
        'layout_json' => json_encode(['blocks' => [['type' => 'text', 'body' => 'x']]]), 'created_by' => 1]);
    RbReports::saveRecipients($id, [['kind' => 'email', 'email' => 'a@b.example']]);
    $now = new DateTime('2026-10-07 06:15:00');
    eq(1, RbReports::runDue($now));
    eq(0, RbReports::runDue(new DateTime('2026-10-07 06:45:00')), 'second cron run same hour');
    eq('2026-10-07 06:15:00', RbReports::get($id)->last_sent_at);
    eq(1, count($GLOBALS['rb_sent']));
});

test('a broken layout is logged, not fatal', function () {
    $id = RbReports::save(['name' => 'Broken', 'layout_json' => '{"blocks":[{"type":"table","dataset":"gone"}]}', 'created_by' => 1]);
    RbReports::saveRecipients($id, [['kind' => 'email', 'email' => 'a@b.example']]);
    $res = RbReports::run(RbReports::get($id), 'manual');
    check(!$res['success'] && stripos($res['message'], 'unknown dataset') !== false, $res['message']);
});

@unlink($cfgFile);
