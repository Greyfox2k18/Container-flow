<?php
/**
 * SKU Lot / Expiration Scanner — combined container export
 * -------------------------------------------------------------
 * Exports every lot scanned across every SKU session tied to one
 * container into a single .xlsx, grouped by SKU. Bare endpoint (no
 * template include) for the same reason as sku_scan_export.php and
 * placard_generate_pdf.php — file-download headers need to go out
 * before any template HTML does.
 * -------------------------------------------------------------
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/sku_scan_functions.php';

if (!$user->isLoggedIn()) {
    die('Not authenticated.');
}

if (!Token::check(Input::get('csrf'))) {
    die('Invalid or expired link — go back to the container page and click Export again.');
}

$container_id = (int) Input::get('container_id');
$container    = $container_id ? getContainerById($container_id) : null;
if (!$container) {
    die('Container not found.');
}

$customer = $container->customer_id ? getCustomerById($container->customer_id) : null;
$lots     = getScanLotsByContainer($container_id);

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
    die('Could not locate vendor/autoload.php - update $autoloadCandidates in sku_scan_export_container.php');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

if (headers_sent($file, $line)) {
    die("Output already started at $file:$line - something before this point in the include chain is echoing content.");
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Container Scan');

$sheet->setCellValue('A1', 'Container');
$sheet->setCellValue('B1', $container->container_number);
$sheet->setCellValue('A2', 'Client');
$sheet->setCellValue('B2', $customer ? $customer->name : '—');
$sheet->setCellValue('A3', 'Exported');
$sheet->setCellValue('B3', date('Y-m-d H:i:s'));
$sheet->getStyle('A1:A3')->getFont()->setBold(true);

$headerRow = 5;
$headers = ['SKU', 'Lot Number', 'Expiration Date', 'Expiration (as scanned)', 'Quantity', 'Scanned At'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $sheet->getStyle($col . $headerRow)->getFont()->setBold(true);
    $col++;
}

$row = $headerRow + 1;
$totalQty = 0;
foreach ($lots as $lot) {
    $sheet->setCellValue('A' . $row, $lot->sku);
    $sheet->setCellValue('B' . $row, $lot->lot_number);

    if ($lot->expiration_date) {
        $sheet->setCellValue('C' . $row, PhpSpreadsheetDate::PHPToExcel(new \DateTime($lot->expiration_date)));
        $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    } else {
        $sheet->setCellValue('C' . $row, '');
    }

    $sheet->setCellValue('D' . $row, (string) $lot->expiration_raw);
    $sheet->setCellValue('E' . $row, (int) $lot->quantity);
    $sheet->setCellValue('F' . $row, $lot->scanned_at);
    $totalQty += (int) $lot->quantity;
    $row++;
}

$sheet->setCellValue('D' . $row, 'Total Qty:');
$sheet->getStyle('D' . $row)->getFont()->setBold(true);
$sheet->setCellValue('E' . $row, $totalQty);

foreach (range('A', 'F') as $c) {
    $sheet->getColumnDimension($c)->setAutoSize(true);
}

$containerSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $container->container_number);
$filename = 'container_scan_' . $containerSafe . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
