<?php
/**
 * Report Builder API — Container Flow wrapper around the report_builder
 * plugin's RbApi::handle(). Session, CSRF and JSON only; every permission
 * check and all validation happen inside the plugin.
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!$user->isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'You are not logged in.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Token::check(Input::get('csrf'))) {
    echo json_encode(['ok' => false, 'error' => 'Your session expired — reload the page.']);
    exit;
}
if (!pluginActive('report_builder', true) || !class_exists('RbApi')) {
    echo json_encode(['ok' => false, 'error' => 'The Report Builder plugin is not active.']);
    exit;
}

// Read raw: Input::get() HTML-escapes, which would corrupt the JSON.
$payload = json_decode(isset($_POST['payload']) && is_string($_POST['payload']) ? $_POST['payload'] : '{}', true);
echo json_encode(RbApi::handle(Input::get('action'), is_array($payload) ? $payload : [], $user->data()->id));
