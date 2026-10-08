<?php
/**
 * Yard Board setup — supervisors only.
 *   - Doors & yard spots (bulk add DR01–DR14, F01–F47; rename; retire)
 *   - Client colours (the sheet's "CUSTOMER COLORS" tab) on Container Flow's clients
 *   - Import the T-Card sheet (TODAY tab exported as CSV) to go live
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/yard_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}
if (!isSupervisor()) {
    Redirect::to('yard_board.php');
}

$user_id = (int) $user->data()->id;
ensureYardTables();
[$warehouse_id, $warehouses] = yardResolveWarehouse($user_id, Input::get('warehouse_id'));
$db = DB::getInstance();

$errors  = [];
$message = '';
$report  = null;
// The uploaded CSV is parked here between "Preview" and "Import now".
$import_path = sys_get_temp_dir() . '/yard_import_' . $user_id . '_' . md5(session_id()) . '.csv';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Token::check(Input::get('csrf'))) {
    $action = Input::get('action');

    if ($action === 'add_range') {
        $prefix = strtoupper(trim(Input::get('prefix')));
        $start  = (int) Input::get('start');
        $end    = (int) Input::get('end');
        if (!preg_match('/^[A-Z]{1,6}-?$/', $prefix)) {
            $errors[] = 'Prefix should be letters only, e.g. DR or F.';
        } elseif ($end < $start) {
            $errors[] = '"To" must be at least "From".';
        } else {
            $n = createYardLocationRange($warehouse_id, $prefix, $start, $end, Input::get('kind') === 'door' ? 'door' : 'yard', (int) Input::get('pad'));
            $message = "Added {$n} location" . ($n === 1 ? '' : 's') . ($n < ($end - $start + 1) ? ' (the rest already existed)' : '') . '.';
        }
    } elseif ($action === 'add_one') {
        $code = strtoupper(trim(Input::get('code')));
        if ($code === '' || strlen($code) > 20) {
            $errors[] = 'Enter a location code (up to 20 characters).';
        } elseif (!createYardLocation($warehouse_id, $code, Input::get('kind') === 'door' ? 'door' : 'yard')) {
            $errors[] = "{$code} already exists.";
        } else {
            $message = "Added {$code}.";
        }
    } elseif ($action === 'save_locations') {
        $codes  = (array) ($_POST['code'] ?? []);
        $kinds  = (array) ($_POST['kind'] ?? []);
        $sorts  = (array) ($_POST['sort'] ?? []);
        $active = (array) ($_POST['active'] ?? []);
        $saved = 0;
        foreach ($codes as $id => $code) {
            $loc = getYardLocationById((int) $id);
            if (!$loc || (int) $loc->warehouse_id !== (int) $warehouse_id) continue;
            $code = strtoupper(trim($code));
            if ($code === '') continue;
            $clash = findYardLocationByCode($warehouse_id, $code);
            if ($clash && (int) $clash->id !== (int) $loc->id) {
                $errors[] = "Can't rename {$loc->code} to {$code} — that code is already used.";
                continue;
            }
            $is_active = isset($active[$id]) ? 1 : 0;
            if (!$is_active && $loc->active && getYardUnitAtLocation($loc->id)) {
                $errors[] = "{$loc->code} still has a container in it — move it before hiding the spot.";
                $is_active = 1;
            }
            $db->update('yard_locations', $loc->id, [
                'code'       => $code,
                'kind'       => ($kinds[$id] ?? '') === 'door' ? 'door' : 'yard',
                'sort_order' => (int) ($sorts[$id] ?? 0),
                'active'     => $is_active,
            ]);
            $saved++;
        }
        logYardEvent((object) ['warehouse_id' => $warehouse_id, 'container_number' => null], $user_id, 'locations', null, null, "Saved {$saved} locations");
        if (!$errors) $message = 'Locations saved.';
    } elseif ($action === 'delete_location') {
        $loc = getYardLocationById((int) Input::get('location_id'));
        if ($loc && (int) $loc->warehouse_id === (int) $warehouse_id) {
            if (getYardUnitAtLocation($loc->id)) {
                $errors[] = "{$loc->code} has a container in it — move it first.";
            } else {
                $db->delete('yard_locations', (int) $loc->id);
                logYardEvent((object) ['warehouse_id' => $warehouse_id, 'container_number' => null], $user_id, 'locations', $loc->code, null, 'Location deleted');
                $message = "Deleted {$loc->code}.";
            }
        }
    } elseif ($action === 'save_colors') {
        foreach ((array) ($_POST['color'] ?? []) as $cid => $hex) {
            $hex = strtolower(trim((string) $hex));
            $use = isset($_POST['use_color'][$cid]) && preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : null;
            $db->query("UPDATE customers SET yard_color = ? WHERE id = ?", [$use, (int) $cid]);
        }
        $message = 'Client colours saved.';
    } elseif ($action === 'import_preview') {
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Choose the CSV file first.';
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            $errors[] = 'That file is too big for a yard sheet (5 MB max).';
        } elseif (!move_uploaded_file($f['tmp_name'], $import_path)) {
            $errors[] = 'Could not store the upload — check the server temp directory permissions.';
        } else {
            $report = importYardCsv($import_path, $warehouse_id, $user_id, true);
            $report['preview'] = true;
        }
    } elseif ($action === 'import_run') {
        if (!is_file($import_path)) {
            $errors[] = 'The preview expired — upload the file again.';
        } else {
            $report = importYardCsv($import_path, $warehouse_id, $user_id, false);
            @unlink($import_path);
            if (empty($report['error'])) $message = 'Import finished.';
        }
    }
    if (!empty($report['error'])) $errors[] = $report['error'];
}

$locations = getYardLocations($warehouse_id, false);
$occupied = [];
foreach ($db->query("SELECT location_id, container_number FROM yard_units WHERE location_id IS NOT NULL")->results() ?: [] as $r) {
    $occupied[(int) $r->location_id] = $r->container_number;
}
$clients = getYardCustomers();
$csrf = Token::generate();
$wh_qs = $warehouse_id ? '?warehouse_id=' . (int) $warehouse_id : '';
?>
<style>
.ys { padding-bottom:40px; }
.ys-head { display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding:16px 0 10px; }
.ys-head h1 { font-size:22px; font-weight:700; margin:0 auto 0 0; }
.ys-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px; align-items:start; }
.ys-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
.ys-card h2 { font-size:16px; font-weight:700; margin:0 0 4px; }
.ys-card p.help { color:#6b7280; font-size:13px; margin:0 0 12px; }
.ys-row { display:flex; flex-wrap:wrap; gap:8px; align-items:end; margin-bottom:10px; }
.ys-row label { font-size:12px; font-weight:600; display:block; margin-bottom:2px; color:#374151; }
.ys-in { border:1px solid #e5e7eb; border-radius:8px; padding:6px 9px; font-size:13px; min-height:34px; background:#fff; }
.ys-btn { border:1px solid #e5e7eb; background:#fff; border-radius:8px; padding:7px 12px; font-size:13px; font-weight:600; color:#111827; cursor:pointer; text-decoration:none; display:inline-flex; gap:6px; align-items:center; }
.ys-btn:hover { background:#f3f4f6; text-decoration:none; color:#111827; }
.ys-btn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
.ys-btn.small { padding:3px 8px; font-size:12px; }
.ys-table { width:100%; border-collapse:collapse; font-size:13px; }
.ys-table th { text-align:left; font-size:11.5px; color:#6b7280; text-transform:uppercase; padding:6px; border-bottom:1px solid #e5e7eb; }
.ys-table td { padding:4px 6px; border-bottom:1px solid #f3f4f6; }
.ys-scroll { max-height:520px; overflow:auto; }
.ys-msg { border-radius:8px; padding:10px 12px; margin-bottom:12px; font-size:14px; }
.ys-msg.ok { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
.ys-msg.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
.ys-report { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px; font-size:13px; margin-top:10px; }
.ys-report ul { margin:6px 0 0; padding-left:18px; max-height:220px; overflow:auto; }
.ys-swatch { width:18px; height:18px; border-radius:4px; display:inline-block; vertical-align:middle; border:1px solid rgba(0,0,0,.1); }
</style>

<div id="page-wrapper">
<div class="container-fluid ys">
    <div class="ys-head">
        <h1><i class="fa fa-cog"></i> Yard Board Setup</h1>
        <?php if (count($warehouses) > 1): ?>
        <form method="get"><select class="ys-in" name="warehouse_id" onchange="this.form.submit()">
            <?php foreach ($warehouses as $w): ?>
            <option value="<?php echo (int) $w->id; ?>" <?php echo (int) $w->id === (int) $warehouse_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($w->name); ?></option>
            <?php endforeach; ?>
        </select></form>
        <?php endif; ?>
        <a class="ys-btn" href="yard_board.php<?php echo $wh_qs; ?>"><i class="fa fa-th"></i> Back to board</a>
    </div>

    <?php if ($message): ?><div class="ys-msg ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="ys-msg err"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div><?php endif; ?>

    <div class="ys-grid">
        <div>
            <div class="ys-card" style="margin-bottom:16px;">
                <h2>Import the T-Card sheet</h2>
                <p class="help">In Google Sheets open the <b>TODAY</b> tab → File → Download → Comma-separated values (.csv), then upload it here.
                    Doors/spots that don't exist yet are created, the incoming list on the right of the sheet becomes the Incoming column,
                    and anything already on the board is left alone. You'll see a preview before anything is saved.</p>
                <form method="post" enctype="multipart/form-data" class="ys-row">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="import_preview">
                    <input type="file" name="csv" accept=".csv,text/csv" required class="ys-in">
                    <button class="ys-btn primary" type="submit">Preview import</button>
                </form>
                <?php if ($report && empty($report['error'])): ?>
                <div class="ys-report">
                    <b><?php echo !empty($report['preview']) ? 'Preview — nothing saved yet' : 'Imported'; ?>:</b>
                    <?php echo (int) $report['placed']; ?> on the board,
                    <?php echo (int) $report['incoming']; ?> incoming,
                    <?php echo (int) $report['locations']; ?> new door/yard spots<?php echo $report['skipped'] ? ', ' . count($report['skipped']) . ' skipped' : ''; ?>.
                    <?php if ($report['lines']): ?><ul><?php foreach ($report['lines'] as $l): ?><li><?php echo htmlspecialchars($l); ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <?php if (!empty($report['unknown_accounts'])): ?><div style="margin-top:8px;color:#92400e;"><b>Not a Container Flow client yet:</b>
                        <?php echo htmlspecialchars(implode(', ', $report['unknown_accounts'])); ?>.
                        These import as plain text. To use them as clients, add them under <a href="customer_list.php">Manage Clients</a> first, then preview again.</div><?php endif; ?>
                    <?php if ($report['skipped']): ?><div style="margin-top:8px;color:#991b1b;"><b>Skipped</b></div>
                    <ul><?php foreach ($report['skipped'] as $l): ?><li><?php echo htmlspecialchars($l); ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <?php if (!empty($report['preview']) && ($report['placed'] || $report['incoming'] || $report['locations'])): ?>
                    <form method="post" style="margin-top:10px;">
                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="import_run">
                        <button class="ys-btn primary" type="submit">Import now</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="ys-card" style="margin-bottom:16px;">
                <h2>Add doors &amp; yard spots</h2>
                <p class="help">Your sheet uses DR01–DR14 for doors and F01–F47 for yard spots.</p>
                <form method="post" class="ys-row">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="add_range">
                    <div><label>Type</label><select name="kind" class="ys-in"><option value="door">Doors</option><option value="yard">Yard spots</option></select></div>
                    <div><label>Prefix</label><input name="prefix" class="ys-in" value="DR" size="4" required></div>
                    <div><label>From</label><input name="start" type="number" class="ys-in" value="1" min="0" style="width:70px;" required></div>
                    <div><label>To</label><input name="end" type="number" class="ys-in" value="14" min="0" style="width:70px;" required></div>
                    <div><label>Digits</label><input name="pad" type="number" class="ys-in" value="2" min="1" max="4" style="width:60px;"></div>
                    <button class="ys-btn primary" type="submit">Add range</button>
                </form>
                <form method="post" class="ys-row" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="add_one">
                    <div><label>Single spot</label><input name="code" class="ys-in" placeholder="e.g. TRAILER LOT A" required></div>
                    <div><label>Type</label><select name="kind" class="ys-in"><option value="yard">Yard</option><option value="door">Door</option></select></div>
                    <button class="ys-btn" type="submit">Add</button>
                </form>
            </div>

            <div class="ys-card">
                <h2>Client colours</h2>
                <p class="help">ACCOUNT on the board is picked from Container Flow's clients, so there's one client list for the whole site.
                    Tick “Colour” to fill that client's cells on the board, like the sheet's CUSTOMER COLORS tab; unticked clients get an automatic colour.
                    Add or rename clients under <a href="customer_list.php">Manage Clients</a>.</p>
                <?php if (!$clients): ?>
                <p class="help">No clients yet. Add them under <a href="customer_list.php">Manage Clients</a>.</p>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="save_colors">
                    <div class="ys-scroll" style="max-height:340px;">
                    <table class="ys-table">
                        <thead><tr><th>Client</th><th>Colour</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($clients as $c): ?>
                        <tr>
                            <td><span class="ys-swatch" style="background:<?php echo htmlspecialchars($c->effective_color); ?>"></span> <?php echo htmlspecialchars($c->name); ?></td>
                            <td><input type="checkbox" name="use_color[<?php echo (int) $c->id; ?>]" value="1" <?php echo !empty($c->yard_color) ? 'checked' : ''; ?> aria-label="Use custom colour for <?php echo htmlspecialchars($c->name); ?>"></td>
                            <td><input type="color" name="color[<?php echo (int) $c->id; ?>]" value="<?php echo htmlspecialchars($c->effective_color); ?>" aria-label="Colour for <?php echo htmlspecialchars($c->name); ?>"
                                       onchange="this.closest('tr').querySelector('[type=checkbox]').checked = true"></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <button class="ys-btn primary" type="submit" style="margin-top:10px;">Save colours</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="ys-card">
            <h2>Doors &amp; yard spots (<?php echo count($locations); ?>)</h2>
            <p class="help">Untick “Show” to hide a spot from the board without losing its history. Spots with a container in them can't be hidden or deleted.</p>
            <?php if (!$locations): ?>
            <p class="help">None yet — add a range or import the sheet.</p>
            <?php else: ?>
            <form method="post" id="locForm">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="save_locations">
                <div class="ys-scroll">
                <table class="ys-table">
                    <thead><tr><th>Code</th><th>Type</th><th>Order</th><th>Show</th><th>Now</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($locations as $l): $occ = $occupied[(int) $l->id] ?? null; ?>
                    <tr>
                        <td><input class="ys-in" name="code[<?php echo (int) $l->id; ?>]" value="<?php echo htmlspecialchars($l->code); ?>" size="8"></td>
                        <td><select class="ys-in" name="kind[<?php echo (int) $l->id; ?>]">
                            <option value="door" <?php echo $l->kind === 'door' ? 'selected' : ''; ?>>Door</option>
                            <option value="yard" <?php echo $l->kind === 'yard' ? 'selected' : ''; ?>>Yard</option>
                        </select></td>
                        <td><input class="ys-in" type="number" name="sort[<?php echo (int) $l->id; ?>]" value="<?php echo (int) $l->sort_order; ?>" style="width:64px;"></td>
                        <td><input type="checkbox" name="active[<?php echo (int) $l->id; ?>]" value="1" <?php echo $l->active ? 'checked' : ''; ?>></td>
                        <td style="font-family:ui-monospace,monospace;font-size:12px;"><?php echo htmlspecialchars($occ ?? ''); ?></td>
                        <td><?php if (!$occ): ?><button class="ys-btn small" type="submit" form="del<?php echo (int) $l->id; ?>" title="Delete">&times;</button><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <button class="ys-btn primary" type="submit" style="margin-top:10px;">Save locations</button>
            </form>
            <?php foreach ($locations as $l): if (isset($occupied[(int) $l->id])) continue; ?>
            <form method="post" id="del<?php echo (int) $l->id; ?>" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('Delete ' . $l->code . '?')); ?>);" hidden>
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="delete_location">
                <input type="hidden" name="location_id" value="<?php echo (int) $l->id; ?>">
            </form>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
