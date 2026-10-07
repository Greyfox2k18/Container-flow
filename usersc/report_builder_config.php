<?php
/**
 * Report Builder — Container Flow project hooks.
 * Read by usersc/plugins/report_builder (RbReports::config()). Another
 * project using the plugin supplies its own version of this file.
 */
if (count(get_included_files()) == 1) die();

return [
    // Send with the plugin's built-in SparkPost/Postmark sender, using the
    // provider, keys and from-address already saved in Container Flow
    // Settings — no need to enter them again on the plugin's settings page.
    // (The built-in sender also supports inline chart images.)
    'mail' => function () {
        global $abs_us_root, $us_url_root;
        require_once __DIR__ . '/includes/container_functions.php'; // getContainerSetting()
        return [
            'provider'          => getContainerSetting('email_provider', 'sparkpost') === 'postmark' ? 'postmark' : 'sparkpost',
            'sparkpost_api_key' => getContainerSetting('sparkpost_api_key', ''),
            'postmark_token'    => getContainerSetting('postmark_api_key', ''),
            'from_email'        => getContainerSetting('sparkpost_from_email', 'noreply@mail.container-flow.com'),
            'from_name'         => getContainerSetting('sparkpost_from_name', 'Container Flow'),
        ];
    },

    // Site address for button links. Loads container_functions.php itself:
    // from the cron nothing else has loaded it yet, and without it the links
    // in emails would come out relative (broken).
    'base_url' => function () {
        global $abs_us_root, $us_url_root;
        require_once __DIR__ . '/includes/container_functions.php';
        return getContainerSetting('site_url', CONTAINER_SITE_URL);
    },

    'editor_url'    => 'usersc/reports_builder.php',
    'brand'         => 'Container Flow',
    'primary_color' => '#1e3a5f',

    // Who can do what (UserSpice master accounts can always do everything).
    // Supervisors build and send; only people without warehouse tags — who
    // already see every warehouse — can make reports that ignore warehouses.
    'can_build'   => function ($user_id) { return function_exists('isSupervisor') && isSupervisor($user_id); },
    'can_send'    => function ($user_id) { return function_exists('isSupervisor') && isSupervisor($user_id); },
    'can_unscope' => function ($user_id) {
        return function_exists('isSupervisor') && isSupervisor($user_id)
            && function_exists('getUserWarehouseIds') && empty(getUserWarehouseIds($user_id));
    },
];
