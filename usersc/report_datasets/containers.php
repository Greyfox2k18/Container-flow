<?php
/**
 * Report Builder dataset — Container Flow containers.
 *
 * Loaded by the report_builder plugin (see
 * usersc/plugins/report_builder/assets/includes/rb_registry.php for the
 * config format). Adding another dataset = dropping another file like
 * this one into usersc/report_datasets/; no builder code changes.
 *
 * Scope: a viewer with warehouse tags only sees containers in those
 * warehouses plus unassigned ones — same rule as
 * filterContainersByWarehouseAccess(). Untagged users are unrestricted.
 */
if (count(get_included_files()) == 1) die();

rb_register_dataset('containers', [
    'label'       => 'Containers',
    'description' => 'Inbound/outbound containers with client, warehouse and carrier.',
    'table'       => 'containers',
    'alias'       => 'c',
    'joins'       => [
        'cu' => ['table' => 'customers',  'on' => 'cu.id = c.customer_id'],
        'wh' => ['table' => 'warehouses', 'on' => 'wh.id = c.warehouse_id'],
        'u'  => ['table' => 'users',      'on' => 'u.id = c.created_by'],
    ],
    'fields' => [
        'id'                => ['label' => 'ID', 'type' => 'number', 'aggregatable' => false, 'groupable' => false],
        'container_number'  => ['label' => 'Container #', 'type' => 'text', 'groupable' => false],
        'type'              => ['label' => 'Type', 'type' => 'enum', 'options' => ['inbound' => 'Inbound', 'outbound' => 'Outbound']],
        'status'            => ['label' => 'Status', 'type' => 'enum', 'options' => [
                                    'pending' => 'Pending', 'in_progress' => 'In Progress',
                                    'completed' => 'Completed', 'reviewed' => 'Reviewed']],
        'customer'          => ['label' => 'Client', 'type' => 'text', 'expr' => 'cu.name', 'join' => 'cu'],
        'customer_id'       => ['label' => 'Client (pick list)', 'type' => 'enum', 'options' => function () {
                                    $out = [];
                                    foreach (DB::getInstance()->query('SELECT id, name FROM customers ORDER BY name')->results() ?: [] as $r) $out[$r->id] = $r->name;
                                    return $out;
                                }],
        'warehouse'         => ['label' => 'Warehouse', 'type' => 'text', 'expr' => 'wh.name', 'join' => 'wh'],
        'warehouse_id'      => ['label' => 'Warehouse (pick list)', 'type' => 'enum', 'options' => function () {
                                    $out = [];
                                    foreach (DB::getInstance()->query('SELECT id, name FROM warehouses ORDER BY sort_order, name')->results() ?: [] as $r) $out[$r->id] = $r->name;
                                    return $out;
                                }],
        'carrier'           => ['label' => 'Carrier', 'type' => 'text'],
        'shipment_number'   => ['label' => 'Shipment #', 'type' => 'text', 'groupable' => false],
        'seal_number'       => ['label' => 'Seal #', 'type' => 'text', 'groupable' => false],
        'po_bol_number'     => ['label' => 'PO/BOL', 'type' => 'text', 'groupable' => false],
        'piece_count'       => ['label' => 'Piece Count', 'type' => 'number', 'groupable' => false],
        'receipt_ship_date' => ['label' => 'Receipt/Ship Date', 'type' => 'date'],
        'notes'             => ['label' => 'Notes', 'type' => 'text', 'groupable' => false, 'sortable' => false],
        'created_by_name'   => ['label' => 'Created By', 'type' => 'text', 'expr' => "CONCAT_WS(' ', u.fname, u.lname)", 'join' => 'u'],
        'created_at'        => ['label' => 'Created', 'type' => 'datetime'],
        'updated_at'        => ['label' => 'Last Updated', 'type' => 'datetime'],
        'archived_at'       => ['label' => 'Archived', 'type' => 'datetime'],
        // TODO once the live schema is confirmed: completed_at / reviewed_at.
    ],
    'default_date_field' => 'created_at',
    'default_fields'     => ['container_number', 'type', 'status', 'customer', 'warehouse', 'carrier', 'receipt_ship_date'],
    'scope' => function (array $ctx) {
        if (empty($ctx['user_id']) || !function_exists('getUserWarehouseIds')) return null;
        $ids = array_map('intval', getUserWarehouseIds((int) $ctx['user_id']));
        if (empty($ids)) return null;
        return [
            'where'  => 'c.warehouse_id IS NULL OR c.warehouse_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            'params' => $ids,
        ];
    },
]);
