<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) { die(); }
if (!isFloorWorker()) { Redirect::to('container_dashboard.php'); }

$customers   = getAllCustomers();
$customer_names = array_map(fn($c) => $c->name, $customers);
$csrf        = Token::generate();
$is_supervisor = isSupervisor();
?>
<style>
.bi-wrap { max-width: 960px; margin: 0 auto; }
.bi-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 22px 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.07); }
.bi-card h4 { margin: 0 0 14px; font-size: 16px; color: #1f2937; }
#pasteArea {
    width: 100%; min-height: 140px; font-family: 'Consolas','Courier New',monospace;
    font-size: 13px; border: 2px dashed #cbd5e1; border-radius: 8px; padding: 14px;
    resize: vertical; color: #374151; background: #f8fafc;
}
#pasteArea:focus { outline: none; border-color: #667eea; background: #fff; }
.bi-hint { font-size: 12px; color: #6b7280; margin-top: 6px; }
.bi-hint a { color: #667eea; }
.mapping-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
.mapping-item label { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; display: block; margin-bottom: 4px; }
.mapping-item select { width: 100%; height: 34px; border: 1px solid #e1e4e8; border-radius: 6px; font-size: 13px; padding: 0 8px; }
#previewTable { width: 100%; border-collapse: collapse; font-size: 13px; }
#previewTable thead th { background: #f8f9fa; padding: 8px 10px; text-align: left; font-weight: 600; color: #6b7280; font-size: 11px; text-transform: uppercase; border-bottom: 2px solid #e9ecef; white-space: nowrap; }
#previewTable tbody td { padding: 7px 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
#previewTable tbody tr:hover { background: #f8fafc; }
.row-ok   td:first-child { border-left: 3px solid #10b981; }
.row-warn td:first-child { border-left: 3px solid #f59e0b; }
.row-err  td:first-child { border-left: 3px solid #ef4444; background: #fff1f0; }
.bi-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
.bi-badge-ok   { background: #d1fae5; color: #065f46; }
.bi-badge-warn { background: #fef3c7; color: #92400e; }
.bi-badge-err  { background: #fee2e2; color: #991b1b; }
.result-row-ok   { background: #f0fdf4; }
.result-row-warn { background: #fffbeb; }
.result-row-err  { background: #fff1f0; }
#importBtn { min-width: 180px; }
.bi-section-divider { border: none; border-top: 1px solid #e9ecef; margin: 20px 0; }
</style>

<div id="page-wrapper">
<div class="container-fluid">
<div class="bi-wrap">

    <div style="display:flex; align-items:center; justify-content:space-between; padding: 18px 0 14px; flex-wrap:wrap; gap:10px;">
        <h2 style="margin:0;">Bulk Container Import</h2>
        <a href="container_dashboard.php" class="btn btn-default"><i class="fa fa-arrow-left"></i> Back</a>
    </div>

    <!-- Step 1: Paste -->
    <div class="bi-card">
        <h4><span style="background:#667eea;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;margin-right:8px;">1</span>Paste from Google Sheets</h4>
        <p style="font-size:13px;color:#6b7280;margin:0 0 10px;">
            In your dock sheet, select your data range (with or without the header row), copy it
            <kbd style="font-size:11px;padding:2px 5px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;">Ctrl+C</kbd>,
            then paste it below <kbd style="font-size:11px;padding:2px 5px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;">Ctrl+V</kbd>.
        </p>
        <textarea id="pasteArea" placeholder="Paste your Google Sheet data here&#10;&#10;Example:&#10;Container #&#9;Customer&#9;Date&#9;Carrier&#10;ABCU1234567&#9;ACME Corp&#9;07/01/2026&#9;XPO"></textarea>
        <p class="bi-hint">Google Sheets copies as tab-separated values. Headers are auto-detected.</p>
        <div style="display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;">
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;margin-right:8px;">Default type:</label>
                <select id="defaultType" style="height:34px;border:1px solid #e1e4e8;border-radius:6px;font-size:13px;padding:0 8px;">
                    <option value="inbound" selected>Inbound</option>
                    <option value="outbound">Outbound</option>
                </select>
            </div>
            <label style="font-size:13px;color:#374151;cursor:pointer;display:flex;align-items:center;gap:6px;">
                <input type="checkbox" id="hasHeaders" checked> First row is headers
            </label>
            <button type="button" class="btn btn-primary" id="parseBtn">
                <i class="fa fa-eye"></i> Preview
            </button>
        </div>
    </div>

    <!-- Step 2: Column mapping (hidden until parsed) -->
    <div class="bi-card" id="mappingCard" style="display:none;">
        <h4><span style="background:#667eea;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;margin-right:8px;">2</span>Map Columns</h4>
        <p style="font-size:13px;color:#6b7280;margin:0 0 14px;">Tell us which column in your sheet maps to each field. Unneeded fields can stay as "— skip —".</p>
        <div class="mapping-grid" id="mappingGrid"></div>
        <button type="button" class="btn btn-default btn-sm" id="reparseBtn"><i class="fa fa-refresh"></i> Re-preview with this mapping</button>
    </div>

    <!-- Step 3: Preview table -->
    <div class="bi-card" id="previewCard" style="display:none;">
        <h4><span style="background:#667eea;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;margin-right:8px;">3</span>Preview &amp; Import</h4>
        <div id="previewSummary" style="margin-bottom:14px;font-size:13px;"></div>
        <div style="overflow-x:auto;">
            <table id="previewTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Container Number</th>
                        <th>Type</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Carrier</th>
                        <th>PO / BOL</th>
                        <th>Seal</th>
                        <th>Shipment #</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="previewBody"></tbody>
            </table>
        </div>
        <hr class="bi-section-divider">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <button type="button" class="btn btn-success btn-lg" id="importBtn" disabled>
                <i class="fa fa-upload"></i> Import <span id="importCount">0</span> Containers
            </button>
            <div id="importProgress" style="display:none;font-size:13px;color:#6b7280;"><i class="fa fa-spinner fa-spin"></i> Importing...</div>
        </div>
        <div id="importResults" style="margin-top:16px;"></div>
    </div>

</div>
</div>
</div>

<script>
(function () {
    'use strict';

    var CSRF      = '<?php echo $csrf; ?>';
    var BASE_URL  = '<?php echo $us_url_root; ?>';
    var CUSTOMERS = <?php echo json_encode($customer_names); ?>;
    var customerSet = {};
    CUSTOMERS.forEach(function(n) { customerSet[n.toLowerCase().trim()] = n; });

    // ── TSV parser ────────────────────────────────────────────────────────
    // Handles Google Sheets paste: tab-delimited, newline-separated,
    // with optional RFC-4180 quoting for cells that contain special chars.
    function parseTSV(text) {
        var rows = [];
        var lines = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
        lines.forEach(function(line) {
            if (line.trim() === '') return;
            var cells = [], i = 0, len = line.length;
            while (i < len) {
                if (line[i] === '"') {
                    var j = i + 1, cell = '';
                    while (j < len) {
                        if (line[j] === '"' && line[j+1] === '"') { cell += '"'; j += 2; }
                        else if (line[j] === '"') { j++; break; }
                        else { cell += line[j++]; }
                    }
                    cells.push(cell);
                    if (line[j] === '\t') j++;
                    i = j;
                } else {
                    var end = line.indexOf('\t', i);
                    if (end === -1) end = len;
                    cells.push(line.substring(i, end));
                    i = end + 1;
                }
            }
            rows.push(cells);
        });
        return rows;
    }

    // ── Column auto-detection ─────────────────────────────────────────────
    var FIELD_PATTERNS = {
        container_number:  /container|cont\s*#?|cntr/i,
        customer_name:     /customer|client|company|account/i,
        type:              /type|direction|inbound|outbound/i,
        receipt_ship_date: /date|arrival|ship\s*date|receipt/i,
        carrier:           /carrier|trucking|trucker|driver/i,
        po_bol_number:     /^po|bol|p\.?o\.?|bill\s*of\s*lading/i,
        seal_number:       /seal/i,
        shipment_number:   /shipment|ship\s*#|ship\s*num/i,
        piece_count:       /piece|pallet|count|qty|quantity|pcs/i,
    };

    function detectColumns(headers) {
        var mapping = {};
        Object.keys(FIELD_PATTERNS).forEach(function(field) { mapping[field] = -1; });
        headers.forEach(function(h, idx) {
            Object.keys(FIELD_PATTERNS).forEach(function(field) {
                if (mapping[field] === -1 && FIELD_PATTERNS[field].test(h.trim())) {
                    mapping[field] = idx;
                }
            });
        });
        return mapping;
    }

    // ── State ─────────────────────────────────────────────────────────────
    var parsedRows  = [];   // raw TSV rows (excluding header if present)
    var colHeaders  = [];   // header labels (or Col A, Col B, … if no headers)
    var colCount    = 0;
    var mapping     = {};

    // ── Parse button ──────────────────────────────────────────────────────
    document.getElementById('parseBtn').addEventListener('click', doParse);

    function doParse() {
        var text = document.getElementById('pasteArea').value.trim();
        if (!text) { alert('Paste some data first.'); return; }

        var allRows   = parseTSV(text);
        if (allRows.length === 0) { alert('Could not parse any rows.'); return; }

        var hasHeaders = document.getElementById('hasHeaders').checked;
        colCount = Math.max.apply(null, allRows.map(function(r) { return r.length; }));

        if (hasHeaders && allRows.length > 0) {
            colHeaders  = allRows[0];
            parsedRows  = allRows.slice(1);
        } else {
            colHeaders  = Array.from({length: colCount}, function(_, i) {
                return 'Column ' + (String.fromCharCode(65 + i));
            });
            parsedRows  = allRows;
        }

        // Pad short rows
        parsedRows = parsedRows.map(function(r) {
            while (r.length < colCount) r.push('');
            return r;
        });

        mapping = detectColumns(colHeaders);
        buildMappingUI();
        buildPreview();
        document.getElementById('mappingCard').style.display = 'block';
        document.getElementById('previewCard').style.display = 'block';
    }

    document.getElementById('reparseBtn').addEventListener('click', function() {
        readMappingUI();
        buildPreview();
    });

    // ── Mapping UI ────────────────────────────────────────────────────────
    var FIELD_LABELS = {
        container_number:  'Container Number *',
        customer_name:     'Client / Customer',
        type:              'Type (inbound/outbound)',
        receipt_ship_date: 'Date',
        carrier:           'Carrier',
        po_bol_number:     'PO / BOL Number',
        seal_number:       'Seal Number',
        shipment_number:   'Shipment Number',
        piece_count:       'Piece / Pallet Count',
    };

    function buildMappingUI() {
        var grid = document.getElementById('mappingGrid');
        grid.innerHTML = '';
        Object.keys(FIELD_LABELS).forEach(function(field) {
            var div = document.createElement('div');
            div.className = 'mapping-item';
            var label = document.createElement('label');
            label.textContent = FIELD_LABELS[field];
            var sel = document.createElement('select');
            sel.id = 'map_' + field;
            sel.innerHTML = '<option value="-1">— skip —</option>';
            colHeaders.forEach(function(h, idx) {
                var opt = document.createElement('option');
                opt.value = idx;
                opt.textContent = h || ('Column ' + (idx + 1));
                if (mapping[field] === idx) opt.selected = true;
                sel.appendChild(opt);
            });
            div.appendChild(label);
            div.appendChild(sel);
            grid.appendChild(div);
        });
    }

    function readMappingUI() {
        Object.keys(FIELD_LABELS).forEach(function(field) {
            var el = document.getElementById('map_' + field);
            if (el) mapping[field] = parseInt(el.value, 10);
        });
    }

    // ── Preview ───────────────────────────────────────────────────────────
    function cellVal(row, field) {
        var idx = mapping[field];
        if (idx === undefined || idx < 0 || idx >= row.length) return '';
        return (row[idx] || '').trim();
    }

    function buildPreview() {
        var defaultType = document.getElementById('defaultType').value;
        var tbody = document.getElementById('previewBody');
        tbody.innerHTML = '';

        var okCount = 0, warnCount = 0, errCount = 0;

        parsedRows.forEach(function(row, idx) {
            var num   = cellVal(row, 'container_number').toUpperCase();
            var type  = cellVal(row, 'type').toLowerCase();
            if (!['inbound','outbound'].includes(type)) type = defaultType;

            var cname = cellVal(row, 'customer_name');
            var date  = cellVal(row, 'receipt_ship_date');
            var carr  = cellVal(row, 'carrier');
            var po    = cellVal(row, 'po_bol_number');
            var seal  = cellVal(row, 'seal_number');
            var ship  = cellVal(row, 'shipment_number');

            var rowClass = 'row-ok', statusHtml = '<span class="bi-badge bi-badge-ok">Ready</span>';
            var notes = [];

            if (!num) {
                rowClass  = 'row-err';
                statusHtml = '<span class="bi-badge bi-badge-err">No container #</span>';
                errCount++;
            } else {
                if (cname && !customerSet[cname.toLowerCase()]) {
                    notes.push('Client "' + escHtml(cname) + '" not found — will import without client');
                    rowClass   = 'row-warn';
                    statusHtml = '<span class="bi-badge bi-badge-warn">Client unknown</span>';
                    warnCount++;
                } else {
                    okCount++;
                }
            }

            var tr = document.createElement('tr');
            tr.className = rowClass;
            tr.innerHTML =
                '<td>' + (idx + 1) + '</td>' +
                '<td><strong style="font-family:monospace;">' + escHtml(num || '—') + '</strong></td>' +
                '<td>' + ucFirst(type) + '</td>' +
                '<td>' + escHtml(cname || '—') + '</td>' +
                '<td>' + escHtml(date  || '—') + '</td>' +
                '<td>' + escHtml(carr  || '—') + '</td>' +
                '<td>' + escHtml(po    || '—') + '</td>' +
                '<td>' + escHtml(seal  || '—') + '</td>' +
                '<td>' + escHtml(ship  || '—') + '</td>' +
                '<td>' + statusHtml + (notes.length ? '<br><small style="color:#6b7280;">' + notes.join('<br>') + '</small>' : '') + '</td>';
            tbody.appendChild(tr);
        });

        var total = okCount + warnCount;
        document.getElementById('previewSummary').innerHTML =
            '<strong>' + parsedRows.length + '</strong> rows parsed &nbsp;·&nbsp; ' +
            '<span style="color:#10b981;font-weight:600;">' + okCount + ' ready</span> &nbsp;·&nbsp; ' +
            (warnCount ? '<span style="color:#f59e0b;font-weight:600;">' + warnCount + ' with warnings</span> &nbsp;·&nbsp; ' : '') +
            (errCount  ? '<span style="color:#ef4444;font-weight:600;">' + errCount  + ' will be skipped</span>' : '');

        var importBtn = document.getElementById('importBtn');
        document.getElementById('importCount').textContent = total;
        importBtn.disabled = total === 0;
    }

    // ── Import ────────────────────────────────────────────────────────────
    document.getElementById('importBtn').addEventListener('click', function() {
        var defaultType = document.getElementById('defaultType').value;
        var toImport = [];

        parsedRows.forEach(function(row) {
            var num = cellVal(row, 'container_number').toUpperCase();
            if (!num) return;
            var type = cellVal(row, 'type').toLowerCase();
            if (!['inbound','outbound'].includes(type)) type = defaultType;
            toImport.push({
                container_number:  num,
                type:              type,
                customer_name:     cellVal(row, 'customer_name'),
                receipt_ship_date: cellVal(row, 'receipt_ship_date'),
                carrier:           cellVal(row, 'carrier'),
                po_bol_number:     cellVal(row, 'po_bol_number'),
                seal_number:       cellVal(row, 'seal_number'),
                shipment_number:   cellVal(row, 'shipment_number'),
                piece_count:       cellVal(row, 'piece_count'),
            });
        });

        if (toImport.length === 0) { alert('Nothing valid to import.'); return; }
        if (!confirm('Import ' + toImport.length + ' container(s)?')) return;

        document.getElementById('importBtn').disabled = true;
        document.getElementById('importProgress').style.display = 'inline-block';
        document.getElementById('importResults').innerHTML = '';

        var fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('rows', JSON.stringify(toImport));

        fetch(BASE_URL + 'usersc/ajax/container_bulk_import.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                document.getElementById('importProgress').style.display = 'none';
                document.getElementById('importBtn').disabled = false;

                var html = '<div style="margin-bottom:12px;">';
                if (resp.created > 0) {
                    html += '<div class="alert alert-success"><i class="fa fa-check-circle"></i> <strong>' + resp.created + ' container' + (resp.created !== 1 ? 's' : '') + ' created successfully.</strong>';
                    if (resp.skipped > 0) html += ' ' + resp.skipped + ' skipped.';
                    html += ' <a href="container_dashboard.php" class="btn btn-default btn-sm" style="margin-left:10px;">Go to Dashboard</a></div>';
                }
                if (resp.skipped > 0 && resp.created === 0) {
                    html += '<div class="alert alert-danger">No containers were created. ' + resp.skipped + ' rows had errors.</div>';
                }
                html += '</div>';

                // Per-row results table
                html += '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                html += '<thead><tr style="background:#f8f9fa;"><th style="padding:6px 10px;text-align:left;">Row</th><th style="padding:6px 10px;text-align:left;">Container</th><th style="padding:6px 10px;text-align:left;">Result</th></tr></thead><tbody>';
                (resp.results || []).forEach(function(r) {
                    var cls = r.success ? 'result-row-ok' : 'result-row-err';
                    var icon = r.success ? '✓' : '✗';
                    var msg  = r.success ? ('Created' + (r.customer_matched === false && r.customer_matched !== undefined ? ' (client not matched)' : '')) : (r.reason || 'Error');
                    html += '<tr class="' + cls + '">' +
                            '<td style="padding:6px 10px;">' + r.row + '</td>' +
                            '<td style="padding:6px 10px;font-family:monospace;font-weight:600;">' + escHtml(r.container_number || '—') + '</td>' +
                            '<td style="padding:6px 10px;">' + icon + ' ' + escHtml(msg) + '</td>' +
                            '</tr>';
                });
                html += '</tbody></table>';
                document.getElementById('importResults').innerHTML = html;
            })
            .catch(function(err) {
                document.getElementById('importProgress').style.display = 'none';
                document.getElementById('importBtn').disabled = false;
                document.getElementById('importResults').innerHTML =
                    '<div class="alert alert-danger">Request failed: ' + escHtml(err.message) + '</div>';
            });
    });

    // ── Helpers ───────────────────────────────────────────────────────────
    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function ucFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

})();
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
