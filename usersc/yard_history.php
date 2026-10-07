<?php
/**
 * Yard History — replaces the sheet's "Picked Up", "PICKED UP ARCHIVE" and
 * "Move Sheet" tabs. Two views:
 *   ?view=picked  containers that have left the yard (default)
 *   ?view=moves   every place / move / swap / edit on the board
 * Both filter by text and date range and export to CSV (&export=csv).
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/yard_functions.php';

// CSV export skips prep.php — the template echoes HTML immediately, which
// would break the download headers (same as sku_scan_export.php).
$export = Input::get('export') === 'csv';
if ($export) {
    if (!$user->isLoggedIn()) die('Not authenticated.');
} else {
    require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
    if (!securePage($_SERVER['PHP_SELF'])) {
        die();
    }
}

$user_id = (int) $user->data()->id;
ensureYardTables();
[$warehouse_id, $warehouses] = yardResolveWarehouse($user_id, Input::get('warehouse_id'));

$view = Input::get('view') === 'moves' ? 'moves' : 'picked';
$q    = trim((string) Input::get('q'));
$from = yardParseDate(Input::get('from')) ?: date('Y-m-d', strtotime('-30 days'));
$to   = yardParseDate(Input::get('to')) ?: date('Y-m-d');
$page = max(1, (int) Input::get('page'));
$per_page = 100;

$db = DB::getInstance();
$params = [];
$wh = yardWarehouseWhere($view === 'picked' ? 'yu.warehouse_id' : 'e.warehouse_id', $warehouse_id, $params);

if ($view === 'picked') {
    $where = "{$wh} AND yu.picked_up_at IS NOT NULL AND yu.picked_up_at >= ? AND yu.picked_up_at < ?";
    $params[] = $from . ' 00:00:00';
    $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    if ($q !== '') {
        $where .= " AND (yu.container_number LIKE ? OR yu.account LIKE ? OR yu.driver LIKE ? OR yu.drayman LIKE ? OR yu.notes LIKE ?)";
        array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
    }
    $sql = "FROM yard_units yu LEFT JOIN users u ON u.id = yu.picked_up_by WHERE {$where}";
    $select = "SELECT yu.*, u.fname, u.lname {$sql} ORDER BY yu.picked_up_at DESC";
} else {
    $where = "{$wh} AND e.created_at >= ? AND e.created_at < ?";
    $params[] = $from . ' 00:00:00';
    $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    if ($q !== '') {
        $where .= " AND (e.container_number LIKE ? OR e.from_code LIKE ? OR e.to_code LIKE ? OR e.details LIKE ?)";
        array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
    }
    if (!Input::get('all_events')) $where .= " AND e.action IN ('placed','moved','swapped','picked_up','restored','expected','deleted')";
    $sql = "FROM yard_events e LEFT JOIN users u ON u.id = e.user_id WHERE {$where}";
    $select = "SELECT e.*, u.fname, u.lname {$sql} ORDER BY e.id DESC";
}

$who = fn($r) => trim(($r->fname ?? '') . ' ' . ($r->lname ?? ''));

if ($export) {
    $rows = $db->query($select . " LIMIT 20000", $params)->results() ?: [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="yard-' . $view . '-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    if ($view === 'picked') {
        fputcsv($out, ['RETURN DATE', 'CONTAINER', 'STATUS', 'DATE IN', 'MT DATE', 'LD DATE', 'DRIVER', 'ACCOUNT', 'LFD', 'DRAYMAN', 'DC NOTES', 'LAST LOCATION', 'PICKED UP BY']);
        foreach ($rows as $r) {
            fputcsv($out, [$r->picked_up_at, $r->container_number, $r->status, $r->date_in, $r->mt_date, $r->ld_date,
                $r->driver, $r->account, $r->lfd, $r->drayman, $r->notes, $r->last_location_code, $who($r)]);
        }
    } else {
        fputcsv($out, ['WHEN', 'CONTAINER', 'ACTION', 'FROM', 'TO', 'DETAILS', 'BY']);
        foreach ($rows as $r) {
            fputcsv($out, [$r->created_at, $r->container_number, $r->action, $r->from_code, $r->to_code, $r->details, $who($r)]);
        }
    }
    fclose($out);
    exit;
}

$total = (int) ($db->query("SELECT COUNT(*) AS c {$sql}", $params)->first()->c ?? 0);
$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);
$rows  = $db->query($select . " LIMIT " . (($page - 1) * $per_page) . ", {$per_page}", $params)->results() ?: [];
$csrf  = Token::generate();

$qs = function ($over = []) use ($view, $q, $from, $to, $warehouse_id) {
    return '?' . http_build_query(array_filter(array_merge([
        'view' => $view, 'q' => $q, 'from' => $from, 'to' => $to, 'warehouse_id' => $warehouse_id,
    ], $over), fn($v) => $v !== null && $v !== ''));
};
$fmt = fn($d) => $d ? date('n/j', strtotime($d)) : '';
?>
<style>
.yh { padding-bottom:40px; }
.yh-head { display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding:16px 0 10px; }
.yh-head h1 { font-size:22px; font-weight:700; margin:0 auto 0 0; }
.yh-tabs { display:flex; gap:4px; border-bottom:1px solid #e5e7eb; margin-bottom:12px; }
.yh-tabs a { padding:8px 14px; font-weight:600; color:#6b7280; border-bottom:2px solid transparent; text-decoration:none; }
.yh-tabs a.on { color:#111827; border-color:#111827; }
.yh-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:end; margin-bottom:12px; }
.yh-filters label { font-size:12px; font-weight:600; display:block; margin-bottom:2px; color:#374151; }
.yh-filters input, .yh-filters select { border:1px solid #e5e7eb; border-radius:8px; padding:6px 10px; font-size:13px; min-height:34px; }
.yh-btn { border:1px solid #e5e7eb; background:#fff; border-radius:8px; padding:7px 12px; font-size:13px; font-weight:600; color:#111827; text-decoration:none; display:inline-flex; gap:6px; align-items:center; cursor:pointer; }
.yh-btn:hover { background:#f3f4f6; text-decoration:none; color:#111827; }
.yh-btn.primary { background:#2563eb; color:#fff; border-color:#2563eb; }
.yh-table-wrap { overflow-x:auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; }
.yh-table { width:100%; border-collapse:collapse; font-size:13px; }
.yh-table th { text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; background:#f9fafb; padding:8px 10px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
.yh-table td { padding:7px 10px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
.yh-table tr:last-child td { border-bottom:0; }
.yh-num { font-family:ui-monospace,monospace; font-weight:700; white-space:nowrap; }
.yh-muted { color:#6b7280; }
.yh-pager { display:flex; gap:8px; align-items:center; margin-top:12px; font-size:13px; color:#6b7280; }
</style>

<div id="page-wrapper">
<div class="container-fluid yh">
    <div class="yh-head">
        <h1><i class="fa fa-history"></i> Yard History</h1>
        <a class="yh-btn" href="yard_board.php<?php echo $warehouse_id ? '?warehouse_id=' . (int) $warehouse_id : ''; ?>"><i class="fa fa-th"></i> Back to board</a>
    </div>

    <div class="yh-tabs">
        <a href="<?php echo htmlspecialchars($qs(['view' => 'picked', 'page' => null])); ?>" class="<?php echo $view === 'picked' ? 'on' : ''; ?>">Picked up</a>
        <a href="<?php echo htmlspecialchars($qs(['view' => 'moves', 'page' => null])); ?>" class="<?php echo $view === 'moves' ? 'on' : ''; ?>">Moves &amp; activity</a>
    </div>

    <form class="yh-filters" method="get">
        <input type="hidden" name="view" value="<?php echo $view; ?>">
        <?php if (count($warehouses) > 1): ?>
        <div><label>Warehouse</label>
            <select name="warehouse_id">
                <?php foreach ($warehouses as $w): ?>
                <option value="<?php echo (int) $w->id; ?>" <?php echo (int) $w->id === (int) $warehouse_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($w->name); ?></option>
                <?php endforeach; ?>
            </select></div>
        <?php elseif ($warehouse_id): ?>
        <input type="hidden" name="warehouse_id" value="<?php echo (int) $warehouse_id; ?>">
        <?php endif; ?>
        <div><label>Search</label><input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Container, account, spot…"></div>
        <div><label>From</label><input type="date" name="from" value="<?php echo $from; ?>"></div>
        <div><label>To</label><input type="date" name="to" value="<?php echo $to; ?>"></div>
        <?php if ($view === 'moves'): ?>
        <div><label style="display:flex;gap:6px;align-items:center;margin-bottom:9px;"><input type="checkbox" name="all_events" value="1" <?php echo Input::get('all_events') ? 'checked' : ''; ?> style="min-height:0;"> Include edits</label></div>
        <?php endif; ?>
        <button class="yh-btn primary" type="submit">Filter</button>
        <a class="yh-btn" href="<?php echo htmlspecialchars($qs(['export' => 'csv', 'all_events' => Input::get('all_events')])); ?>"><i class="fa fa-download"></i> CSV</a>
    </form>

    <div class="yh-table-wrap">
        <table class="yh-table">
        <?php if ($view === 'picked'): ?>
            <thead><tr>
                <th>Picked up</th><th>Container</th><th>Status</th><th>From</th><th>Date in</th><th>MT</th><th>LD</th>
                <th>Driver</th><th>Account</th><th>LFD</th><th>Drayman</th><th>DC notes</th><th>By</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo date('n/j g:ia', strtotime($r->picked_up_at)); ?></td>
                    <td class="yh-num"><?php echo htmlspecialchars($r->container_number); ?></td>
                    <td><?php echo htmlspecialchars($r->status); ?></td>
                    <td><?php echo htmlspecialchars($r->last_location_code ?? ''); ?></td>
                    <td><?php echo $fmt($r->date_in); ?></td>
                    <td><?php echo $fmt($r->mt_date); ?></td>
                    <td><?php echo $fmt($r->ld_date); ?></td>
                    <td><?php echo htmlspecialchars($r->driver ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r->account ?? ''); ?></td>
                    <td><?php echo $fmt($r->lfd); ?></td>
                    <td><?php echo htmlspecialchars($r->drayman ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r->notes ?? ''); ?></td>
                    <td class="yh-muted"><?php echo htmlspecialchars($who($r)); ?></td>
                    <td><button type="button" class="yh-btn" data-restore="<?php echo (int) $r->id; ?>" title="Put back on the board (undo pickup)"><i class="fa fa-undo"></i></button></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="14" class="yh-muted" style="padding:20px;">No pickups in this range.</td></tr><?php endif; ?>
            </tbody>
        <?php else: ?>
            <thead><tr><th>When</th><th>Container</th><th>Action</th><th>From</th><th>To</th><th>Details</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo date('n/j g:ia', strtotime($r->created_at)); ?></td>
                    <td class="yh-num"><?php echo htmlspecialchars($r->container_number ?? ''); ?></td>
                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $r->action)); ?></td>
                    <td><?php echo htmlspecialchars($r->from_code ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r->to_code ?? ''); ?></td>
                    <td class="yh-muted"><?php echo htmlspecialchars($r->details ?? ''); ?></td>
                    <td class="yh-muted"><?php echo htmlspecialchars($who($r)); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="yh-muted" style="padding:20px;">No activity in this range.</td></tr><?php endif; ?>
            </tbody>
        <?php endif; ?>
        </table>
    </div>

    <div class="yh-pager">
        <?php echo number_format($total); ?> row<?php echo $total === 1 ? '' : 's'; ?>
        <?php if ($pages > 1): ?>
            · page <?php echo $page; ?> of <?php echo $pages; ?>
            <?php if ($page > 1): ?><a class="yh-btn" href="<?php echo htmlspecialchars($qs(['page' => $page - 1])); ?>">‹ Prev</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="yh-btn" href="<?php echo htmlspecialchars($qs(['page' => $page + 1])); ?>">Next ›</a><?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-restore]');
    if (!b || !confirm('Put this container back on the board? It returns to its old spot if that spot is free, otherwise to Incoming.')) return;
    var fd = new FormData();
    fd.append('csrf', <?php echo json_encode($csrf); ?>);
    fd.append('action', 'restore');
    fd.append('unit_id', b.dataset.restore);
    b.disabled = true;
    fetch(<?php echo json_encode($us_url_root); ?> + 'usersc/ajax/yard_action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) { alert(r.message); if (r.success) location.reload(); else b.disabled = false; })
        .catch(function () { alert('Network error.'); b.disabled = false; });
});
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
