<?php if (!in_array($user->data()->id, $master_account)) {
  Redirect::to($us_url_root . 'users/admin.php');
} //only allow master accounts to manage plugins!
?>

<?php
include "plugin_info.php";
pluginActive($plugin_name);

// Links back to this page keep whatever admin.php view/plugin params got us here.
$rbUrl = function (array $extra = []) {
  $base = ['view' => Input::get('view'), 'plugin' => Input::get('plugin')];
  return '?' . http_build_query(array_merge($base, $extra));
};
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
// Input::get() HTML-escapes values in this install; text that is escaped again
// on output is read raw instead (same approach as email_template_edit.php).
$raw = function ($k) { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : ''; };

$rbMsg = []; $rbErr = [];
$rbCheckResults = null;
$rbTablesOk = true;
try {
  $db->query('SELECT 1 FROM ' . RbReports::T_REPORTS . ' LIMIT 1');
  if ($db->error()) $rbTablesOk = false;
} catch (\Throwable $e) { $rbTablesOk = false; }

/** "a@b.com | note", "user:5 | note", "perm:3 | note" → recipient items. */
$rbParseRecipients = function ($text) {
  $items = [];
  foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    [$who, $note] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
    if (preg_match('/^user:(\d+)$/i', $who, $m))            $items[] = ['kind' => 'user', 'user_id' => (int) $m[1], 'note' => $note];
    elseif (preg_match('/^perm(?:ission)?:(\d+)$/i', $who, $m)) $items[] = ['kind' => 'permission', 'permission_id' => (int) $m[1], 'note' => $note];
    else                                                     $items[] = ['kind' => 'email', 'email' => $who, 'note' => $note];
  }
  return $items;
};
$rbRecipientsText = function ($report_id) {
  $lines = [];
  foreach (RbReports::recipients($report_id) as $r) {
    $who = $r->kind === 'user' ? 'user:' . $r->user_id : ($r->kind === 'permission' ? 'perm:' . $r->permission_id : $r->email);
    $lines[] = $who . ($r->note ? ' | ' . $r->note : '');
  }
  return implode("\n", $lines);
};

