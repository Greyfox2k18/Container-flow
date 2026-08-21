<?php
require_once '../users/init.php';

if (!securePage($_SERVER['PHP_SELF'])) { die(); }

// ── Helper: strip "19-" style prefix from barcode ──────────────────────────
function strip_prefix($raw) {
    $dash = strpos($raw, '-');
    if ($dash !== false && $dash > 0 && $dash < strlen($raw) - 1) {
        return substr($raw, $dash + 1);
    }
    return $raw;
}

// ── Parse uploaded Excel into order_data + po_index ────────────────────────
function parse_shipments_excel($tmp_path) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $wb     = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_path);
    $ws     = $wb->getActiveSheet();
    $rows   = $ws->toArray(null, true, true, false);
    $header = array_map('trim', $rows[0]);
    $colMap = array_flip($header);

    $required = ['Document', 'Header Reference', 'Qty Ordered'];
    foreach ($required as $r) {
        if (!isset($colMap[$r])) {
            throw new Exception("Column '$r' not found in spreadsheet.");
        }
    }

    $order_data = [];
    $po_index   = [];

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $sh  = trim((string)($row[$colMap['Header Reference']] ?? ''));
        if (!$sh) continue;

        $po_ref = isset($colMap['Ship To Reference']) ? trim((string)($row[$colMap['Ship To Reference']] ?? '')) : '';

        $date_raw = trim((string)($row[$colMap['Date to Ship'] ?? $colMap['Date To Ship'] ?? -1] ?? ''));
        $date     = preg_match('/\d{4}-\d{2}-\d{2}/', $date_raw, $m) ? $m[0] : $date_raw;

        $rec = [
            'document'      => trim((string)($row[$colMap['Document']] ?? '')),
            'header_ref'    => $sh,
            'status'        => trim((string)($row[$colMap['Status'] ?? -1] ?? '')),
            'date_to_ship'  => $date,
            'carrier_code'  => isset($colMap['Carrier Code']) ? trim((string)($row[$colMap['Carrier Code']] ?? '')) : '',
            'ship_to_name'  => isset($colMap['Ship To Name']) ? trim((string)($row[$colMap['Ship To Name']] ?? '')) : '',
            'ship_to_city'  => isset($colMap['Ship To City']) ? trim((string)($row[$colMap['Ship To City']] ?? '')) : '',
            'ship_to_state' => isset($colMap['Ship To State']) ? trim((string)($row[$colMap['Ship To State']] ?? '')) : '',
            'ship_to_ref'   => $po_ref,
            'qty_ordered'   => (int)str_replace(',', '', (string)($row[$colMap['Qty Ordered']] ?? 0)),
            'qty_committed' => isset($colMap['Qty Committed']) ? (int)str_replace(',', '', (string)($row[$colMap['Qty Committed']] ?? 0)) : 0,
            'warehouse'     => isset($colMap['Warehouse']) ? trim((string)($row[$colMap['Warehouse']] ?? '')) : '',
        ];

        $order_data[$sh][] = $rec;

        if ($po_ref) {
            $po_index[strtoupper($po_ref)][] = $sh;
        }
    }

    return ['order_data' => $order_data, 'po_index' => $po_index, 'count' => count($order_data)];
}

// ── Write parsed data to order_data.php ────────────────────────────────────
function write_order_data($data, $cancelled_sh, $source_name) {
    $order_data = $data['order_data'];
    $po_index   = $data['po_index'];

    $lines = [];
    $lines[] = "<?php";
    $lines[] = "// Auto-generated from: " . basename($source_name) . " on " . date('Y-m-d H:i:s');
    $lines[] = "";
    $lines[] = "\$cancelled_sh = ['" . implode("', '", $cancelled_sh) . "'];";
    $lines[] = "";
    $lines[] = "\$order_data = [];";

    foreach ($order_data as $sh => $recs) {
        $sh_safe = str_replace("'", "\'", $sh);
        $lines[] = "\$order_data['{$sh_safe}'] = [";
        foreach ($recs as $r) {
            $lines[] = "    [";
            foreach ($r as $k => $v) {
                if (is_string($v)) {
                    $v_safe = str_replace("'", "\'", $v);
                    $lines[] = "        '{$k}' => '{$v_safe}',";
                } else {
                    $lines[] = "        '{$k}' => {$v},";
                }
            }
            $lines[] = "    ],";
        }
        $lines[] = "];";
    }

    $lines[] = "";
    $lines[] = "\$po_index = [];";
    foreach ($po_index as $po => $shs) {
        $po_safe  = str_replace("'", "\'", $po);
        $shs_str  = implode("', '", $shs);
        $lines[] = "\$po_index['{$po_safe}'] = ['{$shs_str}'];";
    }

    file_put_contents(__DIR__ . '/order_data.php', implode("\n", $lines));
}

