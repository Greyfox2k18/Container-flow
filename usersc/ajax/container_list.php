<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_row_render.php';

ob_end_clean();
header('Content-Type: application/json');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

try {
    $user_id = $user->data()->id;

    $filters = [
        'search' => trim((string) Input::get('search')),
        'type' => Input::get('type') ?: null,
        'status' => Input::get('status') ?: null,
        'customer_name' => Input::get('client') ?: null,
        'warehouse_name' => Input::get('warehouse') ?: null,
        'show_reviewed' => Input::get('show_reviewed') == '1',
    ];
    $page = (int) (Input::get('page') ?: 1);
    $sort = Input::get('sort') ?: 'date';
    $dir = Input::get('dir') ?: 'desc';

    $result = getPaginatedContainers($filters, $user_id, $page, 50, $sort, $dir);

    $desktop_html = '';
    $mobile_html = '';
    foreach ($result['rows'] as $container) {
        $desktop_html .= renderContainerDesktopRow($container);
        $mobile_html .= renderContainerMobileTile($container);
    }

    echo json_encode([
        'success' => true,
        'desktop_html' => $desktop_html,
        'mobile_html' => $mobile_html,
        'total' => $result['total'],
        'total_pages' => $result['total_pages'],
        'page' => $result['page'],
        'stats' => getContainerStats($user_id),
    ]);
} catch (\Throwable $e) {
    error_log('container_list.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
