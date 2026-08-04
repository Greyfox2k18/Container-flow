<?php
/**
 * Client User Management — Container Tracking System
 * Supervisor-only page for linking UserSpice accounts to customers
 * so they can access the client portal.
 *
 * Run this SQL once to create the mapping table:
 *   CREATE TABLE IF NOT EXISTS container_client_users (
 *     id         INT AUTO_INCREMENT PRIMARY KEY,
 *     user_id    INT NOT NULL,
 *     customer_id INT NOT NULL,
 *     created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE KEY uk_user (user_id),
 *     INDEX idx_customer (customer_id)
 *   );
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) { die(); }

$db          = DB::getInstance();
$csrf        = Token::generate();
$message     = '';
$message_type= 'success';

// ── Create table if not exists ────────────────────────────────────────────────
try {
    $db->query("CREATE TABLE IF NOT EXISTS container_client_users (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        customer_id INT NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user (user_id),
        INDEX idx_customer (customer_id)
    )");
} catch (\Throwable $e) { /* already exists */ }

// ── Handle actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Token::check(Input::get('csrf'))) {
    $action      = Input::get('action');
    $link_user   = (int) Input::get('user_id');
    $link_cust   = (int) Input::get('customer_id');

    if ($action === 'link' && $link_user && $link_cust) {
        try {
            $db->query(
                "INSERT INTO container_client_users (user_id, customer_id) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id), created_at = NOW()",
                [$link_user, $link_cust]
            );
            $message = 'Client user linked successfully.';
        } catch (\Throwable $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    } elseif ($action === 'unlink' && $link_user) {
        try {
            $db->query("DELETE FROM container_client_users WHERE user_id = ?", [$link_user]);
            $message = 'Client user unlinked.';
        } catch (\Throwable $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
    Redirect::to('container_client_users.php' . ($message ? '?msg=' . urlencode($message) . '&type=' . $message_type : ''));
}

if (Input::get('msg')) {
    $message      = Input::get('msg');
    $message_type = Input::get('type') ?: 'success';
}

// ── Load data ─────────────────────────────────────────────────────────────────
$customers  = getAllCustomers();
$client_perm = (int) getContainerSetting('client_permission_id', 0);

// Get all users who have the client permission (if set)
$client_user_ids = [];
if ($client_perm > 0) {
    try {
        $perm_rows = fetchPermissionUsers($client_perm);
        $client_user_ids = array_map('intval', array_column((array)$perm_rows, 'user_id'));
    } catch (\Throwable $e) {}
}

// Get all UserSpice users
$all_users = $db->query(
    "SELECT id, username, fname, lname, email FROM users WHERE active = 1 ORDER BY lname, fname"
)->results() ?: [];

// Get existing mappings
$mappings = $db->query(
    "SELECT ccu.*, u.fname, u.lname, u.email, u.username, cu.name AS customer_name
     FROM container_client_users ccu
     JOIN users u ON u.id = ccu.user_id
     JOIN customers cu ON cu.id = ccu.customer_id
     ORDER BY cu.name, u.lname"
)->results() ?: [];

$mapped_user_ids = array_map(fn($m) => (int)$m->user_id, $mappings);
?>
<style>
.ccu-wrap{max-width:900px;margin:0 auto;padding:20px 16px 40px;}
.ccu-card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;}
.ccu-card-header{padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e5e7eb;font-weight:700;font-size:14px;color:#374151;}
.ccu-card-body{padding:20px;}
table.ccu-table{width:100%;border-collapse:collapse;font-size:13px;}
table.ccu-table th{text-align:left;padding:9px 12px;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e5e7eb;background:#f9fafb;}
table.ccu-table td{padding:10px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.ccu-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e40af;}
.ccu-perm-badge{background:#d1fae5;color:#065f46;}
.hint{font-size:12px;color:#9ca3af;margin-top:4px;}
</style>

<div id="page-wrapper">
<div class="container-fluid">
<div class="ccu-wrap">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
    <h2 style="margin:0;font-size:20px;">Client Portal Users</h2>
    <a href="container_dashboard.php" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Dashboard</a>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Setup info -->
<?php if (!$client_perm): ?>
<div class="alert alert-warning">
    <strong>Setup needed:</strong> Go to <a href="container_settings.php">Settings</a> and set the <strong>Client Permission ID</strong>. 
    First create a permission in the UserSpice admin panel (Admin &rarr; Permissions &rarr; Add), then paste its ID in settings.
    Users assigned that permission will be able to log in and see the client portal.
</div>
<?php else: ?>
<div class="alert alert-info">
    Client permission ID is set to <strong><?php echo $client_perm; ?></strong>.
    Assign this permission to client users in UserSpice Admin &rarr; Users &rarr; Edit User &rarr; Permissions.
    Then link them to a customer below.
</div>
<?php endif; ?>

<!-- Existing mappings -->
<div class="ccu-card">
    <div class="ccu-card-header">Current Client User Assignments (<?php echo count($mappings); ?>)</div>
    <div>
        <?php if (empty($mappings)): ?>
        <div style="text-align:center;padding:24px;color:#9ca3af;font-size:13px;">No client users assigned yet.</div>
        <?php else: ?>
        <table class="ccu-table">
            <thead><tr><th>User</th><th>Email</th><th>Customer</th><th>Has Client Perm</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mappings as $m): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($m->fname . ' ' . $m->lname); ?></strong>
                    <div style="font-size:11px;color:#9ca3af;">@<?php echo htmlspecialchars($m->username); ?></div>
                </td>
                <td style="color:#6b7280;font-size:12px;"><?php echo htmlspecialchars($m->email); ?></td>
                <td><span class="ccu-badge"><?php echo htmlspecialchars($m->customer_name); ?></span></td>
                <td>
                    <?php if ($client_perm && in_array((int)$m->user_id, $client_user_ids)): ?>
                    <span class="ccu-badge ccu-perm-badge">&#10003; Yes</span>
                    <?php else: ?>
                    <span style="color:#f59e0b;font-size:12px;">&#9888; Not assigned</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" onsubmit="return confirm('Remove this client user link?');">
                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="unlink">
                        <input type="hidden" name="user_id" value="<?php echo $m->user_id; ?>">
                        <button type="submit" class="btn btn-danger btn-xs">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Add new mapping -->
<div class="ccu-card">
    <div class="ccu-card-header">Link a User to a Customer</div>
    <div class="ccu-card-body">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="link">
            <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;flex-wrap:wrap;">
                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">UserSpice User</label>
                    <select name="user_id" class="form-control" required>
                        <option value="">— Select user —</option>
                        <?php foreach ($all_users as $u): ?>
                        <option value="<?php echo $u->id; ?>"
                            <?php echo in_array((int)$u->id, $mapped_user_ids) ? 'style="color:#9ca3af;"' : ''; ?>>
                            <?php echo htmlspecialchars($u->fname . ' ' . $u->lname . ' (' . $u->email . ')'); ?>
                            <?php echo in_array((int)$u->id, $mapped_user_ids) ? ' [already linked]' : ''; ?>
                            <?php echo ($client_perm && in_array((int)$u->id, $client_user_ids)) ? ' ✓' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Users marked ✓ already have the client permission</div>
                </div>
                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Customer</label>
                    <select name="customer_id" class="form-control" required>
                        <option value="">— Select customer —</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c->id; ?>"><?php echo htmlspecialchars($c->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Link User</button>
                </div>
            </div>
        </form>
        <div class="hint" style="margin-top:12px;">
            <strong>Portal URL for clients:</strong>
            <code><?php echo htmlspecialchars(rtrim(getContainerSetting('site_url', CONTAINER_SITE_URL), '/') . '/usersc/container_portal.php'); ?></code>
        </div>
    </div>
</div>

</div></div></div>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
