<?php
/**
 * Email Template Editor — Container Tracking System
 * Supervisors edit the HTML subject/body used for the automatic
 * inbound/outbound completion-notification emails. Run
 * 01_email_templates_migration.sql once before using this page.
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

$active_tab = Input::get('tab') === 'outbound' ? 'outbound' : 'inbound';
$message    = '';
$msg_type   = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Token::check(Input::get('csrf'))) {
    $action = Input::get('action');
    $type   = Input::get('type') === 'outbound' ? 'outbound' : 'inbound';
    $active_tab = $type;

    if ($action === 'save') {
        // Read these two directly from $_POST rather than Input::get() —
        // this framework's Input helper auto-htmlspecialchars's values,
        // which is right for normal text fields but wrong here: it would
        // double-encode the HTML template (tags become &lt;div&gt; etc.,
        // which then show up as literal text in the sent email instead
        // of rendering). csrf/action/type above are fine through Input::get()
        // since they're not HTML content.
        $subject   = trim($_POST['subject'] ?? '');
        $html_body = $_POST['html_body'] ?? ''; // don't trim — leading whitespace may matter

        if (empty($subject)) {
            $message = 'Subject cannot be empty.';
            $msg_type = 'danger';
        } elseif (empty(trim($html_body))) {
            $message = 'Email body cannot be empty.';
            $msg_type = 'danger';
        } else {
            saveEmailTemplate($type, $subject, $html_body, $user->data()->id);
            $message = ucfirst($type) . ' template saved.';
        }
    } elseif ($action === 'reset') {
        resetEmailTemplate($type);
        $message = ucfirst($type) . ' template reset to default.';
    }
}

$inbound_template  = getEmailTemplate('inbound');
$outbound_template = getEmailTemplate('outbound');
$var_docs           = getEmailTemplateVariableDocs();
$csrf                = Token::generate();

// Sample values for the live preview (client-side JS uses these)
$sample_vars = [
    'client_name'      => 'Acme Imports',
    'container_number' => 'MSKU1234567',
    'date'              => date('l, F j, Y'),
    'photo_count'       => '14',
    'portal_url'        => '#',
    'shipment_row'      => '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:7px 0;color:#6b7280;">Shipment #</td><td style="padding:7px 0;font-weight:600;">SHIP-4821</td></tr>',
    'po_bol_row'        => '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:7px 0;color:#6b7280;">PO / BOL</td><td style="padding:7px 0;font-weight:600;">PO-9981</td></tr>',
    'date_row'          => '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:7px 0;color:#6b7280;">Ship Date</td><td style="padding:7px 0;font-weight:600;">Aug 05, 2026</td></tr>',
    'skipped_notice'    => '',
];
?>
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">Email Templates</h1>
                <p class="text-muted">
                    Edit the HTML used for the automatic completion-notification email that goes
                    out when a container is marked <strong>Reviewed</strong>. Use the
                    <code>{{variable}}</code> tokens listed below — they get replaced with real
                    data each time an email sends.
                </p>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <ul class="nav nav-tabs" style="margin-bottom:20px;">
            <li class="<?php echo $active_tab === 'inbound' ? 'active' : ''; ?>">
                <a href="?tab=inbound">Inbound</a>
            </li>
            <li class="<?php echo $active_tab === 'outbound' ? 'active' : ''; ?>">
                <a href="?tab=outbound">Outbound</a>
            </li>
        </ul>

        <div class="row">
            <div class="col-md-7">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title"><?php echo ucfirst($active_tab); ?> Template</h3>
                    </div>
                    <div class="panel-body">
                        <form method="post" id="templateForm">
                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="type" value="<?php echo $active_tab; ?>">

                            <div class="form-group">
                                <label for="subject">Subject line</label>
                                <input type="text" class="form-control" id="subject" name="subject"
                                       value="<?php echo htmlspecialchars($active_tab === 'inbound' ? $inbound_template['subject'] : $outbound_template['subject']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="html_body">Email body (HTML)</label>
                                <textarea class="form-control" id="html_body" name="html_body" rows="22"
                                          style="font-family:'Courier New',monospace;font-size:12px;"><?php echo htmlspecialchars($active_tab === 'inbound' ? $inbound_template['html_body'] : $outbound_template['html_body']); ?></textarea>
                            </div>

                            <button type="submit" class="btn btn-success">
                                <i class="fa fa-save"></i> Save <?php echo ucfirst($active_tab); ?> Template
                            </button>
                            <button type="button" class="btn btn-default" id="updatePreviewBtn">
                                <i class="fa fa-refresh"></i> Update Preview
                            </button>
                        </form>

                        <form method="post" style="display:inline;margin-top:10px;"
                              onsubmit="return confirm('Reset the <?php echo $active_tab; ?> template back to the default? This can\'t be undone.');">
                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="reset">
                            <input type="hidden" name="type" value="<?php echo $active_tab; ?>">
                            <button type="submit" class="btn btn-link text-danger" style="padding-left:0;margin-top:10px;">
                                Reset to default
                            </button>
                        </form>
                    </div>
                </div>

                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title">Available variables</h3></div>
                    <div class="panel-body">
                        <table class="table table-condensed">
                            <?php foreach ($var_docs as $key => $desc): ?>
                            <tr>
                                <td style="width:180px;"><code>{{<?php echo $key; ?>}}</code></td>
                                <td><?php echo htmlspecialchars($desc); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <p class="text-muted" style="margin-bottom:0;">
                            <code>shipment_row</code>, <code>po_bol_row</code>, <code>date_row</code>, and
                            <code>skipped_notice</code> are ready-made HTML snippets (or blank) — place the
                            token wherever you want that row/notice to appear, but the row content itself
                            isn't separately editable.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-5">
                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title">Live preview</h3></div>
                    <div class="panel-body" style="background:#f3f4f6;">
                        <p style="font-size:13px;"><strong>Subject:</strong> <span id="previewSubject"></span></p>
                        <iframe id="previewFrame" style="width:100%;height:520px;border:1px solid #ddd;background:#fff;"></iframe>
                        <p class="text-muted" style="margin-top:8px;font-size:12px;">
                            Preview uses sample data (container MSKU1234567, etc.) — actual emails use the
                            real container and client info.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var SAMPLE_VARS = <?php echo json_encode($sample_vars); ?>;

function renderPreview() {
    var subjectTpl = document.getElementById('subject').value;
    var bodyTpl = document.getElementById('html_body').value;

    Object.keys(SAMPLE_VARS).forEach(function(key) {
        var token = new RegExp('\\{\\{' + key + '\\}\\}', 'g');
        subjectTpl = subjectTpl.replace(token, SAMPLE_VARS[key]);
        bodyTpl = bodyTpl.replace(token, SAMPLE_VARS[key]);
    });

    document.getElementById('previewSubject').textContent = subjectTpl;

    var frame = document.getElementById('previewFrame');
    var doc = frame.contentWindow.document;
    doc.open();
    doc.write(bodyTpl);
    doc.close();
}

document.getElementById('updatePreviewBtn').addEventListener('click', renderPreview);
document.addEventListener('DOMContentLoaded', renderPreview);
</script>
