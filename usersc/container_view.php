<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/sku_scan_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$user_id = $user->data()->id;
$is_supervisor = isSupervisor();

$container_id = Input::get('id');
if (!$container_id) {
    Redirect::to('container_dashboard.php');
}

$container = getContainerById($container_id);
if (!$container) {
    Redirect::to('container_dashboard.php');
}

// Anyone with floor worker or supervisor permission can view any container.
if (!isFloorWorker()) {
    Redirect::to('container_dashboard.php');
}

$photos = getContainerPhotos($container_id);
$photos_by_type = [];
foreach ($photos as $photo) {
    $photos_by_type[$photo->photo_type][] = $photo;
}

// Get creator info
$db = DB::getInstance();
$creator = $db->query("SELECT fname, lname, email FROM users WHERE id = ?", [$container->created_by])->first();

// Get customer/client info
$customer = $container->customer_id ? getCustomerById($container->customer_id) : null;

// SKU scan summary for this container (tool is optional/newer than this
// page, so tolerate the tables not existing yet on an install that
// hasn't opened sku_scan.php or customer_edit.php since it was added).
$scan_enabled_for_customer = $customer ? !empty($customer->sku_scan_enabled) : false;
$scan_summary = null;
try {
    $scan_summary = getScanSummaryForContainer($container_id);
} catch (\Throwable $e) {
    $scan_summary = null;
}

