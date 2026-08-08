<?php
/**
 * Customer Identifier Settings — Container Tracking System
 * Flags which clients should use Shipment Number (not Container Number)
 * as the field that has to be unique — for clients whose freight rides
 * on their own reused trailers. Run 12_identifier_migration.sql once
 * before using this page.
 *
 * This is a standalone page because customer_edit.php wasn't available
 * to edit directly — if you'd rather this live as a field on that page
 * instead, send it over and it can be folded in there.
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}
if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Token::check(Input::get('csrf'))) {
    $customer_id = (int) Input::get('customer_id');
    $enabled = Input::get('enabled') ? 1 : 0;
    if ($customer_id) {
        DB::getInstance()->update('customers', $customer_id, ['use_shipment_number_as_id' => $enabled]);
        $message = 'Updated.';
    }
}

$customers = getAllCustomers();
$csrf = Token::generate();
?>
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">Customer Identifier Settings</h1>
                <p class="text-muted">
                    By default, a container's <strong>Container Number</strong> has to be unique
                    while it's open (not yet Reviewed). For clients whose freight rides on their
                    own reused trailers — where the same "container number" shows up on multiple
                    shipments — flip the switch below so <strong>Shipment Number</strong> is the
                    field that has to be unique instead, and container numbers are free to repeat.
                </p>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="panel panel-default">
            <div class="panel-body">
                <?php if (empty($customers)): ?>
                <p class="text-muted">No clients yet.</p>
                <?php else: ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Unique identifier field</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($c->name); ?></td>
                            <td>
                                <?php echo !empty($c->use_shipment_number_as_id)
                                    ? '<span class="label label-info">Shipment Number</span>'
                                    : '<span class="label label-default">Container Number (default)</span>'; ?>
                            </td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="customer_id" value="<?php echo $c->id; ?>">
                                    <input type="hidden" name="enabled" value="<?php echo !empty($c->use_shipment_number_as_id) ? '0' : '1'; ?>">
                                    <button type="submit" class="btn btn-xs <?php echo !empty($c->use_shipment_number_as_id) ? 'btn-default' : 'btn-info'; ?>">
                                        <?php echo !empty($c->use_shipment_number_as_id) ? 'Switch back to Container Number' : 'Use Shipment Number instead'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
