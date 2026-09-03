<?php
/**
 * Renders a single desktop table <tr> for container_dashboard.php.
 * Shared between the initial server-rendered page load and
 * ajax/container_list.php, so row markup only lives in one place and
 * can't drift between the two.
 */
function renderContainerDesktopRow($container) {
    static $status_colors = [
        'pending' => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'in_progress' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
        'completed' => ['bg' => '#e0e7ff', 'text' => '#3730a3'],
        'reviewed' => ['bg' => '#d1fae5', 'text' => '#065f46'],
    ];
    $colors = $status_colors[$container->status] ?? ['bg' => '#f3f4f6', 'text' => '#374151'];

    ob_start();
    ?>
    <tr data-type="<?php echo $container->type; ?>"
        data-status="<?php echo $container->status; ?>"
        data-client="<?php echo htmlspecialchars($container->customer_name ?? ''); ?>"
        data-warehouse="<?php echo htmlspecialchars($container->warehouse_name ?? ''); ?>"
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
    <?php
    return ob_get_clean();
}

/**
 * Renders a single mobile tile. Same sharing reasoning as the desktop
 * row renderer above.
 */
function renderContainerMobileTile($container) {
    static $status_bg  = ['pending'=>'#fef3c7','in_progress'=>'#dbeafe','completed'=>'#ede9fe','reviewed'=>'#d1fae5'];
    static $status_txt = ['pending'=>'#92400e','in_progress'=>'#1e40af','completed'=>'#5b21b6','reviewed'=>'#065f46'];
    static $status_lbl = ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Awaiting Review','reviewed'=>'Reviewed'];

    $sbg = $status_bg[$container->status] ?? '#f3f4f6';
    $stx = $status_txt[$container->status] ?? '#374151';
    $slb = $status_lbl[$container->status] ?? ucfirst($container->status);
    $tbg = $container->type === 'inbound' ? '#dbeafe' : '#d1fae5';
    $ttx = $container->type === 'inbound' ? '#1e40af' : '#065f46';

    ob_start();
    ?>
    <a href="container_view.php?id=<?php echo $container->id; ?>"
       class="mob-tile"
       data-status="<?php echo $container->status; ?>">
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
    <?php
    return ob_get_clean();
}
