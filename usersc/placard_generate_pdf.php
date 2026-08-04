<?php
/**
 * Greensource Placard PDF Generator - bare endpoint
 * -------------------------------------------------------------
 * IMPORTANT: This file deliberately does NOT include prep.php or any
 * template loader. UserSpice's template system echoes the site
 * header/nav HTML the moment it's included, which "uses up" the
 * response and makes it impossible to send file-download headers
 * for the PDF afterward. This file only needs session + DB access,
 * so it loads init.php alone, same pattern as your other AJAX/data
 * handlers that run ahead of prep.php.
 * -------------------------------------------------------------
 */

// ---- ADJUST ME if needed: UserSpice bootstrap (session/db only, no template) ----
require_once '../users/init.php';
// checkPerm('...') here if this tool should be permission gated.

// ---- ADJUST ME if needed: Composer autoload path ----
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
    die('Could not locate vendor/autoload.php - update $autoloadCandidates in placard_generate_pdf.php');
}

$CLIENT_NAMES = [
    'GRESOU' => 'Greensource',
];

$selected = $_POST['selected'] ?? [];
$orders = $_SESSION['placard_orders'] ?? [];

if (empty($selected) || empty($orders)) {
    die('No orders selected, or your session expired. Please go back, re-upload the file, and try again.');
}

if (headers_sent($file, $line)) {
    die("Output already started at $file:$line - something before this point in the include chain is echoing content.");
}

$mpdf = new \Mpdf\Mpdf([
    'format'        => 'Letter-L', // Letter landscape
    'margin_top'    => 10,
    'margin_bottom' => 10,
    'margin_left'   => 12,
    'margin_right'  => 12,
]);

$firstPage = true;

foreach ($selected as $idx => $palletCountRaw) {
    $idx = (int)$idx;
    $palletCount = max(1, (int)$palletCountRaw);

    if (!isset($orders[$idx])) {
        continue;
    }
    $order = $orders[$idx];
    $clientDisplay = $CLIENT_NAMES[$order['client']] ?? $order['client'];

    for ($p = 1; $p <= $palletCount; $p++) {
        if (!$firstPage) {
            $mpdf->AddPage();
        }
        $firstPage = false;

        $html = render_placard_html($clientDisplay, $order, $p, $palletCount);
        $mpdf->WriteHTML($html);
    }
}

$mpdf->Output('placards_' . date('Ymd_His') . '.pdf', 'D'); // force download
exit;

/**
 * Builds the HTML for a single placard page.
 */
function render_placard_html(string $clientDisplay, array $order, int $palletNum, int $palletTotal): string
{
    $document = htmlspecialchars($order['document']);
    $shipment = htmlspecialchars($order['shipment']);
    $state    = htmlspecialchars($order['state']);
    $carrier  = htmlspecialchars($order['carrier']);
    $dateShip = htmlspecialchars($order['date_ship']);
    $clientDisplay = htmlspecialchars($clientDisplay);

    return <<<HTML
    <style>
        .placard-outer {
            border: 3px solid #7f8fa6;
            border-radius: 6px;
            width: 100%;
            height: 185mm;
            border-collapse: collapse;
        }
        .placard-inner {
            text-align: center;
            vertical-align: middle;
        }
        .client-name {
            font-size: 48pt;
            font-weight: bold;
            text-align: center;
        }
        .state-box {
            border: 4px solid #000;
            width: 160px;
            height: 110px;
            text-align: center;
            vertical-align: middle;
            font-size: 52pt;
            font-weight: bold;
            margin: 14px auto;
        }
        .order-line {
            font-size: 90pt;
            font-weight: bold;
            border-bottom: 4px solid #000;
            padding: 10px 0;
            margin: 18px auto;
            text-align: center;
            display: table;
            margin-left: auto;
            margin-right: auto;
        }
        .shipment-line {
            font-size: 34pt;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
            margin: 10px auto;
            text-align: center;
            display: table;
        }
        .meta-line {
            font-size: 28pt;
            margin: 8px auto;
            text-align: center;
        }
        .pallet-line {
            font-size: 46pt;
            font-weight: bold;
            text-align: center;
            margin-top: 28px;
        }
    </style>
    <table class="placard-outer">
        <tr>
            <td class="placard-inner">
                <div class="client-name">{$clientDisplay}</div>
                <table class="state-box" align="center"><tr><td align="center">{$state}</td></tr></table>

                <table class="order-line" align="center"><tr><td>ORDER: {$document}</td></tr></table>
                <table class="shipment-line" align="center"><tr><td>SHIPMENT: {$shipment}</td></tr></table>

                <div class="meta-line">CARRIER: {$carrier}</div>
                <div class="meta-line">DATE TO SHIP: {$dateShip}</div>

                <div class="pallet-line">Pallet {$palletNum} of {$palletTotal}</div>
            </td>
        </tr>
    </table>
HTML;
}
