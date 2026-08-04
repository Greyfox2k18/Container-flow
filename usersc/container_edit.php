<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

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

// Anyone with floor worker or supervisor permission can edit/upload photos
// to any container - multiple people may work the same container in a shift.
if (!isFloorWorker()) {
    Redirect::to('container_dashboard.php');
}

$errors = [];
$success = '';

// Handle form submission
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');
        
        if ($action == 'update_info') {
            $container_number = strtoupper(trim(Input::get('container_number')));
            $seal_number = trim(Input::get('seal_number'));
            $shipment_number = trim(Input::get('shipment_number'));
            $receipt_ship_date = Input::get('receipt_ship_date');
            $po_bol_number = trim(Input::get('po_bol_number'));
            $carrier = trim(Input::get('carrier'));
            $piece_count = Input::get('piece_count');
            $customer_id = Input::get('customer_id');
            $notes = trim(Input::get('notes'));
            $status = Input::get('status');
            
            if (empty($container_number)) {
                $errors[] = 'Container number is required.';
            }
            
            if (empty($errors)) {
                $db = DB::getInstance();
                $update_data = [
                    'container_number' => $container_number,
                    'seal_number' => $seal_number ?: null,
                    'shipment_number' => $shipment_number ?: null,
                    'receipt_ship_date' => $receipt_ship_date ?: null,
                    'po_bol_number' => $po_bol_number ?: null,
                    'carrier' => $carrier ?: null,
                    'piece_count' => $piece_count !== '' ? (int)$piece_count : null,
                    'customer_id' => $customer_id ?: null,
                    'notes' => $notes,
                    'status' => $status
                ];
                
                $was_reviewed_already = ($container->status === 'reviewed');
                
                $db->update('containers', $container_id, $update_data);
                logContainerActivity($container_id, $user_id, 'updated', 'Updated container information');
                
                // Floor work being marked Completed no longer emails anyone -
                // that now happens when a supervisor reviews the photos and
                // marks it Reviewed. Only fires once per transition, not on
                // every subsequent save while it's already Reviewed.
                if ($status === 'reviewed' && !$was_reviewed_already) {
                    sendCompletionNotification($container_id, $user_id);
                }
                
                $success = 'Container updated successfully!';
                $container = getContainerById($container_id);
            }
        }
    } else {
        $errors[] = 'Invalid CSRF token.';
    }
}

$photos = getContainerPhotos($container_id);
$photos_by_type = [];
foreach ($photos as $photo) {
    $photos_by_type[$photo->photo_type][] = $photo;
}

$customers = getAllCustomers();

