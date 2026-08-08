<?php
/**
 * Report Builder — Container Tracking System
 * Supervisor-only page to build automated report definitions.
 * Run 07_reports_migration.sql once before using this page, and set up
 * the cron job described in usersc/cron/reports_cron.php for scheduled
 * delivery (event-triggered delivery needs no extra setup — it's wired
 * into every status change automatically).
 */
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}
if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

$user_id = $user->data()->id;
$errors  = [];
$message = '';

// ── Handle form submission ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Token::check(Input::get('csrf'))) {
    $action = Input::get('action');

    if ($action === 'delete') {
        $report_id = (int) Input::get('report_id');
        if ($report_id) {
            deleteReportDefinition($report_id);
            $message = 'Report deleted.';
        }
    } elseif ($action === 'toggle_active') {
        $report_id = (int) Input::get('report_id');
        $report = getReportById($report_id);
        if ($report) {
            DB::getInstance()->update('report_definitions', $report_id, ['active' => $report->active ? 0 : 1]);
            $message = $report->active ? 'Report paused.' : 'Report activated.';
        }
    } elseif ($action === 'send_now') {
        $report_id = (int) Input::get('report_id');
        $report = getReportById($report_id);
        if ($report) {
            [$start, $end] = getReportDateRangeBounds($report);
            $containers = getContainersForReport($report, $start, $end);
            $result = sendReportEmail($report, $containers, 'scheduled', null);
            $message = $result['sent']
                ? 'Report sent to ' . count(array_column(getReportRecipients($report_id), 'email')) . ' recipient(s) — ' . count($containers) . ' container(s) included.'
                : 'Send failed: ' . htmlspecialchars($result['reason']);
        }
    } elseif ($action === 'save') {
        $name = trim(Input::get('name'));
        if (empty($name)) {
            $errors[] = 'Report name is required.';
        }

        $recipients_raw = Input::get('recipients');
        $recipient_emails = preg_split('/[,;\r\n]+/', (string) $recipients_raw);
        $recipient_emails = array_filter(array_map('trim', $recipient_emails));
        $invalid = array_filter($recipient_emails, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
        if (!empty($invalid)) {
            $errors[] = 'These recipient addresses look invalid: ' . htmlspecialchars(implode(', ', $invalid));
        }

        $delivery_mode = in_array(Input::get('delivery_mode'), ['scheduled', 'event', 'both'], true) ? Input::get('delivery_mode') : 'scheduled';

        if (in_array($delivery_mode, ['event', 'both'], true) && empty(Input::get('trigger_status'))) {
            $errors[] = 'Choose a status to trigger on for event-based delivery.';
        }
        if (in_array($delivery_mode, ['scheduled', 'both'], true) && empty(Input::get('schedule_frequency'))) {
            $errors[] = 'Choose a schedule frequency for scheduled delivery.';
        }
        if (empty($errors) && empty($recipient_emails)) {
            $errors[] = 'At least one valid recipient email is required.';
        }

        if (empty($errors)) {
            $data = [
                'name'                  => $name,
                'description'           => trim(Input::get('description')) ?: null,
                'active'                => Input::get('active') ? 1 : 0,
                'delivery_mode'         => $delivery_mode,
                'trigger_status'        => in_array($delivery_mode, ['event', 'both'], true) ? Input::get('trigger_status') : null,
                'schedule_frequency'    => in_array($delivery_mode, ['scheduled', 'both'], true) ? Input::get('schedule_frequency') : null,
                'schedule_day_of_week'  => Input::get('schedule_frequency') === 'weekly' ? (int) Input::get('schedule_day_of_week') : null,
                'schedule_day_of_month' => Input::get('schedule_frequency') === 'monthly' ? (int) Input::get('schedule_day_of_month') : null,
                'schedule_hour'         => Input::get('schedule_hour') !== '' ? (int) Input::get('schedule_hour') : 8,
                'filter_type'           => Input::get('filter_type') ?: null,
                'filter_status'         => Input::get('filter_status') ?: null,
                'filter_customer_id'    => Input::get('filter_customer_id') ?: null,
                'filter_warehouse_id'   => Input::get('filter_warehouse_id') ?: null,
                'filter_carrier'        => trim(Input::get('filter_carrier')) ?: null,
                'date_range'            => Input::get('date_range') ?: 'since_last_report',
                'format'                => in_array(Input::get('format'), ['html_table', 'csv_attachment', 'both'], true) ? Input::get('format') : 'html_table',
            ];

            $report_id = (int) Input::get('report_id');
            if ($report_id) {
                updateReportDefinition($report_id, $data, $recipient_emails);
                $message = 'Report updated.';
            } else {
                $data['created_by'] = $user_id;
                $report_id = createReportDefinition($data, $recipient_emails);
                $message = 'Report created.';
            }
            Redirect::to('reports_builder.php?msg=' . urlencode($message));
        }
    }
}

