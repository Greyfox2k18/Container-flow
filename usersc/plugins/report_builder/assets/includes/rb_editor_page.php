<?php
/**
 * Report Builder — editor page body. Include it from a normal UserSpice page
 * (after init.php, prep.php and securePage()) with these set:
 *
 *   $rbApiUrl   URL of the project's AJAX wrapper around RbApi::handle()
 *   $rbPageUrl  URL of the page itself (list = no query string, editor = ?id=N)
 *
 * The page shows the report list, or the editor when ?id= is present
 * (?id=new for a blank report). Everything else happens in rb_editor.js.
 */
if (count(get_included_files()) == 1) die();

global $user, $us_url_root;
$rbUid = (int) $user->data()->id;
if (!RbReports::canBuild($rbUid)) {
    echo '<div class="container mt-4"><div class="alert alert-warning">You don\'t have permission to build reports.</div></div>';
    return;
}
$rbAssets = $us_url_root . 'usersc/plugins/report_builder/assets/';
$rbVer = '0.3.0';
$rbOpenId = isset($_GET['id']) ? (($_GET['id'] === 'new') ? 'new' : (string) (int) $_GET['id']) : '';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($rbAssets . 'css/rb_editor.css?v=' . $rbVer) ?>">
<div id="rbApp" class="rb-app"
     data-api="<?= htmlspecialchars($rbApiUrl) ?>"
     data-page="<?= htmlspecialchars($rbPageUrl) ?>"
     data-csrf="<?= htmlspecialchars(Token::generate()) ?>"
     data-open="<?= htmlspecialchars($rbOpenId) ?>">
  <div class="rb-loading">Loading report builder…</div>
</div>
<script src="<?= htmlspecialchars($rbAssets . 'js/sortable.min.js?v=1.15.2') ?>"></script><!-- SortableJS 1.15.2, MIT (sortable.LICENSE.txt) -->
<script src="<?= htmlspecialchars($rbAssets . 'js/rb_editor.js?v=' . $rbVer) ?>"></script>
