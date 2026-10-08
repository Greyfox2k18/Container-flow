<?php
/**
 * Yard Board — the Kent T-Card sheet, live. Laid out like the TODAY tab:
 * one row per door/yard spot (DR | CONTAINER | STATUS | DATE IN | MT DATE |
 * LD DATE | DRIVER | ACCOUNT | LFD | Drayman | DC NOTES) with the incoming
 * block (Container | Customer | STATUS | ETA | LOC) on the right.
 *
 * Type in any cell to change it; type a container number into an empty row
 * to put a container there, or into the incoming block to add one that's on
 * the way. Drag a container by its grip onto another row to move it (onto
 * an occupied row to swap). Clearing a CONTAINER cell marks it picked up.
 * Everyone's board refreshes every few seconds. See includes/yard_functions.php.
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/yard_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$user_id       = (int) $user->data()->id;
$is_supervisor = isSupervisor();
ensureYardTables();

[$warehouse_id, $warehouses] = yardResolveWarehouse($user_id, Input::get('warehouse_id'));
$initial   = getYardBoard($warehouse_id);
$accounts  = getYardAccounts();
$csrf      = Token::generate();
$wh_qs     = $warehouse_id ? '?warehouse_id=' . (int) $warehouse_id : '';
?>
<style>
/* Google-Sheets look: Arial 10 bold, centred, light gridlines, grey header row. */
.ys-sheet { --grid:#e2e2e2; --head:#b7b7b7; --door:#93c47d; --yard:#6fa8dc; --yard3:#6d9eeb; --hot:#ffff00; --late:#ff0000; --soon:#f9cb9c;
  --loc:#ffff00; --sel:#1a73e8; --muted:#5f6368; --ink:#000; --paper:#fff;
  font-family: Arial, Helvetica, sans-serif; color: var(--ink); padding-bottom: 32px; }
