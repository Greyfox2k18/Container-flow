<?php
/**
 * Cleanup Preview — Container Tracking System
 * Shows containers currently eligible to be ARCHIVED (safe, reversible)
 * and ones already archived and eligible for permanent DELETION
 * (irreversible, gated by CLEANUP_LIVE_MODE). Lets a supervisor run
 * either phase on demand.
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/cleanup_config.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/cleanup_core.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}
if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

$pdo = new PDO(
    'mysql:host=' . DRIVE_BACKUP_DB_HOST . ';dbname=' . DRIVE_BACKUP_DB_NAME . ';charset=utf8mb4',
    DRIVE_BACKUP_DB_USER,
    DRIVE_BACKUP_DB_PASS
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$archive_candidates = getArchiveCandidates($pdo);
$delete_candidates = getDeleteCandidates($pdo);
$csrf = Token::generate();

$recent_log = '';
if (file_exists(CLEANUP_LOG_FILE)) {
    $lines = file(CLEANUP_LOG_FILE);
    $recent_log = implode('', array_slice($lines, -80));
}
?>
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">Cleanup Preview</h1>
                <p class="text-muted">
                    Two-phase lifecycle: <strong>Archive</strong> is safe and reversible — hides a
                    container from the simple dashboard while keeping it fully visible on the Pro
                    dashboard. <strong>Delete</strong> is permanent — removes local photos and the
                    record entirely (the Drive backup is untouched either way).
                </p>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Archive phase — <?php echo count($archive_candidates); ?> eligible now</h3>
            </div>
            <div class="panel-body">
                <p class="text-muted"><small>Runs for real every cleanup pass — archiving is reversible, so this isn't gated behind dry-run mode.</small></p>
                <?php if (empty($archive_candidates)): ?>
                <p class="text-muted">Nothing eligible right now.</p>
                <?php else: ?>
                <table class="table table-striped">
                    <thead><tr><th>Container #</th><th>Client</th><th>Age</th><th>Threshold</th><th>Backed up</th></tr></thead>
                    <tbody>
                        <?php foreach ($archive_candidates as $c): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($c->container_number); ?></strong></td>
                            <td><?php echo htmlspecialchars($c->customer_name ?? 'No Client'); ?></td>
                            <td><?php echo (int) $c->age_days; ?> days</td>
                            <td><?php echo (int) $c->effective_retention_days; ?> days</td>
                            <td><?php echo htmlspecialchars($c->drive_backed_up_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert <?php echo CLEANUP_LIVE_MODE ? 'alert-danger' : 'alert-warning'; ?>">
            <strong>Delete phase mode: <?php echo CLEANUP_LIVE_MODE ? 'LIVE — deletions are real' : 'DRY RUN — nothing will actually be deleted'; ?></strong>
            <?php if (!CLEANUP_LIVE_MODE): ?>
            <br><small>To go live, edit <code>CLEANUP_LIVE_MODE</code> in <code>usersc/includes/cleanup_config.php</code> — deliberately not a web toggle.</small>
            <?php else: ?>
            <br><small>Max <?php echo CLEANUP_MAX_PER_RUN; ?> container(s) deleted per run (circuit breaker, set in <code>cleanup_config.php</code>).</small>
            <?php endif; ?>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Delete phase — <?php echo count($delete_candidates); ?> eligible now</h3>
            </div>
            <div class="panel-body">
                <?php if (empty($delete_candidates)): ?>
                <p class="text-muted">Nothing eligible right now.</p>
                <?php else: ?>
                <table class="table table-striped">
                    <thead><tr><th>Container #</th><th>Client</th><th>Archived</th><th>Days since archived</th><th>Threshold</th></tr></thead>
                    <tbody>
                        <?php foreach ($delete_candidates as $c): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($c->container_number); ?></strong></td>
                            <td><?php echo htmlspecialchars($c->customer_name ?? 'No Client'); ?></td>
                            <td><?php echo htmlspecialchars($c->archived_at); ?></td>
                            <td><?php echo (int) $c->days_since_archived; ?> days</td>
                            <td><?php echo (int) $c->effective_delete_after_days; ?> days</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <button type="button" id="runCleanupBtn" class="btn <?php echo CLEANUP_LIVE_MODE ? 'btn-danger' : 'btn-default'; ?>">
                    <i class="fa fa-play"></i> Run Cleanup Now (archive for real, delete <?php echo CLEANUP_LIVE_MODE ? 'LIVE' : 'dry run'; ?>)
                </button>
                <div id="cleanupResult" style="margin-top:12px;"></div>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">Recent log</h3></div>
            <div class="panel-body">
                <pre style="max-height:300px;overflow:auto;font-size:12px;"><?php echo htmlspecialchars($recent_log ?: 'No log entries yet.'); ?></pre>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('runCleanupBtn').addEventListener('click', function() {
    var btn = this;
    var resultEl = document.getElementById('cleanupResult');
    btn.disabled = true;
    resultEl.textContent = 'Running...';

    var fd = new FormData();
    fd.append('csrf', '<?php echo $csrf; ?>');

    fetch('ajax/container_cleanup_run.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            resultEl.innerHTML = '<div class="alert alert-' + (data.success ? 'success' : 'danger') + '">' +
                (data.message || 'Done.') + '</div>';
            if (data.success) {
                setTimeout(function() { window.location.reload(); }, 1500);
            }
        })
        .catch(function() {
            resultEl.innerHTML = '<div class="alert alert-danger">Request failed.</div>';
        })
        .finally(function() { btn.disabled = false; });
});
</script>
