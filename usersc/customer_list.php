<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Customer management is a settings-style feature - supervisors only
if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

$customers = getAllCustomers();
?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">
                    Clients
                    <div class="pull-right">
                        <a href="customer_create.php" class="btn btn-primary">
                            <i class="fa fa-plus"></i> Add Client
                        </a>
                        <a href="container_dashboard.php" class="btn btn-default">
                            <i class="fa fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </h1>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">All Clients (<?php echo count($customers); ?>)</h3>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($customers)): ?>
                        <p class="text-muted" style="text-align:center; padding: 30px 0;">
                            No clients yet. <a href="customer_create.php">Add your first client</a>.
                        </p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Contact</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Inbound Notifications</th>
                                        <th>Outbound Notifications</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customers as $customer): ?>
                                    <?php 
                                    $notif_inbound = getCustomerNotificationEmails($customer, 'inbound');
                                    $notif_outbound = getCustomerNotificationEmails($customer, 'outbound');
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($customer->name); ?></strong></td>
                                        <td><?php echo htmlspecialchars($customer->contact_name ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($customer->email ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($customer->phone ?? '-'); ?></td>
                                        <td>
                                            <?php if (!empty($notif_inbound)): ?>
                                            <span class="label label-success">
                                                <i class="fa fa-check"></i> <?php echo count($notif_inbound); ?> email<?php echo count($notif_inbound) === 1 ? '' : 's'; ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="label label-default">Not set up</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($notif_outbound)): ?>
                                            <span class="label label-success">
                                                <i class="fa fa-check"></i> <?php echo count($notif_outbound); ?> email<?php echo count($notif_outbound) === 1 ? '' : 's'; ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="label label-default">Not set up</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="customer_edit.php?id=<?php echo $customer->id; ?>" class="btn btn-sm btn-warning">
                                                <i class="fa fa-edit"></i> Edit
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
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