// ── Handle Excel upload ─────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'upload_excel') {
    header('Content-Type: application/json');
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Upload failed.']);
        exit;
    }
    $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'])) {
        echo json_encode(['status' => 'error', 'message' => 'Only .xlsx or .xls files accepted.']);
        exit;
    }
    try {
        $data = parse_shipments_excel($_FILES['excel_file']['tmp_name']);
        // Preserve existing cancelled list if order_data.php exists
        $cancelled_sh = [];
        if (file_exists(__DIR__ . '/order_data.php')) {
            include __DIR__ . '/order_data.php';
        }
        write_order_data($data, $cancelled_sh, $_FILES['excel_file']['name']);
        echo json_encode(['status' => 'ok', 'count' => $data['count'], 'filename' => $_FILES['excel_file']['name']]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Load order data ─────────────────────────────────────────────────────────
$order_data  = [];
$po_index    = [];
$cancelled_sh = [];
$data_loaded  = false;
$data_info    = '';

if (file_exists(__DIR__ . '/order_data.php')) {
    require __DIR__ . '/order_data.php';
    $data_loaded = true;
    // Grab the source comment from first lines
    $first = file(__DIR__ . '/order_data.php', FILE_IGNORE_NEW_LINES);
    foreach ($first as $line) {
        if (strpos($line, '// Auto-generated from:') === 0) {
            $data_info = trim(str_replace('// Auto-generated from:', '', $line));
            break;
        }
    }
}

// ── Handle barcode lookup AJAX ──────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'lookup') {
    header('Content-Type: application/json');

    if (!$data_loaded) {
        echo json_encode(['status' => 'error', 'message' => 'No order data loaded. Please upload a spreadsheet first.']);
        exit;
    }

    $raw   = trim($_POST['barcode'] ?? '');
    $key   = strtoupper($raw);
    $clean = strtoupper(strip_prefix($raw));

    $cancelled_upper = array_map('strtoupper', $cancelled_sh);
    if (in_array($key, $cancelled_upper) || in_array($clean, $cancelled_upper)) {
        echo json_encode(['status' => 'cancelled', 'sh' => $clean]);
        exit;
    }

    $matches = $order_data[$key] ?? $order_data[$clean] ?? null;

    if (!$matches && isset($po_index[$key])) {
        $matches = [];
        foreach ($po_index[$key] as $sh) {
            if (isset($order_data[$sh])) {
                $matches = array_merge($matches, $order_data[$sh]);
            }
        }
        if (empty($matches)) $matches = null;
    }

    if (!$matches) {
        echo json_encode(['status' => 'not_found', 'raw' => $raw]);
        exit;
    }

    if (count($matches) === 1) {
        echo json_encode(['status' => 'found', 'order' => $matches[0]]);
        exit;
    }

    echo json_encode(['status' => 'multiple', 'matches' => $matches]);
    exit;
}

require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';



$page_title = 'Pick Scanner';
?>

<style>
/* ── Mobile-first pick scanner styles ──────────────────────────────────── */
body { background: #f0f2f5; }

.scanner-wrap {
    max-width: 600px;
    margin: 0 auto;
    padding: 12px;
}

/* Top bar */
.scanner-header {
    background: #1e468c;
    color: #fff;
    border-radius: 10px 10px 0 0;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.2rem;
    font-weight: 700;
}

/* Scan input card */
.scan-card {
    background: #fff;
    padding: 16px;
    border-left: 1px solid #dce3f0;
    border-right: 1px solid #dce3f0;
}

.scan-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #666;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 6px;
}

.scan-input-row {
    display: flex;
    gap: 8px;
}

#barcodeInput {
    flex: 1;
    font-size: 1.2rem;
    padding: 10px 14px;
    border: 2px solid #1e468c;
    border-radius: 8px;
    outline: none;
    -webkit-appearance: none;
}