if (Input::get('msg')) $message = Input::get('msg');

$edit_id = Input::get('edit') ? (int) Input::get('edit') : null;
$editing_report = $edit_id ? getReportById($edit_id) : null;
$editing_recipients = $edit_id ? implode("\n", array_column(getReportRecipients($edit_id), 'email')) : '';

$reports    = getReportDefinitions(false);
$customers  = getAllCustomers();
$warehouses = getWarehouses(false);
$csrf       = Token::generate();

$status_options = ['pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'reviewed' => 'Reviewed'];

function rbSel($val, $target) { return (string)$val === (string)$target ? 'selected' : ''; }
?>
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">Report Builder</h1>
                <p class="text-muted">
                    Build reusable reports that email themselves out — on a schedule, whenever a
                    container hits a certain status, or both. Scheduled sends need the cron job
                    described in <code>usersc/cron/reports_cron.php</code> set up; event-triggered
                    sends work automatically as soon as a report is saved active.
                </p>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul style="margin:0;"><?php foreach ($errors as $e): ?><li><?php echo $e; ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-5">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title"><?php echo $editing_report ? 'Edit Report' : 'New Report'; ?></h3>
                    </div>
                    <div class="panel-body">
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="report_id" value="<?php echo $editing_report->id ?? ''; ?>">

                            <div class="form-group">
                                <label>Report name</label>
                                <input type="text" class="form-control" name="name" required
                                       value="<?php echo htmlspecialchars($editing_report->name ?? ''); ?>"
                                       placeholder="e.g. Acme Imports Weekly Summary">
                            </div>

                            <div class="form-group">
                                <label>Description <small class="text-muted">(optional, just for your reference)</small></label>
                                <input type="text" class="form-control" name="description"
                                       value="<?php echo htmlspecialchars($editing_report->description ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Recipients <small class="text-muted">(one per line, or comma-separated)</small></label>
                                <textarea class="form-control" name="recipients" rows="3" required
                                          placeholder="ops@acmeimports.com&#10;dispatch@drayco.com"><?php echo htmlspecialchars($editing_recipients); ?></textarea>
                            </div>

                            <hr>
                            <h4 style="margin-top:0;">Delivery</h4>

                            <div class="form-group">
                                <label>When should this send?</label>
                                <select class="form-control" name="delivery_mode" id="rbDeliveryMode">
                                    <option value="scheduled" <?php echo rbSel($editing_report->delivery_mode ?? 'scheduled', 'scheduled'); ?>>On a schedule</option>
                                    <option value="event" <?php echo rbSel($editing_report->delivery_mode ?? '', 'event'); ?>>When a container reaches a status</option>
                                    <option value="both" <?php echo rbSel($editing_report->delivery_mode ?? '', 'both'); ?>>Both</option>
                                </select>
                            </div>

                            <div id="rbEventFields" style="display:none;">
                                <div class="form-group">
                                    <label>Trigger status</label>
                                    <select class="form-control" name="trigger_status">
                                        <option value="">-- Select status --</option>
                                        <?php foreach ($status_options as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo rbSel($editing_report->trigger_status ?? '', $key); ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Sends immediately for the one container that just changed to this status (if it matches the filters below).</small>
                                </div>
                            </div>

                            <div id="rbScheduleFields" style="display:none;">
                                <div class="form-group">
                                    <label>Frequency</label>
                                    <select class="form-control" name="schedule_frequency" id="rbFrequency">
                                        <option value="">-- Select frequency --</option>
                                        <option value="daily" <?php echo rbSel($editing_report->schedule_frequency ?? '', 'daily'); ?>>Daily</option>
                                        <option value="weekly" <?php echo rbSel($editing_report->schedule_frequency ?? '', 'weekly'); ?>>Weekly</option>
                                        <option value="monthly" <?php echo rbSel($editing_report->schedule_frequency ?? '', 'monthly'); ?>>Monthly</option>
                                    </select>
                                </div>
                                <div class="form-group" id="rbDayOfWeekGroup" style="display:none;">
                                    <label>Day of week</label>
                                    <select class="form-control" name="schedule_day_of_week">
                                        <?php $dow = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                        foreach ($dow as $i => $d): ?>
                                        <option value="<?php echo $i; ?>" <?php echo rbSel($editing_report->schedule_day_of_week ?? 1, $i); ?>><?php echo $d; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" id="rbDayOfMonthGroup" style="display:none;">
                                    <label>Day of month</label>
                                    <select class="form-control" name="schedule_day_of_month">
                                        <?php for ($d = 1; $d <= 28; $d++): ?>
                                        <option value="<?php echo $d; ?>" <?php echo rbSel($editing_report->schedule_day_of_month ?? 1, $d); ?>><?php echo $d; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <small class="text-muted">Capped at 28 so it works every month.</small>
                                </div>
                                <div class="form-group">
                                    <label>Time of day (server time)</label>
                                    <select class="form-control" name="schedule_hour">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?php echo $h; ?>" <?php echo rbSel($editing_report->schedule_hour ?? 8, $h); ?>><?php echo date('g:i A', mktime($h, 0)); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Content window</label>
                                    <select class="form-control" name="date_range">
                                        <?php $ranges = [
                                            'since_last_report' => 'Since the last time this report sent',
                                            'today' => 'Today',
                                            'yesterday' => 'Yesterday',
                                            'last_7_days' => 'Last 7 days',
                                            'last_30_days' => 'Last 30 days',
                                            'this_month' => 'This month',
                                            'last_month' => 'Last month',
                                            'all_time' => 'All time (no date limit)',
                                        ];
                                        foreach ($ranges as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo rbSel($editing_report->date_range ?? 'since_last_report', $key); ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <hr>
                            <h4 style="margin-top:0;">Which containers</h4>
                            <p class="text-muted" style="margin-top:-8px;"><small>Leave any of these blank to not restrict by that field.</small></p>

                            <div class="form-group">
                                <label>Type</label>
                                <select class="form-control" name="filter_type">
                                    <option value="">Both inbound &amp; outbound</option>
                                    <option value="inbound" <?php echo rbSel($editing_report->filter_type ?? '', 'inbound'); ?>>Inbound only</option>
                                    <option value="outbound" <?php echo rbSel($editing_report->filter_type ?? '', 'outbound'); ?>>Outbound only</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Status <small class="text-muted">(for scheduled content — event reports already filter by trigger status)</small></label>
                                <select class="form-control" name="filter_status">
                                    <option value="">Any status</option>
                                    <?php foreach ($status_options as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo rbSel($editing_report->filter_status ?? '', $key); ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Client</label>
                                <select class="form-control" name="filter_customer_id">
                                    <option value="">Any client</option>
                                    <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo $c->id; ?>" <?php echo rbSel($editing_report->filter_customer_id ?? '', $c->id); ?>><?php echo htmlspecialchars($c->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if (!empty($warehouses)): ?>
                            <div class="form-group">
                                <label>Warehouse</label>
                                <select class="form-control" name="filter_warehouse_id">
                                    <option value="">Any warehouse</option>
                                    <?php foreach ($warehouses as $w): ?>
                                    <option value="<?php echo $w->id; ?>" <?php echo rbSel($editing_report->filter_warehouse_id ?? '', $w->id); ?>><?php echo htmlspecialchars($w->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label>Carrier <small class="text-muted">(exact match — e.g. for a drayage company's reports)</small></label>
                                <input type="text" class="form-control" name="filter_carrier"
                                       value="<?php echo htmlspecialchars($editing_report->filter_carrier ?? ''); ?>"
                                       placeholder="e.g. ABC Trucking">
                            </div>

                            <hr>
                            <h4 style="margin-top:0;">Format</h4>
                            <div class="form-group">
                                <select class="form-control" name="format">
                                    <option value="html_table" <?php echo rbSel($editing_report->format ?? 'html_table', 'html_table'); ?>>Table in the email body</option>
                                    <option value="csv_attachment" <?php echo rbSel($editing_report->format ?? '', 'csv_attachment'); ?>>CSV attachment only</option>
                                    <option value="both" <?php echo rbSel($editing_report->format ?? '', 'both'); ?>>Both</option>
                                </select>
                            </div>

                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="active" value="1" <?php echo (!$editing_report || $editing_report->active) ? 'checked' : ''; ?>>
                                    Active
                                </label>
                            </div>

                            <button type="submit" class="btn btn-success">
                                <i class="fa fa-save"></i> <?php echo $editing_report ? 'Save Changes' : 'Create Report'; ?>
                            </button>
                            <?php if ($editing_report): ?>
                            <a href="reports_builder.php" class="btn btn-default">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title">Reports</h3></div>
                    <div class="panel-body">
                        <?php if (empty($reports)): ?>
                        <p class="text-muted">No reports yet — create one on the left.</p>
                        <?php else: ?>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Delivery</th>
                                    <th>Recipients</th>
                                    <th>Last sent</th>
                                    <th>Active</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $r):
                                    $recip_count = count(getReportRecipients($r->id));
                                    $delivery_desc = [];
                                    if (in_array($r->delivery_mode, ['scheduled','both'], true)) {
                                        $delivery_desc[] = ucfirst($r->schedule_frequency ?? '?') . ' @ ' . date('g A', mktime((int)$r->schedule_hour, 0));
                                    }
                                    if (in_array($r->delivery_mode, ['event','both'], true)) {
                                        $delivery_desc[] = 'On → ' . ucfirst($r->trigger_status ?? '?');
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($r->name); ?></strong>
                                        <?php if ($r->description): ?><br><small class="text-muted"><?php echo htmlspecialchars($r->description); ?></small><?php endif; ?>
                                    </td>
                                    <td><small><?php echo htmlspecialchars(implode(' + ', $delivery_desc)); ?></small></td>
                                    <td><?php echo $recip_count; ?></td>
                                    <td><small><?php echo $r->last_sent_at ? date('M j, g:ia', strtotime($r->last_sent_at)) : 'Never'; ?></small></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="report_id" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="btn btn-xs <?php echo $r->active ? 'btn-success' : 'btn-default'; ?>">
                                                <?php echo $r->active ? 'Active' : 'Paused'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <a href="reports_builder.php?edit=<?php echo $r->id; ?>" class="btn btn-xs btn-default"><i class="fa fa-pencil"></i></a>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="action" value="send_now">
                                            <input type="hidden" name="report_id" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="btn btn-xs btn-primary" title="Send now with current data">
                                                <i class="fa fa-paper-plane"></i>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this report? This can\'t be undone.');">
                                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="report_id" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var modeSel = document.getElementById('rbDeliveryMode');
    var eventFields = document.getElementById('rbEventFields');
    var scheduleFields = document.getElementById('rbScheduleFields');
    var freqSel = document.getElementById('rbFrequency');
    var dowGroup = document.getElementById('rbDayOfWeekGroup');
    var domGroup = document.getElementById('rbDayOfMonthGroup');

    function updateModeVisibility() {
        var mode = modeSel.value;
        eventFields.style.display = (mode === 'event' || mode === 'both') ? 'block' : 'none';
        scheduleFields.style.display = (mode === 'scheduled' || mode === 'both') ? 'block' : 'none';
    }
    function updateFrequencyVisibility() {
        dowGroup.style.display = freqSel.value === 'weekly' ? 'block' : 'none';
        domGroup.style.display = freqSel.value === 'monthly' ? 'block' : 'none';
    }

    modeSel.addEventListener('change', updateModeVisibility);
    freqSel.addEventListener('change', updateFrequencyVisibility);
    updateModeVisibility();
    updateFrequencyVisibility();
})();
</script>
