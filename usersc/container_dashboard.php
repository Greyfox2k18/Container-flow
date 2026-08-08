<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$user_id = $user->data()->id;
$is_supervisor = isSupervisor();

// ── Points / reward ───────────────────────────────────────────────────────────
$reward_on_load    = false;
$reward_threshold  = (int) getContainerSetting('points_reward_threshold', 1000);
$reward_review_url = getContainerSetting('points_review_url', '');
$user_pts          = (int)($user->data()->plg_points ?? 0);
if ($user_pts > 0 && $reward_threshold > 0 && $user_pts >= $reward_threshold) $reward_on_load = true;


// Restricted to the warehouse(s) the current user is tagged for (see
// usersc/warehouses.php) — untagged users and unassigned containers stay
// unrestricted, so nothing changes here until a warehouse is set up.
$containers = getAllContainers();
$customers = getAllCustomers();
$warehouses = getWarehousesForUser($user_id); // only the user's own warehouse(s) — or all, if untagged

// Statistics
$total_containers = count($containers);
$pending_count = count(array_filter($containers, fn($c) => $c->status == 'pending'));
$in_progress_count = count(array_filter($containers, fn($c) => $c->status == 'in_progress'));
$completed_count = count(array_filter($containers, fn($c) => $c->status == 'completed'));
$reviewed_count = count(array_filter($containers, fn($c) => $c->status == 'reviewed'));
$inbound_count = count(array_filter($containers, fn($c) => $c->type == 'inbound'));
$outbound_count = count(array_filter($containers, fn($c) => $c->type == 'outbound'));

$csrf = Token::generate();
?>

<style>
/* Desktop Professional Table Styles */
.desktop-dashboard {
    display: block !important;
}

.mobile-dashboard {
    display: none !important;
}

.page-header-section {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px;
    margin: -15px -15px 25px -15px;
    border-radius: 0;
}

.page-header-section h2 {
    margin: 0;
    font-size: 28px;
}

.stats-bar {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-around;
    align-items: center;
}

.stat-item {
    text-align: center;
    padding: 0 20px;
    border-right: 1px solid #e0e0e0;
}

