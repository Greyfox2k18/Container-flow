<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

$customer_id = Input::get('id');
if (!$customer_id) {
    Redirect::to('customer_list.php');
}

$customer = getCustomerById($customer_id);
if (!$customer) {
    Redirect::to('customer_list.php');
}

$errors = [];
$success = '';

if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $name = trim(Input::get('name'));
        $contact_name = trim(Input::get('contact_name'));
        $email = trim(Input::get('email'));
        $phone = trim(Input::get('phone'));
        $notification_emails_inbound = trim(Input::get('notification_emails_inbound'));
        $notification_emails_outbound = trim(Input::get('notification_emails_outbound'));
        $notes = trim(Input::get('notes'));

        if (empty($name)) {
            $errors[] = 'Client name is required.';
        }

        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if ($notification_emails_inbound) {
            $invalid = getInvalidEmails($notification_emails_inbound);
            if (!empty($invalid)) {
                $errors[] = 'These inbound notification emails are not valid: ' . implode(', ', $invalid);
            }
        }

        if ($notification_emails_outbound) {
            $invalid = getInvalidEmails($notification_emails_outbound);
            if (!empty($invalid)) {
                $errors[] = 'These outbound notification emails are not valid: ' . implode(', ', $invalid);
            }
        }

        if (empty($errors) && isCustomerNameTaken($name, $customer_id)) {
            $errors[] = 'A client with this name already exists.';
        }

        if (empty($errors)) {
            updateCustomer($customer_id, [
                'name' => $name,
                'contact_name' => $contact_name,
                'email' => $email,
                'phone' => $phone,
                'notification_emails_inbound' => $notification_emails_inbound,
                'notification_emails_outbound' => $notification_emails_outbound,
                'notes' => $notes
            ]);

            $success = 'Client updated successfully!';
            $customer = getCustomerById($customer_id);
        }
    } else {
        $errors[] = 'Invalid CSRF token.';
    }
}
?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <h1 class="page-header" style="margin-bottom:0;">Edit Client</h1>
                <a href="customer_photo_types.php?customer_id=<?php echo $customer->id; ?>" class="btn btn-default">
                    <i class="fa fa-camera"></i> Photo Type Requirements
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="panel panel-default">
                    <div class="panel-body">
                        <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul style="margin:0;">
                                <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <input type="hidden" name="csrf" value="<?php echo Token::generate(); ?>">

                            <div class="form-group">
                                <label for="name">Client Name *</label>
                                <input type="text" class="form-control" id="name" name="name" required
                                       value="<?php echo htmlspecialchars($customer->name); ?>">
                            </div>

                            <div class="form-group">
                                <label for="contact_name">Contact Name</label>
                                <input type="text" class="form-control" id="contact_name" name="contact_name"
                                       value="<?php echo htmlspecialchars($customer->contact_name ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo htmlspecialchars($customer->email ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone"
                                       value="<?php echo htmlspecialchars($customer->phone ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="notification_emails_inbound">
                                    <span class="label label-info">Inbound</span> Notification Emails
                                </label>
                                <textarea class="form-control" id="notification_emails_inbound" name="notification_emails_inbound" rows="2"><?php echo htmlspecialchars($customer->notification_emails_inbound ?? ''); ?></textarea>
                                <small class="form-text text-muted">
                                    Receives photos automatically when an <strong>inbound</strong> container for this client is marked Completed. One email per line or comma-separated.
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="notification_emails_outbound">
                                    <span class="label label-success">Outbound</span> Notification Emails
                                </label>
                                <textarea class="form-control" id="notification_emails_outbound" name="notification_emails_outbound" rows="2"><?php echo htmlspecialchars($customer->notification_emails_outbound ?? ''); ?></textarea>
                                <small class="form-text text-muted">
                                    Receives photos automatically when an <strong>outbound</strong> container for this client is marked Completed. One email per line or comma-separated.
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($customer->notes ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa fa-save"></i> Update Client
                                </button>
                                <a href="customer_list.php" class="btn btn-default">Back to Clients</a>
                            </div>
                        </form>
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