.ys-top { display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; padding:12px 0 8px; }
.ys-top h1 { font-size:20px; font-weight:700; margin:0; display:flex; align-items:center; gap:10px; }
.ys-live { font-size:12px; font-weight:400; color:var(--muted); display:inline-flex; align-items:center; gap:5px; }
.ys-live i { width:8px; height:8px; border-radius:50%; background:#188038; display:inline-block; }
.ys-live.off i { background:#d93025; }
.ys-tabs { display:flex; gap:2px; margin-left:auto; flex-wrap:wrap; }
.ys-tab { font:inherit; font-size:13px; padding:6px 12px; border:1px solid #dadce0; background:#f1f3f4; color:#3c4043; border-radius:6px 6px 0 0; text-decoration:none; }
.ys-tab:hover { background:#e8eaed; color:#3c4043; text-decoration:none; }
.ys-tab.on { background:var(--paper); border-bottom-color:var(--paper); color:#188038; font-weight:700; }
.ys-tools { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-bottom:8px; font-size:13px; }
.ys-tools input[type=search], .ys-tools select { font:inherit; border:1px solid #dadce0; border-radius:4px; padding:5px 8px; min-height:30px; background:var(--paper); }
.ys-tools input[type=search] { width:240px; max-width:100%; }
.ys-chip { font:inherit; border:1px solid #dadce0; background:var(--paper); border-radius:14px; padding:3px 10px; cursor:pointer; }
.ys-chip.on { background:#e8f0fe; border-color:var(--sel); color:#174ea6; }
.ys-chip b { font-variant-numeric:tabular-nums; }
.ys-count { color:var(--muted); font-variant-numeric:tabular-nums; }
.ys-wrap { overflow:auto; max-height:calc(100vh - 150px); border:1px solid #c0c0c0; background:var(--paper); }
table.ys { border-collapse:separate; border-spacing:0; font-size:13.3px; font-weight:700; table-layout:fixed; width:max-content; }
.ys th, .ys td { border-right:1px solid var(--grid); border-bottom:1px solid var(--grid); padding:0; height:22px; text-align:center; white-space:nowrap; overflow:hidden; }
.ys thead th { position:sticky; top:0; z-index:3; background:var(--head); font-weight:700; padding:0 4px; border-bottom:1px solid #9e9e9e; }
.ys thead th.inc { background:var(--paper); color:#0000ff; }
.ys thead th.gap, .ys td.gap { background:#f8f9fa; }
.ys td.dr { position:sticky; left:0; z-index:2; background:var(--yard); cursor:default; }
.ys thead th.dr { left:0; z-index:4; }
.ys tr.door td.dr { background:var(--door); }
.ys tr.third td.dr { background:var(--yard3); }
.ys tr.nolocrow td.dr { background:var(--paper); }
.ys input, .ys select { font:inherit; color:inherit; width:100%; height:21px; border:0; background:transparent; text-align:center; padding:0 3px; outline:none; text-overflow:ellipsis; }
.ys select { appearance:none; -webkit-appearance:none; cursor:pointer; text-align-last:center; }
.ys input:disabled, .ys select:disabled { cursor:default; color:transparent; }
.ys td:focus-within { box-shadow:inset 0 0 0 2px var(--sel); }
.ys td.c-container { position:relative; }
.ys .grip { position:absolute; left:0; top:0; bottom:0; width:12px; cursor:grab; color:#80868b; font-size:10px; line-height:22px; display:none; user-select:none; }
.ys tr.has .c-container:hover .grip, .ys tr.inc-has .c-icontainer:hover .grip, .ys .grip:focus { display:block; }
@media (hover: none) { .ys tr.has .c-container .grip, .ys tr.inc-has .c-icontainer .grip { display:block; } .ys tr.has .actbtn { visibility:visible; } }
.ys td.c-icontainer { position:relative; }
.ys td.c-container input, .ys td.c-icontainer input { padding:0 12px; }
/* Container Flow photo record: a corner marker like a Sheets note */
.ys .cf { position:absolute; right:0; top:0; width:0; height:0; border-style:solid; border-width:0 9px 9px 0; border-color:transparent #1a73e8 transparent transparent; font-size:0; }
.ys .cf.done { border-right-color:#188038; }
.ys .cf:hover, .ys .cf:focus { border-width:0 13px 13px 0; }
.ys td.hot { background:var(--hot) !important; }
.ys td.late { background:var(--late) !important; }
.ys td.soon { background:var(--soon) !important; }
.ys td.hotlabel { background:var(--late) !important; }
.ys td.locset { background:var(--loc); }
.ys tr.dim td:not(.dr):not(.gap) { opacity:.28; }
.ys tr.hit td.c-container, .ys tr.ihit td.c-icontainer { box-shadow:inset 0 0 0 2px #f29900; }
.ys tr.drop td:not(.gap):not(.inc) { box-shadow:inset 0 2px 0 var(--sel), inset 0 -2px 0 var(--sel); }
.ys tr.idrop td.inc { box-shadow:inset 0 2px 0 var(--sel), inset 0 -2px 0 var(--sel); }
.ys tr.flash td:not(.dr):not(.gap) { animation:ysFlash 1.8s ease-out; }
@keyframes ysFlash { 0% { background-color:#fde293; } }
.ys td.act { width:48px; }
.ys .acts { display:flex; height:21px; }
.ys .actbtn { border:0; background:none; cursor:pointer; color:#80868b; font:inherit; font-size:13px; flex:1; height:21px; padding:0; visibility:hidden; }
.ys tr.has:hover .actbtn, .ys .actbtn:focus { visibility:visible; }
.ys .actbtn.pick:hover { color:#d93025; }
.ys .actbtn.hist:hover { color:#1a73e8; }
.ys td.c-loc { cursor:pointer; }
.ys-sheet.checking .ys .acts { display:none; }
.ys .chk { display:none; width:15px; height:15px; margin:3px auto; cursor:pointer; }
.ys-sheet.checking .ys tr.has .chk { display:block; }
.ys-moving { display:none; position:sticky; top:0; z-index:5; background:#e8f0fe; border:1px solid var(--sel); color:#174ea6; padding:6px 10px; font-size:13px; margin-bottom:6px; border-radius:4px; }
.ys-moving.show { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.ys-moving button { font:inherit; border:1px solid #dadce0; background:var(--paper); border-radius:4px; padding:2px 10px; cursor:pointer; }
.ys-sheet.picking .ys tbody tr:not(.nolocrow) td.dr { cursor:pointer; outline:2px dashed var(--sel); outline-offset:-3px; }
.ys-help { font-size:12px; color:var(--muted); margin-top:8px; font-weight:400; }
.ys-toast { position:fixed; left:50%; bottom:24px; transform:translateX(-50%); background:#323232; color:#fff; padding:10px 14px; border-radius:4px; font:14px Arial, sans-serif; display:none; gap:14px; align-items:center; z-index:1100; max-width:92vw; box-shadow:0 2px 8px rgba(0,0,0,.3); }
.ys-toast.show { display:flex; }
.ys-toast.err { background:#b3261e; }
.ys-toast button { font:inherit; font-weight:700; color:#8ab4f8; background:none; border:0; cursor:pointer; padding:0; }
.ys-empty { padding:24px; text-align:center; border:1px dashed #c0c0c0; background:var(--paper); margin-bottom:12px; font-weight:400; }
.ys-hist { --paper:#fff; --muted:#5f6368; --door:#93c47d; --yard:#6fa8dc; color:#000; font-family:Arial, Helvetica, sans-serif; }
.ys-hist { position:fixed; top:0; right:0; bottom:0; width:min(460px, 100vw); background:var(--paper); box-shadow:-4px 0 18px rgba(0,0,0,.18); z-index:1090; display:none; flex-direction:column; font-weight:400; }
.ys-hist.open { display:flex; }
.ys-hist header { display:flex; align-items:center; gap:10px; padding:14px 16px; border-bottom:1px solid #dadce0; }
.ys-hist header h2 { margin:0; font-size:17px; font-weight:700; flex:1; font-variant-numeric:tabular-nums; }
.ys-hist header button { border:0; background:none; font-size:24px; line-height:1; cursor:pointer; color:var(--muted); }
.ys-hist .body { overflow:auto; padding:12px 16px 24px; font-size:13px; }
.ys-hist h3 { font-size:11.5px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); margin:16px 0 6px; }
.ys-gate { display:grid; grid-template-columns:auto 1fr; gap:4px 14px; }
.ys-gate dt { color:var(--muted); }
.ys-gate dd { margin:0; font-weight:700; font-variant-numeric:tabular-nums; }
.ys-stays { width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums; }
.ys-stays th { text-align:left; font-size:11px; color:var(--muted); font-weight:700; padding:4px 6px; border-bottom:1px solid #dadce0; }
.ys-stays td { padding:5px 6px; border-bottom:1px solid #f1f3f4; vertical-align:top; }
.ys-stays .code { font-weight:700; padding:1px 6px; border-radius:3px; background:var(--yard); }
.ys-stays .code.door { background:var(--door); }
.ys-stays .est { color:var(--muted); font-size:11px; }
.ys-log { list-style:none; margin:0; padding:0; }
.ys-log li { padding:6px 0; border-bottom:1px solid #f1f3f4; display:grid; grid-template-columns:96px 1fr; gap:2px 10px; }
.ys-log time { color:var(--muted); font-variant-numeric:tabular-nums; grid-row:span 2; }
.ys-log b { font-weight:700; }
.ys-log .who { color:var(--muted); font-size:12px; }
@media (max-width:600px) { .ys-tabs { margin-left:0; } .ys-wrap { max-height:none; } }
@media (prefers-reduced-motion: reduce) { .ys tr.flash td { animation:none; } }
</style>

<div id="page-wrapper">
<div class="container-fluid ys-sheet" id="ysSheet">

    <div class="ys-top">
        <h1>Yard Board <span class="ys-live" id="ysLive"><i></i><span id="ysLiveText">Live</span></span></h1>
        <?php if (count($warehouses) > 1): ?>
        <select id="ysWarehouse" aria-label="Warehouse" class="ys-tab" style="border-radius:6px;">
            <?php foreach ($warehouses as $w): ?>
            <option value="<?php echo (int) $w->id; ?>" <?php echo (int) $w->id === (int) $warehouse_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($w->name); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <nav class="ys-tabs" aria-label="Yard sheets">
            <a class="ys-tab on" href="yard_board.php<?php echo $wh_qs; ?>" aria-current="page">TODAY</a>
            <a class="ys-tab" href="yard_history.php<?php echo $wh_qs; ?>">Picked Up</a>
            <a class="ys-tab" href="yard_history.php?view=inout<?php echo $warehouse_id ? '&warehouse_id=' . (int) $warehouse_id : ''; ?>">In / Out</a>
            <a class="ys-tab" href="yard_history.php?view=moves<?php echo $warehouse_id ? '&warehouse_id=' . (int) $warehouse_id : ''; ?>">Move Sheet</a>
            <?php if ($is_supervisor): ?><a class="ys-tab" href="yard_settings.php<?php echo $wh_qs; ?>">Setup</a><?php endif; ?>
        </nav>
    </div>

    <?php if (empty($initial['locations'])): ?>
    <div class="ys-empty">
        No doors or yard spots yet.
        <?php if ($is_supervisor): ?><a href="yard_settings.php<?php echo $wh_qs; ?>">Set up the yard or import the T-Card sheet</a>.<?php else: ?>Ask a supervisor to set up the yard.<?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="ys-tools">
        <input type="search" id="ysSearch" placeholder="Find a container, account, driver…" autocomplete="off" aria-label="Find">
        <button type="button" class="ys-chip" data-chip="late">Past LFD <b id="nLate">0</b></button>
        <button type="button" class="ys-chip" data-chip="soon">LFD today/tomorrow <b id="nSoon">0</b></button>
        <button type="button" class="ys-chip" data-chip="hot">Hot <b id="nHot">0</b></button>
        <button type="button" class="ys-chip" id="ysCheckToggle">Yard check</button>
        <span class="ys-count" id="ysCounts"></span>
    </div>

    <div class="ys-moving" id="ysMoving" role="status">
        <span id="ysMovingText"></span>
        <button type="button" id="ysMoveIncoming">Back to Incoming</button>
        <button type="button" id="ysMoveCancel">Cancel</button>
    </div>

    <div class="ys-wrap" id="ysWrap">
        <table class="ys" id="ysTable">
            <colgroup>
                <col style="width:52px"><col style="width:132px"><col style="width:84px"><col style="width:62px"><col style="width:66px"><col style="width:64px">
                <col style="width:190px"><col style="width:140px"><col style="width:50px"><col style="width:88px"><col style="width:160px">
                <col style="width:48px">
                <col style="width:132px"><col style="width:132px"><col style="width:190px"><col style="width:50px"><col style="width:52px">
            </colgroup>
            <thead>
                <tr>
                    <th class="dr">DR</th><th>CONTAINER</th><th>STATUS</th><th>DATE IN</th><th>MT DATE</th><th>LD DATE</th>
                    <th>DRIVER</th><th>ACCOUNT</th><th>LFD</th><th>Drayman</th><th>DC NOTES</th>
                    <th class="gap" title="History / picked up / yard check"></th>
                    <th class="inc">Container</th><th class="inc">Customer</th><th class="inc">STATUS</th><th class="inc">ETA</th><th class="inc">LOC</th>
                </tr>
            </thead>
            <tbody id="ysBody"></tbody>
        </table>
    </div>
    <p class="ys-help">
        If the same container number also has a Container Flow photo record, a small blue corner on it links there (green once reviewed).
        Type a container number into an empty row to put it there, or into the blue Container column to add one that's on the way.
        Grab the <b>⠿</b> grip next to a container number to drag it to another row (drop it on an occupied row to swap).
        On a phone, tap the grip, then tap the DR cell of the row to move it to. Clear a CONTAINER cell, or use ⇥, when a container is picked up.
        Every change is recorded with who and when: use ◷ on a row (or click LOC in the incoming block) to see a container's gate in/out and door in/out times.
    </p>
</div>
</div>

<datalist id="ysAccounts"><?php foreach ($accounts as $a): ?><option value="<?php echo htmlspecialchars($a->name); ?>"><?php endforeach; ?></datalist>
<datalist id="ysDraymen"></datalist>
<datalist id="ysLabels"><option value="HOT CONTAINER"><option value="DROP SHIP CONTAINER"></datalist>
<aside class="ys-hist" id="ysHist" aria-labelledby="ysHistTitle">
    <header><h2 id="ysHistTitle">History</h2><button type="button" id="ysHistClose" aria-label="Close">&times;</button></header>
    <div class="body" id="ysHistBody"></div>
</aside>
<div class="ys-toast" id="ysToast" role="status" aria-live="polite"><span id="ysToastText"></span><button type="button" id="ysToastUndo" hidden>UNDO</button></div>

<script>
(function () {
    var BASE = <?php echo json_encode($us_url_root); ?>;
    var CSRF = <?php echo json_encode($csrf); ?>;
    var WAREHOUSE_ID = <?php echo json_encode($warehouse_id); ?>;
    var STATUSES = ['', 'Empty', 'Loaded', 'Full', 'Working', 'Partial'];
    var POLL_MS = 5000;

    var state = <?php echo json_encode($initial); ?>;
    var filter = { q: '', chip: '' };
    var checking = false;
    var picking = null;            // unit being moved by tap
    var rowsBuilt = 0;
    var lastStamp = {};            // unit id → updated_at|location, to flash rows others changed
    var $ = function (id) { return document.getElementById(id); };

    // ---------- helpers ----------
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function md(d) { if (!d) return ''; var p = d.split('-'); return (+p[1]) + '/' + (+p[2]); }
    function stamp(dt) {
        if (!dt) return '';
        var d = dt.slice(0, 10).split('-'), t = dt.slice(11, 16).split(':'), h = +t[0];
        return (+d[1]) + '/' + (+d[2]) + ' ' + ((h % 12) || 12) + ':' + t[1] + (h < 12 ? 'am' : 'pm');
    }
    function toDate(dt) { return new Date(dt.replace(' ', 'T')); }
    function span(from, to) {
        var mins = Math.max(0, Math.round(((to ? toDate(to) : new Date()) - toDate(from)) / 60000));
        if (mins < 60) return mins + 'm';
        var h = Math.floor(mins / 60);
        if (h < 48) return h + 'h ' + (mins % 60) + 'm';
        return Math.floor(h / 24) + 'd ' + (h % 24) + 'h';
    }
    function ago(dt) { return span(dt, null); }
    function byId(id) { id = +id; return state.units.concat(state.incoming).filter(function (u) { return u.id === id; })[0] || null; }
    function locById(id) { return state.locations.filter(function (l) { return l.id === +id; })[0] || null; }
    function unitAt(locId) { return state.units.filter(function (u) { return u.location_id === locId; })[0] || null; }

    var toastTimer;
    function toast(msg, isErr, undo) {
        $('ysToastText').textContent = msg;
        $('ysToast').className = 'ys-toast show' + (isErr ? ' err' : '');
        var b = $('ysToastUndo');
        b.hidden = !undo;
        b.onclick = function () { $('ysToast').className = 'ys-toast'; undo(); };
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { $('ysToast').className = 'ys-toast'; }, undo ? 7000 : (isErr ? 5000 : 2200));
    }
    function post(data) {
        var fd = new FormData();
        fd.append('csrf', CSRF);
        if (WAREHOUSE_ID) fd.append('warehouse_id', WAREHOUSE_ID);
        Object.keys(data).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
        return fetch(BASE + 'usersc/ajax/yard_action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (t) { try { return JSON.parse(t); } catch (e) { console.error('yard_action:', t); return { success: false, message: 'Unexpected server response.' }; } })
            .catch(function () { return { success: false, message: 'Network error — check your connection.' }; });
    }
    function afterWrite(r, okMsg) {
        if (!r.success) toast(r.message, true);
        else if (okMsg) toast(okMsg);
        return poll(true).then(function () { return r; });
    }

    // ---------- grid ----------
    // Left block cells, in sheet order. Dates are typed as m/d.
    var LEFT = [
        { f: 'container_number', cls: 'c-container', input: 'text' },
        { f: 'status', input: 'select' },
        { f: 'date_in', input: 'date' }, { f: 'mt_date', input: 'date' }, { f: 'ld_date', input: 'date' },
        { f: 'driver', input: 'text' },
        { f: 'account', input: 'text', list: 'ysAccounts' },
        { f: 'lfd', input: 'date' },
        { f: 'drayman', input: 'text', list: 'ysDraymen' },
        { f: 'notes', input: 'text', plain: true }
    ];
    var RIGHT = [
        { f: 'container_number', cls: 'c-icontainer', input: 'text' },
        { f: 'account', input: 'text', list: 'ysAccounts' },
        { f: 'list_note', input: 'text', list: 'ysLabels' },
        { f: 'eta', input: 'date' }
    ];

    function cellHtml(c, side) {
        var attrs = ' data-side="' + side + '" data-f="' + c.f + '"';
        var inner;
        if (c.input === 'select') {
            inner = '<select' + attrs + ' aria-label="STATUS">' + STATUSES.map(function (s) { return '<option>' + s + '</option>'; }).join('') + '</select>';
        } else {
            inner = '<input' + attrs + (c.list ? ' list="' + c.list + '"' : '') + ' spellcheck="false" autocomplete="off"' +
                (c.input === 'date' ? ' inputmode="numeric" placeholder=""' : '') + '>';
        }
        if (c.f === 'container_number') inner = '<span class="grip" draggable="true" tabindex="-1" role="button" aria-label="Move">⠿</span>' + inner;
        return '<td class="' + (c.cls || '') + (side === 'R' ? ' inc' : '') + '">' + inner + '</td>';
    }

    function rowCount() {
        return Math.max(state.locations.length, state.incoming.length + 5);
    }

    function buildRows() {
        var n = rowCount(), html = [], yardIdx = 0;
        for (var i = 0; i < n; i++) {
            var loc = state.locations[i];
            var cls = loc ? (loc.kind === 'door' ? 'door' : (++yardIdx % 3 === 0 ? 'yard third' : 'yard')) : 'nolocrow';
            html.push('<tr data-i="' + i + '" class="' + cls + '"' + (loc ? ' data-loc="' + loc.id + '"' : '') + '>' +
                '<td class="dr">' + (loc ? esc(loc.code) : '') + '</td>' +
                (loc ? LEFT.map(function (c) { return cellHtml(c, 'L'); }).join('')
                     : LEFT.map(function () { return '<td></td>'; }).join('')) +
                '<td class="gap act">' + (loc ? '<span class="acts"><button type="button" class="actbtn hist" title="History: in/out times and changes" aria-label="History">&#9719;</button>' +
                    '<button type="button" class="actbtn pick" title="Picked up" aria-label="Picked up">&#8677;</button></span>' +
                    '<input type="checkbox" class="chk" title="Seen in yard check" aria-label="Seen">' : '') + '</td>' +
                RIGHT.map(function (c) { return cellHtml(c, 'R'); }).join('') +
                '<td class="inc c-loc"></td></tr>');
        }
        $('ysBody').innerHTML = html.join('');
        rowsBuilt = n;
    }

    function setVal(el, v) {
        if (!el || el === document.activeElement) return; // never clobber what someone is typing
        if (el.value !== v) el.value = v;
        el.dataset.orig = v;
    }

    function matches(u) {
        if (!u) return false;
        if (filter.chip === 'late' && u.lfd_state !== 'overdue') return false;
        if (filter.chip === 'soon' && u.lfd_state !== 'soon') return false;
        if (filter.chip === 'hot' && !u.hot) return false;
        return true;
    }
    function hay(u) { return u ? [u.container_number, u.account, u.driver, u.drayman, u.notes, u.list_note, u.status].join(' ').toLowerCase() : ''; }

    function paintRow(tr, i) {
        var loc = state.locations[i] || null;
        var u = loc ? unitAt(loc.id) : null;
        var iu = state.incoming[i] || null;
        tr.dataset.unit = u ? u.id : '';
        tr.dataset.iunit = iu ? iu.id : '';
        tr.classList.toggle('has', !!u);
        tr.classList.toggle('inc-has', !!iu);

        // left block
        if (loc) {
            var tds = tr.children;
            LEFT.forEach(function (c, k) {
                var td = tds[k + 1], el = td.querySelector('input,select');
                var v = u ? (c.input === 'date' ? md(u[c.f]) : (u[c.f] || '')) : '';
                setVal(el, v);
                if (c.f !== 'container_number') el.disabled = !u;
                td.style.background = (u && !c.plain && u.account) ? u.color : '';
                td.classList.toggle('hot', !!(u && u.hot && (c.f === 'container_number' || c.f === 'driver')));
                td.classList.toggle('late', !!(u && c.f === 'lfd' && u.lfd_state === 'overdue'));
                td.classList.toggle('soon', !!(u && c.f === 'lfd' && u.lfd_state === 'soon'));
                if (c.f === 'container_number') {
                    var cf = td.querySelector('.cf');
                    if (u && u.cf) {
                        if (!cf) { cf = document.createElement('a'); cf.className = 'cf'; cf.textContent = 'Photos'; td.appendChild(cf); }
                        cf.href = BASE + 'usersc/container_view.php?id=' + u.cf.id;
                        cf.title = 'Container Flow photos: ' + u.cf.status.replace('_', ' ');
                        cf.classList.toggle('done', u.cf.status === 'reviewed');
                    } else if (cf) cf.remove();
                    td.title = u ? (u.updated_by ? 'Last changed by ' + u.updated_by + ' · ' + u.updated_at : '') : 'Type a container number to put it in ' + loc.code;
                }
            });
            tr.children[0].title = u && u.spot_since
                ? loc.code + ': ' + u.container_number + ' since ' + (u.spot_estimated ? md(u.spot_since.slice(0, 10)) + ' (time not recorded)' : stamp(u.spot_since)) + ' · ' + ago(u.spot_since)
                : loc.code;
            var dateInTd = tr.children[3];
            dateInTd.title = u && u.arrived_at ? 'Gate in ' + stamp(u.arrived_at) : '';
            var chk = tr.querySelector('.chk');
            if (chk) chk.checked = !!(u && u.checked_today);
        }

        // incoming block
        var rtd = tr.children;
        var base = LEFT.length + 2;
        RIGHT.forEach(function (c, k) {
            var td = rtd[base + k], el = td.querySelector('input');
            setVal(el, iu ? (c.input === 'date' ? md(iu[c.f]) : (iu[c.f] || '')) : '');
            if (c.f !== 'container_number') el.disabled = !iu;
            td.style.background = (iu && c.f === 'account' && iu.account) ? iu.color : '';
            td.classList.toggle('hotlabel', !!(iu && c.f === 'list_note' && /\bHOT\b/i.test(iu.list_note || '')));
        });
        var locTd = rtd[base + RIGHT.length];
        locTd.title = iu ? 'History for ' + iu.container_number : '';
        locTd.textContent = iu && iu.location_code ? iu.location_code : '';
        locTd.classList.toggle('locset', !!(iu && iu.location_code));

        // search + filters
        var dim = false;
        if (filter.chip) dim = !(matches(u) || matches(iu));
        tr.classList.toggle('dim', dim);
        tr.classList.toggle('hit', !!(filter.q && u && hay(u).indexOf(filter.q) >= 0));
        tr.classList.toggle('ihit', !!(filter.q && iu && hay(iu).indexOf(filter.q) >= 0));

        // flash rows someone else just changed
        [u, iu].forEach(function (x) {
            if (!x) return;
            var stamp = x.updated_at + '|' + x.location_id;
            if (lastStamp[x.id] && lastStamp[x.id] !== stamp && !tr.contains(document.activeElement)) {
                tr.classList.remove('flash'); void tr.offsetWidth; tr.classList.add('flash');
            }
        });
    }

    function render() {
        if (rowsBuilt !== rowCount() || $('ysBody').children.length !== rowCount()) {
            var keep = focusKey();
            buildRows();
            restoreFocus(keep);
        }
        var trs = $('ysBody').children;
        for (var i = 0; i < trs.length; i++) paintRow(trs[i], i);
        lastStamp = {};
        state.units.concat(state.incoming).forEach(function (u) { lastStamp[u.id] = u.updated_at + '|' + u.location_id; });
        summary();
    }
    function focusKey() {
        var el = document.activeElement;
        if (!el || !el.dataset || !el.dataset.f) return null;
        return { i: el.closest('tr').dataset.i, side: el.dataset.side, f: el.dataset.f, v: el.value };
    }
    function restoreFocus(k) {
        if (!k) return;
        var el = document.querySelector('tr[data-i="' + k.i + '"] [data-side="' + k.side + '"][data-f="' + k.f + '"]');
        if (el) { el.value = k.v; el.focus(); }
    }

    function summary() {
        var late = 0, soon = 0, hot = 0, doors = 0, doorsUsed = 0, yard = 0, yardUsed = 0;
        state.units.forEach(function (u) { if (u.lfd_state === 'overdue') late++; if (u.lfd_state === 'soon') soon++; if (u.hot) hot++; });
        state.locations.forEach(function (l) {
            var used = !!unitAt(l.id);
            if (l.kind === 'door') { doors++; if (used) doorsUsed++; } else { yard++; if (used) yardUsed++; }
        });
        $('nLate').textContent = late; $('nSoon').textContent = soon; $('nHot').textContent = hot;
        var checked = state.units.filter(function (u) { return u.checked_today; }).length;
        $('ysCounts').textContent = 'Doors ' + doorsUsed + '/' + doors + ' · Yard ' + yardUsed + '/' + yard +
            ' · Incoming ' + state.incoming.filter(function (u) { return !u.location_id; }).length +
            (checking ? ' · Checked ' + checked + '/' + state.units.length : '');
        var dray = {};
        state.units.concat(state.incoming).forEach(function (u) { if (u.drayman) dray[u.drayman.toUpperCase()] = 1; });
        $('ysDraymen').innerHTML = Object.keys(dray).sort().map(function (d) { return '<option value="' + esc(d) + '">'; }).join('');
    }

    // ---------- live refresh ----------
    var pollTimer = null, lastOk = Date.now(), n = 0;
    function poll(force) {
        clearTimeout(pollTimer);
        var url = BASE + 'usersc/ajax/yard_data.php?warehouse_id=' + encodeURIComponent(WAREHOUSE_ID || '') +
            ((force || ++n % 24 === 0) ? '' : '&since=' + encodeURIComponent(state.version));
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) throw new Error(d.message);
                lastOk = Date.now(); live(true);
                if (!d.unchanged) { delete d.success; state = d; render(); }
            })
            .catch(function () { live(false); })
            .then(function () { if (!document.hidden) pollTimer = setTimeout(poll, POLL_MS); });
    }
    function live(ok) {
        var s = Math.round((Date.now() - lastOk) / 1000);
        $('ysLive').className = 'ys-live' + (ok ? '' : ' off');
        $('ysLiveText').textContent = ok ? 'Live' : 'Offline — last update ' + (s < 60 ? s + 's' : Math.round(s / 60) + 'm') + ' ago';
    }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); else clearTimeout(pollTimer); });
    setInterval(function () { if (Date.now() - lastOk > POLL_MS * 3) live(false); }, 5000);

    // ---------- typing in cells ----------
    function commit(el) {
        var v = el.value.trim();
        if (v === (el.dataset.orig || '')) return;
        var tr = el.closest('tr'), side = el.dataset.side, f = el.dataset.f;
        var u = byId(side === 'L' ? tr.dataset.unit : tr.dataset.iunit);
        el.dataset.orig = v;

        if (f === 'container_number' && !v) {
            if (!u) return;
            if (side === 'L') {
                post({ action: 'pickup', unit_id: u.id }).then(function (r) {
                    afterWrite(r);
                    if (r.success) toast(u.container_number + ' marked picked up', false, function () {
                        post({ action: 'restore', unit_id: u.id }).then(function (r2) { afterWrite(r2, r2.success ? u.container_number + ' put back' : null); });
                    });
                });
            } else {
                post({ action: 'unlist', unit_id: u.id }).then(function (r) {
                    afterWrite(r);
                    if (r.success) toast(r.message, false, function () {
                        var again = u.location_id ? { action: 'save', unit_id: u.id, on_list: 1 }
                            : { action: 'save', unit_id: 0, container_number: u.container_number, account: u.account || '', list_note: u.list_note || '', eta: u.eta || '' };
                        post(again).then(function (r2) { afterWrite(r2, r2.success ? 'Restored' : null); });
                    });
                });
            }
            return;
        }
        if (!u) {
            if (f !== 'container_number') return;
            var data = { action: 'save', unit_id: 0, container_number: v };
            if (side === 'L') data.location_id = tr.dataset.loc;
            post(data).then(function (r) {
                var where = side === 'L' ? locById(tr.dataset.loc).code : 'Incoming';
                if (!r.success) el.dataset.orig = '';
                afterWrite(r, r.success ? v.toUpperCase() + ' → ' + where : null);
            });
            return;
        }
        var payload = { action: 'save', unit_id: u.id };
        payload[f] = v;
        post(payload).then(function (r) { if (!r.success) el.dataset.orig = ''; afterWrite(r); });
    }

    $('ysBody').addEventListener('change', function (e) {
        if (e.target.matches('.chk')) {
            var u = byId(e.target.closest('tr').dataset.unit);
            if (u && e.target.checked) post({ action: 'check', unit_id: u.id }).then(function (r) { afterWrite(r); });
            else if (u) e.target.checked = true; // a check can't be undone, it's a record of what was seen
            return;
        }
        if (e.target.dataset.f) commit(e.target);
    });
    $('ysBody').addEventListener('keydown', function (e) {
        var el = e.target;
        if (!el.dataset || !el.dataset.f) return;
        var tr = el.closest('tr');
        if (e.key === 'Escape') { el.value = el.dataset.orig || ''; el.blur(); return; }
        var dir = e.key === 'Enter' || (e.key === 'ArrowDown' && el.tagName !== 'SELECT') ? 1
                : (e.key === 'ArrowUp' && el.tagName !== 'SELECT') ? -1 : 0;
        if (!dir) return;
        e.preventDefault();
        if (el.tagName === 'INPUT') commit(el);
        // next row down/up that has this column; if that cell is locked (empty row), land on its CONTAINER cell
        var sel = '[data-side="' + el.dataset.side + '"]';
        for (var next = tr; (next = dir > 0 ? next.nextElementSibling : next.previousElementSibling);) {
            var target = next.querySelector(sel + '[data-f="' + el.dataset.f + '"]:not(:disabled)') || next.querySelector(sel + '[data-f="container_number"]');
            if (target) { target.focus(); if (target.select) target.select(); break; }
        }
    });
    $('ysBody').addEventListener('click', function (e) {
        var hist = e.target.closest('.actbtn.hist');
        if (hist) { openHistory(hist.closest('tr').dataset.unit); return; }
        var locCell = e.target.closest('td.c-loc');
        if (locCell && locCell.closest('tr').dataset.iunit) { openHistory(locCell.closest('tr').dataset.iunit); return; }
        var btn = e.target.closest('.actbtn.pick');
        if (btn) {
            var inp = btn.closest('tr').querySelector('[data-side="L"][data-f="container_number"]');
            inp.value = '';
            commit(inp);
            return;
        }
        var grip = e.target.closest('.grip');
        if (grip) { startPick(grip); return; }
        if (picking) {
            var tr = e.target.closest('tr[data-loc]');
            if (tr && e.target.closest('td.dr')) { finishMove(picking, +tr.dataset.loc); stopPick(); }
        }
    });

    // ---------- moving: drag & drop, or tap grip then tap a DR cell ----------
    function unitForGrip(grip) {
        var tr = grip.closest('tr');
        return byId(grip.closest('td').classList.contains('inc') ? tr.dataset.iunit : tr.dataset.unit);
    }
    function finishMove(u, toLoc, swap) {
        if (!u || (u.location_id || 0) === toLoc) return Promise.resolve();
        var occupant = toLoc ? unitAt(toLoc) : null;
        if (occupant && !swap) {
            if (!u.location_id) { toast(locById(toLoc).code + ' has ' + occupant.container_number + ' in it. Move that one first.', true); return Promise.resolve(); }
            if (!confirm('Swap ' + u.container_number + ' (' + locById(u.location_id).code + ') with ' + occupant.container_number + ' (' + locById(toLoc).code + ')?')) return Promise.resolve();
            swap = true;
        }
        return post({ action: 'move', unit_id: u.id, location_id: toLoc, swap: swap ? 1 : 0 }).then(function (r) {
            return afterWrite(r, r.success ? u.container_number + ' → ' + (toLoc ? locById(toLoc).code : 'Incoming') : null);
        });
    }
    function startPick(grip) {
        var u = unitForGrip(grip);
        if (!u) return;
        picking = u;
        $('ysSheet').classList.add('picking');
        $('ysMovingText').textContent = 'Moving ' + u.container_number + (u.location_id ? ' from ' + locById(u.location_id).code : '') + ' — tap the DR cell of the row to put it in.';
        $('ysMoveIncoming').hidden = !u.location_id;
        $('ysMoving').classList.add('show');
    }
    function stopPick() { picking = null; $('ysSheet').classList.remove('picking'); $('ysMoving').classList.remove('show'); }
    $('ysMoveCancel').addEventListener('click', stopPick);
    $('ysMoveIncoming').addEventListener('click', function () { var u = picking; stopPick(); finishMove(u, 0); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && picking) stopPick(); });

    var dragU = null;
    $('ysBody').addEventListener('dragstart', function (e) {
        var grip = e.target.closest && e.target.closest('.grip');
        if (!grip) return;
        dragU = unitForGrip(grip);
        if (!dragU) { e.preventDefault(); return; }
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', dragU.container_number); e.dataTransfer.setDragImage(grip.closest('td'), 10, 10); } catch (x) {}
    });
    function clearDrop() { document.querySelectorAll('.drop,.idrop').forEach(function (n) { n.classList.remove('drop', 'idrop'); }); }
    function dropTarget(e) {
        var tr = e.target.closest && e.target.closest('tbody tr');
        if (!tr) return null;
        var inc = !!e.target.closest('td.inc');
        if (inc) return { tr: tr, loc: 0 };
        return tr.dataset.loc ? { tr: tr, loc: +tr.dataset.loc } : null;
    }
    $('ysBody').addEventListener('dragover', function (e) {
        if (!dragU) return;
        var t = dropTarget(e);
        if (!t || (t.loc === 0 && !dragU.location_id)) return;
        e.preventDefault();
        clearDrop();
        t.tr.classList.add(t.loc ? 'drop' : 'idrop');
    });
    $('ysBody').addEventListener('dragleave', function (e) { if (!e.relatedTarget || !$('ysBody').contains(e.relatedTarget)) clearDrop(); });
    $('ysBody').addEventListener('drop', function (e) {
        if (!dragU) return;
        var t = dropTarget(e);
        e.preventDefault();
        clearDrop();
        var u = dragU; dragU = null;
        if (t) finishMove(u, t.loc);
    });
    document.addEventListener('dragend', function () { dragU = null; clearDrop(); });

    // ---------- history: gate in/out, door & yard stays, every change ----------
    var ACTIONS = { placed: 'Put in', moved: 'Moved', swapped: 'Swapped', picked_up: 'Picked up (gate out)', restored: 'Pickup undone',
        expected: 'Added to Incoming', updated: 'Edited', renamed: 'Container # changed', deleted: 'Removed' };
    function openHistory(unitId) {
        if (!unitId) return;
        var panel = $('ysHist');
        $('ysHistTitle').textContent = (byId(unitId) || {}).container_number || 'History';
        $('ysHistBody').innerHTML = '<p style="color:#5f6368">Loading…</p>';
        panel.classList.add('open');
        $('ysHistClose').focus();
        fetch(BASE + 'usersc/ajax/yard_data.php?unit_history=' + encodeURIComponent(unitId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (h) {
                if (!h.success) { $('ysHistBody').textContent = h.message; return; }
                $('ysHistTitle').textContent = h.container_number;
                var gateEstimated = h.arrived_at && h.stints.length && h.stints[0].estimated && h.stints[0].in_at === h.arrived_at;
                var gate = '<dl class="ys-gate">' +
                    '<dt>Gate in</dt><dd>' + (h.arrived_at ? (gateEstimated ? md(h.arrived_at.slice(0, 10)) + ' <span class="est">(time not recorded)</span>' : stamp(h.arrived_at))
                        : (h.date_in ? md(h.date_in) : 'Not arrived yet')) + '</dd>' +
                    '<dt>Gate out</dt><dd>' + (h.picked_up_at ? stamp(h.picked_up_at) : (h.location ? 'Still on site at ' + esc(h.location) : '—')) + '</dd>' +
                    (h.arrived_at ? '<dt>' + (h.picked_up_at ? 'Time on site' : 'On site for') + '</dt><dd>' + span(h.arrived_at, h.picked_up_at) + '</dd>' : '') +
                    '</dl>';
                var stays = h.stints.length ? '<table class="ys-stays"><thead><tr><th>Spot</th><th>In</th><th>Out</th><th>Time there</th></tr></thead><tbody>' +
                    h.stints.map(function (s) {
                        return '<tr><td><span class="code ' + s.kind + '">' + esc(s.code) + '</span></td>' +
                            '<td>' + (s.estimated ? md(s.in_at.slice(0, 10)) + '<div class="est">time not recorded</div>' : stamp(s.in_at) + (s.in_by ? '<div class="est">' + esc(s.in_by) + '</div>' : '')) + '</td>' +
                            '<td>' + (s.out_at ? stamp(s.out_at) + (s.out_by ? '<div class="est">' + esc(s.out_by) + '</div>' : '') : '<b>now</b>') + '</td>' +
                            '<td>' + (s.estimated ? '~' : '') + span(s.in_at, s.out_at) + '</td></tr>';
                    }).join('') + '</tbody></table>' : '<p style="color:#5f6368">Hasn\'t been in a door or yard spot yet.</p>';
                var log = '<ul class="ys-log">' + h.events.map(function (ev) {
                    var what = ACTIONS[ev.action] || ev.action;
                    if (ev.from || ev.to) what += ' ' + (ev.from ? esc(ev.from) : '') + (ev.from && ev.to ? ' → ' : '') + (ev.to ? esc(ev.to) : '');
                    return '<li><time>' + stamp(ev.at) + '</time><b>' + what + '</b>' +
                        '<span class="who">' + (ev.details ? esc(ev.details) + ' · ' : '') + esc(ev.by || 'system') + '</span></li>';
                }).join('') + '</ul>';
                $('ysHistBody').innerHTML = gate + '<h3>Doors &amp; yard spots</h3>' + stays + '<h3>Every change</h3>' + log;
            })
            .catch(function () { $('ysHistBody').textContent = 'Could not load the history. Check your connection.'; });
    }
    $('ysHistClose').addEventListener('click', function () { $('ysHist').classList.remove('open'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') $('ysHist').classList.remove('open'); });

    // ---------- toolbar ----------
    $('ysSearch').addEventListener('input', function () {
        filter.q = this.value.trim().toLowerCase();
        render();
        var hit = document.querySelector('tr.hit, tr.ihit');
        if (filter.q && hit) hit.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });
    document.querySelectorAll('[data-chip]').forEach(function (b) {
        b.addEventListener('click', function () {
            filter.chip = filter.chip === b.dataset.chip ? '' : b.dataset.chip;
            document.querySelectorAll('[data-chip]').forEach(function (x) { x.classList.toggle('on', x.dataset.chip === filter.chip); });
            render();
        });
    });
    $('ysCheckToggle').addEventListener('click', function () {
        checking = !checking;
        this.classList.toggle('on', checking);
        $('ysSheet').classList.toggle('checking', checking);
        summary();
    });
    if ($('ysWarehouse')) $('ysWarehouse').addEventListener('change', function () { location.href = '?warehouse_id=' + encodeURIComponent(this.value); });

    render();
    pollTimer = setTimeout(poll, POLL_MS);
})();
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