if (!empty($_POST)) {
  if (!Token::check(Input::get('csrf'))) {
    include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
  }
  $action = Input::get('action');
  $rid = (int) Input::get('report_id');
  $report = $rid && $rbTablesOk ? RbReports::get($rid) : null;

  if ($action === 'check_datasets') {
    // Run each dataset once with every field, 5 rows, as the current user —
    // proves the config matches the live schema.
    $rbCheckResults = [];
    foreach (RbRegistry::all() as $key => $ds) {
      $start = microtime(true);
      try {
        $res = RbQuery::run($key, ['fields' => array_keys($ds['fields']), 'limit' => 5], ['user_id' => $user->data()->id]);
        $rbCheckResults[$key] = ['ok' => true, 'msg' => count($res['rows']) . ' row(s) returned in ' . round((microtime(true) - $start) * 1000) . ' ms'];
      } catch (\Throwable $e) {
        $rbCheckResults[$key] = ['ok' => false, 'msg' => $e->getMessage()];
      }
    }
  } elseif ($action === 'create_preset' && $rbTablesOk) {
    $presets = RbReports::presets();
    $key = Input::get('preset');
    if (isset($presets[$key])) {
      $newId = RbReports::createFromPreset($presets[$key], $user->data()->id);
      Redirect::to($rbUrl(['rb_edit' => $newId, 'rb_msg' => 'created']));
    }
  } elseif ($action === 'create_blank' && $rbTablesOk) {
    $newId = RbReports::save(['name' => 'New report', 'layout_json' => json_encode(['blocks' => [['type' => 'header', 'title' => '{report_name}', 'subtitle' => '{date}']]]), 'created_by' => $user->data()->id]);
    Redirect::to($rbUrl(['rb_edit' => $newId]));
  } elseif ($report && $action === 'toggle') {
    RbReports::save(['active' => $report->active ? 0 : 1], $rid);
    $rbMsg[] = $report->active ? 'Report paused.' : 'Report activated — it will send on its schedule.';
  } elseif ($report && $action === 'delete') {
    RbReports::delete($rid);
    $rbMsg[] = 'Report deleted.';
  } elseif ($report && in_array($action, ['send_test', 'send_now'], true)) {
    $opts = $action === 'send_test' ? ['only_to' => ['email' => $user->data()->email, 'user_id' => $user->data()->id]] : [];
    $res = RbReports::run($report, $action === 'send_test' ? 'test' : 'manual', $opts);
    if ($res['success']) $rbMsg[] = ($action === 'send_test' ? 'Test sent to ' . $user->data()->email : $res['message']) . " ({$res['rows']} row(s)).";
    else $rbErr[] = 'Send failed: ' . $res['message'];
  } elseif ($report && $action === 'save') {
    $layout = json_decode($raw('layout_json'), true);
    $name = mb_substr($raw('name'), 0, 150);
    if ($name === '') $rbErr[] = 'Name is required.';
    if (!is_array($layout)) $rbErr[] = 'Layout is not valid JSON: ' . json_last_error_msg();
    $data = [
      'name'                  => $name,
      'description'           => mb_substr($raw('description'), 0, 255) ?: null,
      'schedule_frequency'    => in_array(Input::get('schedule_frequency'), RbReports::FREQUENCIES, true) ? Input::get('schedule_frequency') : null,
      'schedule_day_of_week'  => (int) Input::get('schedule_day_of_week'),
      'schedule_day_of_month' => (int) Input::get('schedule_day_of_month') ?: 1,
      'schedule_hour'         => (int) Input::get('schedule_hour'),
      'scope_mode'            => Input::get('scope_mode'),
      'attach_csv'            => Input::get('attach_csv') ? 1 : 0,
    ];
    if (!$rbErr) {
      // Dry-run render so a broken layout never reaches a scheduled send.
      try {
        $probe = clone $report;
        $probe->layout_json = json_encode($layout);
        foreach ($data as $k => $v) $probe->$k = $v;
        RbReports::render($probe, RbReports::scopeCtx($probe, $user->data()->id));
      } catch (\Throwable $e) {
        $rbErr[] = 'Layout problem: ' . $e->getMessage();
      }
    }
    if (!$rbErr) {
      $data['layout_json'] = json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      RbReports::save($data, $rid);
      foreach (RbReports::saveRecipients($rid, $rbParseRecipients($raw('recipients'))) as $p) $rbErr[] = $p;
      $rbMsg[] = 'Saved.';
    }
  }
}

if (Input::get('rb_msg') === 'created') $rbMsg[] = 'Report created (paused). Preview it, send yourself a test, then activate it.';
$rbDatasets   = RbRegistry::all();
$rbLoadErrors = RbRegistry::errors();
$rbReports    = $rbTablesOk ? RbReports::all() : [];
$rbPresets    = $rbTablesOk ? RbReports::presets() : [];
$rbEdit       = $rbTablesOk && Input::get('rb_edit') ? RbReports::get((int) Input::get('rb_edit')) : null;
$rbPreview    = $rbTablesOk && Input::get('rb_preview') ? RbReports::get((int) Input::get('rb_preview')) : null;
$rbDow = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$rbScheduleText = function ($r) use ($rbDow) {
  if (!$r->schedule_frequency) return 'Not scheduled';
  $t = date('g A', mktime((int) $r->schedule_hour, 0));
  if ($r->schedule_frequency === 'weekly') return $rbDow[(int) $r->schedule_day_of_week] . 's at ' . $t;
  if ($r->schedule_frequency === 'monthly') return 'Monthly on day ' . (int) $r->schedule_day_of_month . ' at ' . $t;
  return 'Daily at ' . $t;
};
?>