// Get activity log
$activity_log = $db->query("SELECT al.*, u.fname, u.lname FROM container_activity_log al 
                            LEFT JOIN users u ON al.user_id = u.id 
                            WHERE al.container_id = ? 
                            ORDER BY al.created_at DESC 
                            LIMIT 20", [$container_id])->results();

// Generate ONE csrf token for the whole page. Token::generate() overwrites
// the single token stored in session, so calling it more than once per
// page (one per form/JS call) invalidates the earlier ones' embedded tokens.
$csrf = Token::generate();
?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">
                    Container Details
                    <div class="pull-right">
                        <button type="button" id="openActionsBtn" class="btn btn-default">
                            <i class="fa fa-ellipsis-v"></i> Actions
                        </button>
                        <a href="container_dashboard.php" class="btn btn-default">
                            <i class="fa fa-arrow-left"></i> Back
                        </a>
                    </div>
                </h1>
            </div>
        </div>

        <!-- Container Information - front and center -->
        <div class="row">
            <div class="col-12">
                <div class="panel panel-default container-info-card">
                    <div class="panel-body" id="containerInfoPanelBody" data-container-id="<?php echo $container->id; ?>" data-status="<?php echo $container->status; ?>" data-is-supervisor="<?php echo $is_supervisor ? '1' : '0'; ?>">
                        <div class="container-info-top">
                            <div>
                                <div class="container-info-label">Container Number</div>
                                <div class="container-info-number"><?php echo htmlspecialchars($container->container_number); ?></div>
                            </div>
                            <div class="container-info-badges">
                                <span class="label label-<?php echo $container->type == 'inbound' ? 'info' : 'success'; ?> label-lg">
                                    <?php echo ucfirst($container->type); ?>
                                </span>
                                <?php
                                $status_class = [
                                    'pending' => 'warning',
                                    'in_progress' => 'info',
                                    'completed' => 'primary',
                                    'reviewed' => 'success'
                                ];
                                ?>
                                <span class="label label-<?php echo $status_class[$container->status]; ?> label-lg" id="statusBadge">
                                    <?php echo ucwords(str_replace('_', ' ', $container->status)); ?>
                                </span>
                            </div>
                        </div>

                        <div style="margin-top: 14px;">
                            <button type="button" id="nextStatusBtn" class="btn btn-primary">
                                <i class="fa fa-arrow-right"></i> <span id="nextStatusBtnLabel">Next Status</span>
                            </button>
                            <span id="nextStatusHint" style="font-size: 12.5px; color: #888; margin-left: 8px;"></span>
                        </div>

                        <div class="container-info-toggle" id="containerInfoToggle">
                            <i class="fa fa-chevron-down"></i> <span id="containerInfoToggleText">Show details</span>
                        </div>

                        <div class="container-info-details" id="containerInfoDetails">
                            <div class="row container-info-fields">
                                <div class="col-sm-3">
                                    <div class="container-info-label">Client</div>
                                    <div class="container-info-value"><?php echo $customer ? htmlspecialchars($customer->name) : '—'; ?></div>
                                </div>
                                <div class="col-sm-3">
                                    <div class="container-info-label">Shipment Number</div>
                                    <div class="container-info-value"><?php echo htmlspecialchars($container->shipment_number ?? '—'); ?></div>
                                </div>
                                <div class="col-sm-3">
                                    <div class="container-info-label"><?php echo getDateFieldLabel($container->type); ?></div>
                                    <div class="container-info-value"><?php echo $container->receipt_ship_date ? date('M d, Y', strtotime($container->receipt_ship_date)) : '—'; ?></div>
                                </div>
                                <div class="col-sm-3">
                                    <div class="container-info-label">Seal Number</div>
                                    <div class="container-info-value"><?php echo htmlspecialchars($container->seal_number ?? '—'); ?></div>
                                </div>
                            </div>

                            <div class="row container-info-fields" style="margin-top: 16px;">
                                <div class="col-sm-4">
                                    <div class="container-info-label">PO / BOL Number</div>
                                    <div class="container-info-value"><?php echo htmlspecialchars($container->po_bol_number ?? '—'); ?></div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="container-info-label">Carrier</div>
                                    <div class="container-info-value"><?php echo htmlspecialchars($container->carrier ?? '—'); ?></div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="container-info-label">Piece / Pallet Count</div>
                                    <div class="container-info-value"><?php echo $container->piece_count !== null ? number_format($container->piece_count) : '—'; ?></div>
                                </div>
                            </div>

                            <?php if ($container->notes): ?>
                            <div class="container-info-notes">
                                <div class="container-info-label">Notes</div>
                                <div class="well" style="margin-bottom: 0;">
                                    <?php echo nl2br(htmlspecialchars($container->notes)); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Upload Photos -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Upload Photos</h3>
                    </div>
                    <div class="panel-body">
                        <form id="photoUploadForm" enctype="multipart/form-data">
                            <input type="hidden" name="container_id" value="<?php echo $container->id; ?>">
                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                            
                            <div class="form-group">
                                <label for="photo_type">Photo Type *</label>
                                <select class="form-control" id="photo_type" name="photo_type" required>
                                    <option value="">-- Select Photo Type --</option>
                                    <?php
                                    foreach (getPhotoTypes($container->customer_id ?? null, $container->type) as $key => $label) {
                                        echo "<option value=\"{$key}\">{$label}</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="photo_files">Select Photos *</label>
                                <input type="file" class="form-control-file" id="photo_files" 
                                       name="photo_files[]" accept="image/*" multiple required>
                                <small class="form-text text-muted">
                                    You can select multiple photos. Max 40MB per file. Formats: JPG, PNG, GIF
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="photo_description">Description (Optional)</label>
                                <textarea class="form-control" id="photo_description" 
                                          name="photo_description" rows="2" 
                                          placeholder="Add notes about these photos"></textarea>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fa fa-upload"></i> Upload Photos
                                </button>
                            </div>

                            <div id="uploadProgress" class="progress" style="display:none;">
                                <div class="progress-bar progress-bar-striped active" role="progressbar" 
                                     style="width: 0%">0%</div>
                            </div>

                            <div id="uploadMessages"></div>
                        </form>
                    </div>
                </div>

                <!-- Photos -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Photos (<?php echo count($photos); ?>)</h3>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($photos)): ?>
                        <div class="alert alert-warning">
                            <i class="fa fa-exclamation-triangle"></i> No photos have been uploaded yet.
                        </div>
                        <?php else: ?>
                        <div id="photosContainer">
                        <?php
                        foreach (getPhotoTypes($container->customer_id ?? null, $container->type) as $type_key => $type_label) {
                            if (isset($photos_by_type[$type_key])) {
                                ?>
                                <h4 class="text-primary">
                                    <i class="fa fa-camera"></i> <?php echo $type_label; ?> 
                                    <span class="badge"><?php echo count($photos_by_type[$type_key]); ?></span>
                                </h4>
                                <div class="row">
                                    <?php foreach ($photos_by_type[$type_key] as $photo): ?>
                                    <div class="col-md-3 col-sm-4 col-xs-6 photo-item" data-photo-id="<?php echo $photo->id; ?>">
                                        <div class="thumbnail">
                                            <a href="<?php echo $us_url_root . $photo->file_path; ?>" 
                                               data-lightbox="container-<?php echo $container->id; ?>" 
                                               data-title="<?php echo htmlspecialchars($type_label . ' - ' . ($photo->description ?? '')); ?>">
                                                <img src="<?php echo $us_url_root . $photo->file_path; ?>" 
                                                     alt="<?php echo htmlspecialchars($type_label); ?>" 
                                                     class="img-responsive" 
                                                     style="height: 150px; object-fit: cover; width: 100%;">
                                            </a>
                                            <div class="caption">
                                                <p class="text-muted" style="font-size: 11px;">
                                                    <?php echo date('M d, Y H:i', strtotime($photo->uploaded_at)); ?>
                                                </p>
                                                <?php if ($photo->description): ?>
                                                <p style="font-size: 12px;"><?php echo htmlspecialchars($photo->description); ?></p>
                                                <?php endif; ?>
                                                <button class="btn btn-xs btn-danger delete-photo" 
                                                        data-photo-id="<?php echo $photo->id; ?>">
                                                    <i class="fa fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <hr>
                                <?php
                            }
                        }
                        ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Photo Checklist -->
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title">Photo Checklist</h3>
                    </div>
                    <div class="panel-body">
                        <ul class="list-unstyled">
                            <?php
                            foreach (getPhotoTypes($container->customer_id ?? null, $container->type) as $type_key => $type_label) {
                                $has_photo = isset($photos_by_type[$type_key]);
                                $icon = $has_photo ? 'fa-check-circle text-success' : 'fa-circle-o text-muted';
                                $count = $has_photo ? ' (' . count($photos_by_type[$type_key]) . ')' : '';
                                echo "<li><i class='fa {$icon}'></i> {$type_label}{$count}</li>";
                            }
                            ?>
                        </ul>
                    </div>
                </div>

                <?php if ($scan_enabled_for_customer || ($scan_summary && (int) $scan_summary->lot_count > 0)): ?>
                <!-- SKU Scans -->
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title">SKU Scans</h3>
                    </div>
                    <div class="panel-body">
                        <?php if ($scan_summary && (int) $scan_summary->lot_count > 0): ?>
                        <p style="margin-bottom: 14px;">
                            <strong><?php echo (int) $scan_summary->sku_count; ?></strong> SKU<?php echo (int) $scan_summary->sku_count === 1 ? '' : 's'; ?>,
                            <strong><?php echo (int) $scan_summary->lot_count; ?></strong> lot<?php echo (int) $scan_summary->lot_count === 1 ? '' : 's'; ?>,
                            <strong><?php echo (int) $scan_summary->qty_total; ?></strong> total qty scanned.
                        </p>
                        <a href="sku_scan_export_container.php?container_id=<?php echo $container->id; ?>&csrf=<?php echo urlencode($csrf); ?>" class="btn btn-success btn-block" target="_blank">
                            <i class="fa fa-file-excel-o"></i> Export All to Excel
                        </a>
                        <?php else: ?>
                        <p class="text-muted" style="margin-bottom: 14px;">No lots scanned for this container yet.</p>
                        <?php endif; ?>
                        <?php if ($scan_enabled_for_customer): ?>
                        <a href="sku_scan.php?container_id=<?php echo $container->id; ?>" class="btn btn-info btn-block" style="margin-top: 8px;">
                            <i class="fa fa-barcode"></i> Scan Lots
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Activity Log -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Activity Log</h3>
                    </div>
                    <div class="panel-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($activity_log)): ?>
                        <p class="text-muted">No activity recorded.</p>
                        <?php else: ?>
                        <ul class="timeline">
                            <?php foreach ($activity_log as $log): ?>
                            <li>
                                <div class="timeline-badge"><i class="fa fa-circle"></i></div>
                                <div class="timeline-panel">
                                    <div class="timeline-heading">
                                        <p><small class="text-muted">
                                            <i class="fa fa-clock-o"></i> 
                                            <?php echo date('M d, Y H:i', strtotime($log->created_at)); ?>
                                        </small></p>
                                    </div>
                                    <div class="timeline-body">
                                        <p>
                                            <strong><?php echo htmlspecialchars($log->fname . ' ' . $log->lname); ?></strong>
                                            <?php echo htmlspecialchars($log->action); ?>
                                        </p>
                                        <?php if ($log->details): ?>
                                        <p class="text-muted"><small><?php echo htmlspecialchars($log->details); ?></small></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Record Details - de-emphasized, bottom of page -->
        <div class="row">
            <div class="col-12">
                <div class="record-details-footer">
                    <i class="fa fa-info-circle"></i>
                    Created by <strong><?php echo $creator ? htmlspecialchars($creator->fname . ' ' . $creator->lname) : 'Unknown'; ?></strong>
                    on <?php echo date('M d, Y \a\t g:i A', strtotime($container->created_at)); ?>
                    <span class="record-details-sep">&middot;</span>
                    Last updated <?php echo date('M d, Y \a\t g:i A', strtotime($container->updated_at)); ?>
                    <?php if (!empty($container->drive_folder_link)): ?>
                    <span class="record-details-sep">&middot;</span>
                    <a href="<?php echo htmlspecialchars($container->drive_folder_link); ?>" target="_blank">
                        <i class="fa fa-cloud"></i> Backed up to Drive
                    </a>
                    <?php elseif (!empty($photos)): ?>
                    <span class="record-details-sep">&middot;</span>
                    <span style="color: #b0b0b0;"><i class="fa fa-cloud"></i> Not yet backed up to Drive</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Off-canvas: Download / Edit / Send Email -->
<div id="actionsBackdrop" class="actions-backdrop"></div>
<div id="actionsOffcanvas" class="actions-offcanvas">
    <div class="actions-offcanvas-header">
        <h4 style="margin:0;">Container Actions</h4>
        <button type="button" id="closeActionsBtn" class="close" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <div class="actions-offcanvas-body">
        <a href="container_download.php?id=<?php echo $container->id; ?>" class="btn btn-success btn-block btn-lg">
            <i class="fa fa-download"></i> Download Report
        </a>
        <a href="container_edit.php?id=<?php echo $container->id; ?>" class="btn btn-warning btn-block btn-lg">
            <i class="fa fa-edit"></i> Edit Container
        </a>
        <?php if ($scan_enabled_for_customer): ?>
        <a href="sku_scan.php?container_id=<?php echo $container->id; ?>" class="btn btn-info btn-block btn-lg">
            <i class="fa fa-barcode"></i> Scan SKU Lots
        </a>
        <?php endif; ?>
        <?php if ($is_supervisor): ?>
        <a href="container_email.php?id=<?php echo $container->id; ?>" class="btn btn-primary btn-block btn-lg">
            <i class="fa fa-envelope"></i> Send Email
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Review & Send confirmation (supervisor, Completed -> Reviewed) -->
<div id="reviewBackdrop" class="actions-backdrop"></div>
<div id="reviewModal" class="actions-offcanvas" style="width: 380px;">
    <div class="actions-offcanvas-header">
        <h4 style="margin:0;">Mark as Reviewed?</h4>
        <button type="button" id="reviewModalClose" class="close" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <div class="actions-offcanvas-body">
        <p style="font-size: 14px; color: #374151;">
            You've already got the photos in front of you on this page - confirming here will mark this container as <strong>Reviewed</strong> and email those photos to the client's notification list for this direction.
        </p>
        <div id="reviewModalMsg"></div>
        <button type="button" id="reviewModalConfirm" class="btn btn-primary btn-block btn-lg">
            <i class="fa fa-check"></i> Confirm & Send Email
        </button>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css">

<style>
/* ===== Bootstrap 3 component compatibility shim =====
   Some sites run a newer Bootstrap version where .panel/.label/.well
   were renamed or removed (Bootstrap 4/5 use .card/.badge instead).
   These rules guarantee the classes below render correctly either way -
   harmless if Bootstrap 3 already styles them, and a real fix if not. */
.panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.panel-heading {
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    border-radius: 6px 6px 0 0;
}
.panel-default .panel-heading { background: #f8f9fa; color: #333; }
.panel-primary .panel-heading { background: #0067b8; color: #fff; }
.panel-primary { border-color: #0067b8; }
.panel-title { margin: 0; font-size: 16px; font-weight: 600; }
.panel-body { padding: 16px; }

.label {
    display: inline-block;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    border-radius: 4px;
    color: #fff;
    white-space: nowrap;
}
.label-lg { font-size: 14px; padding: 5px 12px; }
.label-default { background: #6b7280; }
.label-primary { background: #0067b8; }
.label-success { background: #10b981; }
.label-info    { background: #3b82f6; }
.label-warning { background: #f59e0b; }
.label-danger  { background: #ef4444; }

.well {
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 16px;
}

/* ===== Mobile refinements ===== */
@media (max-width: 767px) {
    .page-header {
        font-size: 22px;
        margin-bottom: 15px;
    }
    .page-header .btn {
        font-size: 13px;
        padding: 6px 10px;
    }
    .panel-body {
        padding: 14px;
    }
}

.timeline {
    list-style: none;
    padding: 20px 0;
    position: relative;
}

.timeline:before {
    top: 0;
    bottom: 0;
    position: absolute;
    content: " ";
    width: 2px;
    background-color: #ddd;
    left: 15px;
    margin-left: -1.5px;
}

.timeline > li {
    margin-bottom: 20px;
    position: relative;
}

.timeline > li:after {
    content: "";
    display: table;
    clear: both;
}

.timeline-badge {
    color: #fff;
    width: 10px;
    height: 10px;
    line-height: 50px;
    font-size: 1.4em;
    text-align: center;
    position: absolute;
    top: 16px;
    left: 10px;
    margin-left: -25px;
    background-color: #999;
    z-index: 100;
    border-radius: 50%;
}

.timeline-panel {
    margin-left: 30px;
    background-color: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 3px;
    padding: 10px;
}

.label-lg {
    font-size: 14px;
    padding: 5px 10px;
}

/* Container Information - front and center card */
.container-info-card {
    border-top: 4px solid #667eea;
    margin-bottom: 20px;
}

.container-info-card .panel-body {
    padding: 25px;
}

.container-info-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 15px;
}

.container-info-label {
    font-size: 12px;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.container-info-number {
    font-size: 32px;
    font-weight: 700;
    color: #222;
    line-height: 1.2;
}

.container-info-badges {
    text-align: right;
    white-space: nowrap;
}

.container-info-fields {
    margin-top: 20px;
}

.container-info-value {
    font-size: 18px;
    font-weight: 600;
    color: #333;
}

.container-info-notes {
    margin-top: 20px;
}

.container-info-toggle {
    display: none;
    align-items: center;
    gap: 6px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #f0f0f0;
    color: #667eea;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.container-info-toggle i {
    transition: transform 0.2s ease;
}

.container-info-toggle.expanded i {
    transform: rotate(180deg);
}

.container-info-details {
    margin-top: 16px;
}

@media (max-width: 767px) {
    .container-info-toggle {
        display: flex;
    }
    .container-info-details {
        display: none;
        margin-top: 14px;
    }
    .container-info-details.expanded {
        display: block;
    }
}

/* Record details footer - de-emphasized */
.record-details-footer {
    padding: 12px 16px;
    background: #f8f9fa;
    border-radius: 6px;
    font-size: 12px;
    color: #888;
    margin-top: 10px;
}

.record-details-sep {
    margin: 0 6px;
}

/* Off-canvas actions panel */
.actions-offcanvas {
    position: fixed;
    top: 0;
    right: -340px;
    width: 320px;
    max-width: 85vw;
    height: 100%;
    background: #fff;
    box-shadow: -4px 0 12px rgba(0,0,0,0.2);
    z-index: 1060;
    transition: right 0.3s ease;
    overflow-y: auto;
}

.actions-offcanvas.open {
    right: 0;
}

.actions-offcanvas-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 20px;
    border-bottom: 1px solid #eee;
}

.actions-offcanvas-body {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.actions-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.4);
    z-index: 1050;
    display: none;
}

.actions-backdrop.show {
    display: block;
}

@media (max-width: 600px) {
    .container-info-number {
        font-size: 24px;
    }
}
</style>

<script>
$(document).ready(function() {
    // Photo upload via AJAX
    $('#photoUploadForm').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        var uploadUrl = '<?php echo $us_url_root; ?>usersc/ajax/upload_photos.php';
        
        $('#uploadProgress').show();
        $('#uploadMessages').html('');
        
        $.ajax({
            url: uploadUrl,
            type: 'POST',
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = (evt.loaded / evt.total) * 100;
                        $('#uploadProgress .progress-bar').css('width', percentComplete + '%').text(Math.round(percentComplete) + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                if (response && response.success) {
                    $('#uploadMessages').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    var errorMsg = (response && response.message) ? response.message : 'Upload failed';
                    $('#uploadMessages').html('<div class="alert alert-danger">' + errorMsg + '</div>');
                }
                $('#uploadProgress').hide();
            },
            error: function(xhr, status, error) {
                var errorMsg = 'Upload failed. ';
                
                if (xhr.status === 404) {
                    errorMsg += 'Upload script not found (404). Check that usersc/ajax/upload_photos.php exists.';
                } else if (xhr.status === 403) {
                    errorMsg += 'Permission denied (403). Check file permissions.';
                } else if (xhr.status === 500) {
                    errorMsg += 'Server error (500). Check PHP error logs.';
                } else if (xhr.responseText) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        errorMsg += resp.message || error;
                    } catch(e) {
                        errorMsg += 'Server error: ' + xhr.responseText.substring(0, 200);
                    }
                } else {
                    errorMsg += error + ' (Status: ' + xhr.status + ')';
                }
                
                $('#uploadMessages').html('<div class="alert alert-danger">' + errorMsg + '</div>');
                $('#uploadProgress').hide();
            }
        });
    });
    
    // Delete photo
    $(document).on('click', '.delete-photo', function() {
        if (!confirm('Are you sure you want to delete this photo?')) {
            return;
        }
        
        var photoId = $(this).data('photo-id');
        var photoItem = $(this).closest('.photo-item');
        
        $.ajax({
            url: '<?php echo $us_url_root; ?>usersc/ajax/delete_photo.php',
            type: 'POST',
            data: {
                photo_id: photoId,
                csrf: '<?php echo $csrf; ?>'
            },
            success: function(response) {
                if (response.success) {
                    photoItem.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert('Failed to delete photo: ' + response.message);
                }
            },
            error: function() {
                alert('Failed to delete photo. Please try again.');
            }
        });
    });
    
    // Off-canvas actions panel
    $('#openActionsBtn').on('click', function() {
        $('#actionsOffcanvas').addClass('open');
        $('#actionsBackdrop').addClass('show');
    });
    
    $('#closeActionsBtn, #actionsBackdrop').on('click', function() {
        $('#actionsOffcanvas').removeClass('open');
        $('#actionsBackdrop').removeClass('show');
    });
    
    // Collapsible container details (mobile only - see CSS media query)
    $('#containerInfoToggle').on('click', function() {
        var expanded = $('#containerInfoDetails').toggleClass('expanded').hasClass('expanded');
        $(this).toggleClass('expanded', expanded);
        $('#containerInfoToggleText').text(expanded ? 'Hide details' : 'Show details');
    });
    
    // ===== Next Status button =====
    var nextStatusCsrf = '<?php echo $csrf; ?>';
    var nextStatusBaseUrl = '<?php echo $us_url_root; ?>';
    
    function refreshNextStatusButton() {
        var status = $('#containerInfoPanelBody').data('status');
        var isSupervisor = $('#containerInfoPanelBody').data('is-supervisor') == 1;
        var $btn = $('#nextStatusBtn');
        var $label = $('#nextStatusBtnLabel');
        var $hint = $('#nextStatusHint');
        
        $btn.prop('disabled', false).removeClass('btn-default').addClass('btn-primary');
        $hint.text('');
        
        if (status === 'pending') {
            $label.text('Start Progress');
        } else if (status === 'in_progress') {
            $label.text('Mark Completed');
        } else if (status === 'completed') {
            if (isSupervisor) {
                $label.text('Review & Send');
            } else {
                $btn.prop('disabled', true).removeClass('btn-primary').addClass('btn-default');
                $label.text('Awaiting Review');
                $hint.text('A supervisor needs to review this next.');
            }
        } else if (status === 'reviewed') {
            $btn.prop('disabled', true).removeClass('btn-primary').addClass('btn-default');
            $label.html('<i class="fa fa-check"></i> Reviewed');
        }
    }
    refreshNextStatusButton();
    
    function callNextStatus(confirmReview) {
        var containerId = $('#containerInfoPanelBody').data('container-id');
        var fd = {
            container_id: containerId,
            csrf: nextStatusCsrf
        };
        if (confirmReview) {
            fd.confirm_review = '1';
        }
        return $.ajax({
            url: nextStatusBaseUrl + 'usersc/ajax/container_next_status.php',
            type: 'POST',
            data: fd,
            dataType: 'json'
        });
    }
    
    $('#nextStatusBtn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        
        callNextStatus(false)
            .done(function(resp) {
                if (resp.success) {
                    location.reload();
                } else if (resp.needs_review) {
                    $('#reviewModal').addClass('open');
                    $('#reviewBackdrop').addClass('show');
                } else {
                    alert(resp.message || 'Could not update status');
                    refreshNextStatusButton();
                }
            })
            .fail(function() {
                alert('Failed to update status - please try again.');
                refreshNextStatusButton();
            });
    });
    
    $('#reviewModalConfirm').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Sending...');
        
        callNextStatus(true)
            .done(function(resp) {
                if (resp.success) {
                    location.reload();
                } else {
                    $('#reviewModalMsg').html('<div class="alert alert-danger">' + (resp.message || 'Failed to update status') + '</div>');
                    $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Confirm & Send Email');
                }
            })
            .fail(function() {
                $('#reviewModalMsg').html('<div class="alert alert-danger">Request failed - please try again.</div>');
                $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Confirm & Send Email');
            });
    });
    
    $('#reviewModalClose, #reviewBackdrop').on('click', function() {
        $('#reviewModal').removeClass('open');
        $('#reviewBackdrop').removeClass('show');
    });
});
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
