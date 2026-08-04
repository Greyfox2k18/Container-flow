<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$user_id = $user->data()->id;
$is_supervisor = isSupervisor();

// Check if user has permission
if (!isFloorWorker()) {
    Redirect::to('index.php');
}

$type = Input::get('type');
if (!in_array($type, ['inbound', 'outbound'])) {
    $type = 'inbound';
}

$errors = [];
$success = '';

if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $container_number = trim(Input::get('container_number'));
        $seal_number = trim(Input::get('seal_number'));
        $shipment_number = trim(Input::get('shipment_number'));
        $receipt_ship_date = Input::get('receipt_ship_date');
        $customer_id = Input::get('customer_id');
        $notes = trim(Input::get('notes'));
        
        // Validation
        if (empty($container_number)) {
            $errors[] = 'Container number is required.';
        }
        
        if (empty($errors)) {
            $db = DB::getInstance();
            
            // Insert container
            try {
                $insert = $db->insert('containers', [
                    'container_number' => $container_number,
                    'seal_number' => $seal_number ?: null,
                    'shipment_number' => $shipment_number ?: null,
                    'receipt_ship_date' => $receipt_ship_date ?: null,
                    'customer_id' => $customer_id ?: null,
                    'type' => $type,
                    'status' => 'pending',
                    'created_by' => $user_id,
                    'notes' => $notes
                ]);
                
                if ($insert) {
                    $container_id = $db->lastId();
                    logContainerActivity($container_id, $user_id, 'created', "Created {$type} container");
                    
                    $success = 'Container created successfully!';
                    Redirect::to('container_edit.php?id=' . $container_id);
                } else {
                    $errors[] = 'Failed to create container. Error: ' . $db->errorString();
                }
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    } else {
        $errors[] = 'Invalid CSRF token.';
    }
}

$customers = getAllCustomers();
?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">Create <?php echo ucfirst($type); ?> Container</h1>
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
            <div class="col-md-8">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Container Information</h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" action="">
                            <input type="hidden" name="csrf" value="<?php echo Token::generate(); ?>">
                            
                            <div class="form-group">
                                <label for="container_number">Container Number *</label>
                                <input type="text" class="form-control" id="container_number" 
                                       name="container_number" required 
                                       placeholder="Enter container number" 
                                       value="<?php echo Input::get('container_number'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="customer_id">Client</label>
                                <select class="form-control" id="customer_id" name="customer_id">
                                    <option value="">-- No client selected --</option>
                                    <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo $c->id; ?>" <?php echo Input::get('customer_id') == $c->id ? 'selected' : ''; ?>>
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
                                       placeholder="Enter shipment number (if available)" 
                                       value="<?php echo Input::get('shipment_number'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="receipt_ship_date"><?php echo getDateFieldLabel($type); ?></label>
                                <input type="date" class="form-control" id="receipt_ship_date" 
                                       name="receipt_ship_date" 
                                       value="<?php echo Input::get('receipt_ship_date'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="seal_number">Seal Number</label>
                                <input type="text" class="form-control" id="seal_number" 
                                       name="seal_number" 
                                       placeholder="Enter seal number (if available)" 
                                       value="<?php echo Input::get('seal_number'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" 
                                          rows="4" placeholder="Enter any notes or comments"><?php echo Input::get('notes'); ?></textarea>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fa fa-save"></i> Create Container
                                </button>
                                <a href="container_dashboard.php" class="btn btn-default btn-lg">
                                    <i class="fa fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title">Required Photos - <?php echo ucfirst($type); ?></h3>
                    </div>
                    <div class="panel-body">
                        <?php if ($type == 'inbound'): ?>
                        <ol>
                            <li>Seal photo</li>
                            <li>Back of container with doors closed</li>
                            <li>Back of container with doors open</li>
                            <li>Empty container (after unloading)</li>
                            <li>Any damage photos</li>
                        </ol>
                        <?php else: ?>
                        <ol>
                            <li>Empty container (before loading)</li>
                            <li>Loaded container with door open</li>
                            <li>Seal photo</li>
                            <li>Back of container sealed and closed</li>
                            <li>Any damage photos</li>
                        </ol>
                        <?php endif; ?>
                        <p class="text-muted">
                            <small>After creating the container, you'll be able to upload photos.</small>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