// Generate ONE csrf token for the whole page. Token::generate() overwrites
// the single token stored in session, so calling it more than once per
// page (one per form) invalidates the earlier forms' embedded tokens.
$csrf = Token::generate();
?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">
                    <?php echo ucfirst($container->type); ?> Container: 
                    <?php echo htmlspecialchars($container->container_number); ?>
                </h1>
                <div id="containerEditStatusBlock" data-container-id="<?php echo $container->id; ?>" data-status="<?php echo $container->status; ?>" data-is-supervisor="<?php echo $is_supervisor ? '1' : '0'; ?>" style="margin-bottom: 15px;">
                    <button type="button" id="nextStatusBtn" class="btn btn-primary">
                        <i class="fa fa-arrow-right"></i> <span id="nextStatusBtnLabel">Next Status</span>
                    </button>
                    <span id="nextStatusHint" style="font-size: 12.5px; color: #888; margin-left: 8px;"></span>
                </div>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <?php echo $success; ?>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <!-- Container Information Panel -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Container Information</h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" action="" id="containerUpdateForm" data-original-status="<?php echo htmlspecialchars($container->status); ?>">
                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="update_info">
                            
                            <div class="form-group">
                                <label for="container_number">Container Number *</label>
                                <input type="text" class="form-control" id="container_number" 
                                       name="container_number" required 
                                       style="text-transform: uppercase;"
                                       oninput="this.value = this.value.toUpperCase();"
                                       value="<?php echo htmlspecialchars($container->container_number); ?>">
                            </div>

                            <div class="form-group">
                                <label for="customer_id">Client</label>
                                <select class="form-control" id="customer_id" name="customer_id">
                                    <option value="">-- No client selected --</option>
                                    <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo $c->id; ?>" <?php echo $container->customer_id == $c->id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c->name); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">
                                    <?php if ($is_supervisor): ?>
                                    <a href="customer_create.php" target="_blank">+ Add a new client</a>
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="shipment_number">Shipment Number</label>
                                <input type="text" class="form-control" id="shipment_number" 
                                       name="shipment_number" 
                                       value="<?php echo htmlspecialchars($container->shipment_number ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="receipt_ship_date"><?php echo getDateFieldLabel($container->type); ?></label>
                                <input type="date" class="form-control" id="receipt_ship_date" 
                                       name="receipt_ship_date" 
                                       value="<?php echo htmlspecialchars($container->receipt_ship_date ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="seal_number">Seal Number</label>
                                <input type="text" class="form-control" id="seal_number" 
                                       name="seal_number" 
                                       value="<?php echo htmlspecialchars($container->seal_number ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="po_bol_number">PO / BOL Number</label>
                                <input type="text" class="form-control" id="po_bol_number" 
                                       name="po_bol_number" 
                                       value="<?php echo htmlspecialchars($container->po_bol_number ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="carrier">Carrier</label>
                                <input type="text" class="form-control" id="carrier" 
                                       name="carrier" 
                                       value="<?php echo htmlspecialchars($container->carrier ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="piece_count">Piece / Pallet Count</label>
                                <input type="number" class="form-control" id="piece_count" 
                                       name="piece_count" min="0"
                                       value="<?php echo htmlspecialchars($container->piece_count ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="status">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="pending" <?php echo $container->status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="in_progress" <?php echo $container->status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $container->status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <?php if ($is_supervisor): ?>
                                    <option value="reviewed" <?php echo $container->status == 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" 
                                          rows="4"><?php echo htmlspecialchars($container->notes ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa fa-save"></i> Update Information
                                </button>
                            </div>
                        </form>

                        <hr>

                        <div class="form-group">
                            <a href="container_download.php?id=<?php echo $container->id; ?>" class="btn btn-success btn-block">
                                <i class="fa fa-download"></i> Download Report
                            </a>
                            <a href="container_view.php?id=<?php echo $container->id; ?>" class="btn btn-info btn-block">
                                <i class="fa fa-eye"></i> View Container
                            </a>
                            <?php if ($is_supervisor): ?>
                            <a href="container_email.php?id=<?php echo $container->id; ?>" class="btn btn-warning btn-block">
                                <i class="fa fa-envelope"></i> Send Email
                            </a>
                            <?php endif; ?>
                            <a href="container_dashboard.php" class="btn btn-default btn-block">
                                <i class="fa fa-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <!-- Photo Upload Panel -->
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Upload Photos</h3>
                    </div>
                    <div class="panel-body">
                        <div id="uploadArea">
                            <form id="photoUploadForm" enctype="multipart/form-data">
                                <input type="hidden" name="container_id" value="<?php echo $container->id; ?>">
                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                
                                <div class="form-group">
                                    <label for="photo_type">Photo Type *</label>
                                    <select class="form-control" id="photo_type" name="photo_type" required>
                                        <option value="">-- Select Photo Type --</option>
                                        <?php
                                        global $photo_types;
                                        foreach ($photo_types[$container->type] as $key => $label) {
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
                </div>

                <!-- Uploaded Photos Panel -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Uploaded Photos (<?php echo count($photos); ?>)</h3>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($photos)): ?>
                        <p class="text-muted">No photos uploaded yet.</p>
                        <?php else: ?>
                        <div id="photosContainer">
                            <?php
                            global $photo_types;
                            foreach ($photo_types[$container->type] as $type_key => $type_label) {
                                if (isset($photos_by_type[$type_key])) {
                                    echo "<h4>{$type_label}</h4>";
                                    echo "<div class='row'>";
                                    foreach ($photos_by_type[$type_key] as $photo) {
                                        ?>
                                        <div class="col-md-3 col-sm-4 col-xs-6 photo-item" data-photo-id="<?php echo $photo->id; ?>">
                                            <div class="thumbnail">
                                                <a href="<?php echo $us_url_root . $photo->file_path; ?>" 
                                                   data-lightbox="container-<?php echo $container->id; ?>" 
                                                   data-title="<?php echo htmlspecialchars($photo->description ?? ''); ?>">
                                                    <img src="<?php echo $us_url_root . $photo->file_path; ?>" 
                                                         alt="<?php echo htmlspecialchars($type_label); ?>" 
                                                         style="width: 100%; height: 150px; object-fit: cover; cursor: pointer;">
                                                </a>
                                                <div class="caption">
                                                    <p><small><?php echo date('M d, Y H:i', strtotime($photo->uploaded_at)); ?></small></p>
                                                    <?php if ($photo->description): ?>
                                                    <p><small><?php echo htmlspecialchars($photo->description); ?></small></p>
                                                    <?php endif; ?>
                                                    <button class="btn btn-xs btn-danger delete-photo" 
                                                            data-photo-id="<?php echo $photo->id; ?>">
                                                        <i class="fa fa-trash"></i> Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                    }
                                    echo "</div><hr>";
                                }
                            }
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Review & Send confirmation (supervisor, Completed -> Reviewed) -->
<div class="review-modal-overlay" id="reviewModalOverlay">
    <div class="review-modal-box">
        <h4>Mark as Reviewed?</h4>
        <p style="font-size: 14px; color: #374151;">
            You've already got the photos in front of you on this page - confirming here will mark this container as <strong>Reviewed</strong> and email those photos to the client's notification list for this direction.
        </p>
        <div id="reviewModalMsg"></div>
        <div style="display: flex; gap: 10px; margin-top: 15px;">
            <button type="button" id="reviewModalCancel" class="btn btn-default" style="flex:1;">Cancel</button>
            <button type="button" id="reviewModalConfirm" class="btn btn-primary" style="flex:1;">
                <i class="fa fa-check"></i> Confirm & Send
            </button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css">

<script>
$(document).ready(function() {
    // Photo upload via AJAX
    $('#photoUploadForm').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        var uploadUrl = '<?php echo $us_url_root; ?>usersc/ajax/upload_photos.php';
        
        console.log('Uploading to:', uploadUrl);
        
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
                console.log('Upload response:', response);
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
                console.error('Upload error details:');
                console.error('Status:', status);
                console.error('Error:', error);
                console.error('XHR Status:', xhr.status);
                console.error('Response Text:', xhr.responseText);
                
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
    
    // Confirm before marking a container Reviewed, since that triggers
    // an automatic email with photos to the client - want to be sure
    // that's intentional before it fires.
    $('#containerUpdateForm').on('submit', function(e) {
        var originalStatus = $(this).data('original-status');
        var newStatus = $('#status').val();
        
        if (newStatus === 'reviewed' && originalStatus !== 'reviewed') {
            if (!confirm('Mark this container as Reviewed and email the photos to the client now?')) {
                e.preventDefault();
            }
        }
    });
    
    // ===== Next Status button =====
    var nextStatusCsrf = '<?php echo $csrf; ?>';
    var nextStatusBaseUrl = '<?php echo $us_url_root; ?>';
    
    function refreshNextStatusButton() {
        var status = $('#containerEditStatusBlock').data('status');
        var isSupervisor = $('#containerEditStatusBlock').data('is-supervisor') == 1;
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
        var containerId = $('#containerEditStatusBlock').data('container-id');
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
        $(this).prop('disabled', true);
        
        callNextStatus(false)
            .done(function(resp) {
                if (resp.success) {
                    location.reload();
                } else if (resp.needs_review) {
                    $('#reviewModalOverlay').addClass('open');
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
                    $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Confirm & Send');
                }
            })
            .fail(function() {
                $('#reviewModalMsg').html('<div class="alert alert-danger">Request failed - please try again.</div>');
                $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Confirm & Send');
            });
    });
    
    $('#reviewModalCancel').on('click', function() {
        $('#reviewModalOverlay').removeClass('open');
    });
});
</script>

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
    .panel-body {
        padding: 14px;
    }
    .btn-block {
        font-size: 15px;
    }
}

/* ===== Review & Send confirmation modal ===== */
.review-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(17,24,39,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1100;
}
.review-modal-overlay.open {
    display: flex;
}
.review-modal-box {
    width: 380px;
    max-width: 92vw;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
    padding: 22px;
}
.review-modal-box h4 {
    margin: 0 0 12px 0;
}
</style>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