<div class="content mt-3">
  <div class="row">
    <div class="col-12">
      <a href="<?= $us_url_root ?>users/admin.php?view=plugins">Return to the Plugin Manager</a>
      <h1>Report Builder</h1>

      <?php foreach ($rbMsg as $m): ?><div class="alert alert-success"><?= $h($m) ?></div><?php endforeach; ?>
      <?php foreach ($rbErr as $m): ?><div class="alert alert-danger"><?= $h($m) ?></div><?php endforeach; ?>
      <?php if (!$rbTablesOk): ?>
        <div class="alert alert-warning">The report tables don't exist yet. Run this plugin's migrations (Plugin Manager → Update/Migrate), then reload.</div>
      <?php endif; ?>

      <?php if ($rbTablesOk): ?>
      <!-- ── Reports ─────────────────────────────────────────────────────── -->
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Reports</strong>
          <div class="d-flex gap-2">
            <?php if ($rbPresets): ?>
            <form method="post" class="d-flex gap-2">
              <?= tokenHere(); ?><input type="hidden" name="action" value="create_preset">
              <select name="preset" class="form-select form-select-sm">
                <?php foreach ($rbPresets as $k => $p): ?><option value="<?= $h($k) ?>"><?= $h($p['name']) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-primary" type="submit" style="white-space:nowrap;">Create from preset</button>
            </form>
            <?php endif; ?>
            <form method="post"><?= tokenHere(); ?><input type="hidden" name="action" value="create_blank"><button class="btn btn-sm btn-outline-primary" type="submit">Blank report</button></form>
          </div>
        </div>
        <div class="card-body">
          <?php if (!$rbReports): ?>
            <p class="text-muted mb-0">No reports yet. Use <em>Create from preset</em> to start from the Daily Digest.</p>
          <?php else: ?>
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Name</th><th>Schedule</th><th>Recipients</th><th>Last sent</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rbReports as $r): ?>
              <tr>
                <td><strong><?= $h($r->name) ?></strong><?php if ($r->description): ?><br><small class="text-muted"><?= $h($r->description) ?></small><?php endif; ?></td>
                <td><small><?= $h($rbScheduleText($r)) ?></small></td>
                <td><?= count(RbReports::recipients($r->id)) ?></td>
                <td><small><?= $r->last_sent_at ? $h(date('M j, g:ia', strtotime($r->last_sent_at))) : 'Never' ?></small></td>
                <td>
                  <form method="post" class="d-inline"><?= tokenHere(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="report_id" value="<?= (int) $r->id ?>">
                    <button class="btn btn-sm <?= $r->active ? 'btn-success' : 'btn-outline-secondary' ?>" type="submit"><?= $r->active ? 'Active' : 'Paused' ?></button></form>
                </td>
                <td style="white-space:nowrap;">
                  <a class="btn btn-sm btn-outline-primary" href="<?= $h($rbUrl(['rb_edit' => $r->id])) ?>">Edit</a>
                  <a class="btn btn-sm btn-outline-primary" href="<?= $h($rbUrl(['rb_preview' => $r->id])) ?>">Preview</a>
                  <form method="post" class="d-inline"><?= tokenHere(); ?><input type="hidden" name="action" value="send_test"><input type="hidden" name="report_id" value="<?= (int) $r->id ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit" title="Send only to <?= $h($user->data()->email) ?>">Test to me</button></form>
                  <form method="post" class="d-inline" onsubmit="return confirm('Send this report to all its recipients now?');"><?= tokenHere(); ?><input type="hidden" name="action" value="send_now"><input type="hidden" name="report_id" value="<?= (int) $r->id ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Send now</button></form>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this report and its history?');"><?= tokenHere(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="report_id" value="<?= (int) $r->id ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($rbPreview): ?>
      <!-- ── Preview ─────────────────────────────────────────────────────── -->
      <div class="card mb-4">
        <div class="card-header"><strong>Preview: <?= $h($rbPreview->name) ?></strong> <small class="text-muted">— live data, as you would see it with this report's scope setting</small></div>
        <div class="card-body">
          <?php try { $out = RbReports::render($rbPreview, RbReports::scopeCtx($rbPreview, $user->data()->id)); ?>
            <p class="mb-2"><strong>Subject:</strong> <?= $h($out['subject']) ?></p>
            <?php if ($out['attachments']): ?><p class="mb-2"><strong>CSV:</strong> <?= $h(implode(', ', array_column($out['attachments'], 'name'))) ?><?= $rbPreview->attach_csv ? '' : ' <span class="text-muted">(attachments are off for this report)</span>' ?></p><?php endif; ?>
            <iframe sandbox="" style="width:100%;height:800px;border:1px solid #ddd;background:#fff;" srcdoc="<?= $h('<!doctype html><meta charset="utf-8"><body style="margin:16px;background:#f1f5f9;">' . $out['html']) ?>"></iframe>
          <?php } catch (\Throwable $e) { ?>
            <div class="alert alert-danger"><?= $h($e->getMessage()) ?></div>
          <?php } ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($rbEdit): ?>
      <!-- ── Edit ────────────────────────────────────────────────────────── -->
      <div class="card mb-4">
        <div class="card-header"><strong>Edit: <?= $h($rbEdit->name) ?></strong></div>
        <div class="card-body">
          <form method="post" action="<?= $h($rbUrl(['rb_edit' => $rbEdit->id])) ?>">
            <?= tokenHere(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="report_id" value="<?= (int) $rbEdit->id ?>">
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" value="<?= $h($_POST['name'] ?? $rbEdit->name) ?>" required></div>
              <div class="col-md-6"><label class="form-label">Description</label><input class="form-control" name="description" value="<?= $h($_POST['description'] ?? $rbEdit->description) ?>"></div>

              <div class="col-md-3"><label class="form-label">Frequency</label>
                <select class="form-select" name="schedule_frequency">
                  <option value="">Not scheduled (manual only)</option>
                  <?php foreach (RbReports::FREQUENCIES as $f): ?><option value="<?= $f ?>" <?= $rbEdit->schedule_frequency === $f ? 'selected' : '' ?>><?= ucfirst($f) ?></option><?php endforeach; ?>
                </select></div>
              <div class="col-md-3"><label class="form-label">Day of week <small class="text-muted">(weekly)</small></label>
                <select class="form-select" name="schedule_day_of_week"><?php foreach ($rbDow as $i => $d): ?><option value="<?= $i ?>" <?= (int) $rbEdit->schedule_day_of_week === $i ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
              <div class="col-md-3"><label class="form-label">Day of month <small class="text-muted">(monthly)</small></label>
                <select class="form-select" name="schedule_day_of_month"><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>" <?= (int) $rbEdit->schedule_day_of_month === $d ? 'selected' : '' ?>><?= $d ?><?= $d > 28 ? ' (or last day)' : '' ?></option><?php endfor; ?></select></div>
              <div class="col-md-3"><label class="form-label">Time (server time)</label>
                <select class="form-select" name="schedule_hour"><?php for ($hr = 0; $hr < 24; $hr++): ?><option value="<?= $hr ?>" <?= (int) $rbEdit->schedule_hour === $hr ? 'selected' : '' ?>><?= date('g:00 A', mktime($hr, 0)) ?></option><?php endfor; ?></select></div>

              <div class="col-md-6"><label class="form-label">Whose data access applies</label>
                <select class="form-select" name="scope_mode">
                  <option value="creator" <?= $rbEdit->scope_mode === 'creator' ? 'selected' : '' ?>>Report creator's (default)</option>
                  <option value="recipient" <?= $rbEdit->scope_mode === 'recipient' ? 'selected' : '' ?>>Each user recipient gets their own (e.g. their warehouses only)</option>
                  <option value="none" <?= $rbEdit->scope_mode === 'none' ? 'selected' : '' ?>>No restriction — everything</option>
                </select></div>
              <div class="col-md-6 d-flex align-items-end"><div class="form-check">
                <input class="form-check-input" type="checkbox" name="attach_csv" value="1" id="rbCsv" <?= $rbEdit->attach_csv ? 'checked' : '' ?>>
                <label class="form-check-label" for="rbCsv">Attach a CSV of each table</label></div></div>

              <div class="col-12"><label class="form-label">Recipients</label>
                <textarea class="form-control font-monospace" name="recipients" rows="4" placeholder="ops@client.com | Acme ops manager&#10;user:12 | Night supervisor&#10;perm:3 | All supervisors"><?= $h($_POST['recipients'] ?? $rbRecipientsText($rbEdit->id)) ?></textarea>
                <small class="text-muted">One per line, optional note after <code>|</code>. An email address (doesn't need an account), <code>user:ID</code>, or <code>perm:ID</code> for everyone with that permission level (looked up at send time).</small></div>

              <div class="col-12"><label class="form-label">Layout (JSON)</label>
                <textarea class="form-control font-monospace" name="layout_json" rows="18" style="font-size:12px;"><?= $h($_POST['layout_json'] ?? json_encode(json_decode((string) $rbEdit->layout_json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></textarea>
                <small class="text-muted">Temporary until the drag-and-drop editor (stage 3). Block reference: <code>usersc/plugins/report_builder/assets/includes/rb_render.php</code>. The layout is test-rendered before saving.</small></div>
            </div>
            <div class="mt-3">
              <button class="btn btn-success" type="submit">Save</button>
              <a class="btn btn-outline-primary" href="<?= $h($rbUrl(['rb_edit' => $rbEdit->id, 'rb_preview' => $rbEdit->id])) ?>">Preview</a>
              <a class="btn btn-link" href="<?= $h($rbUrl()) ?>">Close</a>
            </div>
          </form>

          <?php $rbLog = RbReports::runLog($rbEdit->id, 10); if ($rbLog): ?>
          <h6 class="mt-4">Recent runs</h6>
          <table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>Trigger</th><th>Recipients</th><th>Rows</th><th>Result</th></tr></thead>
            <tbody><?php foreach ($rbLog as $l): ?>
              <tr><td><small><?= $h($l->run_at) ?></small></td><td><?= $h($l->trigger_type) ?></td><td><?= (int) $l->recipient_count ?></td><td><?= (int) $l->row_count ?></td>
                <td><?= $l->success ? '<span class="text-success">OK</span>' : '<span class="text-danger">' . $h($l->error_message) . '</span>' ?></td></tr>
            <?php endforeach; ?></tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; /* tables ok */ ?>

      <!-- ── Datasets ──────────────────────────────────────────────────────── -->
      <h2 class="h4 mt-4">Datasets</h2>
      <p>
        Reports are built from <strong>datasets</strong>. Each project registers its own by dropping a PHP
        file into <code>usersc/report_datasets/</code> — see <code>containers.php</code> there, and
        <code>assets/includes/rb_registry.php</code> in this plugin for every option. The builder can only
        query registered fields, and every value a user enters reaches the database as a bound parameter.
      </p>

      <?php foreach ($rbLoadErrors as $file => $msg): ?>
        <div class="alert alert-danger"><strong><?= $h($file) ?> didn't load:</strong> <?= $h($msg) ?></div>
      <?php endforeach; ?>

      <form method="post" class="mb-3">
        <?= tokenHere(); ?>
        <input type="hidden" name="action" value="check_datasets">
        <button type="submit" class="btn btn-primary">Check datasets against the database</button>
      </form>

      <?php if (empty($rbDatasets) && !$rbLoadErrors): ?>
        <div class="alert alert-warning">No datasets registered. Add a file to <code>usersc/report_datasets/</code>.</div>
      <?php endif; ?>

      <?php foreach ($rbDatasets as $key => $ds): ?>
        <div class="card mb-4">
          <div class="card-header">
            <strong><?= $h($ds['label']) ?></strong>
            <code class="ms-2"><?= $h($key) ?></code>
            <?php if (!empty($ds['scope'])): ?><span class="badge bg-info ms-2">scoped per user</span><?php endif; ?>
            <?php if (isset($rbCheckResults[$key])): $r = $rbCheckResults[$key]; ?>
              <span class="badge <?= $r['ok'] ? 'bg-success' : 'bg-danger' ?> ms-2"><?= $r['ok'] ? 'OK' : 'FAILED' ?></span>
              <small class="ms-2"><?= $h($r['msg']) ?></small>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if ($ds['description']): ?><p><?= $h($ds['description']) ?></p><?php endif; ?>
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Filter</th><th>Group</th><th>Sum/Avg</th><th>Sort</th></tr></thead>
              <tbody>
              <?php foreach ($ds['fields'] as $f): ?>
                <tr>
                  <td><code><?= $h($f['key']) ?></code></td>
                  <td><?= $h($f['label']) ?></td>
                  <td><?= $h($f['type']) ?></td>
                  <td><?= $f['filterable'] ? '✓' : '' ?></td>
                  <td><?= $f['groupable'] ? '✓' : '' ?></td>
                  <td><?= $f['aggregatable'] ? '✓' : '' ?></td>
                  <td><?= $f['sortable'] ? '✓' : '' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Do not close the content mt-3 div in this file -->
