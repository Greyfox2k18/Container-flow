<?php
/**
 * Advances a container to its next logical status. Used by the "Next
 * Status" button on the simple dashboard, Pro dashboard, view page, and
 * edit page - one shared endpoint so the state machine only lives in one
 * place.
 *
 * pending -> in_progress -> completed : anyone, instant, no confirmation
 * completed -> reviewed              : supervisors only, and only after
 *                                       the frontend has shown a review
 *                                       screen and passed confirm_review=1
 *                                       (this is also what triggers the
 *                                       client notification email)
 * reviewed                           : terminal, no further advance
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

ob_end_clean();
header('Content-Type: application/json');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$container_id = Input::get('container_id');
if (!$container_id) {
    echo json_encode(['success' => false, 'message' => 'Container ID required']);
    exit;
}

$container = getContainerById($container_id);
if (!$container) {
    echo json_encode(['success' => false, 'message' => 'Container not found']);
    exit;
}

$user_id = $user->data()->id;
$is_supervisor = isSupervisor();
$confirm_review = Input::get('confirm_review') == '1';

$current = $container->status;

if ($current === 'pending') {
    $next = 'in_progress';
} elseif ($current === 'in_progress') {
    $next = 'completed';
} elseif ($current === 'completed') {
    if (!$is_supervisor) {
        echo json_encode(['success' => false, 'message' => 'Awaiting supervisor review', 'no_change' => true]);
        exit;
    }
    if (!$confirm_review) {
        // Tells the frontend to show the review screen first, then call
        // this same endpoint again with confirm_review=1 once confirmed.
        echo json_encode(['success' => false, 'message' => 'Review required', 'needs_review' => true]);
        exit;
    }
    $next = 'reviewed';
} else {
    echo json_encode(['success' => false, 'message' => 'Already reviewed - nothing further to do', 'no_change' => true]);
    exit;
}

// Photo gate: warn when marking Complete with no photos uploaded
if ($current === 'in_progress' && !Input::get('skip_photo_check')) {
    $photo_count = DB::getInstance()->query(
        "SELECT COUNT(*) AS cnt FROM container_photos WHERE container_id = ?",
        [$container_id]
    )->first();
    if ($photo_count->cnt == 0) {
        echo json_encode(['success' => false, 'photo_warning' => true,
            'message' => 'No photos have been uploaded for this container.']);
        exit;
    }
}

try {
    $db = DB::getInstance();
    $db->update('containers', $container_id, ['status' => $next]);
    logContainerActivity($container_id, $user_id, 'status_changed', "Status changed from {$current} to {$next}");

    $email_result         = null;
    $review_notify_result = null;

    if ($next === 'reviewed') {
        $email_result = sendCompletionNotification($container_id, $user_id);
    }

    if ($next === 'completed') {
        $review_notify_result = sendReadyForReviewNotification($container_id, $user_id);
    }

    // Award points for key workflow steps (floor workers only)
    $reward_info   = false;
    $pts_awarded   = 0;
    try {
        $username      = $user->data()->username;
        $container_obj = getContainerById($container_id);
        $cnum          = $container_obj ? $container_obj->container_number : $container_id;
        if ($next === 'completed') {
            $pts = (int) getContainerSetting('points_complete_container', 20);
            if ($pts > 0) {
                $pts_awarded = $pts;
                alterPoints($username, $pts, 'give', "Completed container photos: {$cnum}");
                $reward_info = checkPointsReward($user_id, $pts);
            }
        }
    } catch (\Throwable $e) { /* points unavailable, skip silently */ }

    echo json_encode([
        'success'        => true,
        'message'        => 'Status updated to ' . ucwords(str_replace('_', ' ', $next)),
        'new_status'     => $next,
        'email_sent'     => $email_result         ? $email_result['sent']          : null,
        'email_reason'   => $email_result         ? ($email_result['reason'] ?? '') : null,
        'notify_sent'    => $review_notify_result ? $review_notify_result['sent']   : null,
        'notify_reason'  => $review_notify_result ? ($review_notify_result['reason'] ?? '') : null,
        'points_earned'  => $pts_awarded,
        'reward_earned'  => $reward_info ? true : false,
        'reward_info'    => $reward_info ?: null,
    ]);
} catch (\Throwable $e) {
    error_log('container_next_status.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
