<?php
/**
 * SKU Lot / Expiration Scanner — Container Flow
 * ---------------------------------------------------------------
 * Type or scan a SKU to start a session, then scan (or type) Lot
 * Number, Expiration, and Quantity for each unit. Each scanned row
 * saves to the DB immediately via usersc/ajax/sku_scan_ajax.php.
 * "Export to Excel" downloads the scanned rows as .xlsx — per-session
 * via usersc/sku_scan_export.php when standalone, or the whole
 * container (all SKUs combined) via usersc/sku_scan_export_container.php
 * when opened from a container's page.
 *
 * Everything scanned stays in the DB (sku_scan_sessions / sku_scan_lots).
 *
 * Optional query string: ?container_id=123 — ties every session started
 * on this page load to that container (and its customer), switches the
 * header/export to container mode, and — this is the part that makes it
 * usable across computers — loads and displays every lot already scanned
 * for that container (from any session, any computer) so a second person
 * opening this page sees the full list and can keep adding to it.
 * Opened via the "Scan SKU Lots" button on container_view.php.
 * ---------------------------------------------------------------
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/sku_scan_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$user_id = $user->data()->id;

ensureSkuScanTables();

// Optional container context.
$container_id  = (int) Input::get('container_id');
$container     = $container_id ? getContainerById($container_id) : null;
$scan_customer = ($container && $container->customer_id) ? getCustomerById($container->customer_id) : null;
if (!$container) {
    $container_id = 0; // bad/missing id in the query string -> fall back to standalone mode
}

// In container mode, load every lot already scanned for this container
// (across every SKU / every session / anyone's computer) so this page
// always shows the full picture, not just what happened in this browser.
$existing_lots = $container_id ? getScanLotsByContainer($container_id) : [];
$existing_qty_total = 0;
foreach ($existing_lots as $l) {
    $existing_qty_total += (int) $l->quantity;
}

// One CSRF token for the whole page — Token::generate() overwrites the
// session token, so only call it once per page load (see container_view.php).
$csrf     = Token::generate();
$base_url = $us_url_root;
?>
<style>
.sks-wrap{max-width:960px;margin:0 auto;padding:20px 16px 40px;}
.sks-card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:20px;padding:20px;}
.sks-card label{display:block;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;}
.sks-card input[type=text],.sks-card input[type=number]{width:100%;height:42px;border:1px solid #e1e4e8;border-radius:6px;padding:0 12px;font-size:15px;box-sizing:border-box;}
.sks-card .hint{font-size:12px;color:#9ca3af;margin:8px 0 0;}
.sks-container-banner{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;background:#eff6ff;border:1px solid #bfdbfe;}
.sks-banner-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.sks-banner-value{font-size:20px;font-weight:700;color:#1e3a8a;}
.sks-banner-sub{font-size:13px;color:#374151;margin-top:2px;}
.sks-session-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #e5e7eb;font-size:14px;}
.sks-totals span{margin-right:14px;color:#374151;}
.sks-scan-row{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:6px;}
.sks-field{flex:1 1 200px;min-width:160px;}
.sks-field.sks-qty-field{flex:0 1 100px;min-width:90px;}
.sks-field.sks-save-field{flex:0 0 auto;}
#expDatePicker{margin-top:6px;height:34px;width:100%;border:1px solid #e1e4e8;border-radius:6px;padding:0 8px;box-sizing:border-box;}
.sks-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:16px;}
.sks-table th{text-align:left;padding:9px 12px;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e5e7eb;background:#f9fafb;}
.sks-table td{padding:10px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.sks-unparsed{color:#b45309;font-size:11px;}
.sks-empty{color:#9ca3af;font-size:13px;padding:16px 0;text-align:center;}
#expParseHint{font-size:12px;margin:2px 0 10px;min-height:16px;}
#scanError{margin-top:10px;}
</style>

<div id="page-wrapper">
<div class="container-fluid">
<div class="sks-wrap">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;font-size:20px;">SKU Lot / Expiration Scanner</h2>
        <?php if ($container_id): ?>
        <a href="container_view.php?id=<?php echo $container_id; ?>" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Back to Container</a>
        <?php else: ?>
        <a href="container_dashboard.php" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Dashboard</a>
        <?php endif; ?>
    </div>

    <?php if ($container_id): ?>
    <div class="sks-card sks-container-banner">
        <div>
            <div class="sks-banner-label">Container</div>
            <div class="sks-banner-value"><?php echo htmlspecialchars($container->container_number); ?></div>
            <div class="sks-banner-sub">
                <?php echo $scan_customer ? htmlspecialchars($scan_customer->name) : 'No client assigned'; ?>
            </div>
        </div>
        <a href="sku_scan_export_container.php?container_id=<?php echo $container_id; ?>&csrf=<?php echo urlencode($csrf); ?>" class="btn btn-success" target="_blank">
            <i class="fa fa-file-excel-o"></i> Export Container to Excel
        </a>
    </div>
    <p class="hint" style="margin:-10px 0 18px;">Everyone who scans for this container — on any computer — adds to the same list below.</p>
    <?php endif; ?>

    <!-- Step 1: SKU -->
    <div class="sks-card" id="skuCard">
        <label for="skuInput">SKU</label>
        <input type="text" id="skuInput" autocomplete="off" placeholder="Scan or type SKU, then press Enter" autofocus>
        <p class="hint">Scanning or typing a SKU and pressing Enter starts a new scan session below.</p>
    </div>

    <!-- Scanned lots: in container mode this is always visible and pre-loaded
         with everything scanned so far (any session, any computer). In
         standalone mode it stays hidden until a session starts, same as before. -->
    <div class="sks-card" id="lotsCard" style="<?php echo $container_id ? '' : 'display:none;'; ?>">
        <div class="sks-session-header">
            <div id="lotsCardTitle">
                <?php if ($container_id): ?>
                Scanned so far
                <?php else: ?>
                SKU: <strong id="sessionSkuLabel"></strong>
                <?php endif; ?>
            </div>
            <div class="sks-totals">
                <span><strong id="lotCount"><?php echo count($existing_lots); ?></strong> lots</span>
                <span><strong id="qtyTotal"><?php echo $existing_qty_total; ?></strong> total qty</span>
            </div>
            <?php if (!$container_id): ?>
            <div>
                <button type="button" class="btn btn-default btn-sm" id="newSkuBtn"><i class="fa fa-refresh"></i> New SKU</button>
                <button type="button" class="btn btn-success btn-sm" id="exportBtn"><i class="fa fa-file-excel-o"></i> Export to Excel</button>
            </div>
            <?php endif; ?>
        </div>

        <table class="sks-table" id="lotsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <?php if ($container_id): ?><th>SKU</th><?php endif; ?>
                    <th>Lot Number</th><th>Expiration</th><th>Qty</th><th>Scanned</th><th></th>
                </tr>
            </thead>
            <tbody id="lotsTableBody">
                <?php $row_i = 0; foreach ($existing_lots as $l): $row_i++; ?>
                <tr data-id="<?php echo (int) $l->id; ?>" data-session-id="<?php echo (int) $l->session_id; ?>" data-qty="<?php echo (int) $l->quantity; ?>">
                    <td><?php echo $row_i; ?></td>
                    <?php if ($container_id): ?><td><?php echo htmlspecialchars($l->sku); ?></td><?php endif; ?>
                    <td><?php echo htmlspecialchars($l->lot_number); ?></td>
                    <td>
                        <?php if ($l->expiration_date): ?>
                            <?php echo htmlspecialchars($l->expiration_date); ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars($l->expiration_raw ?: '—'); ?> <span class="sks-unparsed">(unparsed)</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int) $l->quantity; ?></td>
                    <td><?php echo htmlspecialchars($l->scanned_at); ?></td>
                    <td><button type="button" class="btn btn-link btn-xs sks-del" title="Remove"><i class="fa fa-trash"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($container_id && empty($existing_lots)): ?>
        <p class="sks-empty" id="emptyStateMsg">Nothing scanned for this container yet — type a SKU above to start.</p>
        <?php endif; ?>
    </div>

    <!-- Scan input row: hidden until a SKU session starts, in both modes. -->
    <div class="sks-card" id="scanInputCard" style="display:none;">
        <div class="sks-scan-row">
            <div class="sks-field">
                <label for="lotInput">Lot Number</label>
                <input type="text" id="lotInput" autocomplete="off" placeholder="Scan or type lot #">
            </div>
            <div class="sks-field">
                <label for="expInput">Expiration</label>
                <input type="text" id="expInput" autocomplete="off" placeholder="Scan, or type e.g. 2027-03-15">
                <input type="date" id="expDatePicker" title="Pick a date manually instead of scanning">
            </div>
            <div class="sks-field sks-qty-field">
                <label for="qtyInput">Qty</label>
                <input type="number" id="qtyInput" min="1" step="1" value="1">
            </div>
            <div class="sks-field sks-save-field">
                <button type="button" class="btn btn-primary" id="saveLotBtn"><i class="fa fa-check"></i> Save</button>
            </div>
            <?php if ($container_id): ?>
            <div class="sks-field sks-save-field">
                <button type="button" class="btn btn-default" id="doneSkuBtn"><i class="fa fa-refresh"></i> Different SKU</button>
            </div>
            <?php endif; ?>
        </div>
        <div id="expParseHint"></div>
        <div id="scanError" class="alert alert-danger" style="display:none;"></div>
    </div>

</div>
</div>
</div>

<script>
(function () {
    'use strict';

    var CSRF         = '<?php echo $csrf; ?>';
    var BASE_URL     = '<?php echo $base_url; ?>';
    var AJAX_URL     = BASE_URL + 'usersc/ajax/sku_scan_ajax.php';
    var EXPORT_URL   = BASE_URL + 'usersc/sku_scan_export.php';
    var CONTAINER_ID = <?php echo $container_id ? (int) $container_id : 'null'; ?>;

    var sessionId  = null;   // the SKU session currently accepting new scans
    var currentSku = '';     // SKU of that session, used to label new rows in container mode
    var rowNum     = <?php echo count($existing_lots); ?>;
    var lotCount   = <?php echo count($existing_lots); ?>;
    var qtyTotal   = <?php echo $existing_qty_total; ?>;

    var skuInput        = document.getElementById('skuInput');
    var lotsCard         = document.getElementById('lotsCard');
    var scanInputCard    = document.getElementById('scanInputCard');
    var sessionSkuLabel  = document.getElementById('sessionSkuLabel'); // standalone mode only
    var lotInput         = document.getElementById('lotInput');
    var expInput         = document.getElementById('expInput');
    var expDatePicker    = document.getElementById('expDatePicker');
    var qtyInput         = document.getElementById('qtyInput');
    var saveLotBtn       = document.getElementById('saveLotBtn');
    var newSkuBtn        = document.getElementById('newSkuBtn');   // standalone mode only
    var doneSkuBtn       = document.getElementById('doneSkuBtn');  // container mode only
    var exportBtn        = document.getElementById('exportBtn');   // standalone mode only
    var lotsTableBody    = document.getElementById('lotsTableBody');
    var lotCountEl       = document.getElementById('lotCount');
    var qtyTotalEl       = document.getElementById('qtyTotal');
    var expParseHint     = document.getElementById('expParseHint');
    var scanError        = document.getElementById('scanError');
    var emptyStateMsg    = document.getElementById('emptyStateMsg');

    function showError(msg) {
        scanError.textContent = msg;
        scanError.style.display = 'block';
    }
    function clearError() {
        scanError.style.display = 'none';
        scanError.textContent = '';
    }

    function post(action, data) {
        var payload = Object.assign({ action: action, csrf: CSRF }, data);
        var body = Object.keys(payload).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]);
        }).join('&');
        return fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); });
    }

    // ── Expiration parsing (best-effort) ───────────────────────────────
    // Handles: YYYY-MM-DD, YYYY/MM/DD, MM/DD/YYYY, MM/DD/YY,
    // 8 pure digits -> YYYYMMDD, 6 pure digits -> YYMMDD (common on
    // manufacturer lot/expiry barcodes), and a GS1 AI(17) prefix, e.g.
    // "(17)270315" or "17270315" -> also read as YYMMDD.
    // Returns { ok: true, iso: 'YYYY-MM-DD' } or { ok: false }.
    function parseExpiration(raw) {
        var s = (raw || '').trim();
        if (!s) return { ok: false };

        var gs1 = s.match(/^\(?17\)?(\d{6})$/);
        if (gs1) s = gs1[1];

        var m = s.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/);
        if (m) return isoFrom(m[1], m[2], m[3]);

        m = s.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/);
        if (m) return isoFrom(m[3], m[1], m[2]);

        m = s.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{2})$/);
        if (m) return isoFrom('20' + m[3], m[1], m[2]);

        m = s.match(/^(\d{4})(\d{2})(\d{2})$/);
        if (m) return isoFrom(m[1], m[2], m[3]);

        m = s.match(/^(\d{2})(\d{2})(\d{2})$/);
        if (m) return isoFrom('20' + m[1], m[2], m[3]);

        return { ok: false };
    }

    function isoFrom(y, mo, d) {
        y = parseInt(y, 10); mo = parseInt(mo, 10); d = parseInt(d, 10);
        if (mo < 1 || mo > 12 || d < 1 || d > 31) return { ok: false };
        var iso = y + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
        var dt = new Date(iso + 'T00:00:00');
        if (isNaN(dt.getTime())) return { ok: false };
        return { ok: true, iso: iso };
    }

    // ── SKU -> start session ────────────────────────────────────────────
    function startSession() {
        var sku = skuInput.value.trim();
        if (!sku) return;
        clearError();
        var startPayload = { sku: sku };
        if (CONTAINER_ID) { startPayload.container_id = CONTAINER_ID; }
        post('start_session', startPayload).then(function (res) {
            if (!res.success) { showError(res.message || 'Could not start session.'); return; }
            sessionId = res.session_id;
            currentSku = sku;

            if (!CONTAINER_ID) {
                // Standalone mode: the lots table is scoped to this one SKU,
                // so starting a new session clears it out.
                sessionSkuLabel.textContent = sku;
                lotsTableBody.innerHTML = '';
                rowNum = 0; lotCount = 0; qtyTotal = 0;
                updateTotals();
            }

            lotsCard.style.display = 'block';
            if (emptyStateMsg) emptyStateMsg.style.display = 'none';
            scanInputCard.style.display = 'block';
            skuInput.value = '';
            lotInput.focus();
        }).catch(function () { showError('Network error starting session.'); });
    }
    skuInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); startSession(); }
    });

    // ── Enter-to-advance chain: lot -> exp -> qty -> save ──────────────
    lotInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); expInput.focus(); }
    });

    expInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); qtyInput.focus(); qtyInput.select(); }
    });
    expInput.addEventListener('input', function () {
        var parsed = parseExpiration(expInput.value);
        if (!expInput.value.trim()) {
            expParseHint.textContent = '';
        } else if (parsed.ok) {
            expParseHint.textContent = 'Reads as ' + parsed.iso;
            expParseHint.style.color = '#15803d';
        } else {
            expParseHint.textContent = "Couldn't auto-read this — use the date picker, or it'll save as text only.";
            expParseHint.style.color = '#b45309';
        }
    });
    expDatePicker.addEventListener('change', function () {
        if (expDatePicker.value) {
            expInput.value = expDatePicker.value;
            expInput.dispatchEvent(new Event('input'));
            qtyInput.focus(); qtyInput.select();
        }
    });

    qtyInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); saveLot(); }
    });
    saveLotBtn.addEventListener('click', saveLot);

    function saveLot() {
        if (!sessionId) { showError('Start a SKU session first.'); return; }
        var lot = lotInput.value.trim();
        if (!lot) { showError('Lot number is required.'); lotInput.focus(); return; }
        var qty = parseInt(qtyInput.value, 10);
        if (!qty || qty < 1) qty = 1;
        var expRaw = expInput.value.trim();
        var parsed = parseExpiration(expRaw);

        clearError();
        post('save_lot', {
            session_id: sessionId,
            lot_number: lot,
            expiration_raw: expRaw,
            expiration_date: parsed.ok ? parsed.iso : '',
            quantity: qty
        }).then(function (res) {
            if (!res.success) { showError(res.message || 'Could not save lot.'); return; }
            addRowToTable(res.lot, sessionId, currentSku);
            lotInput.value = '';
            expInput.value = '';
            expDatePicker.value = '';
            expParseHint.textContent = '';
            qtyInput.value = 1;
            lotInput.focus();
        }).catch(function () { showError('Network error saving lot.'); });
    }

    function addRowToTable(lot, lotSessionId, sku) {
        rowNum++;
        lotCount++;
        qtyTotal += parseInt(lot.quantity, 10) || 0;
        updateTotals();

        var tr = document.createElement('tr');
        tr.dataset.id = lot.id;
        tr.dataset.sessionId = lotSessionId;
        tr.dataset.qty = parseInt(lot.quantity, 10) || 0;

        var expCell = lot.expiration_date
            ? escapeHtml(lot.expiration_date)
            : (escapeHtml(lot.expiration_raw || '—') + ' <span class="sks-unparsed">(unparsed)</span>');

        var skuCell = CONTAINER_ID ? ('<td>' + escapeHtml(sku) + '</td>') : '';

        tr.innerHTML =
            '<td>' + rowNum + '</td>' +
            skuCell +
            '<td>' + escapeHtml(lot.lot_number) + '</td>' +
            '<td>' + expCell + '</td>' +
            '<td>' + escapeHtml(String(lot.quantity)) + '</td>' +
            '<td>' + escapeHtml(lot.scanned_at || '') + '</td>' +
            '<td><button type="button" class="btn btn-link btn-xs sks-del" title="Remove"><i class="fa fa-trash"></i></button></td>';
        lotsTableBody.appendChild(tr);
    }

    // Event delegation for delete buttons — covers both server-rendered
    // rows (already-scanned history) and rows added live during this page.
    lotsTableBody.addEventListener('click', function (e) {
        var btn = e.target.closest('.sks-del');
        if (!btn) return;
        var tr = btn.closest('tr');
        var id = parseInt(tr.dataset.id, 10);
        var rowSessionId = parseInt(tr.dataset.sessionId, 10);
        var qty = parseInt(tr.dataset.qty, 10) || 0;
        deleteLot(id, rowSessionId, tr, qty);
    });

    function deleteLot(id, rowSessionId, tr, qty) {
        if (!confirm('Remove this scanned lot?')) return;
        post('delete_lot', { session_id: rowSessionId, lot_id: id }).then(function (res) {
            if (!res.success) { showError(res.message || 'Could not remove row.'); return; }
            tr.remove();
            lotCount--;
            qtyTotal -= qty;
            updateTotals();
            if (CONTAINER_ID && lotCount === 0 && emptyStateMsg) {
                emptyStateMsg.style.display = 'block';
            }
        });
    }

    function updateTotals() {
        lotCountEl.textContent = lotCount;
        qtyTotalEl.textContent = qtyTotal;
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    // Standalone mode: "New SKU" clears the table (it's scoped to one SKU).
    if (newSkuBtn) {
        newSkuBtn.addEventListener('click', function () {
            sessionId = null; rowNum = 0;
            lotsCard.style.display = 'none';
            scanInputCard.style.display = 'none';
            skuInput.value = '';
            skuInput.focus();
        });
    }

    // Container mode: "Different SKU" just closes the scan-input row and
    // goes back to the SKU field — the table above keeps everything, since
    // it's a running list for the whole container, not just this SKU.
    if (doneSkuBtn) {
        doneSkuBtn.addEventListener('click', function () {
            sessionId = null; currentSku = '';
            scanInputCard.style.display = 'none';
            skuInput.focus();
        });
    }

    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            if (!sessionId) return;
            window.open(EXPORT_URL + '?session_id=' + encodeURIComponent(sessionId) + '&csrf=' + encodeURIComponent(CSRF), '_blank');
        });
    }
})();
</script>
