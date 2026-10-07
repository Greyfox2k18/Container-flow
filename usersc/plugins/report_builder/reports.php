<?php
/**
 * Report Builder — the editor page that works with no project files:
 *   /usersc/plugins/report_builder/reports.php
 * Who can use it: master accounts plus the permission levels set on the
 * plugin's settings page (or can_build in usersc/report_builder_config.php).
 * A project can host the editor on its own page instead — see README.md.
 */
require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!$user->isLoggedIn()) {
    Redirect::to($us_url_root . 'users/login.php');
}
if (!pluginActive('report_builder', true) || !class_exists('RbApi')) {
    echo '<div class="container mt-4"><div class="alert alert-warning">The Report Builder plugin is not active.</div></div>';
} else {
    $rbApiUrl  = $us_url_root . 'usersc/plugins/report_builder/api.php';
    $rbPageUrl = $us_url_root . 'usersc/plugins/report_builder/reports.php';
    include __DIR__ . '/assets/includes/rb_editor_page.php';   // checks RbReports::canBuild()
}

require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
