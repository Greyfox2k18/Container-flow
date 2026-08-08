<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$user_id = $user->data()->id;
$is_supervisor = isSupervisor();

// Points / reward
$reward_on_load    = false;
$reward_threshold  = (int) getContainerSetting('points_reward_threshold', 1000);
$reward_review_url = getContainerSetting('points_review_url', '');
$user_pts          = (int)($user->data()->plg_points ?? 0);
if ($user_pts > 0 && $reward_threshold > 0 && $user_pts >= $reward_threshold) $reward_on_load = true;

// Change this to whatever you'd like shown in the status bar
$company_name = 'Robbins Solutions';

$containers = getAllContainers();
$customers = getAllCustomers();
$warehouses = getWarehousesForUser($user_id); // only the user's own warehouse(s) — or all, if untagged

// Pull all photos for all containers in one query, grouped by container_id,
// so the modal can show them instantly with no extra round trip.
$container_ids = array_map(fn($c) => (int)$c->id, $containers);
$photos_by_container = [];
if (!empty($container_ids)) {
    $db = DB::getInstance();
    $placeholders = implode(',', array_fill(0, count($container_ids), '?'));
    $all_photos = $db->query(
        "SELECT * FROM container_photos WHERE container_id IN ($placeholders) ORDER BY uploaded_at ASC",
        $container_ids
    )->results();
    foreach ($all_photos as $photo) {
        $photos_by_container[$photo->container_id][] = [
            'id' => (int)$photo->id,
            'url' => $us_url_root . $photo->file_path,
            'photo_type' => $photo->photo_type,
            'description' => $photo->description,
        ];
    }
}

// Build a clean dataset for the client - this drives all filtering, sorting,
// and pagination in-memory in the browser, the same way the design prototype worked.
$js_containers = [];
foreach ($containers as $c) {
    $js_containers[] = [
        'id' => (int)$c->id,
        'container_number' => $c->container_number,
        'shipment_number' => $c->shipment_number,
        'receipt_ship_date' => $c->receipt_ship_date,
        'po_bol_number' => $c->po_bol_number,
        'carrier' => $c->carrier,
        'piece_count' => $c->piece_count !== null ? (int)$c->piece_count : null,
        'seal_number' => $c->seal_number,
        'customer_id' => $c->customer_id ? (int)$c->customer_id : null,
        'customer_name' => $c->customer_name,
        'warehouse_id' => $c->warehouse_id ? (int)$c->warehouse_id : null,
        'warehouse_name' => $c->warehouse_name,
        'type' => $c->type,
        'status' => $c->status,
        'notes' => $c->notes,
        'created_by_name' => trim(($c->creator_fname ?? '') . ' ' . ($c->creator_lname ?? '')),
        'created_at' => $c->created_at,
        'photos' => $photos_by_container[$c->id] ?? [],
    ];
}

$customers_js = array_map(fn($c) => ['id' => (int)$c->id, 'name' => $c->name], $customers);
$warehouses_js = array_map(fn($w) => ['id' => (int)$w->id, 'name' => $w->name], $warehouses);

global $container_statuses;
$status_options_js = $container_statuses;

// One shared CSRF token for the whole page - reused by every AJAX call below.
// (Calling Token::generate() more than once per page invalidates earlier copies.)
$csrf = Token::generate();

