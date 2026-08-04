<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

require_once $abs_us_root.$us_url_root.'usersc/includes/drive_backup_config.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/google_drive.php';

$db = DB::getInstance();
$pending = $db->query("
    SELECT COUNT(*) as count FROM containers c
    WHERE (c.drive_backed_up_at IS NULL OR (SELECT MAX(uploaded_at) FROM container_photos WHERE container_id = c.id) > c.drive_backed_up_at)
      AND EXISTS (SELECT 1 FROM container_photos WHERE container_id = c.id)
")->first();
$pending_count = $pending->count ?? 0;

$last_backed_up = $db->query("
    SELECT container_number, drive_backed_up_at FROM containers
    WHERE drive_backed_up_at IS NOT NULL
    ORDER BY drive_backed_up_at DESC LIMIT 1
")->first();

$log_lines = [];
if (file_exists(DRIVE_BACKUP_LOG_FILE)) {
    $all_lines = file(DRIVE_BACKUP_LOG_FILE, FILE_IGNORE_NEW_LINES);
    $log_lines = array_slice($all_lines, -60);
}

$oauth_creds = getGoogleOAuthCredentials();
$oauth_creds_configured = $oauth_creds && !empty($oauth_creds['client_id'])
    && $oauth_creds['client_id'] !== 'YOUR_CLIENT_ID_HERE.apps.googleusercontent.com';

$oauth_token = getGoogleOAuthToken();
$oauth_connected = $oauth_token && !empty($oauth_token['refresh_token']);

$csrf = Token::generate();
?>

<?php if (!empty($_GET['oauth_connected'])): ?>
<div class="container-fluid" style="margin-top: 15px;">
    <div class="alert alert-success">
        <i class="fa fa-check"></i> Google Drive connected successfully<?php echo $oauth_token['connected_email'] ? ' as ' . htmlspecialchars($oauth_token['connected_email']) : ''; ?>.
    </div>
</div>
<?php endif; ?>
<?php if (!empty($_GET['oauth_error'])): ?>
<div class="container-fluid" style="margin-top: 15px;">
    <div class="alert alert-danger">
        <i class="fa fa-exclamation-triangle"></i> Connection failed: <?php echo htmlspecialchars($_GET['oauth_error']); ?>
    </div>
</div>
<?php endif; ?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">
                    Google Drive Backups
                    <div class="pull-right">
                        <a href="container_dashboard.php" class="btn btn-default">
                            <i class="fa fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </h1>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="panel panel-default">
                    <div class="panel-body" style="text-align: center;">
                        <div style="font-size: 13px; color: #888; text-transform: uppercase;">Pending Backup</div>
                        <div style="font-size: 36px; font-weight: bold; color: <?php echo $pending_count > 0 ? '#f59e0b' : '#10b981'; ?>;">
                            <?php echo $pending_count; ?>
                        </div>
                        <div style="color: #888; font-size: 13px;">container(s) waiting</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="panel panel-default">
                    <div class="panel-body" style="text-align: center;">
                        <div style="font-size: 13px; color: #888; text-transform: uppercase;">Last Backed Up</div>
                        <?php if ($last_backed_up): ?>
                        <div style="font-size: 18px; font-weight: bold; margin-top: 8px;"><?php echo htmlspecialchars($last_backed_up->container_number); ?></div>
                        <div style="color: #888; font-size: 13px;"><?php echo date('M d, Y g:i A', strtotime($last_backed_up->drive_backed_up_at)); ?></div>
                        <?php else: ?>
                        <div style="color: #888; margin-top: 12px;">No backups have run yet</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="panel panel-default">
                    <div class="panel-body" style="text-align: center;">
                        <div style="font-size: 13px; color: #888; text-transform: uppercase;">Google Account</div>
                        <?php if (!$oauth_creds_configured): ?>
                        <div style="margin-top: 10px;"><span class="label label-danger"><i class="fa fa-exclamation-triangle"></i> OAuth client not configured</span></div>
                        <div style="color: #888; font-size: 12px; margin-top: 6px;">See GOOGLE_DRIVE_OAUTH_SETUP.md</div>
                        <?php elseif ($oauth_connected): ?>
                        <div style="margin-top: 10px;"><span class="label label-success"><i class="fa fa-check"></i> Connected</span></div>
                        <?php if (!empty($oauth_token['connected_email'])): ?>
                        <div style="color: #888; font-size: 12px; margin-top: 6px;"><?php echo htmlspecialchars($oauth_token['connected_email']); ?></div>
                        <?php endif; ?>
                        <div style="margin-top: 8px;">
                            <a href="drive_oauth_connect.php" class="btn btn-default btn-xs">Reconnect / switch account</a>
                        </div>
                        <?php else: ?>
                        <div style="margin-top: 10px;"><span class="label label-warning"><i class="fa fa-link"></i> Not connected</span></div>
                        <div style="margin-top: 8px;">
                            <a href="drive_oauth_connect.php" class="btn btn-primary btn-sm">Connect Google Drive Account</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="panel panel-default">
                    <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 class="panel-title">Run Backup Now</h3>
                        <button type="button" class="btn btn-primary" id="runBackupBtn">
                            <i class="fa fa-cloud-upload"></i> Run Backup Now
                        </button>
                    </div>
                    <div class="panel-body">
                        <p class="text-muted">
                            Manually triggers the same backup process the nightly cron job runs.
                            Useful for testing your setup without waiting until 2am. This may take
                            a while if there are many photos pending - the button will show progress.
                        </p>
                        <div id="runBackupResult"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Recent Log (last 60 lines)</h3>
                    </div>
                    <div class="panel-body">
                        <pre id="backupLogOutput" style="background: #1f2937; color: #d1d5db; padding: 15px; border-radius: 6px; max-height: 400px; overflow: auto; font-size: 12.5px; min-height: 40px;"><?php echo empty($log_lines) ? 'No log entries yet. Click "Run Backup Now" above to see activity here.' : htmlspecialchars(implode("\n", $log_lines)); ?></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#runBackupBtn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Running... this may take a minute');
        $('#runBackupResult').html('');

        $.ajax({
            url: '<?php echo $us_url_root; ?>usersc/ajax/drive_backup_run.php',
            type: 'POST',
            data: { csrf: '<?php echo $csrf; ?>' },
            dataType: 'json',
            timeout: 290000,
            success: function(response) {
                if (response.success) {
                    $('#runBackupResult').html('<div class="alert alert-success">' + response.message + '</div>');
                    if (response.log && response.log.length) {
                        $('#backupLogOutput').text(response.log.join('\n'));
                    }
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $('#runBackupResult').html('<div class="alert alert-danger">' + response.message + '</div>');
                }
            },
            error: function(xhr) {
                var msg = 'Request failed (status ' + xhr.status + '). Check the log below or the server error log.';
                $('#runBackupResult').html('<div class="alert alert-danger">' + msg + '</div>');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-cloud-upload"></i> Run Backup Now');
            }
        });
    });
});
</script>

