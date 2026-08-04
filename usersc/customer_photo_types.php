<?php
/**
 * Customer Photo Requirements — Container Tracking System
 * Supervisors define which photo types are required for each client.
 * Defaults to the global photo_types config if no client-specific config is set.
 *
 * Run this SQL once:
 *   CREATE TABLE IF NOT EXISTS customer_photo_requirements (
 *     id             INT AUTO_INCREMENT PRIMARY KEY,
 *     customer_id    INT NOT NULL,
 *     container_type ENUM('inbound','outbound') NOT NULL,
 *     photo_type_key VARCHAR(80) NOT NULL,
 *     label          VARCHAR(120) NOT NULL,
 *     sort_order     INT DEFAULT 0,
 *     INDEX idx_cust_type (customer_id, container_type)
 *   );
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) { die(); }

$db   = DB::getInstance();
$csrf = Token::generate();

// ── Auto-create table if missing ─────────────────────────────────────────────
try {
    $db->query("CREATE TABLE IF NOT EXISTS customer_photo_requirements (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        customer_id    INT NOT NULL,
        container_type ENUM('inbound','outbound') NOT NULL,
        photo_type_key VARCHAR(80) NOT NULL,
        label          VARCHAR(120) NOT NULL,
        sort_order     INT DEFAULT 0,
        INDEX idx_cust_type (customer_id, container_type)
    )");
} catch (\Throwable $e) {}

// ── Handle saves ──────────────────────────────────────────────────────────────
$message = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Token::check(Input::get('csrf'))) {
    $action        = Input::get('action');
    $cust_id       = (int) Input::get('customer_id');
    $cont_type     = Input::get('container_type') === 'outbound' ? 'outbound' : 'inbound';

    if ($action === 'save' && $cust_id) {
        // Collect submitted photo types
        $keys   = $_POST['photo_type_key']  ?? [];
        $labels = $_POST['photo_type_label'] ?? [];

        // Delete existing for this customer+type
        $db->query("DELETE FROM customer_photo_requirements WHERE customer_id = ? AND container_type = ?",
            [$cust_id, $cont_type]);

        // Insert new
        $inserted = 0;
        foreach ($keys as $i => $key) {
            $key   = trim($key);
            $label = trim($labels[$i] ?? '');
            if (!$key || !$label) continue;
            // Sanitize key to alphanumeric + underscore
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower($key));
            $db->query(
                "INSERT INTO customer_photo_requirements (customer_id, container_type, photo_type_key, label, sort_order)
                 VALUES (?, ?, ?, ?, ?)",
                [$cust_id, $cont_type, $key, $label, $i]
            );
            $inserted++;
        }

        if ($inserted === 0) {
            $message  = 'Configuration cleared — this client will now use the default photo types.';
        } else {
            $message  = "Saved {$inserted} photo type(s) for this client.";
        }

    } elseif ($action === 'reset' && $cust_id) {
        $cont_type = Input::get('container_type') === 'outbound' ? 'outbound' : 'inbound';
        $db->query("DELETE FROM customer_photo_requirements WHERE customer_id = ? AND container_type = ?",
            [$cust_id, $cont_type]);
        $message  = 'Reset to default photo types.';
    }

    Redirect::to('customer_photo_types.php?customer_id=' . $cust_id . '&type=' . $cont_type
        . '&msg=' . urlencode($message));
}

if (Input::get('msg')) $message = Input::get('msg');

// ── Load data ─────────────────────────────────────────────────────────────────
global $photo_types;
$customers   = getAllCustomers();
$selected_id = (int)(Input::get('customer_id') ?? ($customers[0]->id ?? 0));
$cont_type   = Input::get('type') === 'outbound' ? 'outbound' : 'inbound';

$selected_customer = null;
foreach ($customers as $c) {
    if ((int)$c->id === $selected_id) { $selected_customer = $c; break; }
}

// Current config for this customer+type (raw rows, so we know if custom exists)
$custom_rows = [];
if ($selected_id) {
    try {
        $custom_rows = $db->query(
            "SELECT photo_type_key, label FROM customer_photo_requirements
             WHERE customer_id = ? AND container_type = ? ORDER BY sort_order ASC",
            [$selected_id, $cont_type]
        )->results() ?: [];
    } catch (\Throwable $e) {}
}

$is_custom    = !empty($custom_rows);
$default_types = $photo_types[$cont_type] ?? [];

// Build editing list — custom if set, default otherwise
$editing_types = $is_custom
    ? array_combine(
        array_column($custom_rows, 'photo_type_key'),
        array_column($custom_rows, 'label')
      )
    : $default_types;
?>
<style>
.cpt-wrap{max-width:860px;margin:0 auto;padding:20px 16px 40px;}
.cpt-card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:18px;overflow:hidden;}
.cpt-card-h{padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e5e7eb;font-weight:700;font-size:14px;color:#374151;display:flex;align-items:center;justify-content:space-between;}
.cpt-card-b{padding:20px;}
.type-row{display:flex;gap:8px;align-items:center;padding:10px 0;border-bottom:1px solid #f3f4f6;}
.type-row:last-child{border-bottom:none;}
.type-row input[type=text]{flex:1;border:1px solid #e5e7eb;border-radius:6px;padding:7px 10px;font-size:13px;}
.type-row input[type=text]:first-child{flex:0 0 160px;font-family:Courier New,monospace;font-size:12px;color:#6b7280;}
.type-row button{background:none;border:none;color:#dc2626;cursor:pointer;padding:4px 8px;font-size:16px;line-height:1;}
.add-row-btn{margin-top:10px;font-size:13px;}
.badge-default{display:inline-block;padding:2px 8px;background:#e0e7ff;color:#3730a3;border-radius:10px;font-size:11px;font-weight:700;}
.badge-custom{display:inline-block;padding:2px 8px;background:#d1fae5;color:#065f46;border-radius:10px;font-size:11px;font-weight:700;}
.cpt-nav{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.cpt-nav-btn{padding:8px 16px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-size:13px;color:#374151;}
.cpt-nav-btn.active{background:#1e3a5f;color:#fff;border-color:#1e3a5f;font-weight:600;}
.hint{font-size:12px;color:#9ca3af;margin-top:4px;}
</style>

<div id="page-wrapper">
<div class="container-fluid">
<div class="cpt-wrap">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
    <h2 style="margin:0;font-size:20px;">Client Photo Requirements</h2>
    <a href="container_dashboard.php" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Dashboard</a>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="cpt-card">
    <div class="cpt-card-h">Select Client</div>
    <div class="cpt-card-b">
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <select id="customerPicker" class="form-control" style="max-width:280px;">
                <?php foreach ($customers as $c): ?>
                <option value="<?php echo $c->id; ?>" <?php echo ((int)$c->id === $selected_id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c->name); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="cpt-nav">
                <button class="cpt-nav-btn <?php echo $cont_type === 'inbound' ? 'active' : ''; ?>"
                        onclick="switchType('inbound')">Inbound</button>
                <button class="cpt-nav-btn <?php echo $cont_type === 'outbound' ? 'active' : ''; ?>"
                        onclick="switchType('outbound')">Outbound</button>
            </div>
        </div>
    </div>
</div>

<?php if ($selected_customer): ?>
<div class="cpt-card">
    <div class="cpt-card-h">
        <span>
            <?php echo htmlspecialchars($selected_customer->name); ?> &mdash; <?php echo ucfirst($cont_type); ?>
            &nbsp;
            <?php if ($is_custom): ?>
            <span class="badge-custom">Custom</span>
            <?php else: ?>
            <span class="badge-default">Using Default</span>
            <?php endif; ?>
        </span>
        <?php if ($is_custom): ?>
        <form method="post" style="margin:0;" onsubmit="return confirm('Reset to default photo types?');">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="customer_id" value="<?php echo $selected_id; ?>">
            <input type="hidden" name="container_type" value="<?php echo $cont_type; ?>">
            <button type="submit" class="btn btn-default btn-xs">Reset to Default</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="cpt-card-b">
        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;">
            <?php if ($is_custom): ?>
            This client has a custom photo checklist. Edit below or reset to use the warehouse default.
            <?php else: ?>
            This client is currently using the warehouse default photo checklist. Customise it below to create a client-specific version.
            <?php endif; ?>
        </p>

        <form method="post" id="photoTypesForm">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="customer_id" value="<?php echo $selected_id; ?>">
            <input type="hidden" name="container_type" value="<?php echo $cont_type; ?>">

            <div style="display:grid;grid-template-columns:180px 1fr 36px;gap:8px;padding:0 0 8px;border-bottom:2px solid #e5e7eb;margin-bottom:4px;">
                <div style="font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Type Key</div>
                <div style="font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Label shown to staff</div>
                <div></div>
            </div>

            <div id="typeRows">
                <?php foreach ($editing_types as $key => $label): ?>
                <div class="type-row">
                    <input type="text" name="photo_type_key[]" value="<?php echo htmlspecialchars($key); ?>"
                           placeholder="type_key" pattern="[a-zA-Z0-9_]+" title="Letters, numbers, underscores only">
                    <input type="text" name="photo_type_label[]" value="<?php echo htmlspecialchars($label); ?>"
                           placeholder="Label e.g. Seal Photo" required>
                    <button type="button" onclick="this.closest('.type-row').remove()" title="Remove">&#10005;</button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn btn-default btn-sm add-row-btn" onclick="addRow()">
                <i class="fa fa-plus"></i> Add Photo Type
            </button>

            <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <button type="submit" class="btn btn-primary">Save Client Config</button>
                <?php if (!$is_custom): ?>
                <span class="hint">Saving will create a custom config for this client based on the current default.</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Default reference -->
<div class="cpt-card">
    <div class="cpt-card-h">Warehouse Default — <?php echo ucfirst($cont_type); ?></div>
    <div class="cpt-card-b">
        <p style="font-size:12px;color:#9ca3af;margin:0 0 12px;">
            These are the built-in defaults defined in <code>container_functions.php</code>. Clients without custom config use these.
        </p>
        <?php foreach ($default_types as $k => $l): ?>
        <div style="display:flex;gap:12px;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">
            <code style="color:#6b7280;width:180px;flex-shrink:0;"><?php echo htmlspecialchars($k); ?></code>
            <span><?php echo htmlspecialchars($l); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div></div></div>

<script>
function addRow() {
    var row = document.createElement('div');
    row.className = 'type-row';
    row.innerHTML = '<input type="text" name="photo_type_key[]" placeholder="type_key" pattern="[a-zA-Z0-9_]+" title="Letters, numbers, underscores only">'
        + '<input type="text" name="photo_type_label[]" placeholder="Label e.g. Interior Photo" required>'
        + '<button type="button" onclick="this.closest(\'.type-row\').remove()" title="Remove">&#10005;</button>';
    document.getElementById('typeRows').appendChild(row);
    row.querySelector('input').focus();
}

function switchType(type) {
    window.location = 'customer_photo_types.php?customer_id=<?php echo $selected_id; ?>&type=' + type;
}

document.getElementById('customerPicker').addEventListener('change', function() {
    window.location = 'customer_photo_types.php?customer_id=' + this.value + '&type=<?php echo $cont_type; ?>';
});
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