$current_user_name = trim($user->data()->fname . ' ' . $user->data()->lname);
?>
<style>
/* ============ Reset / base ============ */
.cd-root, .cd-root *, .cd-modal-overlay, .cd-modal-overlay *, .cd-lightbox-overlay, .cd-lightbox-overlay *{box-sizing:border-box;}
.cd-root, .cd-root *{font-family:'Segoe UI','Segoe UI Web',system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif;}
.cd-root{background:#fff;display:flex;flex-direction:column;border:1px solid #eef0f2;border-radius:8px;overflow:hidden;height:80vh;min-height:560px;margin:0 0 20px 0;}
.cd-mono{font-family:'Consolas','Courier New',monospace;}
.cd-root ::-webkit-scrollbar{width:12px;height:12px;}
.cd-root ::-webkit-scrollbar-thumb{background:#cfcfcf;border:3px solid transparent;background-clip:content-box;border-radius:8px;}

/* ============ Title bar ============ */
.cd-titlebar{display:flex;align-items:center;height:50px;background:#fff;border-bottom:1px solid #eef0f2;padding:0 18px;flex-shrink:0;}
.cd-logo{width:28px;height:28px;border-radius:7px;background:#0067b8;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;margin-right:11px;}
.cd-title{font-size:15px;color:#1f2937;font-weight:600;}
.cd-subtitle{font-size:13px;color:#9aa1ab;margin-left:9px;}
.cd-switch-view{margin-left:auto;font-size:12.5px;color:#0067b8;text-decoration:none;border:1px solid #e1e4e8;border-radius:7px;padding:6px 12px;}
.cd-switch-view:hover{background:#f4f6f8;}

/* ============ Toolbar ============ */
.cd-toolbar{display:flex;align-items:center;gap:9px;height:56px;background:#fff;border-bottom:1px solid #eef0f2;padding:0 18px;flex-shrink:0;}
.cd-btn{display:flex;align-items:center;gap:7px;height:36px;padding:0 14px;border-radius:7px;font-size:13px;cursor:pointer;background:#fff;border:1px solid #e1e4e8;color:#374151;}
.cd-btn:hover{background:#f4f6f8;}
.cd-btn.cd-btn-primary{background:#0067b8;color:#fff;border:none;padding:0 16px;}
.cd-btn.cd-btn-primary:hover{background:#005aa3;}
.cd-btn.cd-btn-danger-outline:hover{background:#fdf0f0;}
.cd-btn .cd-glyph-blue{color:#0067b8;}
.cd-btn .cd-glyph-red{color:#c0392b;}
.cd-btn[disabled]{opacity:.45;cursor:default;pointer-events:none;}
.cd-search{margin-left:auto;display:flex;align-items:center;gap:8px;height:36px;padding:0 14px;border:1px solid #e1e4e8;border-radius:19px;background:#fff;}
.cd-search input{border:none;outline:none;font-size:13px;width:210px;background:transparent;}
.cd-search .cd-search-glyph{color:#9aa1ab;}

/* ============ Filter bar ============ */
.cd-filterbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-height:46px;background:#fafbfc;border-bottom:1px solid #eef0f2;padding:8px 18px;flex-shrink:0;}
.cd-filterbar select{border:1px solid #e1e4e8;border-radius:6px;height:30px;padding:0 24px 0 8px;font-size:12.5px;color:#374151;background:#fff;cursor:pointer;min-width:110px;max-width:180px;-webkit-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239aa1ab'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;}
.cd-filterbar-divider{width:1px;height:20px;background:#e5e7eb;flex-shrink:0;}
.cd-filterbar label{font-size:12px;color:#9aa1ab;font-weight:600;}
.cd-filterbar input[type=date]{border:1px solid #e1e4e8;border-radius:6px;height:30px;padding:0 8px;font-size:12.5px;color:#374151;background:#fff;}
.cd-clear-dates{font-size:12.5px;color:#0067b8;cursor:pointer;}

/* ============ Body layout ============ */
.cd-body{display:flex;flex:1;min-height:0;}

/* ============ Sidebar ============ */
.cd-sidebar{width:244px;flex-shrink:0;background:#fafbfc;border-right:1px solid #eef0f2;overflow:auto;padding:10px 8px;}
.cd-sidebar-label{font-size:11px;font-weight:700;color:#9aa1ab;letter-spacing:.05em;padding:6px 10px 8px;}
.cd-nav-group-row{display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:7px;font-size:13.5px;cursor:default;color:#374151;}
.cd-nav-group-row.active{color:#0067b8;background:#e8f2fc;border-left:3px solid #0067b8;padding-left:9px;}
.cd-nav-chevron{width:14px;color:#9aa1ab;cursor:pointer;font-size:11px;}
.cd-nav-group-label{flex:1;cursor:pointer;}
.cd-nav-sub{display:flex;align-items:center;justify-content:space-between;padding:7px 12px 7px 32px;border-radius:7px;font-size:12.5px;color:#374151;cursor:pointer;}
.cd-nav-sub.active{color:#0067b8;background:#eef5fc;border-left:3px solid #6aa9e0;padding-left:29px;}
.cd-pill{font-size:11px;background:#eef0f3;color:#9a9a9a;border-radius:9px;padding:1px 7px;min-width:18px;text-align:center;display:inline-block;}
.cd-pill.active{color:#0067b8;}

/* ============ Main ============ */
.cd-main{flex:1;display:flex;flex-direction:column;min-width:0;}
.cd-heading-row{padding:16px 22px 8px;display:flex;align-items:baseline;gap:10px;}
.cd-heading{font-size:22px;color:#0067b8;font-weight:700;}
.cd-heading-meta{font-size:13px;color:#9aa1ab;}
.cd-chips-row{display:flex;align-items:center;gap:8px;padding:0 22px 12px;}
.cd-chips-label{font-size:12px;color:#9aa1ab;}
.cd-chip{font-size:12.5px;background:#eef5fc;color:#0067b8;padding:3px 12px;border-radius:13px;font-weight:600;}
.cd-selection-bar{display:flex;align-items:center;gap:16px;margin:0 22px 10px;padding:10px 16px;background:#eef5fc;border-radius:9px;font-size:13px;color:#0067b8;}
.cd-selection-bar .cd-sel-action{cursor:pointer;color:#0067b8;font-weight:600;margin-right:4px;}
.cd-selection-bar .cd-sel-delete{cursor:pointer;color:#c0392b;font-weight:600;}
.cd-selection-bar .cd-sel-clear{cursor:pointer;color:#6b7280;}

/* ============ Table ============ */
.cd-table-wrap{flex:1;overflow:auto;padding:0 10px;}
.cd-table{width:100%;border-collapse:collapse;}
.cd-table thead th{position:sticky;top:0;background:#fff;font-size:11px;font-weight:700;color:#9aa1ab;text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid #eef0f2;padding:10px 12px;text-align:left;cursor:pointer;user-select:none;white-space:nowrap;}
.cd-table thead th.cd-col-check{cursor:default;width:40px;}
.cd-sort-arrow{margin-left:4px;color:#bcd6ef;}
.cd-table tbody td{padding:11px 12px;font-size:13px;border-bottom:1px solid #f2f4f6;color:#374151;vertical-align:middle;}
.cd-table tbody tr:hover{background:#f7fafd;}
.cd-table tbody tr.cd-row-selected{background:#e8f2fc;}
.cd-link-cell{color:#0067b8;cursor:pointer;text-decoration:none;}
.cd-link-cell.cd-mono{font-family:'Consolas','Courier New',monospace;}
.cd-link-cell:hover{text-decoration:underline;}
.cd-seal-cell{color:#6b7280;font-family:'Consolas','Courier New',monospace;}
.cd-muted-cell{color:#6b7280;}
.cd-nowrap{white-space:nowrap;}
.cd-type-inbound{color:#15711f;font-weight:600;font-size:12.5px;}
.cd-type-outbound{color:#9a5b00;font-weight:600;font-size:12.5px;}
.cd-status-pill{display:inline-block;font-size:12px;font-weight:600;border-radius:11px;padding:3px 11px;}
.cd-status-pending{background:#fff3e0;color:#9a5b00;}
.cd-status-in_progress{background:#e6f1fb;color:#15569e;}
.cd-status-completed{background:#e4f3e6;color:#15711f;}
.cd-status-reviewed{background:#efeafa;color:#5a2e95;}
.cd-empty-state{text-align:center;padding:60px 20px;color:#9aa1ab;}

/* ============ Pagination ============ */
.cd-pagination{display:flex;align-items:center;gap:12px;height:46px;background:#fff;border-top:1px solid #eef0f2;padding:0 22px;flex-shrink:0;font-size:12.5px;color:#374151;}
.cd-pagination select{border:1px solid #e1e4e8;border-radius:6px;height:28px;font-size:12.5px;padding:0 4px;}
.cd-page-btn{width:30px;height:30px;border:1px solid #e1e4e8;border-radius:7px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#374151;}
.cd-page-btn:hover{background:#f4f6f8;}
.cd-page-btn[disabled]{color:#cbd2da;cursor:default;pointer-events:none;}

/* ============ Status bar ============ */
.cd-statusbar{display:flex;align-items:center;height:34px;background:#fafbfc;border-top:1px solid #eef0f2;padding:0 18px;font-size:12px;color:#9aa1ab;flex-shrink:0;}
.cd-statusbar .cd-statusbar-right{margin-left:auto;}

/* ============ Modal ============ */
.cd-modal-overlay{position:fixed;inset:0;background:rgba(17,24,39,.45);display:none;align-items:center;justify-content:center;z-index:200;}
.cd-modal-overlay.open{display:flex;}
.cd-modal{width:780px;max-width:94vw;max-height:90vh;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(0,0,0,.32);display:flex;flex-direction:column;overflow:hidden;}
.cd-modal-header{display:flex;align-items:center;gap:11px;padding:18px 22px;border-bottom:1px solid #eef0f2;flex-shrink:0;}
.cd-modal-title{font-size:17px;font-weight:700;color:#1f2937;}
.cd-modal-close{margin-left:auto;font-size:22px;line-height:1;color:#9aa1ab;cursor:pointer;}
.cd-modal-body{padding:20px 22px;overflow:auto;}
.cd-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px 18px;}
.cd-field{display:flex;flex-direction:column;gap:5px;}
.cd-field-label{font-size:11px;font-weight:700;color:#9aa1ab;letter-spacing:.03em;text-transform:uppercase;}
.cd-field input, .cd-field select, .cd-field textarea{border:1px solid #e1e4e8;border-radius:7px;height:36px;padding:0 10px;font-size:13px;color:#1f2937;background:#fff;font-family:inherit;}
.cd-field input[disabled]{background:#f7f8f9;color:#6b7280;}
.cd-field-full{grid-column:1 / -1;}
.cd-field-full textarea{height:70px;padding:8px 10px;resize:vertical;}
.cd-photos-label{font-size:11px;font-weight:700;color:#9aa1ab;letter-spacing:.04em;margin:24px 0 11px;}
.cd-photo-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.cd-photo-tile{position:relative;width:100%;height:110px;border-radius:10px;overflow:hidden;background:#f7f8f9;border:1px solid #eef0f2;}
.cd-photo-tile img{width:100%;height:100%;object-fit:cover;display:block;cursor:zoom-in;}
.cd-photo-delete{position:absolute;top:4px;right:4px;width:22px;height:22px;border-radius:50%;background:rgba(17,24,39,.65);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;}
.cd-lightbox-overlay{position:fixed;inset:0;background:rgba(10,12,16,.88);display:none;align-items:center;justify-content:center;z-index:300;}
.cd-lightbox-overlay.open{display:flex;}
.cd-lightbox-overlay img{max-width:92vw;max-height:90vh;border-radius:6px;box-shadow:0 10px 40px rgba(0,0,0,.5);}
.cd-lightbox-close{position:absolute;top:18px;right:24px;font-size:30px;color:#fff;cursor:pointer;line-height:1;opacity:.85;}
.cd-lightbox-close:hover{opacity:1;}
.cd-photo-add-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;width:100%;height:110px;border-radius:10px;border:1.5px dashed #cbd2da;color:#9aa1ab;font-size:12px;cursor:pointer;background:#fafbfc;text-align:center;}
.cd-photo-add-tile:hover{background:#f4f6f8;}
.cd-modal-footer{display:flex;align-items:center;gap:12px;padding:14px 22px;border-top:1px solid #eef0f2;flex-shrink:0;}
.cd-modal-delete-link{font-size:13px;color:#c0392b;font-weight:600;cursor:pointer;}
.cd-modal-footer-right{margin-left:auto;display:flex;gap:10px;}
.cd-modal-msg{font-size:12.5px;margin-top:10px;padding:8px 12px;border-radius:7px;}
.cd-modal-msg.error{background:#fdf0f0;color:#c0392b;}
.cd-modal-msg.success{background:#e4f3e6;color:#15711f;}

@media (max-width: 900px) {
    .cd-sidebar{display:none;}
}
</style>

<div id="page-wrapper">
<div class="container-fluid">

<div class="cd-root">

    <!-- title bar -->
    <div class="cd-titlebar">
        <div class="cd-logo">&#128230;</div>
        <span class="cd-title">Container Tracking</span>
        <span class="cd-subtitle">Warehouse Management</span>
        <a href="container_dashboard.php" class="cd-switch-view">&#8592; Simple view</a>
    </div>

    <!-- toolbar -->
    <div class="cd-toolbar">
        <div class="cd-btn cd-btn-primary" id="cdBtnNew"><span>&#xFF0B;</span>New</div>
        <div class="cd-btn" id="cdBtnEdit"><span class="cd-glyph-blue">&#9998;</span>Edit</div>
        <?php if ($is_supervisor): ?>
        <div class="cd-btn cd-btn-danger-outline" id="cdBtnDelete"><span class="cd-glyph-red">&times;</span>Delete</div>
        <?php endif; ?>
        <div class="cd-btn" id="cdBtnRefresh"><span class="cd-glyph-blue">&#10227;</span>Refresh</div>
        <span id="cdLastRefreshed" style="font-size:12px;color:#9aa1ab;margin-left:2px;"></span>
        <a href="container_reports.php" class="cd-btn"><i class="fa fa-bar-chart"></i> Reports</a>
        <?php if ($user_pts > 0): ?>
        <span style="font-size:12px;color:#6b7280;background:#f3f4f6;border-radius:20px;padding:4px 10px;margin-left:4px;">
            <i class="fa fa-star" style="color:#d97706;"></i>
            <strong><?php echo number_format($user_pts); ?></strong> pts
            <?php if ($reward_on_load): ?>&nbsp;<a href="#" id="claimPrizeLink" style="color:#d97706;font-weight:700;">&#127942; Claim</a><?php endif; ?>
        </span>
        <?php endif; ?>
        <div class="cd-search">
            <span class="cd-search-glyph">&#9906;</span>
            <input type="text" id="cdSearch" placeholder="Search containers&hellip;">
        </div>
    </div>

    <!-- filter bar -->
    <div class="cd-filterbar">
        <label>Created date</label>
        <input type="date" id="cdDateFrom">
        <span style="color:#9aa1ab;">&rarr;</span>
        <input type="date" id="cdDateTo">
        <span class="cd-clear-dates" id="cdClearDates" style="display:none;">Clear dates</span>
        <span class="cd-filterbar-divider"></span>
        <label>Client</label>
        <select id="cdFilterCustomer"><option value="">All Clients</option></select>
        <label>Warehouse</label>
        <select id="cdFilterWarehouse"><option value="">All Warehouses</option></select>
        <label>Carrier</label>
        <select id="cdFilterCarrier"><option value="">All Carriers</option></select>
        <label>Created By</label>
        <select id="cdFilterCreatedBy"><option value="">Anyone</option></select>
        <span class="cd-clear-dates" id="cdClearDropFilters" style="display:none;">Clear filters</span>
        <span class="cd-filterbar-divider"></span>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400;color:#374151;">
            <input type="checkbox" id="cdShowReviewed" style="width:14px;height:14px;cursor:pointer;">
            Show Reviewed
        </label>
    </div>

    <!-- body -->
    <div class="cd-body">

        <!-- sidebar -->
        <div class="cd-sidebar">
            <div class="cd-sidebar-label">LOGISTICS</div>
            <div id="cdNav"></div>
        </div>

        <!-- main -->
        <div class="cd-main">
            <div class="cd-heading-row">
                <span class="cd-heading">Containers</span>
                <span class="cd-heading-meta" id="cdHeadingMeta"></span>
            </div>
            <div class="cd-chips-row" id="cdChipsRow"></div>
            <div class="cd-selection-bar" id="cdSelectionBar" style="display:none;">
                <strong id="cdSelCount"></strong>
                <span class="cd-sel-action" id="cdSelNextStatus">&#8594; Next Status</span>
                <?php if ($is_supervisor): ?>
                <span class="cd-sel-delete" id="cdSelDelete">Delete</span>
                <?php endif; ?>
                <span class="cd-sel-clear" id="cdSelClear">Clear</span>
            </div>

            <div class="cd-table-wrap">
                <table class="cd-table">
                    <thead>
                        <tr>
                            <th class="cd-col-check"><input type="checkbox" id="cdSelectAll"></th>
                            <th data-sort="container_number">Container Number<span class="cd-sort-arrow"></span></th>
                            <th data-sort="shipment_number">Shipment Number<span class="cd-sort-arrow"></span></th>
                            <th data-sort="po_bol_number">PO/BOL<span class="cd-sort-arrow"></span></th>
                            <th data-sort="carrier">Carrier<span class="cd-sort-arrow"></span></th>
                            <th data-sort="customer_name">Customer<span class="cd-sort-arrow"></span></th>
                            <th data-sort="warehouse_name">Warehouse<span class="cd-sort-arrow"></span></th>
                            <th data-sort="seal_number">Seal Number<span class="cd-sort-arrow"></span></th>
                            <th data-sort="type">Type<span class="cd-sort-arrow"></span></th>
                            <th data-sort="receipt_ship_date">Receipt/Ship Date<span class="cd-sort-arrow"></span></th>
                            <th data-sort="created_by_name">Created By<span class="cd-sort-arrow"></span></th>
                            <th data-sort="created_at">Created<span class="cd-sort-arrow"></span></th>
                            <th data-sort="status">Status<span class="cd-sort-arrow"></span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cdTableBody"></tbody>
                </table>
                <div class="cd-empty-state" id="cdEmptyState" style="display:none;">No containers match these filters.</div>
            </div>
        </div>
    </div>

    <!-- pagination -->
    <div class="cd-pagination">
        <span>Rows per page</span>
        <select id="cdPageSize">
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
        </select>
        <span style="margin-left:auto;" id="cdRangeText"></span>
        <button class="cd-page-btn" id="cdPrevPage">&lsaquo;</button>
        <span id="cdPageText"></span>
        <button class="cd-page-btn" id="cdNextPage">&rsaquo;</button>
    </div>

    <!-- status bar -->
    <div class="cd-statusbar">
        <span><?php echo htmlspecialchars($company_name); ?></span>
        <span style="margin-left:14px;">&middot;</span>
        <span style="margin-left:14px;"><?php echo date('l, F j, Y'); ?></span>
        <span style="margin-left:14px;">&middot;</span>
        <span style="margin-left:14px;"><?php echo htmlspecialchars($current_user_name); ?></span>
        <span class="cd-statusbar-right" id="cdStatusRight"></span>
    </div>
</div>

</div>
</div>

<!-- edit / view modal -->
<div class="cd-modal-overlay" id="cdModalOverlay">
    <div class="cd-modal" id="cdModal">
        <div class="cd-modal-header">
            <span id="cdModalTypeTag"></span>
            <span class="cd-modal-title" id="cdModalTitle"></span>
            <span class="cd-modal-close" id="cdModalClose">&times;</span>
        </div>
        <div class="cd-modal-body">
            <div class="cd-field-grid">
                <label class="cd-field">
                    <span class="cd-field-label">Container Number</span>
                    <input type="text" id="cdFContainerNumber" class="cd-mono" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">Shipment Number</span>
                    <input type="text" id="cdFShipmentNumber">
                </label>
                <label class="cd-field">
                    <span class="cd-field-label" id="cdFEventDateLabel">Receipt Date</span>
                    <input type="date" id="cdFEventDate">
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">PO / BOL Number</span>
                    <input type="text" id="cdFPoBol">
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">Carrier</span>
                    <input type="text" id="cdFCarrier">
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">Piece / Pallet Count</span>
                    <input type="number" id="cdFPieceCount" min="0">
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">Client</span>
                    <select id="cdFCustomer">
                        <option value="">-- No client --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c->id; ?>"><?php echo htmlspecialchars($c->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (!empty($warehouses)): ?>
                <label class="cd-field">
                    <span class="cd-field-label">Warehouse</span>
                    <select id="cdFWarehouse">
                        <option value="">-- No warehouse --</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?php echo $w->id; ?>"><?php echo htmlspecialchars($w->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label class="cd-field">
                    <span class="cd-field-label">Seal Number</span>
                    <input type="text" id="cdFSealNumber" class="cd-mono">
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">Type</span>
                    <select id="cdFType">
                        <option value="inbound">Inbound</option>
                        <option value="outbound">Outbound</option>
                    </select>
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">Status</span>
                    <select id="cdFStatus">
                        <?php foreach ($status_options_js as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">Created By</span>
                    <input type="text" id="cdFCreatedBy" disabled>
                </label>
                <label class="cd-field">
                    <span class="cd-field-label">Created Date</span>
                    <input type="text" id="cdFCreatedDate" disabled>
                </label>
                <label class="cd-field cd-field-full">
                    <span class="cd-field-label">Notes</span>
                    <textarea id="cdFNotes"></textarea>
                </label>
            </div>

            <div class="cd-photos-label">PHOTOS</div>
            <div class="cd-photo-grid" id="cdPhotoGrid"></div>
            <input type="file" id="cdPhotoFileInput" accept="image/*" multiple style="display:none;">

            <?php if ($is_supervisor): ?>
            <div class="cd-photos-label" style="margin-top:18px;">MISSING PHOTOS ALERT</div>
            <div id="cdMissingPanel" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;">
                <div id="cdMissingList" style="font-size:13px;color:#4b5563;margin-bottom:10px;">Checking...</div>
                <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                    <label style="flex:1;min-width:160px;">
                        <span class="cd-field-label">Notify</span>
                        <select id="cdMissingRecipient" style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:6px;"></select>
                    </label>
                    <label style="flex:2;min-width:220px;">
                        <span class="cd-field-label">Note (optional)</span>
                        <input type="text" id="cdMissingMessage" placeholder="e.g. need these before end of shift" style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:6px;">
                    </label>
                    <div class="cd-btn cd-btn-primary" id="cdMissingSendBtn">Send Alert</div>
                </div>
                <div id="cdMissingMsg" style="font-size:12px;margin-top:8px;"></div>
            </div>
            <?php endif; ?>

            <div id="cdModalMsg"></div>
        </div>
        <div class="cd-modal-footer">
            <?php if ($is_supervisor): ?>
            <span class="cd-modal-delete-link" id="cdModalDeleteOne">Delete record</span>
            <?php endif; ?>
            <div class="cd-modal-footer-right">
                <div class="cd-btn" id="cdModalCancel">Cancel</div>
                <div class="cd-btn cd-btn-primary" id="cdModalSave">Save changes</div>
            </div>
        </div>
    </div>
</div>

<!-- photo lightbox -->
<div class="cd-lightbox-overlay" id="cdLightboxOverlay">
    <span class="cd-lightbox-close" id="cdLightboxClose">&times;</span>
    <img id="cdLightboxImg" src="">
</div>

<!-- review/email confirmation -->
<div class="cd-modal-overlay" id="cdConfirmOverlay">
    <div class="cd-modal" style="width: 420px;">
        <div class="cd-modal-header">
            <span class="cd-modal-title">Mark as Reviewed?</span>
        </div>
        <div class="cd-modal-body">
            <p style="margin: 0; font-size: 14px; color: #374151;">
                This will mark the container as <strong>Reviewed</strong> and automatically email the photos to the client's notification list for this direction. Continue?
            </p>
        </div>
        <div class="cd-modal-footer">
            <div class="cd-modal-footer-right" style="margin-left: 0; width: 100%; display: flex; gap: 10px;">
                <div class="cd-btn" id="cdConfirmCancel" style="flex: 1; justify-content: center;">Cancel</div>
                <div class="cd-btn cd-btn-primary" id="cdConfirmYes" style="flex: 1; justify-content: center;">Yes, mark Reviewed</div>
            </div>
        </div>
    </div>
</div>

<!-- Next Status review modal (triggered from the table row button, not the edit modal) -->
<div class="cd-modal-overlay" id="cdNextStatusOverlay">
    <div class="cd-modal" style="width: 460px;">
        <div class="cd-modal-header">
            <span class="cd-modal-title">Mark as Reviewed?</span>
        </div>
        <div class="cd-modal-body">
            <div id="cdNextStatusLoading" style="text-align:center; padding: 20px; color: #9aa1ab;">
                <i class="fa fa-spinner fa-spin"></i> Loading photos...
            </div>
            <div id="cdNextStatusContent" style="display:none;">
                <div id="cdNextStatusInfo" style="font-size: 13px; color: #374151; margin-bottom: 12px;"></div>
                <div id="cdNextStatusPhotoGrid" style="display:grid; grid-template-columns: repeat(4,1fr); gap: 8px; max-height: 280px; overflow-y:auto; margin-bottom: 14px;"></div>
                <p style="font-size: 13px; color: #374151; margin: 0;">
                    Confirming will mark this container as <strong>Reviewed</strong> and email these photos to the client's notification list for this direction.
                </p>
            </div>
            <div id="cdNextStatusMsg"></div>
        </div>
        <div class="cd-modal-footer">
            <div class="cd-modal-footer-right" style="margin-left: 0; width: 100%; display: flex; gap: 10px;">
                <div class="cd-btn" id="cdNextStatusCancel" style="flex: 1; justify-content: center;">Cancel</div>
                <div class="cd-btn cd-btn-primary" id="cdNextStatusConfirm" style="flex: 1; justify-content: center; opacity: .5; pointer-events: none;">Confirm & Send</div>
            </div>
        </div>
    </div>
</div>


<!-- ── Points toast ──────────────────────────────────────── -->
<div id="pts-toast" style="position:fixed;bottom:24px;right:24px;background:#1e3a5f;color:#fff;padding:9px 18px;border-radius:20px;font-size:14px;font-weight:700;z-index:9998;opacity:0;transform:translateY(8px);transition:opacity .3s,transform .3s;pointer-events:none;"></div>
<!-- ── Points reward modal ────────────────────────────── -->
<div id="rewardModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:36px 32px;max-width:400px;width:90%;text-align:center;box-shadow:0 24px 48px rgba(0,0,0,.25);">
        <div style="width:64px;height:64px;background:#fef3c7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;"><i class="fa fa-trophy" style="font-size:28px;color:#d97706;"></i></div>
        <h2 style="margin:0 0 8px;font-size:22px;color:#1e3a5f;">You earned the prize!</h2>
        <p style="color:#6b7280;margin:0 0 22px;font-size:14px;line-height:1.6;">You've hit <strong id="rewardThreshold"></strong> points &mdash; thank you for helping us test Container Flow!</p>
        <div id="rewardReviewWrap" style="display:none;margin-bottom:12px;"><a id="rewardReviewBtn" href="#" target="_blank" class="cd-btn cd-btn-primary" style="display:block;text-align:center;padding:12px;"><i class="fa fa-star"></i> Leave a Review</a></div>
        <div class="cd-btn" id="rewardModalClose" style="display:block;text-align:center;cursor:pointer;">Close</div>
    </div>
</div>

<script>
(function() {
    'use strict';

    // ============ Server-provided data ============
    var DATA = <?php echo json_encode($js_containers); ?>;
    var CUSTOMERS = <?php echo json_encode($customers_js); ?>;
    var WAREHOUSES = <?php echo json_encode($warehouses_js); ?>;
    var STATUS_OPTIONS = <?php echo json_encode($status_options_js); ?>;
    var CSRF = '<?php echo $csrf; ?>';
    var BASE_URL = '<?php echo $us_url_root; ?>';
    var IS_SUPERVISOR = <?php echo $is_supervisor ? 'true' : 'false'; ?>;

    // ============ State ============
    var state = {
        activeType: null,       // 'inbound' | 'outbound' | null
        activeCustomer: '',
        activeWarehouse: '',
        activeCarrier: '',
        activeCreatedBy: '',
        showReviewed: false,
        activeStatus: null,     // 'pending' | 'in_progress' | ... | null
        openGroups: {inbound: true, outbound: true},
        search: '',
        dateFrom: '',
        dateTo: '',
        sortCol: 'created_at',
        sortDir: 'desc',
        page: 1,
        pageSize: 10,
        selected: {},           // id -> true
    };

    var editingId = null;       // container id currently open in the modal, or 'new'

    // ============ Helpers ============
    function byId(id) { return document.getElementById(id); }

    function findContainer(id) {
        for (var i = 0; i < DATA.length; i++) {
            if (DATA[i].id === id) return DATA[i];
        }
        return null;
    }

    function formatDate(dtStr) {
        if (!dtStr) return '';
        var d = new Date(dtStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dtStr;
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function statusLabel(key) {
        return STATUS_OPTIONS[key] || key;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Wraps fetch() so a non-JSON response (PHP fatal error, wrong path, etc.)
    // produces a real, readable error instead of a generic "network error" -
    // the raw response body is logged to the console either way.
    function fetchJson(url, formData) {
        return fetch(url, { method: 'POST', body: formData })
            .then(function(r) {
                return r.text().then(function(text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Non-JSON response from ' + url + ' (HTTP ' + r.status + '):', text);
                        throw new Error('Unexpected server response (HTTP ' + r.status + '). Check the browser console for details.');
                    }
                    return data;
                });
            });
    }

    // ============ Next Status button ============
    var nextStatusActiveId = null;

    function callNextStatus(containerId, confirmReview) {
        var fd = new FormData();
        fd.append('container_id', containerId);
        fd.append('csrf', CSRF);
        if (confirmReview) fd.append('confirm_review', '1');
        return fetchJson(BASE_URL + 'usersc/ajax/container_next_status.php', fd);
    }

    function openNextStatusReview(containerId) {
        nextStatusActiveId = containerId;
        byId('cdNextStatusOverlay').classList.add('open');
        byId('cdNextStatusLoading').style.display = 'block';
        byId('cdNextStatusContent').style.display = 'none';
        byId('cdNextStatusMsg').innerHTML = '';
        byId('cdNextStatusConfirm').style.opacity = '.5';
        byId('cdNextStatusConfirm').style.pointerEvents = 'none';

        var fd = new FormData();
        fd.append('container_id', containerId);
        fd.append('csrf', CSRF);

        fetchJson(BASE_URL + 'usersc/ajax/container_review_data.php', fd)
            .then(function(resp) {
                byId('cdNextStatusLoading').style.display = 'none';
                if (!resp.success) {
                    byId('cdNextStatusMsg').innerHTML = '<div class="cd-modal-msg error">' + escapeHtml(resp.message) + '</div>';
                    return;
                }
                var c = resp.container;
                var infoHtml = '<strong>' + escapeHtml(c.container_number) + '</strong>';
                if (c.customer_name) infoHtml += ' &middot; ' + escapeHtml(c.customer_name);
                if (c.shipment_number) infoHtml += ' &middot; ' + escapeHtml(c.shipment_number);
                byId('cdNextStatusInfo').innerHTML = infoHtml;

                var photosHtml = '';
                if (resp.photos.length === 0) {
                    photosHtml = '<div style="grid-column:1/-1;text-align:center;color:#9aa1ab;padding:10px;">No photos uploaded</div>';
                } else {
                    resp.photos.forEach(function(p) {
                        photosHtml += '<div style="border-radius:8px;overflow:hidden;border:1px solid #eef0f2;"><img src="' + escapeHtml(p.url) + '" style="width:100%;height:80px;object-fit:cover;display:block;"></div>';
                    });
                }
                byId('cdNextStatusPhotoGrid').innerHTML = photosHtml;
                byId('cdNextStatusContent').style.display = 'block';
                byId('cdNextStatusConfirm').style.opacity = '1';
                byId('cdNextStatusConfirm').style.pointerEvents = 'auto';
            })
            .catch(function(err) {
                byId('cdNextStatusLoading').style.display = 'none';
                byId('cdNextStatusMsg').innerHTML = '<div class="cd-modal-msg error">' + escapeHtml(err.message) + '</div>';
            });
    }

    function handleNextStatusClick(containerId, btnEl) {
        if (btnEl) btnEl.disabled = true;
        callNextStatus(containerId, false)
            .then(function(resp) {
                if (resp.success) {
                    if (resp.points_earned) showPtsToast('+' + resp.points_earned + ' pts');
                    if (resp.reward_info) showRewardModal(resp.reward_info);
                    var c = findContainer(containerId);
                    if (c) c.status = resp.new_status;
                    renderAll();
                } else if (resp.needs_review) {
                    openNextStatusReview(containerId);
                } else {
                    alert(resp.message || 'Could not update status');
                }
            })
            .catch(function(err) {
                alert('Failed to update status: ' + err.message);
            })
            .finally(function() {
                if (btnEl) btnEl.disabled = false;
            });
    }

    byId('cdNextStatusConfirm').addEventListener('click', function() {
        if (!nextStatusActiveId) return;
        var $el = byId('cdNextStatusConfirm');
        $el.style.pointerEvents = 'none';
        $el.textContent = 'Sending...';

        callNextStatus(nextStatusActiveId, true)
            .then(function(resp) {
                if (resp.success) {
                    var c = findContainer(nextStatusActiveId);
                    if (c) c.status = resp.new_status;
                    byId('cdNextStatusOverlay').classList.remove('open');
                    renderAll();
                } else {
                    byId('cdNextStatusMsg').innerHTML = '<div class="cd-modal-msg error">' + escapeHtml(resp.message) + '</div>';
                    $el.style.pointerEvents = 'auto';
                    $el.textContent = 'Confirm & Send';
                }
            })
            .catch(function(err) {
                byId('cdNextStatusMsg').innerHTML = '<div class="cd-modal-msg error">' + escapeHtml(err.message) + '</div>';
                $el.style.pointerEvents = 'auto';
                $el.textContent = 'Confirm & Send';
            });
    });

    byId('cdNextStatusCancel').addEventListener('click', function() {
        byId('cdNextStatusOverlay').classList.remove('open');
        nextStatusActiveId = null;
    });

    // ============ Counting (sidebar pills) ============
    function countFor(type, status) {
        var n = 0;
        for (var i = 0; i < DATA.length; i++) {
            if (DATA[i].type === type && (!status || DATA[i].status === status)) n++;
        }
        return n;
    }

    var STATUS_KEYS = Object.keys(STATUS_OPTIONS);

    // ============ Sidebar render ============
    function renderNav() {
        var html = '';
        ['inbound', 'outbound'].forEach(function(type) {
            var isGroupActive = state.activeType === type && !state.activeStatus;
            var open = state.openGroups[type];
            html += '<div style="margin-bottom:2px;">';
            html += '<div class="cd-nav-group-row' + (isGroupActive ? ' active' : '') + '">';
            html += '<span class="cd-nav-chevron" data-toggle-group="' + type + '">' + (open ? '\u25BE' : '\u25B8') + '</span>';
            html += '<span class="cd-nav-group-label" data-select-type="' + type + '">' + (type === 'inbound' ? 'Inbound' : 'Outbound') + '</span>';
            html += '<span class="cd-pill' + (isGroupActive ? ' active' : '') + '">' + countFor(type) + '</span>';
            html += '</div>';
            if (open) {
                STATUS_KEYS.forEach(function(statusKey) {
                    var isSubActive = state.activeType === type && state.activeStatus === statusKey;
                    html += '<div class="cd-nav-sub' + (isSubActive ? ' active' : '') + '" data-select-type="' + type + '" data-select-status="' + statusKey + '">';
                    html += '<span>' + statusLabel(statusKey) + '</span>';
                    html += '<span class="cd-pill' + (isSubActive ? ' active' : '') + '">' + countFor(type, statusKey) + '</span>';
                    html += '</div>';
                });
            }
            html += '</div>';
        });
        byId('cdNav').innerHTML = html;

        byId('cdNav').querySelectorAll('[data-toggle-group]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                var type = this.getAttribute('data-toggle-group');
                state.openGroups[type] = !state.openGroups[type];
                renderNav();
            });
        });
        byId('cdNav').querySelectorAll('[data-select-type]').forEach(function(el) {
            el.addEventListener('click', function() {
                var type = this.getAttribute('data-select-type');
                var status = this.getAttribute('data-select-status');
                state.activeType = type;
                state.activeStatus = status || null;
                if (status === 'reviewed') { state.showReviewed = true; if(byId('cdShowReviewed')) byId('cdShowReviewed').checked = true; }
                if (status) state.openGroups[type] = true;
                state.page = 1;
                renderAll();
            });
        });
    }

    // ============ Filtering ============
    function getFiltered() {
        var search = state.search.trim().toLowerCase();
        var dFrom = state.dateFrom ? new Date(state.dateFrom + 'T00:00:00') : null;
        var dTo = state.dateTo ? new Date(state.dateTo + 'T23:59:59') : null;

        return DATA.filter(function(c) {
            if (state.activeType && c.type !== state.activeType) return false;
            if (!state.showReviewed && c.status === 'reviewed') return false;
            if (state.activeCustomer  && (c.customer_name   || '') !== state.activeCustomer)  return false;
            if (state.activeWarehouse && (c.warehouse_name  || '') !== state.activeWarehouse) return false;
            if (state.activeCarrier   && (c.carrier          || '') !== state.activeCarrier)   return false;
            if (state.activeCreatedBy && (c.created_by_name  || '') !== state.activeCreatedBy) return false;
            if (state.activeStatus && c.status !== state.activeStatus) return false;

            if (search) {
                var hay = [
                    c.container_number, c.shipment_number, c.customer_name,
                    c.warehouse_name,
                    c.seal_number, c.created_by_name, formatDate(c.created_at),
                    formatDate(c.receipt_ship_date), c.po_bol_number, c.carrier,
                    c.piece_count
                ].join(' ').toLowerCase();
                if (hay.indexOf(search) === -1) return false;
            }

            if (dFrom || dTo) {
                var created = new Date(c.created_at.replace(' ', 'T'));
                if (dFrom && created < dFrom) return false;
                if (dTo && created > dTo) return false;
            }

            return true;
        });
    }

    function getSorted(list) {
        var col = state.sortCol, dir = state.sortDir;
        var sorted = list.slice();
        sorted.sort(function(a, b) {
            var av = a[col], bv = b[col];
            if (col === 'created_at') {
                av = new Date(av.replace(' ', 'T')).getTime();
                bv = new Date(bv.replace(' ', 'T')).getTime();
            } else {
                av = (av || '').toString().toLowerCase();
                bv = (bv || '').toString().toLowerCase();
            }
            if (av < bv) return dir === 'asc' ? -1 : 1;
            if (av > bv) return dir === 'asc' ? 1 : -1;
            return 0;
        });
        return sorted;
    }

    // ============ Table render ============
    function renderTable() {
        var filtered = getFiltered();
        var sorted = getSorted(filtered);
        var total = sorted.length;
        var pageCount = Math.max(1, Math.ceil(total / state.pageSize));
        if (state.page > pageCount) state.page = pageCount;

        var startIdx = (state.page - 1) * state.pageSize;
        var pageItems = sorted.slice(startIdx, startIdx + state.pageSize);

        var tbody = byId('cdTableBody');
        if (pageItems.length === 0) {
            tbody.innerHTML = '';
            byId('cdEmptyState').style.display = 'block';
        } else {
            byId('cdEmptyState').style.display = 'none';
            var rows = pageItems.map(function(c) {
                var typeHtml = c.type === 'inbound'
                    ? '<span class="cd-type-inbound">&darr; Inbound</span>'
                    : '<span class="cd-type-outbound">&uarr; Outbound</span>';
                var statusHtml = '<span class="cd-status-pill cd-status-' + c.status + '">' + escapeHtml(statusLabel(c.status)) + '</span>';
                var checked = state.selected[c.id] ? 'checked' : '';
                var rowSelected = state.selected[c.id] ? ' cd-row-selected' : '';
                return '<tr class="' + rowSelected.trim() + '" data-id="' + c.id + '">' +
                    '<td><input type="checkbox" class="cd-row-check" data-id="' + c.id + '" ' + checked + '></td>' +
                    '<td><span class="cd-link-cell cd-mono" data-open="' + c.id + '">' + escapeHtml(c.container_number) + '</span></td>' +
                    '<td><span class="cd-link-cell" data-open="' + c.id + '">' + escapeHtml(c.shipment_number || '-') + '</span></td>' +
                    '<td class="cd-muted-cell">' + escapeHtml(c.po_bol_number || '-') + '</td>' +
                    '<td class="cd-muted-cell">' + escapeHtml(c.carrier || '-') + '</td>' +
                    '<td>' + escapeHtml(c.customer_name || '-') + '</td>' +
                    '<td>' + escapeHtml(c.warehouse_name || '-') + '</td>' +
                    '<td class="cd-seal-cell">' + escapeHtml(c.seal_number || '-') + '</td>' +
                    '<td>' + typeHtml + '</td>' +
                    '<td class="cd-muted-cell cd-nowrap">' + (c.receipt_ship_date ? formatDate(c.receipt_ship_date) : '-') + '</td>' +
                    '<td class="cd-muted-cell">' + escapeHtml(c.created_by_name || '-') + '</td>' +
                    '<td class="cd-muted-cell cd-nowrap">' + formatDate(c.created_at) + '</td>' +
                    '<td>' + statusHtml + '</td>' +
                    '<td><button type="button" class="cd-btn cd-next-status-btn" data-id="' + c.id + '" style="padding:4px 9px;font-size:12px;"><i class="fa fa-arrow-right"></i></button></td>' +
                    '</tr>';
            });
            tbody.innerHTML = rows.join('');

            tbody.querySelectorAll('[data-open]').forEach(function(el) {
                el.addEventListener('click', function() {
                    openModal(parseInt(this.getAttribute('data-open'), 10));
                });
            });
            tbody.querySelectorAll('.cd-row-check').forEach(function(el) {
                el.addEventListener('change', function() {
                    var id = parseInt(this.getAttribute('data-id'), 10);
                    if (this.checked) state.selected[id] = true;
                    else delete state.selected[id];
                    renderSelectionBar();
                    renderTable();
                });
            });
            tbody.querySelectorAll('.cd-next-status-btn').forEach(function(el) {
                el.addEventListener('click', function(e) {
                    e.stopPropagation();
                    handleNextStatusClick(parseInt(this.getAttribute('data-id'), 10), this);
                });
            });
        }

        // Heading / chips
        var typeLabel = state.activeType ? (state.activeType === 'inbound' ? 'Inbound' : 'Outbound') : 'All';
        byId('cdHeadingMeta').textContent = total + ' record' + (total === 1 ? '' : 's') + ' \u00B7 ' + typeLabel;

        var chips = '<span class="cd-chips-label">Filtered by</span><span class="cd-chip">' + typeLabel + '</span>';
        if (state.activeStatus)    chips += '<span class="cd-chip">' + escapeHtml(statusLabel(state.activeStatus)) + '</span>';
        if (state.activeCustomer)  chips += '<span class="cd-chip">' + escapeHtml(state.activeCustomer) + '</span>';
        if (state.activeCarrier)   chips += '<span class="cd-chip">' + escapeHtml(state.activeCarrier) + '</span>';
        if (state.activeCreatedBy) chips += '<span class="cd-chip">' + escapeHtml(state.activeCreatedBy) + '</span>';
        byId('cdChipsRow').innerHTML = chips;

        // Pagination text
        var rangeStart = total === 0 ? 0 : startIdx + 1;
        var rangeEnd = Math.min(startIdx + state.pageSize, total);
        byId('cdRangeText').textContent = rangeStart + '\u2013' + rangeEnd + ' of ' + total;
        byId('cdPageText').textContent = 'Page ' + state.page + ' of ' + pageCount;
        byId('cdPrevPage').disabled = state.page <= 1;
        byId('cdNextPage').disabled = state.page >= pageCount;

        // Status bar right side
        byId('cdStatusRight').textContent = total + ' of ' + DATA.length + ' records';

        // Select-all checkbox state
        var allOnPageSelected = pageItems.length > 0 && pageItems.every(function(c) { return state.selected[c.id]; });
        byId('cdSelectAll').checked = allOnPageSelected;
    }

    function renderSelectionBar() {
        var ids = Object.keys(state.selected);
        var bar = byId('cdSelectionBar');
        if (ids.length === 0) {
            bar.style.display = 'none';
        } else {
            bar.style.display = 'flex';
            byId('cdSelCount').textContent = ids.length + ' selected';
        }
        byId('cdBtnEdit').toggleAttribute('disabled', ids.length !== 1);
        if (byId('cdBtnDelete')) {
            byId('cdBtnDelete').toggleAttribute('disabled', ids.length === 0);
        }
    }

    function renderAll() {
        renderNav();
        renderSelectionBar();
        renderTable();
    }

    // ============ Modal ============
    function updateEventDateLabel() {
        var type = byId('cdFType').value;
        byId('cdFEventDateLabel').textContent = type === 'inbound' ? 'Receipt Date' : 'Ship Date';
    }
    byId('cdFType').addEventListener('change', updateEventDateLabel);

    function openModal(id) {
        editingId = id;
        var c = findContainer(id);
        if (!c) return;

        byId('cdModalTypeTag').innerHTML = c.type === 'inbound'
            ? '<span class="cd-type-inbound">&darr; Inbound</span>'
            : '<span class="cd-type-outbound">&uarr; Outbound</span>';
        byId('cdModalTitle').textContent = c.shipment_number || c.container_number;
        byId('cdFContainerNumber').value = c.container_number || '';
        byId('cdFShipmentNumber').value = c.shipment_number || '';
        byId('cdFEventDate').value = c.receipt_ship_date || '';
        byId('cdFPoBol').value = c.po_bol_number || '';
        byId('cdFCarrier').value = c.carrier || '';
        byId('cdFPieceCount').value = (c.piece_count !== null && c.piece_count !== undefined) ? c.piece_count : '';
        byId('cdFCustomer').value = c.customer_id || '';
        if (byId('cdFWarehouse')) byId('cdFWarehouse').value = c.warehouse_id || '';
        byId('cdFSealNumber').value = c.seal_number || '';
        byId('cdFType').value = c.type;
        updateEventDateLabel();
        byId('cdFStatus').value = c.status;
        byId('cdFCreatedBy').value = c.created_by_name || '';
        byId('cdFCreatedDate').value = formatDate(c.created_at);
        byId('cdFNotes').value = c.notes || '';
        byId('cdModalMsg').innerHTML = '';

        renderPhotoGrid(c.photos || []);

        if (byId('cdMissingPanel')) {
            loadMissingPhotosPanel(id);
        }

        if (byId('cdModalDeleteOne')) {
            byId('cdModalDeleteOne').style.display = 'inline';
        }
        byId('cdModalOverlay').classList.add('open');
    }

    function loadMissingPhotosPanel(container_id) {
        var listEl = byId('cdMissingList');
        var recipSel = byId('cdMissingRecipient');
        var msgEl = byId('cdMissingMsg');
        listEl.textContent = 'Checking...';
        recipSel.innerHTML = '';
        msgEl.textContent = '';

        var checkFd = new FormData();
        checkFd.append('action', 'check');
        checkFd.append('container_id', container_id);

        fetchJson(BASE_URL + 'usersc/ajax/container_notify_missing.php', checkFd)
            .then(function(data) {
                if (!data.success) {
                    listEl.textContent = data.message || 'Could not check photos.';
                    return;
                }
                if (!data.messages_plugin_enabled) {
                    listEl.innerHTML = '<em>The UserSpice Messaging plugin isn\'t enabled — turn it on in the Plugin Manager to send alerts.</em>';
                    byId('cdMissingSendBtn').style.display = 'none';
                    return;
                }
                byId('cdMissingSendBtn').style.display = 'inline-block';

                listEl.textContent = data.missing.length
                    ? 'Missing: ' + data.missing.join(', ')
                    : 'All required photo types are present.';

                data.floor_workers.forEach(function(fw) {
                    var o = document.createElement('option');
                    o.value = fw.id; o.textContent = fw.name;
                    if (data.default_recipient_id && fw.id === data.default_recipient_id) o.selected = true;
                    recipSel.appendChild(o);
                });
            })
            .catch(function() { listEl.textContent = 'Could not check photos.'; });
    }

    if (byId('cdMissingSendBtn')) {
        byId('cdMissingSendBtn').addEventListener('click', function() {
            if (!editingId) return;
            var msgEl = byId('cdMissingMsg');
            msgEl.textContent = 'Sending...';
            var fd = new FormData();
            fd.append('csrf', CSRF);
            fd.append('action', 'send');
            fd.append('container_id', editingId);
            fd.append('recipient_id', byId('cdMissingRecipient').value);
            fd.append('message', byId('cdMissingMessage').value);

            fetchJson(BASE_URL + 'usersc/ajax/container_notify_missing.php', fd)
                .then(function(data) {
                    msgEl.textContent = data.message || (data.success ? 'Sent.' : 'Failed.');
                    msgEl.style.color = data.success ? '#15711f' : '#c0392b';
                    if (data.success) byId('cdMissingMessage').value = '';
                })
                .catch(function() { msgEl.textContent = 'Send failed.'; msgEl.style.color = '#c0392b'; });
        });
    }

    function openModalForNew() {
        // Creating a brand new container reuses the existing, fully-validated
        // create flow rather than re-implementing it inside this modal.
        var type = state.activeType || 'inbound';
        window.location.href = 'container_create.php?type=' + type;
    }

    function closeModal() {
        byId('cdModalOverlay').classList.remove('open');
        editingId = null;
    }

    function renderPhotoGrid(photos) {
        var grid = byId('cdPhotoGrid');
        var html = photos.map(function(p) {
            return '<div class="cd-photo-tile">' +
                '<img src="' + escapeHtml(p.url) + '" data-zoom="' + escapeHtml(p.url) + '">' +
                '<div class="cd-photo-delete" data-delete-photo="' + p.id + '">&times;</div>' +
                '</div>';
        }).join('');
        html += '<div class="cd-photo-add-tile" id="cdPhotoAddTile"><span style="font-size:20px;">+</span><span>Add Photo</span></div>';
        grid.innerHTML = html;

        grid.querySelectorAll('[data-delete-photo]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!confirm('Delete this photo?')) return;
                var photoId = this.getAttribute('data-delete-photo');
                deletePhoto(photoId);
            });
        });
        grid.querySelectorAll('img[data-zoom]').forEach(function(el) {
            el.addEventListener('click', function() {
                openLightbox(this.getAttribute('data-zoom'));
            });
        });
        var addTile = byId('cdPhotoAddTile');
        if (addTile) {
            addTile.addEventListener('click', function() {
                byId('cdPhotoFileInput').click();
            });
        }
    }

    function openLightbox(url) {
        byId('cdLightboxImg').src = url;
        byId('cdLightboxOverlay').classList.add('open');
    }

    function closeLightbox() {
        byId('cdLightboxOverlay').classList.remove('open');
        byId('cdLightboxImg').src = '';
    }

    byId('cdLightboxOverlay').addEventListener('click', closeLightbox);
    byId('cdLightboxClose').addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLightbox();
    });

    function deletePhoto(photoId) {
        var fd = new FormData();
        fd.append('photo_id', photoId);
        fd.append('csrf', CSRF);
        fetchJson(BASE_URL + 'usersc/ajax/delete_photo.php', fd)
            .then(function(resp) {
                if (resp.success) {
                    try {
                        var c = findContainer(editingId);
                        if (c) {
                            c.photos = (c.photos || []).filter(function(p) { return p.id != photoId; });
                            renderPhotoGrid(c.photos);
                        }
                    } catch (renderErr) {
                        console.error('Photo deleted on server, but UI update failed:', renderErr);
                        showModalMsg('Photo deleted, but the page failed to refresh. Please reload.', 'error');
                    }
                } else {
                    showModalMsg(resp.message || 'Failed to delete photo', 'error');
                }
            })
            .catch(function(err) {
                console.error('Delete photo request failed:', err);
                showModalMsg('Failed to delete photo: ' + err.message, 'error');
            });
    }

    byId('cdPhotoFileInput').addEventListener('change', function() {
        var files = this.files;
        if (!files || files.length === 0) return;
        var c = findContainer(editingId);
        if (!c) return;

        var fd = new FormData();
        fd.append('container_id', editingId);
        fd.append('photo_type', 'other');
        fd.append('photo_description', '');
        fd.append('csrf', CSRF);
        for (var i = 0; i < files.length; i++) {
            fd.append('photo_files[]', files[i]);
        }

        showModalMsg('Uploading...', 'success');
        fetchJson(BASE_URL + 'usersc/ajax/upload_photos.php', fd)
            .then(function(resp) {
                if (resp.success) {
                    showModalMsg(resp.message || 'Uploaded', 'success');
                    // Re-fetching just this container's photo list isn't wired
                    // server-side yet, so reload to pick up the new thumbnails.
                    window.location.reload();
                } else {
                    showModalMsg(resp.message || 'Upload failed', 'error');
                }
            })
            .catch(function(err) {
                console.error('Upload request failed:', err);
                showModalMsg('Upload failed: ' + err.message, 'error');
            });

        this.value = '';
    });

    function showModalMsg(text, type) {
        byId('cdModalMsg').innerHTML = '<div class="cd-modal-msg ' + type + '">' + escapeHtml(text) + '</div>';
    }

    function saveModal() {
        if (!editingId) return;
        
        var c = findContainer(editingId);
        var newStatus = byId('cdFStatus').value;
        var wasReviewedAlready = c && c.status === 'reviewed';
        
        // Marking something Reviewed triggers an automatic email with
        // photos to the client - confirm that's intentional first. Marking
        // something Completed (floor work done) no longer emails anyone.
        if (newStatus === 'reviewed' && !wasReviewedAlready) {
            byId('cdConfirmOverlay').classList.add('open');
            return;
        }
        
        doSaveModal();
    }
    
    function doSaveModal() {
        var fd = new FormData();
        fd.append('container_id', editingId);
        fd.append('container_number', byId('cdFContainerNumber').value.trim());
        fd.append('shipment_number', byId('cdFShipmentNumber').value.trim());
        fd.append('receipt_ship_date', byId('cdFEventDate').value);
        fd.append('po_bol_number', byId('cdFPoBol').value.trim());
        fd.append('carrier', byId('cdFCarrier').value.trim());
        fd.append('piece_count', byId('cdFPieceCount').value);
        fd.append('customer_id', byId('cdFCustomer').value);
        if (byId('cdFWarehouse')) fd.append('warehouse_id', byId('cdFWarehouse').value);
        fd.append('seal_number', byId('cdFSealNumber').value.trim());
        fd.append('type', byId('cdFType').value);
        fd.append('status', byId('cdFStatus').value);
        fd.append('notes', byId('cdFNotes').value.trim());
        fd.append('csrf', CSRF);

        fetchJson(BASE_URL + 'usersc/ajax/container_update.php', fd)
            .then(function(resp) {
                if (resp.success) {
                    try {
                        var c = findContainer(editingId);
                        if (c) Object.assign(c, resp.container);
                        showModalMsg('Saved', 'success');
                        renderAll();
                        setTimeout(closeModal, 500);
                    } catch (renderErr) {
                        console.error('Saved on server, but UI update failed:', renderErr);
                        showModalMsg('Saved, but the page failed to refresh (' + renderErr.message + '). Please reload.', 'error');
                    }
                } else {
                    showModalMsg(resp.message || 'Save failed', 'error');
                }
            })
            .catch(function(err) {
                console.error('Save request failed:', err);
                showModalMsg('Save failed: ' + err.message, 'error');
            });
    }

    function deleteContainers(ids) {
        if (ids.length === 0) return;
        if (!confirm('Delete ' + ids.length + ' container(s)? This permanently removes their photos too. This cannot be undone.')) return;

        var fd = new FormData();
        fd.append('ids', ids.join(','));
        fd.append('csrf', CSRF);

        fetchJson(BASE_URL + 'usersc/ajax/container_delete.php', fd)
            .then(function(resp) {
                if (resp.success) {
                    try {
                        DATA = DATA.filter(function(c) { return ids.indexOf(c.id) === -1; });
                        ids.forEach(function(id) { delete state.selected[id]; });
                        closeModal();
                        renderAll();
                    } catch (renderErr) {
                        console.error('Deleted on server, but UI update failed:', renderErr);
                        alert('Deleted, but the page failed to refresh. Please reload.');
                    }
                } else {
                    alert(resp.message || 'Delete failed');
                }
            })
            .catch(function(err) {
                console.error('Delete request failed:', err);
                alert('Delete failed: ' + err.message);
            });
    }

    // ============ Toolbar wiring ============
    byId('cdBtnNew').addEventListener('click', openModalForNew);
    byId('cdBtnEdit').addEventListener('click', function() {
        var ids = Object.keys(state.selected);
        if (ids.length === 1) openModal(parseInt(ids[0], 10));
    });
    if (byId('cdBtnDelete')) {
        byId('cdBtnDelete').addEventListener('click', function() {
            deleteContainers(Object.keys(state.selected).map(function(s) { return parseInt(s, 10); }));
        });
    }
    // ── Populate dropdown filters from DATA ──────────────────────────────
    function populateFilterDropdowns() {
        var customers = {}, warehouses = {}, carriers = {}, creators = {};
        DATA.forEach(function(c) {
            if (!state.showReviewed && c.status === 'reviewed') return;
            if (c.customer_name)   customers[c.customer_name]   = true;
            if (c.warehouse_name)  warehouses[c.warehouse_name] = true;
            if (c.carrier)         carriers[c.carrier]          = true;
            if (c.created_by_name) creators[c.created_by_name]  = true;
        });
        function fill(id, map) {
            var sel = byId(id); if (!sel) return;
            var cur = sel.value;
            var first = sel.options[0].cloneNode(true);
            sel.innerHTML = ''; sel.appendChild(first);
            Object.keys(map).sort(function(a,b){return a.localeCompare(b);}).forEach(function(v){
                var o = document.createElement('option'); o.value = o.textContent = v; sel.appendChild(o);
            });
            if (map[cur]) sel.value = cur;
        }
        fill('cdFilterCustomer', customers);
        fill('cdFilterWarehouse', warehouses);
        fill('cdFilterCarrier',  carriers);
        fill('cdFilterCreatedBy', creators);
    }

    // Dropdown filter events
    function updateDropClear() {
        var active = state.activeCustomer || state.activeWarehouse || state.activeCarrier || state.activeCreatedBy;
        byId('cdClearDropFilters').style.display = active ? 'inline' : 'none';
    }
    byId('cdFilterCustomer').addEventListener('change', function() {
        state.activeCustomer = this.value; state.page = 1; updateDropClear(); renderTable();
    });
    if (byId('cdFilterWarehouse')) {
        byId('cdFilterWarehouse').addEventListener('change', function() {
            state.activeWarehouse = this.value; state.page = 1; updateDropClear(); renderTable();
        });
    }
    byId('cdFilterCarrier').addEventListener('change', function() {
        state.activeCarrier = this.value; state.page = 1; updateDropClear(); renderTable();
    });
    byId('cdFilterCreatedBy').addEventListener('change', function() {
        state.activeCreatedBy = this.value; state.page = 1; updateDropClear(); renderTable();
    });
    byId('cdClearDropFilters').addEventListener('click', function() {
        state.activeCustomer = state.activeWarehouse = state.activeCarrier = state.activeCreatedBy = '';
        byId('cdFilterCustomer').value = byId('cdFilterCarrier').value = byId('cdFilterCreatedBy').value = '';
        if (byId('cdFilterWarehouse')) byId('cdFilterWarehouse').value = '';
        updateDropClear(); state.page = 1; renderTable();
    });
    byId('cdShowReviewed').addEventListener('change', function() {
        state.showReviewed = this.checked; state.page = 1;
        populateFilterDropdowns(); renderAll();
    });

    // Auto-refresh
    var CF_REFRESH_MS = 60000;
    var cfRefreshTimeout;
    function cfUpdateLastRefreshed() {
        var el = byId('cdLastRefreshed');
        if (el) el.textContent = 'Updated ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    }
    function cfAutoRefresh() {
        fetch(BASE_URL + 'usersc/ajax/get_containers_json.php')
            .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
            .then(function(resp){
                if (resp.success && Array.isArray(resp.containers)) {
                    DATA.length = 0; resp.containers.forEach(function(c){ DATA.push(c); });
                    renderAll(); cfUpdateLastRefreshed();
                }
            }).catch(function(){})
            .finally(function(){ cfRefreshTimeout = setTimeout(cfAutoRefresh, CF_REFRESH_MS); });
    }
    cfRefreshTimeout = setTimeout(cfAutoRefresh, CF_REFRESH_MS);

    // Points / reward
    function showPtsToast(text) {
        var t = byId('pts-toast'); if (!t) return;
        t.textContent = text; t.style.opacity = '1'; t.style.transform = 'translateY(0)';
        setTimeout(function(){ t.style.opacity = '0'; t.style.transform = 'translateY(8px)'; }, 2500);
    }
    function showRewardModal(info) {
        if (!info) return;
        var key = 'cf_reward_' + (info.threshold || 1000);
        if (localStorage.getItem(key)) return;
        byId('rewardThreshold').textContent = (info.threshold || 1000).toLocaleString() + ' pts';
        var modal = byId('rewardModal'); modal.setAttribute('data-reward-key', key);
        var wrap = byId('rewardReviewWrap');
        if (info.review_url) { byId('rewardReviewBtn').href = info.review_url; wrap.style.display = 'block'; }
        else { wrap.style.display = 'none'; }
        modal.style.display = 'flex';
    }
    function dismissRewardModal() {
        var modal = byId('rewardModal');
        var key = modal.getAttribute('data-reward-key');
        if (key) localStorage.setItem(key, '1');
        modal.style.display = 'none';
    }
    byId('rewardModalClose').addEventListener('click', dismissRewardModal);
    byId('rewardModal').addEventListener('click', function(e){ if(e.target===this) dismissRewardModal(); });
    <?php if ($reward_on_load): ?>
    showRewardModal({ threshold: <?php echo $reward_threshold; ?>, review_url: '<?php echo addslashes($reward_review_url); ?>' });
    <?php endif; ?>
    var claimLink = byId('claimPrizeLink');
    if (claimLink) claimLink.addEventListener('click', function(e){
        e.preventDefault();
        localStorage.removeItem('cf_reward_<?php echo $reward_threshold; ?>');
        showRewardModal({ threshold: <?php echo $reward_threshold; ?>, review_url: '<?php echo addslashes($reward_review_url); ?>' });
    });

    populateFilterDropdowns();

        byId('cdBtnRefresh').addEventListener('click', function() {
        state.search = '';
        state.dateFrom = ''; state.dateTo = '';
        state.activeCustomer = ''; state.activeCarrier = ''; state.activeCreatedBy = '';
        state.showReviewed = false; state.selected = {};
        state.sortCol = 'created_at';
        state.sortDir = 'desc';
        state.page = 1;
        byId('cdSearch').value = '';
        byId('cdDateFrom').value = '';
        byId('cdDateTo').value = '';
        byId('cdClearDates').style.display = 'none';
        byId('cdClearDropFilters').style.display = 'none';
        byId('cdFilterCustomer').value = ''; byId('cdFilterCarrier').value = ''; byId('cdFilterCreatedBy').value = '';
        if (byId('cdShowReviewed')) byId('cdShowReviewed').checked = false;
        populateFilterDropdowns();
        document.querySelectorAll('.cd-sort-arrow').forEach(function(el) { el.textContent = ''; });
        renderAll();
    });

    byId('cdSearch').addEventListener('input', function() {
        state.search = this.value;
        state.page = 1;
        renderTable();
    });

    function updateDateClear() {
        byId('cdClearDates').style.display = (state.dateFrom || state.dateTo) ? 'inline' : 'none';
    }
    byId('cdDateFrom').addEventListener('input', function() {
        state.dateFrom = this.value;
        state.page = 1;
        updateDateClear();
        renderTable();
    });
    byId('cdDateTo').addEventListener('input', function() {
        state.dateTo = this.value;
        state.page = 1;
        updateDateClear();
        renderTable();
    });
    byId('cdClearDates').addEventListener('click', function() {
        state.dateFrom = '';
        state.dateTo = '';
        byId('cdDateFrom').value = '';
        byId('cdDateTo').value = '';
        updateDateClear();
        state.page = 1;
        renderTable();
    });

    // ============ Sorting ============
    document.querySelectorAll('.cd-table thead th[data-sort]').forEach(function(th) {
        th.addEventListener('click', function() {
            var col = this.getAttribute('data-sort');
            if (state.sortCol === col) {
                state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortCol = col;
                state.sortDir = 'asc';
            }
            document.querySelectorAll('.cd-sort-arrow').forEach(function(el) { el.textContent = ''; });
            this.querySelector('.cd-sort-arrow').textContent = state.sortDir === 'asc' ? ' \u25B2' : ' \u25BC';
            renderTable();
        });
    });

    // ============ Selection ============
    byId('cdSelectAll').addEventListener('change', function() {
        var checked = this.checked;
        var filtered = getSorted(getFiltered());
        var startIdx = (state.page - 1) * state.pageSize;
        var pageItems = filtered.slice(startIdx, startIdx + state.pageSize);
        pageItems.forEach(function(c) {
            if (checked) state.selected[c.id] = true;
            else delete state.selected[c.id];
        });
        renderSelectionBar();
        renderTable();
    });
    byId('cdSelClear').addEventListener('click', function() {
        state.selected = {};
        renderSelectionBar();
        renderTable();
    });
    if (byId('cdSelDelete')) {
        byId('cdSelDelete').addEventListener('click', function() {
            deleteContainers(Object.keys(state.selected).map(function(s) { return parseInt(s, 10); }));
        });
    }

    // ── Bulk Next Status ──────────────────────────────────────────────
    function doBulkNextStatus(ids, confirmReview) {
        var fd = new FormData();
        fd.append('ids', ids.join(','));
        fd.append('csrf', CSRF);
        if (confirmReview) fd.append('confirm_review', '1');
        return fetchJson(BASE_URL + 'usersc/ajax/container_bulk_next_status.php', fd);
    }

    if (byId('cdSelNextStatus')) {
        byId('cdSelNextStatus').addEventListener('click', function() {
            var ids = Object.keys(state.selected).map(function(s) { return parseInt(s, 10); });
            if (ids.length === 0) return;

            // Confirm if any in_progress containers will become completed
            var completingCount = ids.filter(function(id) {
                var c = findContainer(id); return c && c.status === 'in_progress';
            }).length;
            if (completingCount > 0) {
                var msg = completingCount + ' container(s) will be marked Complete and supervisors notified.';
                if (!confirm(msg)) return;
            }

            doBulkNextStatus(ids, false)
                .then(function(resp) {
                    if (resp.needs_review) {
                        byId('cdBulkReviewMsg').textContent =
                            resp.review_count + ' container(s) are ready — confirm to mark Reviewed and email photos to clients.';
                        byId('cdBulkReviewResult').innerHTML = '';
                        byId('cdBulkReviewConfirm').disabled = false;
                        byId('cdBulkReviewConfirm').textContent = 'Confirm & Send';
                        byId('cdBulkReviewOverlay').style.display = 'flex';

                        byId('cdBulkReviewConfirm').onclick = function() {
                            this.disabled = true;
                            this.textContent = 'Sending…';
                            doBulkNextStatus(ids, true)
                                .then(function(r) {
                                    if (r.success) {
                                        (r.results || []).forEach(function(res) {
                                            if (!res.skipped) { var c = findContainer(res.id); if (c) c.status = res.new_status; }
                                        });
                                        state.selected = {};
                                        byId('cdBulkReviewOverlay').style.display = 'none';
                                        renderAll();
                                    } else {
                                        byId('cdBulkReviewResult').innerHTML = '<span style="color:#c0392b;">' + escapeHtml(r.message || 'Error') + '</span>';
                                        byId('cdBulkReviewConfirm').disabled = false;
                                        byId('cdBulkReviewConfirm').textContent = 'Confirm & Send';
                                    }
                                }).catch(function(e) {
                                    byId('cdBulkReviewResult').innerHTML = '<span style="color:#c0392b;">' + escapeHtml(e.message) + '</span>';
                                    byId('cdBulkReviewConfirm').disabled = false;
                                    byId('cdBulkReviewConfirm').textContent = 'Confirm & Send';
                                });
                        };
                    } else if (resp.success) {
                        (resp.results || []).forEach(function(res) {
                            if (!res.skipped) { var c = findContainer(res.id); if (c) c.status = res.new_status; }
                        });
                        state.selected = {};
                        renderAll();
                    } else {
                        alert(resp.message || 'Bulk status update failed');
                    }
                })
                .catch(function(e) { alert('Bulk status update failed: ' + e.message); });
        });
    }

    if (byId('cdBulkReviewCancel')) {
        byId('cdBulkReviewCancel').addEventListener('click', function() {
            byId('cdBulkReviewOverlay').style.display = 'none';
        });
        byId('cdBulkReviewOverlay').addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    }

    // ============ Pagination ============
    byId('cdPageSize').addEventListener('change', function() {
        state.pageSize = parseInt(this.value, 10);
        state.page = 1;
        renderTable();
    });
    byId('cdPrevPage').addEventListener('click', function() {
        if (state.page > 1) { state.page--; renderTable(); }
    });
    byId('cdNextPage').addEventListener('click', function() {
        state.page++; renderTable();
    });

    // ============ Modal wiring ============
    byId('cdModalClose').addEventListener('click', closeModal);
    byId('cdModalCancel').addEventListener('click', closeModal);
    byId('cdModalSave').addEventListener('click', saveModal);
    byId('cdModalOverlay').addEventListener('click', function(e) {
        if (e.target === byId('cdModalOverlay')) closeModal();
    });
    byId('cdModal').addEventListener('click', function(e) { e.stopPropagation(); });
    if (byId('cdModalDeleteOne')) {
        byId('cdModalDeleteOne').addEventListener('click', function() {
            if (editingId) deleteContainers([editingId]);
        });
    }
    
    // Completion confirmation
    byId('cdConfirmCancel').addEventListener('click', function() {
        byId('cdConfirmOverlay').classList.remove('open');
    });
    byId('cdConfirmYes').addEventListener('click', function() {
        byId('cdConfirmOverlay').classList.remove('open');
        doSaveModal();
    });
    byId('cdConfirmOverlay').addEventListener('click', function(e) {
        if (e.target === byId('cdConfirmOverlay')) {
            byId('cdConfirmOverlay').classList.remove('open');
        }
    });

    // ============ Initial render ============
    renderAll();
})();
</script>

<!-- ── Bulk Review Overlay (completed → reviewed confirmation) ─── -->
<div id="cdBulkReviewOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;padding:28px 28px 20px;max-width:440px;width:90%;box-shadow:0 16px 40px rgba(0,0,0,.2);">
        <h4 style="margin:0 0 10px;font-size:17px;color:#1e3a5f;">Confirm Review &amp; Send</h4>
        <p id="cdBulkReviewMsg" style="font-size:14px;color:#374151;margin:0 0 16px;"></p>
        <div id="cdBulkReviewResult" style="margin-bottom:12px;font-size:13px;"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" class="cd-btn" id="cdBulkReviewCancel">Cancel</button>
            <button type="button" class="cd-btn" id="cdBulkReviewConfirm" style="background:#1e3a5f;color:#fff;">Confirm &amp; Send</button>
        </div>
    </div>
</div>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