.stat-item:last-child {
    border-right: none;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.toolbar {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.toolbar-row {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.toolbar-item {
    flex: 1;
    min-width: 200px;
}

.toolbar-actions {
    display: flex;
    gap: 10px;
}

.show-completed-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    font-size: 14px;
    color: #495057;
    cursor: pointer;
    white-space: nowrap;
    margin-bottom: 0;
    padding: 0 5px;
}

.show-completed-toggle input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.mobile-show-completed {
    background: white;
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.mobile-no-results {
    text-align: center;
    background: white;
    border-radius: 8px;
    padding: 30px 20px;
    color: #6b7280;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.data-table-container {
    background: white;
    border-radius: 8px;
    overflow-x: auto;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.data-table thead th {
    padding: 15px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.data-table thead th.sortable {
    cursor: pointer;
    user-select: none;
}

.data-table thead th.sortable:hover {
    background: #e9ecef;
}

.data-table tbody td {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
    vertical-align: middle;
}

.data-table tbody tr {
    transition: background 0.15s;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.badge-pill {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
    white-space: nowrap;
}

.actions-cell {
    white-space: nowrap;
}

.btn-icon {
    padding: 6px 10px;
    font-size: 13px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state i {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}

/* Mobile Dashboard Styles */
@media (max-width: 991px) {
    .desktop-dashboard {
        display: none !important;
    }
    
    .mobile-dashboard {
        display: block !important;
    }
}

.mobile-header {
    padding: 15px 0;
    margin-bottom: 10px;
}

.mobile-header h2 {
    margin: 0 0 5px 0;
    font-size: 24px;
    color: #1f2937;
}

/* ── Mobile compact redesign ─────────────────────────────────── */
.mob-stats-toggle {
    display: flex; align-items: center; justify-content: space-between;
    background: #fff; border-radius: 8px; padding: 10px 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 10px;
    font-size: 13px; color: #374151; cursor: pointer; user-select: none;
}
.mob-stats-toggle .counts { display: flex; gap: 14px; font-weight: 600; }
.mob-stats-toggle .counts span { color: #6b7280; font-weight: 400; font-size: 12px; }
.mob-stats-grid {
    display: grid; grid-template-columns: repeat(4,1fr); gap: 8px;
    margin-bottom: 10px; display: none;
}
.mob-stat {
    background: #fff; border-radius: 8px; padding: 10px 6px;
    text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
.mob-stat-n { font-size: 22px; font-weight: 800; line-height: 1; }
.mob-stat-l { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: .04em; margin-top: 3px; }

.mobile-actions {
    display: grid; grid-template-columns: repeat(2,1fr); gap: 10px; margin-bottom: 10px;
}
.mobile-action-btn {
    padding: 14px; border-radius: 8px; text-align: center; font-weight: 600;
    display: flex; flex-direction: column; align-items: center; gap: 6px;
}
.mobile-action-btn i { font-size: 20px; }

.mobile-search { margin-bottom: 10px; }
.mobile-search input {
    width: 100%; padding: 12px 14px; border: 1px solid #e5e7eb;
    border-radius: 8px; font-size: 16px; background: #fff;
}

.mob-reviewed-row {
    background: #fff; border-radius: 8px; padding: 12px 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 10px;
    display: flex; align-items: center; gap: 10px; font-size: 14px; color: #374151;
}
.mob-reviewed-row input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }

/* Compact tap-to-view container tile */
.mob-tile {
    display: block; text-decoration: none; color: inherit;
    background: #fff; border-radius: 10px; padding: 14px 16px;
    margin-bottom: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
    border-left: 4px solid #e5e7eb;
    transition: box-shadow .15s, transform .1s;
    -webkit-tap-highlight-color: transparent;
}
.mob-tile:active { transform: scale(.98); box-shadow: 0 0 0 rgba(0,0,0,.04); }
.mob-tile:hover  { text-decoration: none; color: inherit; }
.mob-tile[data-status=pending]     { border-left-color: #f59e0b; }
.mob-tile[data-status=in_progress] { border-left-color: #3b82f6; }
.mob-tile[data-status=completed]   { border-left-color: #7c3aed; }
.mob-tile[data-status=reviewed]    { border-left-color: #10b981; }
.mob-tile-top {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.mob-tile-num {
    font-size: 17px; font-weight: 800; font-family: 'Courier New', monospace;
    color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.mob-tile-status {
    font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px;
    white-space: nowrap; flex-shrink: 0;
}
.mob-tile-bottom {
    display: flex; align-items: center; gap: 8px; margin-top: 6px; flex-wrap: wrap;
}
.mob-tile-type {
    font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px;
}
.mob-tile-client { font-size: 12px; color: #6b7280; }
.mob-tile-photos { font-size: 11px; color: #9ca3af; margin-left: auto; }
</style>

<div id="page-wrapper">
    <div class="container-fluid">
        
        <!-- DESKTOP DASHBOARD -->
        <div class="desktop-dashboard">
            <!-- Header -->
            <div style="display:flex; justify-content: flex-end; align-items: center; flex-wrap: wrap; gap: 10px; padding: 20px 0;">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php if ($user_pts > 0): ?>
                    <span style="font-size:13px;color:#6b7280;background:#f3f4f6;border-radius:20px;padding:5px 12px;display:inline-flex;align-items:center;gap:5px;">
                        <i class="fa fa-star" style="color:#d97706;"></i>
                        <strong><?php echo number_format($user_pts); ?></strong> pts
                        <?php if ($reward_on_load): ?>
                        &nbsp;<a href="#" id="claimPrizeLink" style="color:#d97706;font-weight:700;">&#127942; Claim Prize</a>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                    <a href="container_dashboard_pro.php" class="btn btn-default"><i class="fa fa-table"></i> Advanced View
                    </a>
                    <a href="container_reports.php" class="btn btn-default"><i class="fa fa-bar-chart"></i> Reports
                        <i class="fa fa-table"></i> Advanced View
                    </a>
                    <?php if ($is_supervisor): ?>
                    <a href="customer_list.php" class="btn btn-default">
                        <i class="fa fa-address-book"></i> Manage Clients
                    </a>
                    <a href="drive_backup_status.php" class="btn btn-default">
                        <i class="fa fa-cloud-upload"></i> Drive Backups
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics Bar -->
            <div class="stats-bar">
                <div class="stat-item">
                    <div class="stat-number" style="color: #667eea;"><?php echo $total_containers; ?></div>
                    <div class="stat-label">Total</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #f59e0b;"><?php echo $in_progress_count; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #3b82f6;"><?php echo $inbound_count; ?></div>
                    <div class="stat-label">Inbound</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #10b981;"><?php echo $outbound_count; ?></div>
                    <div class="stat-label">Outbound</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #f59e0b;"><?php echo $completed_count; ?></div>
                    <div class="stat-label">Awaiting Review</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-row">
                    <div class="toolbar-item">
                        <input type="text" class="form-control" id="searchInput" placeholder="Search containers...">
                    </div>
                    <div class="toolbar-item">
                        <select class="form-control" id="typeFilter">
                            <option value="">All Types</option>
                            <option value="inbound">Inbound</option>
                            <option value="outbound">Outbound</option>
                        </select>
                    </div>
                    <div class="toolbar-item">
                        <select class="form-control" id="statusFilter">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="reviewed">Reviewed</option>
                        </select>
                    </div>
                    <?php if (!empty($customers)): ?>
                    <div class="toolbar-item">
                        <select class="form-control" id="clientFilter">
                            <option value="">All Clients</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo htmlspecialchars($c->name); ?>"><?php echo htmlspecialchars($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($warehouses)): ?>
                    <div class="toolbar-item">
                        <select class="form-control" id="warehouseFilter">
                            <option value="">All Warehouses</option>
                            <?php foreach ($warehouses as $w): ?>
                            <option value="<?php echo htmlspecialchars($w->name); ?>"><?php echo htmlspecialchars($w->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="toolbar-item" style="flex: 0 0 auto; min-width: auto;">
                        <label class="show-completed-toggle" for="showCompletedToggle">
                            <input type="checkbox" id="showCompletedToggle">
                            <span>Show Reviewed</span>
                        </label>
                    </div>
                    <div class="toolbar-actions">
                        <a href="container_create.php?type=inbound" class="btn btn-primary">
                            <i class="fa fa-plus"></i> Inbound
                        </a>
                        <a href="container_create.php?type=outbound" class="btn btn-success">
                            <i class="fa fa-plus"></i> Outbound
                        </a>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <?php if (empty($containers)): ?>
            <div class="data-table-container">
                <div class="empty-state">
                    <i class="fa fa-inbox"></i>
                    <h3>No Containers Found</h3>
                    <p>Create your first container to get started</p>
                </div>
            </div>
            <?php else: ?>
            <div class="data-table-container">
                <table class="data-table" id="containerTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="container">Container #</th>
                            <th class="sortable" data-sort="client">Client</th>
                            <th class="sortable" data-sort="warehouse">Warehouse</th>
                            <th class="sortable" data-sort="shipment">Shipment #</th>
                            <th class="sortable" data-sort="pobol">PO/BOL #</th>
                            <th class="sortable" data-sort="carrier">Carrier</th>
                            <th class="sortable" data-sort="seal">Seal #</th>
                            <th class="sortable" data-sort="type">Type</th>
                            <th class="sortable" data-sort="eventdate">Receipt/Ship Date</th>
                            <th class="sortable" data-sort="status">Status</th>
                            <th class="sortable" data-sort="creator">Created By</th>
                            <th class="sortable" data-sort="date">Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($containers as $container): ?>
                        <tr data-type="<?php echo $container->type; ?>" 
                            data-status="<?php echo $container->status; ?>" 
                            data-client="<?php echo htmlspecialchars($container->customer_name ?? ''); ?>"
                            data-warehouse="<?php echo htmlspecialchars($container->warehouse_name ?? ''); ?>"
                            data-search="<?php echo strtolower($container->container_number . ' ' . ($container->seal_number ?? '') . ' ' . ($container->shipment_number ?? '') . ' ' . ($container->customer_name ?? '') . ' ' . ($container->po_bol_number ?? '') . ' ' . ($container->carrier ?? '') . ' ' . ($container->warehouse_name ?? '')); ?>"
                            data-date="<?php echo strtotime($container->created_at); ?>"
                            data-eventdate="<?php echo $container->receipt_ship_date ? strtotime($container->receipt_ship_date) : 0; ?>">
                            <td><strong><?php echo htmlspecialchars($container->container_number); ?></strong></td>
                            <td><?php echo htmlspecialchars($container->customer_name ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($container->warehouse_name ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($container->shipment_number ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($container->po_bol_number ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($container->carrier ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($container->seal_number ?? '-'); ?></td>
                            <td>
                                <span class="badge-pill" style="background: <?php echo $container->type == 'inbound' ? '#dbeafe' : '#d1fae5'; ?>; color: <?php echo $container->type == 'inbound' ? '#1e40af' : '#065f46'; ?>;">
                                    <?php echo ucfirst($container->type); ?>
                                </span>
                            </td>
                            <td style="white-space: nowrap;"><?php echo $container->receipt_ship_date ? date('M d, Y', strtotime($container->receipt_ship_date)) : '-'; ?></td>
                            <td>
                                <?php
                                $status_colors = [
                                    'pending' => ['bg' => '#fef3c7', 'text' => '#92400e'],
                                    'in_progress' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
                                    'completed' => ['bg' => '#e0e7ff', 'text' => '#3730a3'],
                                    'reviewed' => ['bg' => '#d1fae5', 'text' => '#065f46']
                                ];
                                $colors = $status_colors[$container->status];
                                ?>
                                <span class="badge-pill" style="background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['text']; ?>;">
                                    <?php echo ucwords(str_replace('_', ' ', $container->status)); ?>
                                </span>
                            </td>
                            <td><?php echo isset($container->creator_fname) ? htmlspecialchars($container->creator_fname . ' ' . $container->creator_lname) : '-'; ?></td>
                            <td><?php echo date('M d, Y', strtotime($container->created_at)); ?></td>
                            <td class="actions-cell">
                                <button type="button" class="btn btn-primary btn-xs btn-icon next-status-btn" 
                                        data-container-id="<?php echo $container->id; ?>" 
                                        data-current-status="<?php echo $container->status; ?>" 
                                        title="Next Status">
                                    <i class="fa fa-arrow-right"></i>
                                </button>
                                <a href="container_view.php?id=<?php echo $container->id; ?>" class="btn btn-info btn-xs btn-icon" title="View">
                                    <i class="fa fa-eye"></i>
                                </a>
                                <a href="container_edit.php?id=<?php echo $container->id; ?>" class="btn btn-warning btn-xs btn-icon" title="Edit">
                                    <i class="fa fa-edit"></i>
                                </a>
                                <a href="container_download.php?id=<?php echo $container->id; ?>" class="btn btn-success btn-xs btn-icon" title="Download">
                                    <i class="fa fa-download"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- MOBILE DASHBOARD -->
        <div class="mobile-dashboard">
            <!-- Mobile Header -->
            <div class="mobile-header">
                                <?php if ($is_supervisor): ?>
                <a href="customer_list.php" class="btn btn-default btn-sm" style="margin-top: 8px;">
                    <i class="fa fa-address-book"></i> Manage Clients
                </a>
                <?php endif; ?>
            </div>

            <!-- Mobile Stats — collapsed by default, tap to expand -->
            <?php
            $status_labels_mob = ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Awaiting Review','reviewed'=>'Reviewed'];
            ?>
            <div class="mob-stats-toggle" id="mobStatsToggle">
                <div class="counts">
                    <div><?php echo $total_containers; ?> <span>Total</span></div>
                    <div style="color:#3b82f6;"><?php echo $in_progress_count; ?> <span>Active</span></div>
                    <div style="color:#7c3aed;"><?php echo $completed_count; ?> <span>Ready</span></div>
                </div>
                <i class="fa fa-chevron-down" id="mobStatsChevron" style="color:#9ca3af;font-size:12px;transition:transform .2s;"></i>
            </div>
            <div class="mob-stats-grid" id="mobStatsGrid">
                <?php foreach ([
                    [$total_containers,    '#667eea', 'Total'],
                    [$in_progress_count,   '#f59e0b', 'Active'],
                    [$inbound_count,       '#3b82f6', 'Inbound'],
                    [$outbound_count,      '#10b981', 'Outbound'],
                ] as [$n, $col, $lbl]): ?>
                <div class="mob-stat">
                    <div class="mob-stat-n" style="color:<?php echo $col; ?>;"><?php echo $n; ?></div>
                    <div class="mob-stat-l"><?php echo $lbl; ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Mobile Actions -->
            <div class="mobile-actions">
                <a href="container_create.php?type=inbound" class="btn btn-primary mobile-action-btn">
                    <i class="fa fa-plus-circle"></i>
                    <span>Inbound</span>
                </a>
                <a href="container_create.php?type=outbound" class="btn btn-success mobile-action-btn">
                    <i class="fa fa-plus-circle"></i>
                    <span>Outbound</span>
                </a>
            </div>

            <?php if ($user_pts > 0): ?>
            <div style="text-align:center;padding:4px 0 8px;font-size:13px;color:#6b7280;">
                <i class="fa fa-star" style="color:#d97706;"></i>
                <strong><?php echo number_format($user_pts); ?></strong> pts
                <?php if ($reward_on_load): ?>
                &nbsp;<a href="#" id="claimPrizeLinkMobile" style="color:#d97706;font-weight:700;">&#127942; Claim Prize</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Mobile Search -->
            <div class="mobile-search">
                <input type="text" id="mobileSearch" placeholder="Search containers...">
            </div>

            <!-- Show Reviewed -->
            <label class="mob-reviewed-row" for="mobileShowCompletedToggle">
                <input type="checkbox" id="mobileShowCompletedToggle">
                <span>Show Reviewed (<?php echo $reviewed_count; ?>)</span>
            </label>

            <!-- Mobile Container Tiles — tap to view -->
            <?php if (empty($containers)): ?>
            <div style="text-align:center;padding:40px 20px;color:#9ca3af;">
                <i class="fa fa-inbox" style="font-size:40px;margin-bottom:12px;opacity:.3;display:block;"></i>
                <p>No containers yet. Tap a button above to create one.</p>
            </div>
            <?php else: ?>
            <div id="mobileNoResults" style="display:none;text-align:center;padding:30px 20px;color:#9ca3af;">
                <i class="fa fa-search" style="font-size:32px;opacity:.3;margin-bottom:10px;display:block;"></i>
                <p>No containers match "<strong><span id="mobileNoResultsTerm"></span></strong>"</p>
                <div style="display:flex;gap:10px;justify-content:center;margin-top:12px;">
                    <a href="#" id="mobileCreateInboundSuggestion" class="btn btn-primary btn-sm"><i class="fa fa-plus"></i> Create Inbound</a>
                    <a href="#" id="mobileCreateOutboundSuggestion" class="btn btn-success btn-sm"><i class="fa fa-plus"></i> Create Outbound</a>
                </div>
            </div>
            <div id="mobileContainerList">
                <?php
                $status_bg_mob   = ['pending'=>'#fef3c7','in_progress'=>'#dbeafe','completed'=>'#ede9fe','reviewed'=>'#d1fae5'];
                $status_txt_mob  = ['pending'=>'#92400e','in_progress'=>'#1e40af','completed'=>'#5b21b6','reviewed'=>'#065f46'];
                $status_lbl_mob  = ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Awaiting Review','reviewed'=>'Reviewed'];
                foreach ($containers as $container):
                    $sbg = $status_bg_mob[$container->status]  ?? '#f3f4f6';
                    $stx = $status_txt_mob[$container->status] ?? '#374151';
                    $slb = $status_lbl_mob[$container->status] ?? ucfirst($container->status);
                    $tbg = $container->type === 'inbound' ? '#dbeafe' : '#d1fae5';
                    $ttx = $container->type === 'inbound' ? '#1e40af' : '#065f46';
                ?>
                <a href="container_view.php?id=<?php echo $container->id; ?>"
                   class="mob-tile"
                   data-status="<?php echo $container->status; ?>"
                   data-search="<?php echo strtolower(htmlspecialchars($container->container_number . ' ' . ($container->seal_number ?? '') . ' ' . ($container->shipment_number ?? '') . ' ' . ($container->customer_name ?? '') . ' ' . ($container->po_bol_number ?? '') . ' ' . ($container->carrier ?? '') . ' ' . ($container->warehouse_name ?? ''))); ?>">
                    <div class="mob-tile-top">
                        <div class="mob-tile-num"><?php echo htmlspecialchars($container->container_number); ?></div>
                        <span class="mob-tile-status" style="background:<?php echo $sbg; ?>;color:<?php echo $stx; ?>;"><?php echo $slb; ?></span>
                    </div>
                    <div class="mob-tile-bottom">
                        <span class="mob-tile-type" style="background:<?php echo $tbg; ?>;color:<?php echo $ttx; ?>;"><?php echo ucfirst($container->type); ?></span>
                        <?php if ($container->customer_name): ?>
                        <span class="mob-tile-client"><?php echo htmlspecialchars($container->customer_name); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($container->warehouse_name)): ?>
                        <span class="mob-tile-client"><i class="fa fa-building-o"></i> <?php echo htmlspecialchars($container->warehouse_name); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($container->photo_count)): ?>
                        <span class="mob-tile-photos"><i class="fa fa-camera"></i> <?php echo $container->photo_count; ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Next Status review modal (shared across all rows/cards) -->
<div class="review-modal-overlay" id="reviewModalOverlay">
    <div class="review-modal-box">
        <h4>Mark as Reviewed?</h4>
        <div id="reviewModalLoading" style="text-align: center; padding: 20px; color: #888;">
            <i class="fa fa-spinner fa-spin"></i> Loading photos...
        </div>
        <div id="reviewModalContent" style="display: none;">
            <div id="reviewModalInfo" style="font-size: 13px; color: #374151; margin-bottom: 12px;"></div>
            <div id="reviewModalPhotoGrid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; max-height: 320px; overflow-y: auto; margin-bottom: 14px;"></div>
            <p style="font-size: 13px; color: #374151;">
                Confirming will mark this container as <strong>Reviewed</strong> and email these photos to the client's notification list for this direction.
            </p>
        </div>
        <div id="reviewModalMsg"></div>
        <div style="display: flex; gap: 10px; margin-top: 12px;">
            <button type="button" id="reviewModalCancel" class="btn btn-default" style="flex:1;">Cancel</button>
            <button type="button" id="reviewModalConfirm" class="btn btn-primary" style="flex:1;" disabled>
                <i class="fa fa-check"></i> Confirm & Send
            </button>
        </div>
    </div>
</div>

<style>
.review-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(17,24,39,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1100;
    padding: 20px;
}
.review-modal-overlay.open {
    display: flex;
}
.review-modal-box {
    width: 460px;
    max-width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
    padding: 22px;
}
.review-modal-box h4 {
    margin: 0 0 12px 0;
}
.review-modal-photo-tile img {
    width: 100%;
    height: 80px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}
</style>

<!-- ── Points toast ──────────────────────────────────────── -->
<div id="pts-toast" style="position:fixed;bottom:24px;right:24px;background:#1e3a5f;color:#fff;padding:9px 18px;border-radius:20px;font-size:14px;font-weight:700;z-index:9998;opacity:0;transform:translateY(8px);transition:opacity .3s,transform .3s;pointer-events:none;"></div>
<!-- ── Points reward modal ────────────────────────────── -->
<div id="rewardModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:36px 32px;max-width:400px;width:90%;text-align:center;box-shadow:0 24px 48px rgba(0,0,0,.25);">
        <div style="width:64px;height:64px;background:#fef3c7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fa fa-trophy" style="font-size:28px;color:#d97706;"></i>
        </div>
        <h2 style="margin:0 0 8px;font-size:22px;color:#1e3a5f;">You earned the prize!</h2>
        <p style="color:#6b7280;margin:0 0 22px;font-size:14px;line-height:1.6;">
            You've hit <strong id="rewardThreshold"></strong> points &mdash; thank you for helping us test Container Flow!
        </p>
        <div id="rewardReviewWrap" style="display:none;margin-bottom:12px;">
            <a id="rewardReviewBtn" href="#" target="_blank" class="btn btn-primary btn-block btn-lg">
                <i class="fa fa-star"></i> Leave a Review
            </a>
        </div>
        <button type="button" id="rewardModalClose" class="btn btn-default btn-block">Close</button>
    </div>
</div>
<script>
$(document).ready(function() {
    // ── Points toast ──────────────────────────────────────────────────
    function showPtsToast(text) {
        var t = document.getElementById('pts-toast');
        if (!t) return;
        t.textContent = text;
        t.style.opacity = '1'; t.style.transform = 'translateY(0)';
        setTimeout(function() { t.style.opacity = '0'; t.style.transform = 'translateY(8px)'; }, 2500);
    }
    // ── Reward modal ───────────────────────────────────────────────────
    function showRewardModal(info) {
        if (!info) return;
        var key = 'cf_reward_' + (info.threshold || 1000);
        if (localStorage.getItem(key)) return;
        document.getElementById('rewardThreshold').textContent = (info.threshold || 1000).toLocaleString() + ' pts';
        var modal = document.getElementById('rewardModal');
        modal.setAttribute('data-reward-key', key);
        var wrap = document.getElementById('rewardReviewWrap');
        if (info.review_url) { document.getElementById('rewardReviewBtn').href = info.review_url; wrap.style.display = 'block'; }
        else { wrap.style.display = 'none'; }
        modal.style.display = 'flex';
    }
    function dismissRewardModal() {
        var modal = document.getElementById('rewardModal');
        var key = modal.getAttribute('data-reward-key');
        if (key) localStorage.setItem(key, '1');
        modal.style.display = 'none';
    }
    document.getElementById('rewardModalClose').addEventListener('click', dismissRewardModal);
    document.getElementById('rewardModal').addEventListener('click', function(e) { if (e.target === this) dismissRewardModal(); });
    // On-load reward popup + claim link
    <?php if ($reward_on_load): ?>
        showRewardModal({ threshold: <?php echo $reward_threshold; ?>, review_url: '<?php echo addslashes($reward_review_url); ?>' });
    <?php endif; ?>
    ['claimPrizeLink','claimPrizeLinkMobile'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function(e) {
            e.preventDefault();
            localStorage.removeItem('cf_reward_<?php echo $reward_threshold; ?>');
            showRewardModal({ threshold: <?php echo $reward_threshold; ?>, review_url: '<?php echo addslashes($reward_review_url); ?>' });
        });
    });
    // Desktop filtering
    function isDoneStatus(status) {
        // Completed means "floor work is done, awaiting supervisor review" -
        // supervisors need to actually see these, so only Reviewed (the
        // true end state, after photos have been sent) gets hidden by default.
        return status === 'reviewed';
    }
    
    function filterTable() {
        var searchText = $('#searchInput').val().toLowerCase();
        var typeFilter = $('#typeFilter').val();
        var statusFilter = $('#statusFilter').val();
        var clientFilter = $('#clientFilter').length ? $('#clientFilter').val() : '';
        var warehouseFilter = $('#warehouseFilter').length ? $('#warehouseFilter').val() : '';
        var showCompleted = $('#showCompletedToggle').is(':checked');
        
        $('#containerTable tbody tr').each(function() {
            var $row = $(this);
            var searchData = $row.data('search');
            var type = $row.data('type');
            var status = String($row.data('status'));
            var client = String($row.data('client') || '');
            var warehouse = String($row.data('warehouse') || '');
            
            var searchMatch = !searchText || searchData.indexOf(searchText) !== -1;
            var typeMatch = !typeFilter || type === typeFilter;
            var statusMatch = !statusFilter || status === statusFilter;
            var clientMatch = !clientFilter || client === clientFilter;
            var warehouseMatch = !warehouseFilter || warehouse === warehouseFilter;
            
            // Hide completed/reviewed by default, unless the toggle is on
            // or the person explicitly picked that status from the dropdown.
            var completedMatch = showCompleted || !isDoneStatus(status) || statusFilter === status;
            
            if (searchMatch && typeMatch && statusMatch && clientMatch && warehouseMatch && completedMatch) {
                $row.show();
            } else {
                $row.hide();
            }
        });
    }
    
    $('#searchInput, #typeFilter, #statusFilter, #clientFilter, #warehouseFilter').on('input change', filterTable);
    $('#showCompletedToggle').on('change', filterTable);
    
    // Run once on load so completed/reviewed start hidden
    filterTable();
    
    // Desktop sorting
    $('.sortable').on('click', function() {
        var $th = $(this);
        var sortType = $th.data('sort');
        var $tbody = $('#containerTable tbody');
        var rows = $tbody.find('tr').toArray();
        
        var ascending = !$th.hasClass('sorted-asc');
        
        $('.sortable').removeClass('sorted-asc sorted-desc');
        $th.addClass(ascending ? 'sorted-asc' : 'sorted-desc');
        
        rows.sort(function(a, b) {
            var aVal, bVal;
            
            switch(sortType) {
                case 'date':
                    aVal = $(a).data('date');
                    bVal = $(b).data('date');
                    break;
                case 'container':
                    aVal = $(a).find('td:eq(0)').text();
                    bVal = $(b).find('td:eq(0)').text();
                    break;
                case 'client':
                    aVal = $(a).find('td:eq(1)').text();
                    bVal = $(b).find('td:eq(1)').text();
                    break;
                case 'warehouse':
                    aVal = $(a).data('warehouse');
                    bVal = $(b).data('warehouse');
                    break;
                case 'shipment':
                    aVal = $(a).find('td:eq(3)').text();
                    bVal = $(b).find('td:eq(3)').text();
                    break;
                case 'pobol':
                    aVal = $(a).find('td:eq(4)').text();
                    bVal = $(b).find('td:eq(4)').text();
                    break;
                case 'carrier':
                    aVal = $(a).find('td:eq(5)').text();
                    bVal = $(b).find('td:eq(5)').text();
                    break;
                case 'seal':
                    aVal = $(a).find('td:eq(6)').text();
                    bVal = $(b).find('td:eq(6)').text();
                    break;
                case 'type':
                    aVal = $(a).data('type');
                    bVal = $(b).data('type');
                    break;
                case 'eventdate':
                    aVal = $(a).data('eventdate');
                    bVal = $(b).data('eventdate');
                    break;
                case 'status':
                    aVal = $(a).data('status');
                    bVal = $(b).data('status');
                    break;
                case 'creator':
                    aVal = $(a).find('td:eq(10)').text();
                    bVal = $(b).find('td:eq(10)').text();
                    break;
            }
            
            if (ascending) {
                return aVal > bVal ? 1 : -1;
            } else {
                return aVal < bVal ? 1 : -1;
            }
        });
        
        $.each(rows, function(index, row) {
            $tbody.append(row);
        });
    });
    
    // Mobile search + Show Completed toggle
    // ── Mobile stats toggle ───────────────────────────────────────────
    var mobStatsOpen = false;
    document.getElementById('mobStatsToggle').addEventListener('click', function() {
        mobStatsOpen = !mobStatsOpen;
        document.getElementById('mobStatsGrid').style.display = mobStatsOpen ? 'grid' : 'none';
        document.getElementById('mobStatsChevron').style.transform = mobStatsOpen ? 'rotate(180deg)' : '';
    });

    // ── Mobile filter (tiles) ─────────────────────────────────────────
    function filterMobileCards() {
        var searchText    = $('#mobileSearch').val().toLowerCase();
        var showReviewed  = $('#mobileShowCompletedToggle').is(':checked');
        var visibleCount  = 0;

        $('.mob-tile').each(function() {
            var $t      = $(this);
            var status  = $t.attr('data-status') || '';
            var search  = $t.attr('data-search') || '';
            var match   = (!searchText || search.indexOf(searchText) !== -1);
            var visible = match && (showReviewed || status !== 'reviewed');
            $t.toggle(visible);
            if (visible) visibleCount++;
        });

        var rawSearch = $('#mobileSearch').val().trim();
        if (rawSearch && visibleCount === 0) {
            $('#mobileNoResultsTerm').text(rawSearch);
            var enc = encodeURIComponent(rawSearch.toUpperCase());
            $('#mobileCreateInboundSuggestion').attr('href', 'container_create.php?type=inbound&container_number=' + enc);
            $('#mobileCreateOutboundSuggestion').attr('href', 'container_create.php?type=outbound&container_number=' + enc);
            $('#mobileNoResults').show();
        } else {
            $('#mobileNoResults').hide();
        }
    }

    $('#mobileSearch').on('input', filterMobileCards);
    $('#mobileShowCompletedToggle').on('change', filterMobileCards);
    filterMobileCards(); // hide reviewed on load
    
    // ===== Next Status button (shared modal for all rows/cards) =====
    var nextStatusCsrf = '<?php echo $csrf; ?>';
    var nextStatusBaseUrl = '<?php echo $us_url_root; ?>';
    var activeReviewContainerId = null;
    
    function escapeHtmlDash(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    
    function callNextStatus(containerId, confirmReview) {
        var fd = { container_id: containerId, csrf: nextStatusCsrf };
        if (confirmReview) fd.confirm_review = '1';
        return $.ajax({
            url: nextStatusBaseUrl + 'usersc/ajax/container_next_status.php',
            type: 'POST',
            data: fd,
            dataType: 'json'
        });
    }
    
    function openReviewModal(containerId) {
        activeReviewContainerId = containerId;
        $('#reviewModalOverlay').addClass('open');
        $('#reviewModalLoading').show();
        $('#reviewModalContent').hide();
        $('#reviewModalMsg').html('');
        $('#reviewModalConfirm').prop('disabled', true);
        
        $.ajax({
            url: nextStatusBaseUrl + 'usersc/ajax/container_review_data.php',
            type: 'GET',
            data: { container_id: containerId },
            dataType: 'json'
        }).done(function(resp) {
            $('#reviewModalLoading').hide();
            if (!resp.success) {
                $('#reviewModalMsg').html('<div class="alert alert-danger">' + escapeHtmlDash(resp.message) + '</div>');
                return;
            }
            var c = resp.container;
            var infoHtml = '<strong>' + escapeHtmlDash(c.container_number) + '</strong>';
            if (c.customer_name) infoHtml += ' &middot; ' + escapeHtmlDash(c.customer_name);
            if (c.shipment_number) infoHtml += ' &middot; ' + escapeHtmlDash(c.shipment_number);
            $('#reviewModalInfo').html(infoHtml);
            
            var photosHtml = '';
            if (resp.photos.length === 0) {
                photosHtml = '<div style="grid-column: 1/-1; text-align:center; color:#999; padding: 10px;">No photos uploaded</div>';
            } else {
                resp.photos.forEach(function(p) {
                    photosHtml += '<div class="review-modal-photo-tile"><img src="' + escapeHtmlDash(p.url) + '"></div>';
                });
            }
            $('#reviewModalPhotoGrid').html(photosHtml);
            $('#reviewModalContent').show();
            $('#reviewModalConfirm').prop('disabled', false);
        }).fail(function() {
            $('#reviewModalLoading').hide();
            $('#reviewModalMsg').html('<div class="alert alert-danger">Failed to load photos - please try again.</div>');
        });
    }
    
    $(document).on('click', '.next-status-btn', function() {
        var $btn = $(this);
        var containerId = $btn.data('container-id');
        $btn.prop('disabled', true);
        
        callNextStatus(containerId, false)
            .done(function(resp) {
                if (resp.success) {
                    if (resp.points_earned) showPtsToast('+' + resp.points_earned + ' pts');
                    if (resp.reward_info) { showRewardModal(resp.reward_info); setTimeout(function(){ location.reload(); }, 2500); return; }
                    location.reload();
                } else if (resp.needs_review) {
                    openReviewModal(containerId);
                } else {
                    alert(resp.message || 'Could not update status');
                }
            })
            .fail(function() {
                alert('Failed to update status - please try again.');
            })
            .always(function() {
                $btn.prop('disabled', false);
            });
    });
    
    $('#reviewModalConfirm').on('click', function() {
        if (!activeReviewContainerId) return;
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Sending...');
        
        callNextStatus(activeReviewContainerId, true)
            .done(function(resp) {
                if (resp.success) {
                    location.reload();
                } else {
                    $('#reviewModalMsg').html('<div class="alert alert-danger">' + escapeHtmlDash(resp.message) + '</div>');
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
        activeReviewContainerId = null;
    });
});
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
