<?php
/**
 * Yard Board — live door & yard map (replaces the Kent T-Card Google Sheet).
 * Every door and yard spot is a card; changes made by anyone show up on
 * every open board within a few seconds. See includes/yard_functions.php.
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
$customers = getYardCustomerColors();
$csrf      = Token::generate();
?>
<style>
.yb { --yb-border:#e5e7eb; --yb-muted:#6b7280; --yb-text:#111827; --yb-bg:#f9fafb; --yb-red:#dc2626; --yb-amber:#d97706; --yb-green:#16a34a; color:var(--yb-text); padding-bottom:40px; }
.yb-head { display:flex; flex-wrap:wrap; align-items:center; gap:10px; padding:16px 0 10px; }
.yb-head h1 { font-size:22px; font-weight:700; margin:0; margin-right:auto; display:flex; align-items:center; gap:10px; }
.yb-live { font-size:12px; font-weight:500; color:var(--yb-muted); display:inline-flex; align-items:center; gap:6px; }
.yb-live-dot { width:8px; height:8px; border-radius:50%; background:var(--yb-green); box-shadow:0 0 0 0 rgba(22,163,74,.6); animation:ybPulse 2s infinite; }
.yb-live.offline .yb-live-dot { background:var(--yb-red); animation:none; }
@keyframes ybPulse { 0%{box-shadow:0 0 0 0 rgba(22,163,74,.5)} 70%{box-shadow:0 0 0 7px rgba(22,163,74,0)} 100%{box-shadow:0 0 0 0 rgba(22,163,74,0)} }
.yb-btn { border:1px solid var(--yb-border); background:#fff; color:var(--yb-text); border-radius:8px; padding:7px 12px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; text-decoration:none; white-space:nowrap; }
.yb-btn:hover { background:#f3f4f6; text-decoration:none; color:var(--yb-text); }
.yb-btn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
.yb-btn.primary:hover { background:#1d4ed8; color:#fff; }
.yb-btn.danger { color:var(--yb-red); }
.yb-btn.active { background:#111827; border-color:#111827; color:#fff; }
.yb-btn:disabled { opacity:.5; cursor:default; }
.yb-select, .yb-input { border:1px solid #d1d5db; border-radius:8px; padding:7px 10px; font-size:13px; background:#fff; color:var(--yb-text); min-height:34px; }
.yb-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
.yb-toolbar .yb-input { flex:1 1 200px; max-width:320px; }
.yb-chips { display:flex; flex-wrap:wrap; gap:6px; }
.yb-chip { border:1px solid var(--yb-border); background:#fff; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:600; cursor:pointer; display:inline-flex; gap:6px; align-items:center; color:var(--yb-text); }
.yb-chip b { font-variant-numeric:tabular-nums; }
.yb-chip.on { outline:2px solid #111827; outline-offset:-1px; }
.yb-chip.alert { color:var(--yb-red); border-color:#fecaca; background:#fef2f2; }
.yb-chip.warn { color:var(--yb-amber); border-color:#fde68a; background:#fffbeb; }
.yb-layout { display:grid; grid-template-columns:minmax(0,1fr) 290px; gap:16px; align-items:start; }
.yb-section { margin-bottom:18px; }
.yb-section h2 { font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:var(--yb-muted); margin:0 0 8px; font-weight:700; display:flex; gap:8px; align-items:baseline; }
.yb-section h2 span { font-weight:500; letter-spacing:0; text-transform:none; }
.yb-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:8px; }
.yb-tile { position:relative; background:#fff; border:1px solid var(--yb-border); border-left:6px solid var(--acct, #d1d5db); border-radius:10px; padding:8px 9px 7px; min-height:96px; cursor:pointer; display:flex; flex-direction:column; gap:3px; transition:box-shadow .15s, opacity .15s, transform .15s; user-select:none; }
.yb-tile:hover, .yb-tile:focus-visible { box-shadow:0 2px 10px rgba(0,0,0,.08); outline:none; }
.yb-tile.tinted { background:color-mix(in srgb, var(--acct) 18%, #fff); }
.yb-tile.dim { opacity:.25; }
.yb-tile.match { box-shadow:0 0 0 3px #2563eb; }
.yb-tile.flash { animation:ybFlash 1.6s ease-out; }
@keyframes ybFlash { 0%{box-shadow:0 0 0 4px #facc15} 100%{box-shadow:0 0 0 0 rgba(250,204,21,0)} }
.yb-tile.drop-target { box-shadow:0 0 0 3px #2563eb inset; }
.yb-tile.empty { border:1.5px dashed #d1d5db; background:transparent; align-items:center; justify-content:center; color:#9ca3af; min-height:96px; }
.yb-tile.empty .yb-code { position:absolute; top:7px; left:9px; }
.yb-tile.empty .yb-plus { font-size:22px; line-height:1; }
.yb-top { display:flex; justify-content:space-between; align-items:center; gap:4px; }
.yb-code { font-size:12px; font-weight:800; color:#374151; letter-spacing:.02em; }
.yb-pill { font-size:11px; font-weight:700; border-radius:999px; padding:1px 8px; white-space:nowrap; }
.yb-num { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:14.5px; font-weight:700; letter-spacing:.02em; word-break:break-all; line-height:1.25; }
.yb-acct { font-size:12px; color:#374151; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.yb-acct small { color:var(--yb-muted); font-weight:500; }
.yb-foot { margin-top:auto; display:flex; gap:6px; align-items:center; font-size:11.5px; color:var(--yb-muted); flex-wrap:wrap; }
.yb-lfd { font-weight:700; border-radius:4px; padding:0 4px; }
.yb-lfd.overdue { background:var(--yb-red); color:#fff; }
.yb-lfd.soon { background:#fef3c7; color:#92400e; }
.yb-hot { color:#dc2626; font-size:11px; font-weight:800; }
.yb-icons { margin-left:auto; display:flex; gap:6px; }
.yb-check { position:absolute; top:-7px; right:-7px; width:22px; height:22px; border-radius:50%; background:var(--yb-green); color:#fff; font-size:12px; display:none; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.yb.checking .yb-tile.checked .yb-check { display:flex; }
.yb.checking .yb-tile:not(.empty):not(.checked) { border-style:dashed; }
.yb-check-bar { display:none; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:10px; padding:8px 12px; margin-bottom:12px; font-size:13px; align-items:center; gap:10px; flex-wrap:wrap; }
.yb.checking .yb-check-bar { display:flex; }
.yb-side { background:#fff; border:1px solid var(--yb-border); border-radius:12px; padding:12px; position:sticky; top:12px; max-height:calc(100vh - 24px); overflow:auto; }
.yb-side h2 { margin-bottom:10px; }
.yb-inc { border:1px solid var(--yb-border); border-left:5px solid var(--acct, #d1d5db); border-radius:8px; padding:7px 9px; margin-bottom:6px; cursor:grab; background:#fff; }
.yb-inc:hover { background:#f9fafb; }
.yb-inc .yb-num { font-size:13.5px; }
.yb-inc-meta { font-size:12px; color:var(--yb-muted); display:flex; gap:8px; flex-wrap:wrap; }
.yb-inc-drop { border:1.5px dashed transparent; border-radius:8px; min-height:30px; }
.yb-inc-drop.drop-target { border-color:#2563eb; background:#eff6ff; }
.yb-empty-msg { color:var(--yb-muted); font-size:13px; padding:8px 2px; }
.yb-legend { display:flex; flex-wrap:wrap; gap:6px 12px; font-size:12px; color:#374151; margin-top:14px; }
.yb-legend span { display:inline-flex; align-items:center; gap:5px; }
.yb-legend i { width:12px; height:12px; border-radius:3px; display:inline-block; }
.yb-setup { background:#fff; border:1px dashed #d1d5db; border-radius:12px; padding:28px; text-align:center; }
/* modal */
.yb-modal-bg { position:fixed; inset:0; background:rgba(17,24,39,.45); z-index:1050; display:none; align-items:flex-start; justify-content:center; padding:4vh 12px; overflow:auto; }
.yb-modal-bg.open { display:flex; }
.yb-modal { background:#fff; border-radius:14px; width:100%; max-width:560px; box-shadow:0 20px 50px rgba(0,0,0,.25); }
.yb-modal-head { padding:14px 18px; border-bottom:1px solid var(--yb-border); display:flex; align-items:center; gap:10px; }
.yb-modal-head h3 { margin:0; font-size:17px; font-weight:700; flex:1; }
.yb-x { border:0; background:none; font-size:24px; line-height:1; color:var(--yb-muted); cursor:pointer; padding:0 4px; }
.yb-modal-body { padding:14px 18px; }
.yb-form { display:grid; grid-template-columns:1fr 1fr; gap:10px 12px; }
.yb-form label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px; }
.yb-form .full { grid-column:1 / -1; }
.yb-form .yb-input, .yb-form .yb-select { width:100%; max-width:none; }
.yb-form textarea.yb-input { min-height:60px; resize:vertical; }
.yb-banner { border-radius:8px; padding:8px 10px; font-size:13px; margin-bottom:10px; display:none; }
.yb-banner.show { display:block; }
.yb-banner.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
.yb-banner.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
.yb-cf { background:#f9fafb; border:1px solid var(--yb-border); border-radius:8px; padding:8px 10px; font-size:13px; margin-top:12px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.yb-meta { font-size:12px; color:var(--yb-muted); margin-top:10px; }
.yb-history { margin-top:10px; font-size:12px; max-height:150px; overflow:auto; border-top:1px solid var(--yb-border); padding-top:8px; }
.yb-history div { padding:2px 0; color:#374151; }
.yb-history time { color:var(--yb-muted); margin-right:6px; }
.yb-modal-foot { padding:12px 18px; border-top:1px solid var(--yb-border); display:flex; gap:8px; flex-wrap:wrap; }
.yb-modal-foot .spacer { flex:1; }
.yb-toast { position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(20px); background:#111827; color:#fff; padding:10px 16px; border-radius:10px; font-size:14px; opacity:0; pointer-events:none; transition:all .2s; z-index:1100; max-width:90vw; }
.yb-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
.yb-toast.err { background:#b91c1c; }
@media (max-width: 900px) {
  .yb-layout { grid-template-columns:1fr; }
  .yb-side { position:static; max-height:none; }
}
@media (max-width: 520px) {
  .yb-grid { grid-template-columns:repeat(2, minmax(0,1fr)); gap:6px; }
  .yb-head h1 { font-size:19px; width:100%; }
  .yb-form { grid-template-columns:1fr; }
  .yb-toolbar .yb-input { max-width:none; }
  .yb-modal-bg { padding:0; }
  .yb-modal { border-radius:0; min-height:100vh; }
}
@media print {
  .yb-toolbar, .yb-head .yb-btn, .yb-side, .yb-live { display:none !important; }
  .yb-layout { grid-template-columns:1fr; }
  .yb-tile { break-inside:avoid; }
}
</style>

<div id="page-wrapper">
<div class="container-fluid yb" id="yb">

    <div class="yb-head">
        <h1><i class="fa fa-th"></i> Yard Board
            <span class="yb-live" id="ybLive" title="Board refreshes automatically"><span class="yb-live-dot"></span><span id="ybLiveText">Live</span></span>
        </h1>
        <?php if (count($warehouses) > 1): ?>
        <select class="yb-select" id="ybWarehouse" aria-label="Warehouse">
            <?php foreach ($warehouses as $w): ?>
            <option value="<?php echo (int) $w->id; ?>" <?php echo (int) $w->id === (int) $warehouse_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($w->name); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="button" class="yb-btn primary" id="ybAddIncoming"><i class="fa fa-plus"></i> Incoming</button>
        <button type="button" class="yb-btn" id="ybCheckToggle"><i class="fa fa-check-square-o"></i> Yard check</button>
        <a class="yb-btn" href="yard_history.php<?php echo $warehouse_id ? '?warehouse_id=' . (int) $warehouse_id : ''; ?>"><i class="fa fa-history"></i> History</a>
        <a class="yb-btn" href="container_dashboard.php"><i class="fa fa-cubes"></i> Containers</a>
        <?php if ($is_supervisor): ?>
        <a class="yb-btn" href="yard_settings.php<?php echo $warehouse_id ? '?warehouse_id=' . (int) $warehouse_id : ''; ?>"><i class="fa fa-cog"></i> Setup</a>
        <?php endif; ?>
    </div>

    <?php if (empty($initial['locations'])): ?>
    <div class="yb-setup">
        <h3 style="margin-top:0;">No doors or yard spots yet</h3>
        <?php if ($is_supervisor): ?>
        <p>Add your doors (DR01–DR14) and yard spots (F01–F47), or import the T-Card sheet to set everything up in one go.</p>
        <a class="yb-btn primary" href="yard_settings.php<?php echo $warehouse_id ? '?warehouse_id=' . (int) $warehouse_id : ''; ?>">Set up the yard</a>
        <?php else: ?>
        <p>Ask a supervisor to set up the doors and yard spots for this warehouse.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="yb-toolbar">
        <input type="search" class="yb-input" id="ybSearch" placeholder="Find container, account, driver…" autocomplete="off">
        <select class="yb-select" id="ybAccount" aria-label="Account"><option value="">All accounts</option></select>
        <div class="yb-chips" id="ybChips"></div>
    </div>

    <div class="yb-check-bar">
        <i class="fa fa-check-circle" style="color:#16a34a;"></i>
        <span id="ybCheckProgress"></span>
        <span style="color:#6b7280;">Tap each card once you've seen it in its spot. Wrong container? Use the pencil to fix it.</span>
        <button type="button" class="yb-btn" id="ybCheckDone" style="margin-left:auto;">Done</button>
    </div>

    <div class="yb-layout">
        <div>
            <div class="yb-section"><h2>Doors <span id="ybDoorCount"></span></h2><div class="yb-grid" id="ybDoors"></div></div>
            <div class="yb-section"><h2>Yard <span id="ybYardCount"></span></h2><div class="yb-grid" id="ybYard"></div></div>
            <div class="yb-legend" id="ybLegend"></div>
        </div>
        <aside class="yb-side">
            <div class="yb-section" style="margin:0;">
                <h2>Incoming <span id="ybIncCount"></span></h2>
                <div class="yb-inc-drop" id="ybIncoming"></div>
            </div>
        </aside>
    </div>
</div>
</div>

<div class="yb-modal-bg" id="ybModal" role="dialog" aria-modal="true" aria-labelledby="ybModalTitle">
    <div class="yb-modal">
        <div class="yb-modal-head">
            <h3 id="ybModalTitle">Container</h3>
            <button type="button" class="yb-x" data-close aria-label="Close">&times;</button>
        </div>
        <form id="ybForm" autocomplete="off">
            <div class="yb-modal-body">
                <div class="yb-banner warn" id="ybStale"></div>
                <div class="yb-banner err" id="ybError"></div>
                <div class="yb-form">
                    <div class="full">
                        <label for="f_container_number">Container / trailer #</label>
                        <input class="yb-input" id="f_container_number" name="container_number" required style="font-family:ui-monospace,monospace;font-weight:700;text-transform:uppercase;">
                    </div>
                    <div>
                        <label for="f_status">Status</label>
                        <select class="yb-select" id="f_status" name="status">
                            <?php foreach (YARD_STATUSES as $s): ?><option><?php echo $s; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="f_location">Location</label>
                        <select class="yb-select" id="f_location"></select>
                    </div>
                    <div>
                        <label for="f_account">Account</label>
                        <input class="yb-input" id="f_account" name="account" list="ybAccounts">
                    </div>
                    <div>
                        <label for="f_driver">Driver / ref</label>
                        <input class="yb-input" id="f_driver" name="driver" placeholder="e.g. PRELOAD, Sisi">
                    </div>
                    <div>
                        <label for="f_drayman">Drayman</label>
                        <input class="yb-input" id="f_drayman" name="drayman" list="ybDraymen">
                    </div>
                    <div>
                        <label for="f_lfd">LFD (last free day)</label>
                        <input class="yb-input" type="date" id="f_lfd" name="lfd">
                    </div>
                    <div class="yb-onsite">
                        <label for="f_date_in">Date in</label>
                        <input class="yb-input" type="date" id="f_date_in" name="date_in">
                    </div>
                    <div class="yb-incoming-only">
                        <label for="f_eta">ETA</label>
                        <input class="yb-input" type="date" id="f_eta" name="eta">
                    </div>
                    <div class="yb-onsite">
                        <label for="f_mt_date">MT date</label>
                        <input class="yb-input" type="date" id="f_mt_date" name="mt_date">
                    </div>
                    <div class="yb-onsite">
                        <label for="f_ld_date">LD date</label>
                        <input class="yb-input" type="date" id="f_ld_date" name="ld_date">
                    </div>
                    <div class="full">
                        <label for="f_notes">DC notes</label>
                        <textarea class="yb-input" id="f_notes" name="notes" placeholder="BOL / UL #, units, damage…"></textarea>
                    </div>
                    <div class="full">
                        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" id="f_hot" name="hot" value="1"> <span class="yb-hot">HOT</span> container (priority)
                        </label>
                    </div>
                </div>
                <div class="yb-cf" id="ybCf"></div>
                <div class="yb-meta" id="ybMeta"></div>
                <div class="yb-history" id="ybHistory" hidden></div>
            </div>
            <div class="yb-modal-foot">
                <button type="submit" class="yb-btn primary" id="ybSave">Save</button>
                <button type="button" class="yb-btn" id="ybPickup"><i class="fa fa-truck"></i> Picked up</button>
                <span class="spacer"></span>
                <button type="button" class="yb-btn" id="ybShowHistory"><i class="fa fa-history"></i></button>
                <button type="button" class="yb-btn danger" id="ybDelete"><i class="fa fa-trash"></i></button>
            </div>
        </form>
    </div>
</div>

<datalist id="ybAccounts">
    <?php foreach ($customers as $c): ?><option value="<?php echo htmlspecialchars($c->name); ?>"><?php endforeach; ?>
</datalist>
<datalist id="ybDraymen"></datalist>
<div class="yb-toast" id="ybToast" role="status" aria-live="polite"></div>

<script>
(function () {
    var BASE = <?php echo json_encode($us_url_root); ?>;
    var CSRF = <?php echo json_encode($csrf); ?>;
    var WAREHOUSE_ID = <?php echo json_encode($warehouse_id); ?>;
    var IS_SUPERVISOR = <?php echo $is_supervisor ? 'true' : 'false'; ?>;
    var STATUS_COLORS = <?php echo json_encode(YARD_STATUS_COLORS); ?>;
    var CUSTOMER_COLORS = <?php echo json_encode(array_map(fn($c) => ['name' => $c->name, 'color' => $c->effective_color], $customers)); ?>;
    var POLL_MS = 8000;

    var state = <?php echo json_encode($initial); ?>;
    var filter = { q: '', account: '', chip: '' };
    var checking = false;
    var editing = null;      // unit being edited (snapshot) or {newAt: locId|null}
    var prevUnitStamp = {};  // unit id → updated_at, to flash changed cards
    var $ = function (id) { return document.getElementById(id); };

    // ---------- helpers ----------
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function md(d) { if (!d) return ''; var p = d.split('-'); return (+p[1]) + '/' + (+p[2]); }
    function toast(msg, isErr) {
        var t = $('ybToast');
        t.textContent = msg;
        t.className = 'yb-toast show' + (isErr ? ' err' : '');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { t.className = 'yb-toast'; }, isErr ? 4500 : 2200);
    }
    function post(data) {
        var fd = new FormData();
        fd.append('csrf', CSRF);
        if (WAREHOUSE_ID) fd.append('warehouse_id', WAREHOUSE_ID);
        Object.keys(data).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
        return fetch(BASE + 'usersc/ajax/yard_action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (text) {
                try { return JSON.parse(text); } catch (e) {
                    console.error('yard_action non-JSON:', text);
                    return { success: false, message: 'Unexpected server response.' };
                }
            })
            .catch(function () { return { success: false, message: 'Network error — check your connection.' }; });
    }
    function unitsByLoc() {
        var m = {};
        state.units.forEach(function (u) { m[u.location_id] = u; });
        return m;
    }
    function findUnit(id) {
        id = +id;
        return state.units.concat(state.incoming).filter(function (u) { return u.id === id; })[0] || null;
    }
    function locById(id) { return state.locations.filter(function (l) { return l.id === +id; })[0] || null; }
    function statusPill(s) {
        var c = STATUS_COLORS[s] || ['#e5e7eb', '#374151'];
        return '<span class="yb-pill" style="background:' + c[0] + ';color:' + c[1] + '">' + esc(s) + '</span>';
    }
    function haystack(u) {
        return [u.container_number, u.account, u.driver, u.drayman, u.notes, u.status].join(' ').toLowerCase();
    }
    function chipMatch(u) {
        switch (filter.chip) {
            case '': return true;
            case 'overdue': return u.lfd_state === 'overdue';
            case 'soon': return u.lfd_state === 'soon';
            case 'hot': return u.hot;
            case 'unchecked': return !u.checked_today;
            default: return u.status === filter.chip;
        }
    }
    function matches(u) {
        if (filter.account && (u.account || '').toUpperCase() !== filter.account) return false;
        if (filter.q && haystack(u).indexOf(filter.q) === -1) return false;
        return chipMatch(u);
    }
    function filtering() { return !!(filter.q || filter.account || filter.chip); }

    // ---------- rendering ----------
    function tileHtml(loc, u) {
        if (!u) {
            var dim = filtering() ? ' dim' : '';
            return '<div class="yb-tile empty' + dim + '" tabindex="0" role="button" data-loc="' + loc.id + '" aria-label="' + esc(loc.code) + ' empty — add container">' +
                '<span class="yb-code">' + esc(loc.code) + '</span><span class="yb-plus">+</span></div>';
        }
        var cls = 'yb-tile tinted';
        if (filtering()) cls += matches(u) ? (filter.q ? ' match' : '') : ' dim';
        if (u.checked_today) cls += ' checked';
        if (prevUnitStamp[u.id] && prevUnitStamp[u.id] !== u.updated_at + '|' + u.location_id) cls += ' flash';
        var foot = [];
        if (u.hot) foot.push('<span class="yb-hot">HOT</span>');
        if (u.lfd) foot.push('<span class="yb-lfd ' + u.lfd_state + '" title="Last free day">LFD ' + md(u.lfd) + '</span>');
        if (u.days_in !== null && u.days_in >= 0) foot.push('<span title="Days on site">' + u.days_in + 'd</span>');
        var icons = [];
        if (u.notes) icons.push('<i class="fa fa-sticky-note-o" title="' + esc(u.notes) + '"></i>');
        if (u.cf) icons.push('<i class="fa fa-camera" title="Container Flow: ' + esc(u.cf.status.replace('_', ' ')) + '" style="color:' + (u.cf.status === 'reviewed' ? '#16a34a' : '#2563eb') + '"></i>');
        if (checking) icons.push('<i class="fa fa-pencil" data-edit="' + u.id + '" title="Edit" style="cursor:pointer;color:#111827;padding:0 2px;"></i>');
        var sub = [u.account, u.driver].filter(Boolean);
        return '<div class="' + cls + '" style="--acct:' + esc(u.color) + '" tabindex="0" role="button" draggable="true" data-loc="' + loc.id + '" data-unit="' + u.id + '"' +
            ' aria-label="' + esc(loc.code + ' ' + u.container_number + ' ' + u.status) + '">' +
            '<span class="yb-check"><i class="fa fa-check"></i></span>' +
            '<div class="yb-top"><span class="yb-code">' + esc(loc.code) + '</span>' + statusPill(u.status) + '</div>' +
            '<div class="yb-num">' + esc(u.container_number) + '</div>' +
            '<div class="yb-acct">' + (sub.length ? esc(sub[0]) + (sub[1] ? ' <small>· ' + esc(sub[1]) + '</small>' : '') : '&nbsp;') + '</div>' +
            '<div class="yb-foot">' + foot.join('') + (icons.length ? '<span class="yb-icons">' + icons.join('') + '</span>' : '') + '</div>' +
            '</div>';
    }

    function incomingHtml(u) {
        var dim = filtering() && !matches(u) ? ' style="opacity:.3;--acct:' + esc(u.color) + '"' : ' style="--acct:' + esc(u.color) + '"';
        var meta = [];
        if (u.account) meta.push(esc(u.account));
        if (u.eta) meta.push('ETA ' + md(u.eta));
        if (u.lfd) meta.push('<span class="yb-lfd ' + u.lfd_state + '">LFD ' + md(u.lfd) + '</span>');
        return '<div class="yb-inc" draggable="true" tabindex="0" role="button" data-unit="' + u.id + '"' + dim + '>' +
            '<div style="display:flex;justify-content:space-between;gap:6px;align-items:center;"><span class="yb-num">' + esc(u.container_number) + '</span>' +
            (u.hot ? '<span class="yb-hot">HOT</span>' : '') + '</div>' +
            '<div class="yb-inc-meta">' + meta.join(' · ') + '</div>' +
            (u.notes ? '<div class="yb-inc-meta" style="margin-top:2px;">' + esc(u.notes) + '</div>' : '') +
            '</div>';
    }

    function render() {
        var byLoc = unitsByLoc();
        var doors = state.locations.filter(function (l) { return l.kind === 'door'; });
        var yard = state.locations.filter(function (l) { return l.kind !== 'door'; });
        $('ybDoors').innerHTML = doors.map(function (l) { return tileHtml(l, byLoc[l.id]); }).join('') || '<div class="yb-empty-msg">No doors set up.</div>';
        $('ybYard').innerHTML = yard.map(function (l) { return tileHtml(l, byLoc[l.id]); }).join('') || '<div class="yb-empty-msg">No yard spots set up.</div>';
        var used = function (list) { return list.filter(function (l) { return byLoc[l.id]; }).length; };
        $('ybDoorCount').textContent = used(doors) + ' / ' + doors.length + ' occupied';
        $('ybYardCount').textContent = used(yard) + ' / ' + yard.length + ' occupied';
        $('ybIncoming').innerHTML = state.incoming.map(incomingHtml).join('') ||
            '<div class="yb-empty-msg">Nothing expected. Add containers that are on the way so the yard can plan for them.</div>';
        $('ybIncCount').textContent = state.incoming.length ? '(' + state.incoming.length + ')' : '';

        renderChips();
        renderAccounts();
        renderCheckProgress();
        prevUnitStamp = {};
        state.units.forEach(function (u) { prevUnitStamp[u.id] = u.updated_at + '|' + u.location_id; });
    }

    function renderChips() {
        var counts = {};
        var overdue = 0, soon = 0, hot = 0;
        state.units.forEach(function (u) {
            counts[u.status] = (counts[u.status] || 0) + 1;
            if (u.lfd_state === 'overdue') overdue++;
            if (u.lfd_state === 'soon') soon++;
            if (u.hot) hot++;
        });
        var chips = [];
        ['Empty', 'Loaded', 'Full', 'Working', 'Partial'].forEach(function (s) {
            if (!counts[s]) return;
            var c = STATUS_COLORS[s];
            chips.push('<button type="button" class="yb-chip' + (filter.chip === s ? ' on' : '') + '" data-chip="' + s + '"><i style="width:8px;height:8px;border-radius:50%;background:' + c[1] + ';display:inline-block;"></i>' + s + ' <b>' + counts[s] + '</b></button>');
        });
        if (overdue) chips.push('<button type="button" class="yb-chip alert' + (filter.chip === 'overdue' ? ' on' : '') + '" data-chip="overdue">Past LFD <b>' + overdue + '</b></button>');
        if (soon) chips.push('<button type="button" class="yb-chip warn' + (filter.chip === 'soon' ? ' on' : '') + '" data-chip="soon">LFD today/tomorrow <b>' + soon + '</b></button>');
        if (hot) chips.push('<button type="button" class="yb-chip alert' + (filter.chip === 'hot' ? ' on' : '') + '" data-chip="hot">Hot <b>' + hot + '</b></button>');
        if (checking) chips.push('<button type="button" class="yb-chip' + (filter.chip === 'unchecked' ? ' on' : '') + '" data-chip="unchecked">Not checked</button>');
        $('ybChips').innerHTML = chips.join('');
    }

    function renderAccounts() {
        var names = {};
        state.units.concat(state.incoming).forEach(function (u) { if (u.account) names[u.account.toUpperCase()] = u.color; });
        var sel = $('ybAccount');
        var keys = Object.keys(names).sort();
        sel.innerHTML = '<option value="">All accounts</option>' + keys.map(function (k) {
            return '<option value="' + esc(k) + '"' + (filter.account === k ? ' selected' : '') + '>' + esc(k) + '</option>';
        }).join('');
        $('ybLegend').innerHTML = keys.map(function (k) { return '<span><i style="background:' + esc(names[k]) + '"></i>' + esc(k) + '</span>'; }).join('');
        var dray = {};
        state.units.concat(state.incoming).forEach(function (u) { if (u.drayman) dray[u.drayman.toUpperCase()] = 1; });
        $('ybDraymen').innerHTML = Object.keys(dray).sort().map(function (d) { return '<option value="' + esc(d) + '">'; }).join('');
    }

    function renderCheckProgress() {
        var done = state.units.filter(function (u) { return u.checked_today; }).length;
        $('ybCheckProgress').innerHTML = '<b>' + done + ' / ' + state.units.length + '</b> cards checked today';
    }

    // ---------- live polling ----------
    var pollTimer = null, lastOk = Date.now(), fullEvery = 0;
    function poll(force) {
        clearTimeout(pollTimer);
        var url = BASE + 'usersc/ajax/yard_data.php?warehouse_id=' + encodeURIComponent(WAREHOUSE_ID || '') +
            // full reload every ~2 min picks up Container Flow status changes too
            ((force || ++fullEvery % 15 === 0) ? '' : '&since=' + encodeURIComponent(state.version));
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) throw new Error(data.message || 'error');
                lastOk = Date.now();
                setLive(true);
                if (!data.unchanged) {
                    delete data.success;
                    state = data;
                    render();
                    checkEditingStale();
                }
            })
            .catch(function () { setLive(false); })
            .then(function () { if (!document.hidden) pollTimer = setTimeout(poll, POLL_MS); });
    }
    function setLive(ok) {
        var secs = Math.round((Date.now() - lastOk) / 1000);
        $('ybLive').className = 'yb-live' + (ok ? '' : ' offline');
        $('ybLiveText').textContent = ok ? 'Live' : 'Offline — last update ' + (secs < 60 ? secs + 's' : Math.round(secs / 60) + 'm') + ' ago';
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll(); else clearTimeout(pollTimer);
    });

    // ---------- filters ----------
    $('ybSearch').addEventListener('input', function () {
        filter.q = this.value.trim().toLowerCase();
        render();
        if (filter.q) {
            var first = document.querySelector('.yb-tile.match');
            if (first && first.scrollIntoView) first.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    });
    $('ybAccount').addEventListener('change', function () { filter.account = this.value; render(); });
    $('ybChips').addEventListener('click', function (e) {
        var b = e.target.closest('[data-chip]');
        if (!b) return;
        filter.chip = filter.chip === b.dataset.chip ? '' : b.dataset.chip;
        render();
    });
    if ($('ybWarehouse')) $('ybWarehouse').addEventListener('change', function () {
        location.href = '?warehouse_id=' + encodeURIComponent(this.value);
    });

    // ---------- yard check ----------
    function setChecking(on) {
        checking = on;
        $('yb').classList.toggle('checking', on);
        $('ybCheckToggle').classList.toggle('active', on);
        if (!on && filter.chip === 'unchecked') filter.chip = '';
        render();
    }
    $('ybCheckToggle').addEventListener('click', function () { setChecking(!checking); });
    $('ybCheckDone').addEventListener('click', function () { setChecking(false); });

    // ---------- clicks on the board ----------
    function onBoardActivate(e) {
        var pencil = e.target.closest('[data-edit]');
        if (pencil) { openEditor(findUnit(pencil.dataset.edit)); return; }
        var tile = e.target.closest('.yb-tile');
        if (!tile) return;
        var u = tile.dataset.unit ? findUnit(tile.dataset.unit) : null;
        if (checking && u) {
            if (u.checked_today) return;
            u.checked_today = true;
            tile.classList.add('checked');
            renderCheckProgress();
            post({ action: 'check', unit_id: u.id }).then(function (r) { if (!r.success) toast(r.message, true); });
            return;
        }
        if (u) openEditor(u); else openEditor(null, +tile.dataset.loc);
    }
    ['ybDoors', 'ybYard'].forEach(function (id) {
        $(id).addEventListener('click', onBoardActivate);
        $(id).addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onBoardActivate(e); } });
    });
    $('ybIncoming').addEventListener('click', function (e) {
        var c = e.target.closest('[data-unit]');
        if (c) openEditor(findUnit(c.dataset.unit));
    });
    $('ybIncoming').addEventListener('keydown', function (e) {
        var c = e.target.closest('[data-unit]');
        if (c && e.key === 'Enter') openEditor(findUnit(c.dataset.unit));
    });
    $('ybAddIncoming').addEventListener('click', function () { openEditor(null, null); });

    // ---------- drag & drop (desktop) ----------
    var dragUnitId = null;
    document.addEventListener('dragstart', function (e) {
        var el = e.target.closest && e.target.closest('[data-unit][draggable]');
        if (!el || checking) { if (el) e.preventDefault(); return; }
        dragUnitId = +el.dataset.unit;
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', String(dragUnitId)); } catch (err) {}
    });
    document.addEventListener('dragend', function () {
        dragUnitId = null;
        document.querySelectorAll('.drop-target').forEach(function (n) { n.classList.remove('drop-target'); });
    });
    function dropZone(e) {
        return e.target.closest && (e.target.closest('.yb-tile') || e.target.closest('#ybIncoming'));
    }
    document.addEventListener('dragover', function (e) {
        if (!dragUnitId) return;
        var z = dropZone(e);
        if (!z) return;
        e.preventDefault();
        document.querySelectorAll('.drop-target').forEach(function (n) { if (n !== z) n.classList.remove('drop-target'); });
        z.classList.add('drop-target');
    });
    document.addEventListener('drop', function (e) {
        if (!dragUnitId) return;
        var z = dropZone(e);
        if (!z) return;
        e.preventDefault();
        var u = findUnit(dragUnitId);
        dragUnitId = null;
        z.classList.remove('drop-target');
        if (!u) return;
        var toLoc = z.id === 'ybIncoming' ? 0 : +z.dataset.loc;
        if ((u.location_id || 0) === toLoc) return;
        doMove(u, toLoc);
    });

    function doMove(u, toLoc, swap) {
        return post({ action: 'move', unit_id: u.id, location_id: toLoc, swap: swap ? 1 : 0 }).then(function (r) {
            if (!r.success && r.occupied && u.location_id) {
                var other = unitsByLoc()[toLoc];
                if (confirm('Swap ' + u.container_number + ' with ' + (other ? other.container_number : 'the container') + ' in ' + (locById(toLoc) || {}).code + '?')) {
                    return doMove(u, toLoc, true);
                }
                return r;
            }
            if (!r.success) toast(r.message, true);
            else toast(u.container_number + ' → ' + (toLoc ? locById(toLoc).code : 'Incoming'));
            return poll(true).then(function () { return r; });
        });
    }

    // ---------- editor ----------
    var F = ['container_number', 'status', 'account', 'driver', 'drayman', 'lfd', 'date_in', 'eta', 'mt_date', 'ld_date', 'notes'];
    function openEditor(u, newLocId) {
        editing = u ? JSON.parse(JSON.stringify(u)) : { id: 0, newAt: newLocId || null };
        var onSite = u ? !!u.location_id : !!newLocId;
        var loc = u ? locById(u.location_id) : locById(newLocId);
        $('ybModalTitle').textContent = u ? (loc ? loc.code + ' · ' : 'Incoming · ') + u.container_number
            : (loc ? 'Add container to ' + loc.code : 'Add incoming container');
        F.forEach(function (k) { $('f_' + k).value = u ? (u[k] || '') : ''; });
        $('f_status').value = u ? u.status : (onSite ? 'Full' : 'Expected');
        $('f_hot').checked = u ? u.hot : false;

        // location picker: incoming + every spot (occupied ones offer a swap)
        var byLoc = unitsByLoc();
        var opts = ['<option value="0">Incoming (not on site)</option>'];
        ['door', 'yard'].forEach(function (kind) {
            var group = state.locations.filter(function (l) { return (l.kind === 'door') === (kind === 'door'); });
            if (!group.length) return;
            opts.push('<optgroup label="' + (kind === 'door' ? 'Doors' : 'Yard') + '">');
            group.forEach(function (l) {
                var occ = byLoc[l.id];
                var mine = u && occ && occ.id === u.id;
                var label = l.code + (occ && !mine ? ' — ' + occ.container_number + (u && u.location_id ? ' (swap)' : ' (occupied)') : '');
                var disabled = occ && !mine && !(u && u.location_id) ? ' disabled' : '';
                opts.push('<option value="' + l.id + '"' + disabled + '>' + esc(label) + '</option>');
            });
            opts.push('</optgroup>');
        });
        $('f_location').innerHTML = opts.join('');
        $('f_location').value = String(u ? (u.location_id || 0) : (newLocId || 0));
        toggleOnsiteFields();

        // Container Flow link
        var cf = $('ybCf');
        if (u && u.cf) {
            cf.innerHTML = '<i class="fa fa-camera"></i> Container Flow: <b>' + esc(u.cf.type) + '</b> · ' + esc(u.cf.status.replace('_', ' ')) +
                ' <a class="yb-btn" style="margin-left:auto;" href="' + BASE + 'usersc/container_view.php?id=' + u.cf.id + '">Open photos &amp; record →</a>';
            cf.hidden = false;
        } else if (u) {
            var q = 'type=inbound&container_number=' + encodeURIComponent(u.container_number) +
                (u.customer_id ? '&customer_id=' + u.customer_id : '') + (WAREHOUSE_ID ? '&warehouse_id=' + WAREHOUSE_ID : '');
            cf.innerHTML = '<span style="color:#6b7280;">No Container Flow record for this number yet.</span>' +
                ' <a class="yb-btn" style="margin-left:auto;" href="' + BASE + 'usersc/container_create.php?' + q + '"><i class="fa fa-plus"></i> Start photo record</a>';
            cf.hidden = false;
        } else {
            cf.hidden = true;
        }

        $('ybMeta').textContent = u && u.updated_by ? 'Last changed by ' + u.updated_by + ' · ' + u.updated_at : '';
        $('ybHistory').hidden = true;
        $('ybPickup').hidden = !u || !u.location_id;
        $('ybDelete').hidden = !u || (!!u.location_id && !IS_SUPERVISOR);
        $('ybShowHistory').hidden = !u;
        $('ybStale').className = 'yb-banner warn';
        $('ybError').className = 'yb-banner err';
        $('ybModal').classList.add('open');
        setTimeout(function () { (u ? $('f_status') : $('f_container_number')).focus(); }, 30);
    }
    function toggleOnsiteFields() {
        var onSite = $('f_location').value !== '0';
        document.querySelectorAll('.yb-onsite').forEach(function (n) { n.style.display = onSite ? '' : 'none'; });
        document.querySelectorAll('.yb-incoming-only').forEach(function (n) { n.style.display = onSite ? 'none' : ''; });
        var st = $('f_status');
        if (onSite && st.value === 'Expected') st.value = 'Full';
        if (!onSite && !editing.id) st.value = 'Expected';
    }
    $('f_location').addEventListener('change', toggleOnsiteFields);

    function closeEditor() {
        $('ybModal').classList.remove('open');
        editing = null;
    }
    $('ybModal').addEventListener('click', function (e) {
        if (e.target === this || e.target.closest('[data-close]')) closeEditor();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && editing) closeEditor(); });

    function checkEditingStale() {
        if (!editing || !editing.id) return;
        var cur = findUnit(editing.id);
        var b = $('ybStale');
        if (!cur) {
            b.textContent = 'This container was removed from the board by someone else.';
            b.className = 'yb-banner warn show';
        } else if (cur.updated_at !== editing.updated_at || cur.location_id !== editing.location_id) {
            b.textContent = (cur.updated_by || 'Someone') + ' just changed this card. Close and reopen it to see their changes before saving.';
            b.className = 'yb-banner warn show';
        }
    }
    function showError(msg) { var b = $('ybError'); b.textContent = msg; b.className = 'yb-banner err show'; }

    $('ybForm').addEventListener('submit', function (e) {
        e.preventDefault();
        if (!editing) return;
        var data = { action: 'save', unit_id: editing.id || 0 };
        F.forEach(function (k) { data[k] = $('f_' + k).value; });
        data.hot = $('f_hot').checked ? 1 : 0;
        var toLoc = +$('f_location').value;
        if (!editing.id) data.location_id = toLoc;
        else data.version = editing.updated_at;
        $('ybSave').disabled = true;
        var snap = editing;
        post(data).then(function (r) {
            if (!r.success) { showError(r.message); return; }
            var moved = snap.id && (snap.location_id || 0) !== toLoc;
            var after = moved ? doMove(snap, toLoc) : poll(true);
            return Promise.resolve(after).then(function (mr) {
                if (moved && mr && !mr.success) return; // doMove already toasted
                closeEditor();
                if (!moved) toast(snap.id ? 'Saved' : (data.container_number.toUpperCase() + (toLoc ? ' placed in ' + locById(toLoc).code : ' added to Incoming')));
            });
        }).then(function () { $('ybSave').disabled = false; });
    });

    $('ybPickup').addEventListener('click', function () {
        if (!editing || !editing.id) return;
        if (!confirm('Mark ' + editing.container_number + ' as picked up? It will leave the board and go to History.')) return;
        var u = editing;
        post({ action: 'pickup', unit_id: u.id }).then(function (r) {
            if (!r.success) { showError(r.message); return; }
            closeEditor();
            toast(r.message);
            poll(true);
        });
    });
    $('ybDelete').addEventListener('click', function () {
        if (!editing || !editing.id) return;
        if (!confirm('Delete ' + editing.container_number + ' from the board? (Use "Picked up" for containers that left the yard.)')) return;
        post({ action: 'delete', unit_id: editing.id }).then(function (r) {
            if (!r.success) { showError(r.message); return; }
            closeEditor();
            toast('Deleted');
            poll(true);
        });
    });
    $('ybShowHistory').addEventListener('click', function () {
        if (!editing || !editing.id) return;
        var box = $('ybHistory');
        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false;
        box.textContent = 'Loading…';
        fetch(BASE + 'usersc/ajax/yard_data.php?unit_history=' + editing.id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { box.textContent = d.message; return; }
                box.innerHTML = d.events.map(function (ev) {
                    var what = ev.action.replace('_', ' ');
                    if (ev.from || ev.to) what += ' ' + (ev.from || '') + (ev.from && ev.to ? ' → ' : '') + (ev.to || '');
                    return '<div><time>' + esc(ev.at) + '</time><b>' + esc(ev.by || '—') + '</b> ' + esc(what) +
                        (ev.details ? ' <span style="color:#6b7280">' + esc(ev.details) + '</span>' : '') + '</div>';
                }).join('') || 'No history yet.';
            });
    });

    render();
    pollTimer = setTimeout(poll, POLL_MS);
    // keep the "Offline — Ns ago" label ticking
    setInterval(function () { if (Date.now() - lastOk > POLL_MS * 2) setLive(false); }, 5000);
})();
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
