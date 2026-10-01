<?php
/**
 * SKU Lot / Expiration Scanner — Excel export
 * -------------------------------------------------------------
 * Deliberately does NOT include prep.php / the template loader — same
 * reasoning as placard_generate_pdf.php: UserSpice's template echoes
 * header HTML immediately, which makes it impossible to send file-
 * download headers afterward. This only needs session + DB access.
 * -------------------------------------------------------------
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/sku_scan_functions.php';

if (!$user->isLoggedIn()) {
    die('Not authenticated.');
}

if (!Token::check(Input::get('csrf'))) {
    die('Invalid or expired link — go back to the scan page and click Export again.');
}

$session_id = (int) Input::get('session_id');
$session    = $session_id ? getScanSession($session_id) : null;
if (!$session) {
    die('Session not found.');
}

$lots = getScanLots($session_id);

// ---- ADJUST ME if needed: Composer autoload path (same search pattern as placard_greensource.php) ----
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
    die('Could not locate vendor/autoload.php - update $autoloadCandidates in sku_scan_export.php');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

if (headers_sent($file, $line)) {
    die("Output already started at $file:$line - something before this point in the include chain is echoing content.");
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('SKU Scan');

$sheet->setCellValue('A1', 'SKU');
$sheet->setCellValue('B1', $session->sku);
$sheet->setCellValue('A2', 'Exported');
$sheet->setCellValue('B2', date('Y-m-d H:i:s'));
$sheet->getStyle('A1:A2')->getFont()->setBold(true);

$headerRow = 4;
$headers = ['Lot Number', 'Expiration Date', 'Expiration (as scanned)', 'Quantity', 'Scanned At'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $sheet->getStyle($col . $headerRow)->getFont()->setBold(true);
    $col++;
}

$row = $headerRow + 1;
$totalQty = 0;
foreach ($lots as $lot) {
    $sheet->setCellValue('A' . $row, $lot->lot_number);

    if ($lot->expiration_date) {
        // Store as a real Excel date serial so the column sorts/filters correctly.
        $sheet->setCellValue('B' . $row, PhpSpreadsheetDate::PHPToExcel(new \DateTime($lot->expiration_date)));
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    } else {
        $sheet->setCellValue('B' . $row, '');
    }

    $sheet->setCellValue('C' . $row, (string) $lot->expiration_raw);
    $sheet->setCellValue('D' . $row, (int) $lot->quantity);
    $sheet->setCellValue('E' . $row, $lot->scanned_at);
    $totalQty += (int) $lot->quantity;
    $row++;
}

$sheet->setCellValue('C' . $row, 'Total Qty:');
$sheet->getStyle('C' . $row)->getFont()->setBold(true);
$sheet->setCellValue('D' . $row, $totalQty);

foreach (range('A', 'E') as $c) {
    $sheet->getColumnDimension($c)->setAutoSize(true);
}

$skuSafe  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $session->sku);
$filename = 'sku_scan_' . $skuSafe . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
