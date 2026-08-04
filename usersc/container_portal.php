<?php
/**
 * Client Portal — Container Tracking System
 * Requires login. Access is gated by the client permission (configurable in settings).
 * Client users are linked to a customer via the container_client_users table.
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) { die(); }

// Must have client permission
if (!isClient()) {
    Redirect::to('../index.php');
}

$user_id  = $user->data()->id;
$customer = getClientCustomer($user_id);

// If no customer linked yet, show a helpful message
if (!$customer) {
    require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
    ?>
    <div class="container-fluid" style="max-width:600px;margin:60px auto;text-align:center;font-family:Arial,sans-serif;">
        <div style="background:#fff;border-radius:10px;padding:40px;box-shadow:0 2px 8px rgba(0,0,0,.08);">
            <i class="fa fa-building-o" style="font-size:48px;color:#d1d5db;margin-bottom:16px;display:block;"></i>
            <h2 style="margin:0 0 10px;color:#1e3a5f;">Not Linked to a Client</h2>
            <p style="color:#6b7280;margin:0;">Your account hasn't been linked to a client yet. Please contact the warehouse team to get access set up.</p>
        </div>
    </div>
    <?php
    require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php';
    exit;
}

// Load this client's containers (reviewed only — completed work)
$db = DB::getInstance();
$containers = $db->query(
    "SELECT c.*, 
            (SELECT COUNT(*) FROM container_photos cp WHERE cp.container_id = c.id) AS photo_count
     FROM containers c
     WHERE c.customer_id = ?
     ORDER BY c.created_at DESC",
    [$customer->id]
)->results() ?: [];

// Group by status for summary
$by_status = ['pending'=>0,'in_progress'=>0,'completed'=>0,'reviewed'=>0];
foreach ($containers as $c) { if (isset($by_status[$c->status])) $by_status[$c->status]++; }

global $photo_types;
$base_url = rtrim(getContainerSetting('site_url', CONTAINER_SITE_URL), '/');

// Selected container for detail view
$selected_id = (int) Input::get('container');
$selected    = null;
$sel_photos  = [];
$sel_by_type = [];
if ($selected_id) {
    foreach ($containers as $c) {
        if ((int)$c->id === $selected_id) { $selected = $c; break; }
    }
    if ($selected) {
        $sel_photos = getContainerPhotos($selected_id);
        foreach ($sel_photos as $p) $sel_by_type[$p->photo_type][] = $p;
    }
}

$status_label = ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Awaiting Send','reviewed'=>'Complete'];
$status_color = ['pending'=>'#f59e0b','in_progress'=>'#3b82f6','completed'=>'#7c3aed','reviewed'=>'#15803d'];
?>
<style>
.cp-wrap{max-width:1100px;margin:0 auto;padding:20px 16px 48px;}
.cp-header{background:#1e3a5f;color:#fff;border-radius:10px;padding:20px 24px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
.cp-header h2{margin:0;font-size:20px;font-weight:700;}
.cp-header p{margin:4px 0 0;font-size:13px;color:#9bbdd6;}
.cp-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-bottom:20px;}
.cp-stat{background:#fff;border-radius:8px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border-top:3px solid #e5e7eb;}
.cp-stat-n{font-size:26px;font-weight:700;line-height:1;}
.cp-stat-l{font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:4px;}
.cp-card{background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.06);overflow:hidden;margin-bottom:20px;}
.cp-card-header{padding:16px 20px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:15px;display:flex;align-items:center;justify-content:space-between;}
table.cp-table{width:100%;border-collapse:collapse;font-size:13px;}
table.cp-table th{text-align:left;padding:10px 14px;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e5e7eb;background:#f9fafb;}
table.cp-table td{padding:12px 14px;border-bottom:1px solid #f8fafc;}
table.cp-table tr:hover td{background:#f9fafb;}
.cp-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;}
.cp-row-link{color:#1e3a5f;text-decoration:none;font-weight:700;cursor:pointer;}
.cp-row-link:hover{text-decoration:underline;}

/* Detail panel */
.cp-detail{background:#fff;border-radius:10px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:20px;}
.cp-detail-info{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin-bottom:20px;}
.cp-detail-info label{font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:3px;}
.cp-detail-info span{font-size:14px;font-weight:600;color:#374151;}
.cp-photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;}
.cp-photo{border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;}
.cp-photo img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;cursor:zoom-in;}
.cp-photo-foot{padding:7px 10px;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;font-size:11px;}
.cp-dl{color:#1e3a5f;font-weight:700;text-decoration:none;font-size:12px;}
.cp-dl:hover{text-decoration:underline;}
.cp-section-h{font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em;margin:16px 0 10px;border-bottom:1px solid #f1f5f9;padding-bottom:8px;}
#lb{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;align-items:center;justify-content:center;cursor:zoom-out;}
#lb.open{display:flex;}
#lb img{max-width:92vw;max-height:90vh;object-fit:contain;border-radius:6px;cursor:default;}
#lb-x{position:fixed;top:14px;right:18px;color:#fff;font-size:30px;cursor:pointer;line-height:1;}
#lb-dl{position:fixed;bottom:18px;right:18px;background:#1e3a5f;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:700;}
@media(max-width:600px){.cp-photo-grid{grid-template-columns:repeat(2,1fr);}}
</style>

<div id="page-wrapper">
<div class="container-fluid">
<div class="cp-wrap">

<div class="cp-header">
    <div>
        <h2><?php echo htmlspecialchars($customer->name); ?></h2>
        <p>Container Tracking Portal</p>
    </div>
    <div style="font-size:13px;color:#9bbdd6;">
        Logged in as <?php echo htmlspecialchars($user->data()->fname . ' ' . $user->data()->lname); ?>
    </div>
</div>

<!-- Stats -->
<div class="cp-stats">
    <?php
    $stat_data = [
        ['Total Containers', count($containers), '#1e3a5f'],
        ['Complete',         $by_status['reviewed'],    '#15803d'],
        ['Awaiting Send',    $by_status['completed'],   '#7c3aed'],
        ['In Progress',      $by_status['in_progress'], '#3b82f6'],
    ];
    foreach ($stat_data as [$label, $count, $color]): ?>
    <div class="cp-stat" style="border-top-color:<?php echo $color; ?>;">
        <div class="cp-stat-n" style="color:<?php echo $color; ?>;"><?php echo $count; ?></div>
        <div class="cp-stat-l"><?php echo $label; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($selected): ?>
<!-- Detail view -->
<div style="margin-bottom:8px;">
    <a href="container_portal.php" class="btn btn-default btn-sm">
        <i class="fa fa-arrow-left"></i> Back to all containers
    </a>
</div>
<div class="cp-detail">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;">
        <h3 style="margin:0;font-size:22px;font-weight:800;font-family:Courier New,monospace;"><?php echo htmlspecialchars($selected->container_number); ?></h3>
        <span class="cp-badge" style="background:<?php echo $status_color[$selected->status]??'#6b7280'; ?>22;color:<?php echo $status_color[$selected->status]??'#6b7280'; ?>;">
            <?php echo $status_label[$selected->status] ?? ucfirst($selected->status); ?>
        </span>
        <span class="cp-badge" style="background:#e0e7ff;color:#3730a3;"><?php echo ucfirst($selected->type); ?></span>
    </div>
    <div class="cp-detail-info">
        <?php
        $fields = [
            'Shipment #'  => $selected->shipment_number,
            'Carrier'     => $selected->carrier,
            'PO / BOL'    => $selected->po_bol_number,
            'Seal #'      => $selected->seal_number,
            ($selected->type==='inbound'?'Receipt Date':'Ship Date') => $selected->receipt_ship_date ? date('M j, Y', strtotime($selected->receipt_ship_date)) : null,
            'Photos'      => count($sel_photos),
        ];
        foreach ($fields as $label => $val):
            if ($val === null || $val === '') continue; ?>
        <div>
            <label><?php echo $label; ?></label>
            <span><?php echo htmlspecialchars((string)$val); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($sel_photos)): ?>
    <p style="color:#9ca3af;text-align:center;padding:24px 0;">No photos uploaded yet.</p>
    <?php else: ?>
        <?php foreach ($sel_by_type as $type_key => $type_photos):
            $pt_labels = [];
            foreach (($photo_types[$selected->type] ?? []) as $k => $cfg) $pt_labels[$k] = $cfg['label'] ?? ucwords(str_replace('_',' ',$k));
            $type_label_txt = $pt_labels[$type_key] ?? ucwords(str_replace('_',' ',$type_key));
        ?>
        <div class="cp-section-h"><?php echo htmlspecialchars($type_label_txt); ?> (<?php echo count($type_photos); ?>)</div>
        <div class="cp-photo-grid">
            <?php foreach ($type_photos as $photo):
                $photo_url = $base_url . '/' . ltrim($photo->file_path, '/'); ?>
            <div class="cp-photo">
                <img src="<?php echo htmlspecialchars($photo_url); ?>"
                     alt="<?php echo htmlspecialchars($photo->file_name); ?>"
                     loading="lazy"
                     onclick="openLb('<?php echo addslashes($photo_url); ?>')">
                <div class="cp-photo-foot">
                    <span><?php echo $photo->uploaded_at ? date('M j', strtotime($photo->uploaded_at)) : ''; ?></span>
                    <a href="<?php echo htmlspecialchars($photo_url); ?>" download="<?php echo htmlspecialchars($photo->file_name); ?>" class="cp-dl">&#8681; Download</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Container list -->
<div class="cp-card">
    <div class="cp-card-header">
        All Containers
        <span style="font-size:12px;color:#9ca3af;font-weight:400;"><?php echo count($containers); ?> total</span>
    </div>
    <?php if (empty($containers)): ?>
    <div style="text-align:center;padding:32px;color:#9ca3af;">No containers yet.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="cp-table">
        <thead>
            <tr>
                <th>Container #</th>
                <th>Type</th>
                <th>Status</th>
                <th>Shipment #</th>
                <th>Date</th>
                <th>Photos</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($containers as $c): ?>
        <tr>
            <td>
                <?php if ($c->photo_count > 0 || $c->status === 'reviewed'): ?>
                <a href="container_portal.php?container=<?php echo $c->id; ?>" class="cp-row-link">
                    <?php echo htmlspecialchars($c->container_number); ?>
                </a>
                <?php else: ?>
                <span style="font-family:Courier New,monospace;font-weight:700;"><?php echo htmlspecialchars($c->container_number); ?></span>
                <?php endif; ?>
            </td>
            <td><?php echo ucfirst($c->type); ?></td>
            <td>
                <span class="cp-badge" style="background:<?php echo ($status_color[$c->status]??'#6b7280'); ?>22;color:<?php echo ($status_color[$c->status]??'#6b7280'); ?>;">
                    <?php echo $status_label[$c->status] ?? ucfirst($c->status); ?>
                </span>
            </td>
            <td style="color:#6b7280;"><?php echo htmlspecialchars($c->shipment_number ?: '—'); ?></td>
            <td style="color:#6b7280;font-size:12px;"><?php echo date('M j, Y', strtotime($c->created_at)); ?></td>
            <td style="color:#6b7280;"><?php echo $c->photo_count; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

</div></div></div>

<div id="lb" onclick="closeLb()">
    <span id="lb-x" onclick="closeLb()">&#x2715;</span>
    <img id="lb-img" src="" alt="" onclick="event.stopPropagation()">
    <a id="lb-dl" href="#" download onclick="event.stopPropagation()">&#8681; Download</a>
</div>
<script>
function openLb(src){document.getElementById('lb-img').src=src;document.getElementById('lb-dl').href=src;document.getElementById('lb').classList.add('open');}
function closeLb(){document.getElementById('lb').classList.remove('open');document.getElementById('lb-img').src='';}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeLb();});
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
