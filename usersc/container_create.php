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
        $container_number = strtoupper(trim(Input::get('container_number')));
        $seal_number = trim(Input::get('seal_number'));
        $shipment_number = trim(Input::get('shipment_number'));
        $receipt_ship_date = Input::get('receipt_ship_date');
        $po_bol_number = trim(Input::get('po_bol_number'));
        $carrier = trim(Input::get('carrier'));
        $piece_count = Input::get('piece_count');
        $customer_id = Input::get('customer_id');
        $warehouse_id = Input::get('warehouse_id');
        $notes = trim(Input::get('notes'));
        
        // Validation
        if (empty($container_number)) {
            $errors[] = 'Container number is required.';
        }

        // Which field actually has to be unique depends on the client — see
        // usersc/customer_identifier_settings.php. Default is container_number;
        // clients flagged there use shipment_number instead (their "container
        // number" is really a reused trailer number).
        $identifier_field = getIdentifierField($customer_id ?: null);
        $identifier_value = $identifier_field === 'shipment_number' ? $shipment_number : $container_number;

        if (empty($errors)) {
            if ($identifier_field === 'shipment_number' && empty($shipment_number)) {
                $errors[] = 'This client is set up to use Shipment Number as the unique identifier — it\'s required.';
            } else {
                $dupe = findDuplicateIdentifier($identifier_field, $identifier_value);
                if ($dupe) {
                    $field_label = $identifier_field === 'shipment_number' ? 'shipment number' : 'container number';
                    $errors[] = 'That ' . $field_label . ' is already in use by an open container (#' . $dupe->id . ', status: ' . htmlspecialchars($dupe->status) . '). It\'ll free up once that one is marked Reviewed.';
                }
            }
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
                    'po_bol_number' => $po_bol_number ?: null,
                    'carrier' => $carrier ?: null,
                    'piece_count' => $piece_count !== '' ? (int)$piece_count : null,
                    'customer_id' => $customer_id ?: null,
                    'warehouse_id' => $warehouse_id ?: null,
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
$warehouses = getWarehousesForUser($user_id);
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
                                       style="text-transform: uppercase;"
                                       oninput="this.value = this.value.toUpperCase();"
                                       value="<?php echo htmlspecialchars(Input::get('container_number') ?? ''); ?>">
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

                            <?php if (!empty($warehouses)): ?>
                            <?php
                            $selected_warehouse_id = Input::get('warehouse_id');
                            if (!$selected_warehouse_id && count($warehouses) === 1) {
                                $selected_warehouse_id = $warehouses[0]->id; // only one warehouse this user can pick — default to it
                            }
                            ?>
                            <div class="form-group">
                                <label for="warehouse_id">Warehouse</label>
                                <select class="form-control" id="warehouse_id" name="warehouse_id">
                                    <option value="">-- No warehouse selected --</option>
                                    <?php foreach ($warehouses as $w): ?>
                                    <option value="<?php echo (int) $w->id; ?>" <?php echo $selected_warehouse_id == $w->id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($w->name); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

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
                                <label for="po_bol_number">PO / BOL Number</label>
                                <input type="text" class="form-control" id="po_bol_number" 
                                       name="po_bol_number" 
                                       placeholder="Purchase order or bill of lading number" 
                                       value="<?php echo Input::get('po_bol_number'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="carrier">Carrier</label>
                                <input type="text" class="form-control" id="carrier" 
                                       name="carrier" list="carrier-suggestions"
                                       placeholder="Trucking company / carrier name" 
                                       value="<?php echo Input::get('carrier'); ?>">
                                <datalist id="carrier-suggestions">
                                    <?php foreach ($carrier_list as $cn): ?>
                                    <option value="<?php echo htmlspecialchars($cn); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="form-group">
                                <label for="piece_count">Piece / Pallet Count</label>
                                <input type="number" class="form-control" id="piece_count" 
                                       name="piece_count" min="0"
                                       placeholder="Number of pieces or pallets" 
                                       value="<?php echo Input::get('piece_count'); ?>">
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

@media (max-width: 767px) {
    .page-header {
        font-size: 22px;
        margin-bottom: 15px;
    }
    .panel-body {
        padding: 14px;
    }
}
</style>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
