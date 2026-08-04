<?php
/**
 * Greensource Placard Generator - page UI
 * -------------------------------------------------------------
 * This is a normal dashboard page (uses prep.php/template like your
 * other pages). PDF generation itself happens in placard_generate_pdf.php,
 * a bare endpoint with no template include - see the comment in that
 * file for why the split is necessary.
 *
 * Workflow:
 *   - Upload the "Shipments" xlsx export
 *   - Rows get parsed into $_SESSION so nothing but small selections
 *     travel back over the wire
 *   - Filter/search the table, tick the orders you want, enter how
 *     many pallets each one needs
 *   - Submit -> POSTs to placard_generate_pdf.php, which streams back
 *     one 8.5x11 landscape PDF, one placard page per pallet
 * -------------------------------------------------------------
 */

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';
// checkPerm('...') here if this tool should be permission gated.

// ---- ADJUST ME if needed: Composer autoload path (only needed here for the xlsx parse step) ----
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
];
$autoloaded = false;
foreach ($autoloadCandidates as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaded = true;
        break;
    }
}
if (!$autoloaded) {
    die('Could not locate vendor/autoload.php - update $autoloadCandidates in placard_greensource.php');
}

use PhpOffice\PhpSpreadsheet\IOFactory;

$action = $_POST['action'] ?? '';

// -------------------------------------------------------------
// Handle xlsx upload -> parse -> stash in session
// -------------------------------------------------------------
$uploadError = '';
if ($action === 'upload' && isset($_FILES['shipments_file']) && $_FILES['shipments_file']['error'] === UPLOAD_ERR_OK) {
    $tmpPath = $_FILES['shipments_file']['tmp_name'];
    try {
        $spreadsheet = IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);

        if (count($rows) < 2) {
            throw new Exception('No data rows found in the uploaded file.');
        }

        $header = array_map('trim', $rows[0]);
        $colIndex = array_flip($header);

        $required = ['Document', 'Header Reference', 'Client', 'Ship To State', 'Header User 1', 'Date to Ship', 'Status'];
        foreach ($required as $col) {
            if (!isset($colIndex[$col])) {
                throw new Exception("Expected column '$col' not found in the uploaded file.");
            }
        }

        $orders = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $document = trim((string)($row[$colIndex['Document']] ?? ''));
            if ($document === '') {
                continue; // skip blank rows
            }

            $dateToShip = $row[$colIndex['Date to Ship']] ?? '';
            $dateToShipFormatted = '';
            if (!empty($dateToShip)) {
                if ($dateToShip instanceof \DateTimeInterface) {
                    $dateToShipFormatted = $dateToShip->format('m/d/Y');
                } elseif (is_numeric($dateToShip)) {
                    try {
                        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateToShip);
                        $dateToShipFormatted = $dt->format('m/d/Y');
                    } catch (Exception $e) {
                        $dateToShipFormatted = (string)$dateToShip;
                    }
                } else {
                    $dateToShipFormatted = (string)$dateToShip;
                }
            }

            $clientCode = trim((string)($row[$colIndex['Client']] ?? ''));

            $orders[] = [
                'document'   => $document,
                'shipment'   => trim((string)($row[$colIndex['Header Reference']] ?? '')),
                'client'     => $clientCode,
                'state'      => trim((string)($row[$colIndex['Ship To State']] ?? '')),
                'carrier'    => trim((string)($row[$colIndex['Header User 1']] ?? '')),
                'date_ship'  => $dateToShipFormatted,
                'status'     => trim((string)($row[$colIndex['Status']] ?? '')),
            ];
        }

        if (empty($orders)) {
            throw new Exception('No usable order rows were parsed from the file.');
        }

        $_SESSION['placard_orders'] = $orders;
        $_SESSION['placard_uploaded_at'] = date('Y-m-d H:i:s');
    } catch (Exception $e) {
        $uploadError = 'Error parsing file: ' . $e->getMessage();
    }
} elseif ($action === 'upload') {
    $uploadError = 'No file was uploaded or the upload failed. Please try again.';
}

