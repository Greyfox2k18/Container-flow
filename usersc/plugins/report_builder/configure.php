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

  if ($action === 'save_settings') {
    // Only fields that were submitted change: greyed-out (config-file) fields
    // aren't posted by the browser and must not wipe what's saved.
    $vals = [];
    foreach (['mail_provider', 'sparkpost_region', 'from_email', 'from_name', 'reply_to', 'base_url', 'brand', 'primary_color'] as $k) {
      if (array_key_exists($k, $_POST)) $vals[$k] = $raw($k);
    }
    foreach (['sparkpost_api_key', 'postmark_token'] as $k) if ($raw($k) !== '') $vals[$k] = $raw($k);   // blank = keep the saved key
    foreach (['build_perms', 'send_perms', 'unscope_perms'] as $k) {
      if (!array_key_exists($k . '_present', $_POST)) continue;   // a multi-select with nothing picked posts nothing
      $ids = isset($_POST[$k]) && is_array($_POST[$k]) ? array_filter(array_map('intval', $_POST[$k])) : [];
      $vals[$k] = implode(',', $ids);
    }
    if (isset($vals['mail_provider']) && !in_array($vals['mail_provider'], RbMail::PROVIDERS, true)) $vals['mail_provider'] = 'userspice';
    if (isset($vals['sparkpost_region']) && $vals['sparkpost_region'] !== 'eu') $vals['sparkpost_region'] = 'us';
    foreach (['from_email', 'reply_to'] as $k) if (($vals[$k] ?? '') !== '' && !filter_var($vals[$k], FILTER_VALIDATE_EMAIL)) $rbErr[] = "\"{$vals[$k]}\" isn't a valid email address.";
    if (($vals['base_url'] ?? '') !== '' && !preg_match('#^https?://[^\s]+$#i', $vals['base_url'])) $rbErr[] = 'Site address must start with http:// or https://';
    if (isset($vals['primary_color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $vals['primary_color'])) $vals['primary_color'] = '#1e3a5f';
    if (isset($vals['base_url'])) $vals['base_url'] = rtrim($vals['base_url'], '/');
    if (!$rbErr) { RbReports::saveSettings($vals); $rbMsg[] = 'Settings saved.'; }
  } elseif ($action === 'test_email') {
    $cfgNow = RbReports::config();
    $html = '<div style="font-family:Arial,sans-serif;padding:20px;"><h2 style="color:#1e3a5f;">Report Builder test</h2><p>If you can read this, scheduled reports can send email.</p></div>';
    $to = [$user->data()->email];
    $res = $cfgNow['mailer'] ? call_user_func($cfgNow['mailer'], $to, 'Report Builder test email', $html, [])
                             : RbMail::send(RbReports::mailSettings(), $to, 'Report Builder test email', $html);
    if (!empty($res['success'])) $rbMsg[] = 'Test email sent to ' . $user->data()->email . ' — ' . ($res['message'] ?? '');
    else $rbErr[] = 'Test email failed: ' . ($res['message'] ?? 'unknown error');
  } elseif ($action === 'check_datasets') {
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
      <?php $rbEditorUrl = RbReports::config()['editor_url']; if ($rbEditorUrl): ?>
        <p><a class="btn btn-primary" href="<?= $h($us_url_root . ltrim($rbEditorUrl, '/')) ?>">Open the report editor</a>
          <span class="text-muted ms-2">Supervisors build and send reports there. This page is the admin view: datasets, plus a raw JSON editor.</span></p>
      <?php endif; ?>

      <?php foreach ($rbMsg as $m): ?><div class="alert alert-success"><?= $h($m) ?></div><?php endforeach; ?>
      <?php foreach ($rbErr as $m): ?><div class="alert alert-danger"><?= $h($m) ?></div><?php endforeach; ?>
      <?php if (!$rbTablesOk): ?>
        <div class="alert alert-warning">The report tables don't exist yet. Run this plugin's migrations (Plugin Manager → Update/Migrate), then reload.</div>
      <?php endif; ?>

      <?php if ($rbTablesOk):
        $rbSt = RbReports::settings();
        $rbFromFile = RbReports::config()['from_file'];
        $rbFileHas = function ($k) use ($rbFromFile) { return in_array($k, $rbFromFile, true); };
        $rbMailFromFile = $rbFileHas('mail') || $rbFileHas('mailer');
        $rbPerms = [];
        try { $rbPerms = $db->query('SELECT id, name FROM permissions ORDER BY id')->results(); } catch (\Throwable $e) {}
        $rbMask = function ($v) { return $v === '' ? 'not set' : 'saved — ends in ' . substr($v, -4); };
        $rbLocked = function ($k) use ($rbFileHas, $h) { return $rbFileHas($k) ? ' disabled title="Set in usersc/report_builder_config.php"' : ''; };
        // Value shown: what's actually in effect (config file beats the settings page).
        $rbCfg = RbReports::config();
        $rbShow = function ($k) use ($rbFileHas, $rbCfg, $rbSt) {
          if (!$rbFileHas($k)) return $rbSt[$k];
          return $k === 'base_url' ? RbReports::baseUrl() : (is_scalar($rbCfg[$k] ?? null) ? (string) $rbCfg[$k] : $rbSt[$k]);
        };
        $rbPermSel = function ($name, $csv, $locked) use ($rbPerms, $h) {
          $ids = array_map('intval', explode(',', (string) $csv));
          $o = ($locked ? '' : '<input type="hidden" name="' . $name . '_present" value="1">')
             . '<select class="form-select" name="' . $name . '[]" multiple size="4"' . $locked . '>';
          foreach ($rbPerms as $p) $o .= '<option value="' . (int) $p->id . '"' . (in_array((int) $p->id, $ids, true) ? ' selected' : '') . '>' . $h($p->name) . ' (#' . (int) $p->id . ')</option>';
          return $o . '</select>';
        };
      ?>
      <!-- ── Settings ────────────────────────────────────────────────────── -->
      <details class="card mb-4" <?= ($rbSt['mail_provider'] === 'userspice' && !$rbMailFromFile) || !empty($_POST['action']) && in_array($_POST['action'], ['save_settings', 'test_email'], true) ? 'open' : '' ?>>
        <summary class="card-header" style="cursor:pointer;"><strong>Settings</strong> — email, site address, who can build reports</summary>
        <div class="card-body">
          <?php if ($rbFromFile): ?>
            <p class="text-muted">Greyed-out settings are set in <code>usersc/report_builder_config.php</code>, which takes priority over this page.</p>
          <?php endif; ?>
          <form method="post">
            <?= tokenHere(); ?><input type="hidden" name="action" value="save_settings">
            <h6>Email</h6>
            <?php if ($rbMailFromFile): ?>
              <div class="alert alert-info py-2">Email is configured in <code>usersc/report_builder_config.php</code><?= $rbFileHas('mail') ? ' (provider: ' . $h(RbReports::mailSettings()['provider']) . ')' : ' (custom sender)' ?>. Use <em>Send test email</em> below to check it.</div>
            <?php endif; ?>
            <fieldset <?= $rbMailFromFile ? 'disabled' : '' ?>>
            <div class="row g-3">
              <div class="col-md-4"><label class="form-label">Send with</label>
                <select class="form-select" name="mail_provider">
                  <?php foreach (['userspice' => "UserSpice's email settings (no attachments or chart images)", 'sparkpost' => 'SparkPost', 'postmark' => 'Postmark'] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $rbSt['mail_provider'] === $k ? 'selected' : '' ?>><?= $h($l) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="col-md-5"><label class="form-label">SparkPost API key <small class="text-muted">(<?= $h($rbMask($rbSt['sparkpost_api_key'])) ?>)</small></label>
                <input class="form-control" type="password" name="sparkpost_api_key" autocomplete="new-password" placeholder="Leave blank to keep the saved key"></div>
              <div class="col-md-3"><label class="form-label">SparkPost region</label>
                <select class="form-select" name="sparkpost_region"><option value="us">US (api.sparkpost.com)</option><option value="eu" <?= $rbSt['sparkpost_region'] === 'eu' ? 'selected' : '' ?>>EU (api.eu.sparkpost.com)</option></select></div>
              <div class="col-md-5"><label class="form-label">Postmark server token <small class="text-muted">(<?= $h($rbMask($rbSt['postmark_token'])) ?>)</small></label>
                <input class="form-control" type="password" name="postmark_token" autocomplete="new-password" placeholder="Leave blank to keep the saved token"></div>
              <div class="col-md-4"><label class="form-label">From address</label><input class="form-control" name="from_email" value="<?= $h($rbSt['from_email']) ?>" placeholder="reports@yourdomain.com"></div>
              <div class="col-md-3"><label class="form-label">From name</label><input class="form-control" name="from_name" value="<?= $h($rbSt['from_name']) ?>"></div>
              <div class="col-md-4"><label class="form-label">Reply-to <small class="text-muted">(optional)</small></label><input class="form-control" name="reply_to" value="<?= $h($rbSt['reply_to']) ?>"></div>
            </div>
            <small class="text-muted">The from address must be on a domain verified with SparkPost/Postmark.</small>
            </fieldset>

            <h6 class="mt-4">Site</h6>
            <div class="row g-3">
              <div class="col-md-5"><label class="form-label">Site address <small class="text-muted">(for links in emails)</small></label>
                <input class="form-control" name="base_url" value="<?= $h($rbShow('base_url')) ?>" placeholder="https://<?= $h($_SERVER['HTTP_HOST'] ?? 'example.com') ?>"<?= $rbLocked('base_url') ?>></div>
              <div class="col-md-4"><label class="form-label">Brand name <small class="text-muted">({brand})</small></label><input class="form-control" name="brand" value="<?= $h($rbShow('brand')) ?>"<?= $rbLocked('brand') ?>></div>
              <div class="col-md-3"><label class="form-label">Header colour</label><input class="form-control form-control-color" type="color" name="primary_color" value="<?= $h($rbShow('primary_color')) ?>"<?= $rbLocked('primary_color') ?>></div>
            </div>

            <h6 class="mt-4">Who can use the report builder <small class="text-muted">(master accounts always can)</small></h6>
            <div class="row g-3">
              <div class="col-md-4"><label class="form-label">Build reports &amp; send tests</label><?= $rbPermSel('build_perms', $rbSt['build_perms'], $rbLocked('can_build')) ?></div>
              <div class="col-md-4"><label class="form-label">Send to all recipients <small class="text-muted">(none = same as build)</small></label><?= $rbPermSel('send_perms', $rbSt['send_perms'], $rbLocked('can_send')) ?></div>
              <div class="col-md-4"><label class="form-label">Make reports that ignore data restrictions</label><?= $rbPermSel('unscope_perms', $rbSt['unscope_perms'], $rbLocked('can_unscope')) ?></div>
            </div>
            <small class="text-muted">Ctrl/Cmd-click to pick several.</small>

            <div class="mt-3 d-flex gap-2">
              <button class="btn btn-success" type="submit">Save settings</button>
            </div>
          </form>
          <form method="post" class="mt-2"><?= tokenHere(); ?><input type="hidden" name="action" value="test_email">
            <button class="btn btn-outline-secondary" type="submit">Send test email to <?= $h($user->data()->email) ?></button></form>
          <p class="text-muted mt-3 mb-0"><small>Scheduled sends need this cron line (runs hourly; each report sends only in its own hour):<br>
            <code>0 * * * * <?= $h(PHP_BINDIR . '/php') ?> <?= $h(__DIR__ . '/cron/run.php') ?> &gt;&gt; <?= $h(dirname(__DIR__, 2) . '/logs/report_builder.log') ?> 2&gt;&amp;1</code></small></p>
        </div>
      </details>

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
