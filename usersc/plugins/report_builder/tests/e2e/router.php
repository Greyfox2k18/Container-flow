<?php
// php -S router for the editor e2e test. Dev only — see run_e2e.sh.
if (PHP_SAPI !== 'cli-server') die();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/editor.php') {
    require __DIR__ . '/env.php';
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Reports</title><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;font-family:system-ui,sans-serif;background:#f1f5f9">';
    $rbApiUrl = '/api.php'; $rbPageUrl = '/editor.php';
    include dirname(__DIR__, 2) . '/assets/includes/rb_editor_page.php';
    echo '</body></html>';
    return true;
}
if ($path === '/api.php') {
    require __DIR__ . '/env.php';
    header('Content-Type: application/json');
    if (!Token::check(Input::get('csrf'))) { echo json_encode(['ok' => false, 'error' => 'csrf']); return true; }
    $payload = json_decode($_POST['payload'] ?? '{}', true);
    echo json_encode(RbApi::handle(Input::get('action'), is_array($payload) ? $payload : [], $user->data()->id));
    return true;
}
if (strpos($path, '/usersc/plugins/report_builder/assets/') === 0) return false; // static file from docroot
http_response_code(404);
return true;
