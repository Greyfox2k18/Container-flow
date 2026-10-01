<?php
/**
 * Photo Compression Preview — Container Tracking System
 * Read-only: shows the results of the last time
 * usersc/cron/photo_compression_scan.php was run via SSH. This page
 * deliberately does NOT trigger a scan itself - scanning a large photo
 * catalog can take a while, and a web request timing out partway
 * through is worse than just running it from the command line.
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/photo_compression_config.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/photo_compression_core.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}
if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

$report = readPhotoCompressionReport();

function pcMB($bytes) { return number_format($bytes / 1024 / 1024, 1); }
?>
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">Photo Compression</h1>
                <p class="text-muted">
                    Shrinks stored photos to <?php echo PHOTO_COMPRESSION_MAX_DIM; ?>px / quality
                    <?php echo PHOTO_COMPRESSION_QUALITY; ?> to save disk space. This page only shows
                    the last scan's results — to run a new scan, SSH in and run:
                    <code>php usersc/cron/photo_compression_scan.php</code>
                </p>
            </div>
        </div>

        <div class="alert <?php echo PHOTO_COMPRESSION_LIVE_MODE ? 'alert-danger' : 'alert-warning'; ?>">
            <strong>Mode: <?php echo PHOTO_COMPRESSION_LIVE_MODE ? 'LIVE — the scan actually compresses files' : 'DRY RUN — the scan only measures, nothing is touched'; ?></strong>
            <?php if (!PHOTO_COMPRESSION_LIVE_MODE): ?>
            <br><small>To go live, edit <code>PHOTO_COMPRESSION_LIVE_MODE</code> in <code>usersc/includes/photo_compression_config.php</code> — deliberately not a web toggle. This is a one-way, lossy change to your only local copy of these photos, so make sure a dry-run's numbers look right first.</small>
            <?php endif; ?>
        </div>

        <?php if (!$report): ?>
        <div class="panel panel-default">
            <div class="panel-body">
                <p class="text-muted">No scan has been run yet. SSH in and run <code>php usersc/cron/photo_compression_scan.php</code> to generate a report.</p>
            </div>
        </div>
        <?php else: ?>

        <div class="row">
            <div class="col-md-3">
                <div class="panel panel-default"><div class="panel-body" style="text-align:center;">
                    <div style="font-size:28px;font-weight:700;"><?php echo $report['photos_scanned']; ?></div>
                    <div class="text-muted">Photos scanned</div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="panel panel-default"><div class="panel-body" style="text-align:center;">
                    <div style="font-size:28px;font-weight:700;"><?php echo pcMB($report['original_bytes']); ?> MB</div>
                    <div class="text-muted">Current size</div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="panel panel-default"><div class="panel-body" style="text-align:center;">
                    <div style="font-size:28px;font-weight:700;"><?php echo pcMB($report['new_bytes']); ?> MB</div>
                    <div class="text-muted"><?php echo $report['dry_run'] ? 'Projected size' : 'New size'; ?></div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="panel panel-default"><div class="panel-body" style="text-align:center;background:#f0fdf4;">
                    <div style="font-size:28px;font-weight:700;color:#15711f;"><?php echo pcMB($report['saved_bytes']); ?> MB</div>
                    <div class="text-muted"><?php echo $report['dry_run'] ? 'Would save' : 'Saved'; ?>
                        (<?php echo $report['original_bytes'] > 0 ? round(100 * $report['saved_bytes'] / $report['original_bytes']) : 0; ?>%)</div>
                </div></div>
            </div>
        </div>

        <p class="text-muted"><small>Last run: <?php echo htmlspecialchars($report['ran_at']); ?> — mode was <?php echo $report['dry_run'] ? 'dry run' : 'LIVE'; ?> at the time.</small></p>

        <?php if (!empty($report['skipped_reasons'])): ?>
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">Skipped</h3></div>
            <div class="panel-body">
                <ul>
                    <?php foreach ($report['skipped_reasons'] as $reason => $count): ?>
                    <li><?php echo $count; ?>x — <?php echo htmlspecialchars($reason); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($report['top_savers'])): ?>
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">Biggest individual savings</h3></div>
            <div class="panel-body">
                <table class="table table-striped">
                    <thead><tr><th>File</th><th>Container</th><th>Before</th><th>After</th><th>Saved</th></tr></thead>
                    <tbody>
                        <?php foreach ($report['top_savers'] as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['file_name']); ?></td>
                            <td><a href="container_view.php?id=<?php echo $s['container_id']; ?>">#<?php echo $s['container_id']; ?></a></td>
                            <td><?php echo number_format($s['original_kb']); ?> KB</td>
                            <td><?php echo number_format($s['new_kb']); ?> KB</td>
                            <td><?php echo number_format($s['saved_kb']); ?> KB</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