<style>
/* ===== Bootstrap 3 component compatibility shim =====
   Some sites run a newer Bootstrap version where .panel/.label/.well
   were renamed or removed (Bootstrap 4/5 use .card/.badge instead).
   These rules guarantee the classes below render correctly either way -
   harmless if Bootstrap 3 already styles them, and a real fix if not. */
.panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.panel-heading {
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    border-radius: 6px 6px 0 0;
}
.panel-default .panel-heading { background: #f8f9fa; color: #333; }
.panel-primary .panel-heading { background: #0067b8; color: #fff; }
.panel-primary { border-color: #0067b8; }
.panel-title { margin: 0; font-size: 16px; font-weight: 600; }
.panel-body { padding: 16px; }

.label {
    display: inline-block;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    border-radius: 4px;
    color: #fff;
    white-space: nowrap;
}
.label-lg { font-size: 14px; padding: 5px 12px; }
.label-default { background: #6b7280; }
.label-primary { background: #0067b8; }
.label-success { background: #10b981; }
.label-info    { background: #3b82f6; }
.label-warning { background: #f59e0b; }
.label-danger  { background: #ef4444; }

.well {
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 16px;
}

@media (max-width: 767px) {
    .page-header {
        font-size: 22px;
        margin-bottom: 15px;
    }
    .panel-body {
        padding: 14px;
    }
}
</style>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
