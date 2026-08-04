<?php
ob_start(); error_reporting(E_ALL); ini_set('display_errors', 0);
require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
ob_end_clean();
header('Content-Type: application/json');
if (!$user->isLoggedIn()) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }
if (!Token::check(Input::get('csrf'))) { echo json_encode(['success'=>false,'message'=>'Invalid CSRF']); exit; }
$allowed = [
    'email_provider','postmark_api_key','sparkpost_api_key','sparkpost_from_email','sparkpost_from_name','site_url',
    'points_create_container','points_upload_photo','points_delete_photo','points_complete_container',
    'points_review_container','points_reward_threshold','points_review_url','client_permission_id',
];
$saved = 0;
foreach ($allowed as $key) {
    if (isset($_POST[$key])) { setContainerSetting($key, trim($_POST[$key])); $saved++; }
}
echo json_encode(['success'=>true,'message'=>"Saved {$saved} setting(s)"]);
