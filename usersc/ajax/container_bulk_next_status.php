<?php
/**
 * Bulk Next Status — Container Tracking System
 *
 * Advances multiple containers toward their next logical status in one request.
 *
 * Two-pass flow:
 *   Pass 1 (no confirm_review): if any selected containers are 'completed',
 *     return needs_review=true so the frontend can show a confirmation modal.
 *   Pass 2 (confirm_review=1): advance every container, fire emails for
 *     any that move to 'reviewed'.
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';

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

$ids_raw       = Input::get('ids');
$confirm_review = Input::get('confirm_review') == '1';
$ids = array_values(array_filter(array_map('intval', explode(',', $ids_raw ?? ''))));

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No container IDs provided']);
    exit;
}

$user_id       = $user->data()->id;
$is_supervisor = isSupervisor();
$db            = DB::getInstance();

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$containers   = $db->query(
    "SELECT * FROM containers WHERE id IN ($placeholders)", $ids
)->results();

if (empty($containers)) {
    echo json_encode(['success' => false, 'message' => 'No matching containers found']);
    exit;
}

// ── Pass 1: check if any 'completed' containers need review confirmation ──────
if (!$confirm_review) {
    $review_count = 0;
    foreach ($containers as $c) {
        if ($c->status === 'completed' && $is_supervisor) $review_count++;
    }
    if ($review_count > 0) {
        echo json_encode([
            'success'        => false,
            'needs_review'   => true,
            'review_count'   => $review_count,
            'total_selected' => count($containers),
            'message'        => $review_count . ' container(s) will be marked Reviewed and emails sent.',
        ]);
        exit;
    }
}

// ── Pass 2: advance each container ───────────────────────────────────────────
$results      = [];
$advanced     = 0;
$skipped      = 0;
$email_sent   = 0;
$email_failed = 0;

foreach ($containers as $c) {
    $current = $c->status;

    if ($current === 'pending') {
        $next = 'in_progress';
    } elseif ($current === 'in_progress') {
        $next = 'completed';
    } elseif ($current === 'completed') {
        if (!$is_supervisor) {
            $results[] = ['id' => $c->id, 'skipped' => true, 'reason' => 'Awaiting supervisor review'];
            $skipped++;
            continue;
        }
        if (!$confirm_review) {
            $results[] = ['id' => $c->id, 'skipped' => true, 'reason' => 'Review not confirmed'];
            $skipped++;
            continue;
        }
        $next = 'reviewed';
    } else {
        $results[] = ['id' => $c->id, 'skipped' => true, 'reason' => 'Already reviewed'];
        $skipped++;
        continue;
    }

    try {
        $db->update('containers', $c->id, ['status' => $next]);
        logContainerActivity($c->id, $user_id, 'status_changed',
            "Bulk: status changed from {$current} to {$next}");

        // Completion email to client
        $email_result = null;
        if ($next === 'reviewed') {
            $email_result = sendCompletionNotification($c->id, $user_id);
            if ($email_result && $email_result['sent']) $email_sent++;
            else $email_failed++;
        }

        // Supervisor notification when floor worker marks complete
        if ($next === 'completed') {
            sendReadyForReviewNotification($c->id, $user_id);
        }

        // Award points
        try {
            if ($next === 'completed') {
                $pts = (int) getContainerSetting('points_complete_container', 20);
                if ($pts > 0) alterPoints($user->data()->username, $pts, 'give',
                    "Bulk: completed container {$c->container_number}");
            }
        } catch (\Throwable $e) { /* points unavailable */ }

        $results[] = [
            'id'         => (int) $c->id,
            'old_status' => $current,
            'new_status' => $next,
            'email'      => $email_result,
        ];
        $advanced++;

    } catch (\Throwable $e) {
        error_log('container_bulk_next_status error for id ' . $c->id . ': ' . $e->getMessage());
        $results[] = ['id' => (int) $c->id, 'skipped' => true, 'reason' => 'Server error'];
        $skipped++;
    }
}

$summary = $advanced . ' container' . ($advanced !== 1 ? 's' : '') . ' advanced';
if ($skipped > 0)     $summary .= ', ' . $skipped . ' skipped';
if ($email_sent > 0)  $summary .= ', ' . $email_sent . ' email' . ($email_sent !== 1 ? 's' : '') . ' sent';
if ($email_failed > 0) $summary .= ', ' . $email_failed . ' email' . ($email_failed !== 1 ? 's' : '') . ' failed';

echo json_encode([
    'success'      => true,
    'advanced'     => $advanced,
    'skipped'      => $skipped,
    'email_sent'   => $email_sent,
    'email_failed' => $email_failed,
    'results'      => $results,
    'message'      => $summary,
]);
