<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$user_id = $user->data()->id;
$is_supervisor = isSupervisor();

$container_id = Input::get('id');
if (!$container_id) {
    die('Container ID required');
}

$container = getContainerById($container_id);
if (!$container) {
    die('Container not found');
}

// Check permissions
// Anyone with floor worker or supervisor permission can download any report
if (!isFloorWorker()) {
    die('Access denied');
}

$photos = getContainerPhotos($container_id);

// Get creator info
$db = DB::getInstance();
$creator = $db->query("SELECT fname, lname FROM users WHERE id = ?", [$container->created_by])->first();

// Get customer/client info
$customer = $container->customer_id ? getCustomerById($container->customer_id) : null;

// Group photos by type
global $photo_types;
$photos_by_type = [];
foreach ($photos as $photo) {
    $photos_by_type[$photo->photo_type][] = $photo;
}

// Set headers for download
$filename = 'Container_' . $container->container_number . '_Report.html';
header('Content-Type: text/html');
header('Content-Disposition: attachment; filename="' . $filename . '"');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Container Report - <?php echo htmlspecialchars($container->container_number); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container-report {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .info-table {
            width: 100%;
            margin: 20px 0;
            border-collapse: collapse;
        }
        .info-table th {
            background: #f8f9fa;
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
            width: 30%;
        }
        .info-table td {
            padding: 10px;
            border: 1px solid #ddd;
        }
        .photo-section {
            margin: 30px 0;
            page-break-inside: avoid;
        }
        .photo-section h2 {
            color: #007bff;
            border-bottom: 2px solid #007bff;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .photo-item {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            background: #fafafa;
        }
        .photo-item img {
            width: 100%;
            height: auto;
            border-radius: 4px;
            display: block;
        }
        .photo-caption {
            margin-top: 8px;
            font-size: 12px;
            color: #666;
        }
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: bold;
        }
        .badge-inbound { background: #d1ecf1; color: #0c5460; }
        .badge-outbound { background: #d4edda; color: #155724; }
        .notes-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
        @media print {
            body { background: white; }
            .container-report { box-shadow: none; }
        }
        @media (max-width: 600px) {
            .photo-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container-report">
        <h1>Container Report</h1>
        
        <table class="info-table">
            <tr>
                <th>Container Number</th>
                <td><strong><?php echo htmlspecialchars($container->container_number); ?></strong></td>
            </tr>
            <tr>
                <th>Client</th>
                <td><?php echo $customer ? htmlspecialchars($customer->name) : 'N/A'; ?></td>
            </tr>
            <tr>
                <th>Shipment Number</th>
                <td><?php echo htmlspecialchars($container->shipment_number ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <th><?php echo getDateFieldLabel($container->type); ?></th>
                <td><?php echo $container->receipt_ship_date ? date('F d, Y', strtotime($container->receipt_ship_date)) : 'N/A'; ?></td>
            </tr>
            <tr>
                <th>Seal Number</th>
                <td><?php echo htmlspecialchars($container->seal_number ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <th>PO / BOL Number</th>
                <td><?php echo htmlspecialchars($container->po_bol_number ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <th>Carrier</th>
                <td><?php echo htmlspecialchars($container->carrier ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <th>Piece / Pallet Count</th>
                <td><?php echo $container->piece_count !== null ? number_format($container->piece_count) : 'N/A'; ?></td>
            </tr>
            <tr>
                <th>Type</th>
                <td>
                    <span class="badge badge-<?php echo $container->type; ?>">
                        <?php echo strtoupper($container->type); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Status</th>
                <td><?php echo ucwords(str_replace('_', ' ', $container->status)); ?></td>
            </tr>
            <tr>
                <th>Created By</th>
                <td><?php echo $creator ? htmlspecialchars($creator->fname . ' ' . $creator->lname) : 'N/A'; ?></td>
            </tr>
            <tr>
                <th>Created</th>
                <td><?php echo date('F d, Y \a\t g:i A', strtotime($container->created_at)); ?></td>
            </tr>
        </table>

        <?php if ($container->notes): ?>
        <div class="notes-box">
            <strong>Notes:</strong><br>
            <?php echo nl2br(htmlspecialchars($container->notes)); ?>
        </div>
        <?php endif; ?>

        <?php if (empty($photos)): ?>
        <p style="color: #999; text-align: center; padding: 40px;">No photos available</p>
        <?php else: ?>
            <?php foreach ($photo_types[$container->type] as $type_key => $type_label): ?>
                <?php if (isset($photos_by_type[$type_key])): ?>
                <div class="photo-section">
                    <h2><?php echo htmlspecialchars($type_label); ?></h2>
                    <div class="photo-grid">
                        <?php foreach ($photos_by_type[$type_key] as $photo): ?>
                        <div class="photo-item">
                            <?php
                            $image_path = $abs_us_root . $us_url_root . $photo->file_path;
                            if (file_exists($image_path)) {
                                $image_data = base64_encode(file_get_contents($image_path));
                                $image_type = pathinfo($photo->file_name, PATHINFO_EXTENSION);
                                $mime_type = 'image/' . ($image_type == 'jpg' ? 'jpeg' : $image_type);
                                ?>
                                <img src="data:<?php echo $mime_type; ?>;base64,<?php echo $image_data; ?>" 
                                     alt="<?php echo htmlspecialchars($type_label); ?>">
                                <?php
                            } else {
                                echo '<p style="color: red;">Image not found</p>';
                            }
                            ?>
                            <div class="photo-caption">
                                <strong>Uploaded:</strong> <?php echo date('M d, Y g:i A', strtotime($photo->uploaded_at)); ?><br>
                                <?php if ($photo->description): ?>
                                <strong>Note:</strong> <?php echo htmlspecialchars($photo->description); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <hr style="margin: 30px 0;">
        <p style="text-align: center; color: #999; font-size: 12px;">
            Generated on <?php echo date('F d, Y \a\t g:i A'); ?><br>
            Container Tracking System
        </p>
    </div>
</body>
</html>
<?php
exit;
?>