#barcodeInput:focus { border-color: #2d5ec9; box-shadow: 0 0 0 3px rgba(30,70,140,0.15); }

.btn-clear {
    padding: 10px 16px;
    background: #e0e4ed;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    cursor: pointer;
    color: #444;
}

/* ── Result card ─────────────────────────────────────────────────────────── */
#resultCard {
    display: none;
    border-radius: 0 0 10px 10px;
    border: 1px solid #dce3f0;
    border-top: none;
    overflow: hidden;
}

/* S number banner */
.doc-banner {
    padding: 14px 18px 10px;
}

.doc-banner .doc-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #7a8aaa;
    margin-bottom: 2px;
}

.doc-banner .doc-value {
    font-size: 2.6rem;
    font-weight: 800;
    line-height: 1;
    color: #1e468c;
}

.doc-divider { height: 1px; background: #dce3f0; margin: 0 18px; }

/* Detail rows */
.detail-grid {
    padding: 10px 18px 14px;
    display: grid;
    grid-template-columns: 110px 1fr;
    row-gap: 6px;
    font-size: 0.95rem;
}

.detail-grid .dl { color: #888; text-align: right; padding-right: 12px; }
.detail-grid .dv { color: #1a1a3a; font-weight: 600; }

/* Status variants */
#resultCard.state-found   { background: #f2fbf5; }
#resultCard.state-cancelled { background: #fff0f0; }
#resultCard.state-notfound  { background: #fff8f0; }

.state-cancelled .doc-value { color: #b00; }
.state-notfound  .doc-value { color: #b05000; }

/* Cancelled / not found message */
.status-msg {
    padding: 0 18px 14px;
    font-size: 1rem;
    font-weight: 600;
}
.state-cancelled .status-msg { color: #b00; }
.state-notfound  .status-msg  { color: #b05000; }

/* ── Duplicate picker modal ───────────────────────────────────────────────── */
.picker-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 12px 14px;
    border: 2px solid #dce3f0;
    border-radius: 8px;
    margin-bottom: 8px;
    background: #fff;
    cursor: pointer;
    font-size: 0.95rem;
    transition: border-color .15s, background .15s;
}
.picker-item:hover, .picker-item:focus {
    border-color: #1e468c;
    background: #f0f4ff;
    outline: none;
}
.picker-item .pi-doc  { font-size: 1.4rem; font-weight: 800; color: #1e468c; }
.picker-item .pi-meta { color: #555; font-size: 0.85rem; margin-top: 2px; }

/* ── Scan log ─────────────────────────────────────────────────────────────── */
.log-section {
    margin-top: 16px;
    background: #fff;
    border-radius: 10px;
    border: 1px solid #dce3f0;
    overflow: hidden;
}
.log-header {
    background: #f5f7fb;
    padding: 8px 14px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #666;
    text-transform: uppercase;
    letter-spacing: .5px;
    border-bottom: 1px solid #dce3f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
#logList {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 200px;
    overflow-y: auto;
    font-family: 'Courier New', monospace;
    font-size: 0.8rem;
}
#logList li {
    padding: 6px 14px;
    border-bottom: 1px solid #f0f2f5;
    color: #444;
}
#logList li.log-found     { border-left: 3px solid #28a745; }
#logList li.log-cancelled { border-left: 3px solid #dc3545; color: #b00; font-weight: 600; }
#logList li.log-multi     { border-left: 3px solid #ffc107; }
#logList li.log-notfound  { border-left: 3px solid #fd7e14; }

/* Spinner */
#spinner { display: none; color: #1e468c; padding: 10px 18px; font-size: 0.9rem; }

@media (max-width: 400px) {
    .doc-banner .doc-value { font-size: 2rem; }
    .detail-grid { font-size: 0.88rem; }
}
</style>

<div class="scanner-wrap">

    <!-- Header -->
    <div class="scanner-header">
        <span>📦</span> Pick Scanner
    </div>

    <!-- Data file info + upload -->
    <div class="upload-card">
        <?php if ($data_loaded): ?>
            <span class="data-info">✔ <?= htmlspecialchars($data_info) ?></span>
        <?php else: ?>
            <span class="data-info no-data">⚠ No order data loaded</span>
        <?php endif; ?>
        <label class="btn-upload" for="excelUpload" style="margin:0;">
            📂 Upload Spreadsheet
        </label>
        <input type="file" id="excelUpload" accept=".xlsx,.xls" style="display:none;">
        <span id="uploadSpinner">⏳ Processing...</span>
    </div>

    <!-- Scan input -->
    <div class="scan-card">
        <div class="scan-label">Scan or enter barcode</div>
        <div class="scan-input-row">
            <input type="text"
                   id="barcodeInput"
                   autocomplete="off"
                   autocorrect="off"
                   autocapitalize="off"
                   spellcheck="false"
                   inputmode="text"
                   placeholder="e.g. 19-SH54824559">
            <button class="btn-clear" onclick="clearAll()">Clear</button>
        </div>
        <div id="spinner">⏳ Looking up...</div>
    </div>

    <!-- Result -->
    <div id="resultCard">
        <div class="doc-banner">
            <div class="doc-label" id="docLabel">DOCUMENT (S#)</div>
            <div class="doc-value" id="docValue">—</div>
        </div>
        <div class="doc-divider"></div>
        <div class="detail-grid" id="detailGrid"></div>
        <div class="status-msg" id="statusMsg"></div>
    </div>

    <!-- Scan log -->
    <div class="log-section">
        <div class="log-header">
            Scan Log
            <a href="#" onclick="clearLog();return false;" style="font-size:0.75rem;color:#aaa;font-weight:400;">Clear</a>
        </div>
        <ul id="logList"></ul>
    </div>

</div>

<!-- Duplicate picker modal -->
<div class="modal fade" id="pickerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#1e468c;color:#fff;">
                <h5 class="modal-title">Multiple orders found</h5>
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;">&times;</button>
            </div>
            <div class="modal-body" id="pickerBody">
                <p class="text-muted" style="font-size:0.9rem;">This SH number appears on more than one order. Select the correct one:</p>
                <div id="pickerItems"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const input   = document.getElementById('barcodeInput');
    const result  = document.getElementById('resultCard');
    const docVal  = document.getElementById('docValue');
    const docLbl  = document.getElementById('docLabel');
    const grid    = document.getElementById('detailGrid');
    const statusMsg = document.getElementById('statusMsg');
    const spinner = document.getElementById('spinner');
    const logList = document.getElementById('logList');

    let debounce;

    // ── Debounce input (handles scanner bursts) ─────────────────────────────
    input.addEventListener('input', function () {
        clearTimeout(debounce);
        if (input.value.trim().length >= 6) {
            debounce = setTimeout(() => doLookup(input.value.trim()), 500);
        }
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            clearTimeout(debounce);
            doLookup(input.value.trim());
        }
    });

    // ── Main lookup ─────────────────────────────────────────────────────────
    function doLookup(raw) {
        if (!raw) return;
        spinner.style.display = 'block';
        result.style.display  = 'none';

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=lookup&barcode=' + encodeURIComponent(raw)
        })
        .then(r => r.json())
        .then(data => {
            spinner.style.display = 'none';
            if (data.status === 'found') {
                showOrder(data.order);
                logEntry('✔ ' + raw + '  →  ' + data.order.document + '  qty ' + data.order.qty_ordered, 'log-found');
                input.value = '';
            } else if (data.status === 'cancelled') {
                showCancelled(data.sh || raw);
                logEntry('🚫 CANCELLED: ' + raw, 'log-cancelled');
                input.value = '';
            } else if (data.status === 'multiple') {
                logEntry('⚠ Multiple matches: ' + raw, 'log-multi');
                showPicker(data.matches, raw);
            } else {
                showNotFound(raw);
                logEntry('✖ Not found: ' + raw, 'log-notfound');
                input.value = '';
            }
            input.focus();
        })
        .catch(() => {
            spinner.style.display = 'none';
            showNotFound(raw);
        });
    }

    // ── Display states ──────────────────────────────────────────────────────
    function showOrder(o) {
        result.className = 'state-found';
        result.style.display = 'block';
        docLbl.textContent = 'DOCUMENT (S#)';
        docVal.textContent = o.document;
        docVal.style.color = '';
        statusMsg.textContent = '';

        var location = [o.ship_to_city, o.ship_to_state].filter(Boolean).join(', ');
        grid.innerHTML =
            row('Header Ref',   o.header_ref) +
            row('Ship To',      o.ship_to_name + (location ? '  –  ' + location : '')) +
            row('PO / Ref',     o.ship_to_ref) +
            row('Qty Ordered',  '<strong style="color:#1e468c;font-size:1.1em">' + Number(o.qty_ordered).toLocaleString() + '</strong>') +
            row('Date To Ship', o.date_to_ship) +
            row('Carrier',      o.carrier_code) +
            row('Warehouse',    o.warehouse);
    }

    function showCancelled(sh) {
        result.className = 'state-cancelled';
        result.style.display = 'block';
        docLbl.textContent = 'ORDER STATUS';
        docVal.textContent  = '⛔ CANCELLED';
        docVal.style.color  = '#b00';
        grid.innerHTML = row('SH Number', sh);
        statusMsg.textContent = 'Do not pick this order.';
    }

    function showNotFound(raw) {
        result.className = 'state-notfound';
        result.style.display = 'block';
        docLbl.textContent = 'NOT FOUND';
        docVal.textContent  = raw;
        docVal.style.color  = '#b05000';
        grid.innerHTML = '';
        statusMsg.textContent = 'No matching order in the loaded data.';
    }

    function row(label, value) {
        return '<span class="dl">' + label + '</span><span class="dv">' + (value || '—') + '</span>';
    }

    // ── Duplicate picker ────────────────────────────────────────────────────
    function showPicker(matches, raw) {
        const container = document.getElementById('pickerItems');
        container.innerHTML = '';
        matches.forEach(function (o, i) {
            const btn = document.createElement('button');
            btn.className = 'picker-item';
            btn.innerHTML =
                '<div class="pi-doc">' + o.document + '</div>' +
                '<div class="pi-meta">' +
                    o.ship_to_code + '  ' + o.ship_to_name +
                    '  &nbsp;·&nbsp;  Qty: ' + Number(o.qty_ordered).toLocaleString() +
                    '  &nbsp;·&nbsp;  ' + o.date_to_ship +
                '</div>';
            btn.addEventListener('click', function () {
                $('#pickerModal').modal('hide');
                showOrder(o);
                logEntry('✔ Selected ' + o.document + '  qty ' + o.qty_ordered, 'log-found');
                input.select();
            });
            container.appendChild(btn);
        });
        $('#pickerModal').modal('show');
    }

    // ── Log ─────────────────────────────────────────────────────────────────
    function logEntry(msg, cls) {
        const li = document.createElement('li');
        li.className = cls || '';
        const now = new Date();
        const t = now.getHours().toString().padStart(2,'0') + ':' +
                  now.getMinutes().toString().padStart(2,'0') + ':' +
                  now.getSeconds().toString().padStart(2,'0');
        li.textContent = '[' + t + ']  ' + msg;
        logList.insertBefore(li, logList.firstChild);
        if (logList.children.length > 100) logList.removeChild(logList.lastChild);
    }

    // ── Clear ───────────────────────────────────────────────────────────────
    window.clearAll = function () {
        input.value = '';
        result.style.display = 'none';
        input.focus();
    };

    window.clearLog = function () { logList.innerHTML = ''; };

    // Auto-focus on load
    input.focus();

    // ── Excel upload ───────────────────────────────────────────────────────
    document.getElementById('excelUpload').addEventListener('change', function () {
        if (!this.files.length) return;
        const file = this.files[0];
        const fd   = new FormData();
        fd.append('action', 'upload_excel');
        fd.append('excel_file', file);

        document.getElementById('uploadSpinner').style.display = 'inline';
        document.querySelector('.data-info').textContent = '⏳ Processing ' + file.name + '...';

        fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            document.getElementById('uploadSpinner').style.display = 'none';
            const info = document.querySelector('.data-info');
            if (data.status === 'ok') {
                info.className = 'data-info';
                info.textContent = '✔ ' + data.filename + ' — ' + data.count + ' orders  (reload to confirm)';
                logEntry('📂 Loaded ' + data.count + ' orders from ' + data.filename, 'log-found');
            } else {
                info.className = 'data-info no-data';
                info.textContent = '✖ ' + (data.message || 'Upload failed');
                logEntry('✖ Upload error: ' + (data.message || 'unknown'), 'log-notfound');
            }
            input.focus();
        })
        .catch(() => {
            document.getElementById('uploadSpinner').style.display = 'none';
            document.querySelector('.data-info').textContent = '✖ Upload failed';
        });

        this.value = ''; // reset so same file can be re-uploaded
    });
})();
</script>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
