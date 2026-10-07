<?php
/**
 * Report Builder — Container Flow project hooks.
 * Read by usersc/plugins/report_builder (RbReports::config()). Another
 * project using the plugin supplies its own version of this file.
 */
if (count(get_included_files()) == 1) die();

return [
    // Same sender as every other Container Flow email (SparkPost or Postmark,
    // whichever sparkpost_email.php routes to).
    'mailer' => function (array $to, $subject, $html, array $attachments) {
        global $abs_us_root, $us_url_root;
        require_once __DIR__ . '/includes/container_functions.php'; // getContainerSetting()
        require_once __DIR__ . '/includes/sparkpost_email.php';
        $att = array_map(function ($a) {
            return ['name' => $a['name'], 'data' => base64_encode($a['content']), 'type' => $a['type']];
        }, $attachments);
        $r = sendSparkPostEmail($to, $subject, $html, null, null, $att);
        return ['success' => !empty($r['success']), 'message' => $r['message'] ?? ''];
    },

    'base_url' => function () {
        if (function_exists('getContainerSetting')) {
            return getContainerSetting('site_url', defined('CONTAINER_SITE_URL') ? CONTAINER_SITE_URL : '');
        }
        return defined('CONTAINER_SITE_URL') ? CONTAINER_SITE_URL : '';
    },

    'brand'         => 'Container Flow',
    'primary_color' => '#1e3a5f',
];
