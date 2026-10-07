<?php
/**
 * Report Builder — Container Flow page.
 * The editor itself lives in the report_builder plugin
 * (usersc/plugins/report_builder/); this page just provides the
 * UserSpice frame, page security and the URLs it should use.
 * Who may build/send is decided by usersc/report_builder_config.php
 * (supervisors), on top of the page permission set in UserSpice admin.
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

if (!pluginActive('report_builder', true) || !class_exists('RbApi')) {
    echo '<div class="container mt-4"><div class="alert alert-warning">The Report Builder plugin is not installed or not active. Install it from Admin → Plugins.</div></div>';
} else {
    $rbApiUrl  = $us_url_root . 'usersc/ajax/report_builder_api.php';
    $rbPageUrl = $us_url_root . 'usersc/reports_builder.php';
    include $abs_us_root . $us_url_root . 'usersc/plugins/report_builder/assets/includes/rb_editor_page.php';
}

require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php';