$orders = $_SESSION['placard_orders'] ?? [];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Greensource Placard Generator</title>
    <style>
        table.orders { border-collapse: collapse; width: 100%; margin-top: 15px; }
        table.orders th, table.orders td {
            border: 1px solid #ccc; padding: 6px 8px; font-size: 14px; text-align: left;
        }
        table.orders th { background: #f0f0f0; position: sticky; top: 0; }
        .pallet-input { width: 55px; }
        .toolbar { margin: 10px 0; display: flex; gap: 10px; align-items: center; }
        .error { color: #b00020; font-weight: bold; }
        .status-CANCELLED { color: #b00020; }
    </style>
</head>
<body>

<h2>Greensource Placard Generator</h2>

<?php if ($uploadError): ?>
    <p class="error"><?= htmlspecialchars($uploadError) ?></p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload">
    <label>Shipments export (.xlsx): </label>
    <input type="file" name="shipments_file" accept=".xlsx" required>
    <input type="submit" value="Upload & Parse">
</form>

<?php if (!empty($orders)): ?>
    <p><em>Loaded <?= count($orders) ?> orders (uploaded <?= htmlspecialchars($_SESSION['placard_uploaded_at'] ?? '') ?>)</em></p>

    <div class="toolbar">
        <input type="text" id="filterBox" placeholder="Filter by document, shipment, carrier, state, status..." style="width:320px;">
        <button type="button" onclick="selectAll(true)">Select All (visible)</button>
        <button type="button" onclick="selectAll(false)">Clear Selection</button>
    </div>

    <!-- Posts to the bare PDF endpoint, not this page -->
    <form method="post" id="pdfForm" action="placard_generate_pdf.php">
        <table class="orders" id="ordersTable">
            <thead>
                <tr>
                    <th></th>
                    <th>Document</th>
                    <th>Shipment (Header Ref)</th>
                    <th>State</th>
                    <th>Carrier</th>
                    <th>Date to Ship</th>
                    <th>Status</th>
                    <th># Pallets</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $idx => $o): ?>
                <tr class="order-row status-<?= htmlspecialchars($o['status']) ?>">
                    <td><input type="checkbox" class="row-check" data-idx="<?= $idx ?>"></td>
                    <td><?= htmlspecialchars($o['document']) ?></td>
                    <td><?= htmlspecialchars($o['shipment']) ?></td>
                    <td><?= htmlspecialchars($o['state']) ?></td>
                    <td><?= htmlspecialchars($o['carrier']) ?></td>
                    <td><?= htmlspecialchars($o['date_ship']) ?></td>
                    <td><?= htmlspecialchars($o['status']) ?></td>
                    <td>
                        <input type="number" min="1" value="1" class="pallet-input pallet-count" data-idx="<?= $idx ?>" disabled>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="toolbar">
            <input type="submit" value="Generate PDF Placards">
        </div>
    </form>
<?php endif; ?>

<script>
document.getElementById('filterBox')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#ordersTable tbody tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

document.querySelectorAll('.row-check').forEach(function (cb) {
    cb.addEventListener('change', function () {
        const idx = this.dataset.idx;
        const palletInput = document.querySelector('.pallet-count[data-idx="' + idx + '"]');
        palletInput.disabled = !this.checked;
    });
});

function selectAll(state) {
    document.querySelectorAll('#ordersTable tbody tr').forEach(function (row) {
        if (row.style.display === 'none') return;
        const cb = row.querySelector('.row-check');
        const palletInput = row.querySelector('.pallet-count');
        cb.checked = state;
        palletInput.disabled = !state;
    });
}

document.getElementById('pdfForm')?.addEventListener('submit', function () {
    document.querySelectorAll('.row-check').forEach(function (cb) {
        const idx = cb.dataset.idx;
        const palletInput = document.querySelector('.pallet-count[data-idx="' + idx + '"]');
        if (cb.checked) {
            palletInput.name = 'selected[' + idx + ']';
        } else {
            palletInput.removeAttribute('name');
        }
    });
});
</script>

</body>
</html>
