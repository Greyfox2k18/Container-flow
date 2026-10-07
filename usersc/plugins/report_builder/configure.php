<?php if (!in_array($user->data()->id, $master_account)) {
  Redirect::to($us_url_root . 'users/admin.php');
} //only allow master accounts to manage plugins!
?>

<?php
include "plugin_info.php";
pluginActive($plugin_name);

$rbCheckResults = null;
if (!empty($_POST)) {
  if (!Token::check(Input::get('csrf'))) {
    include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
  }
  if (Input::get('action') === 'check_datasets') {
    // Run each dataset once with every selectable field, 5 rows, as the
    // current user — proves the config matches the live schema.
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
  }
}

$rbDatasets   = RbRegistry::all();
$rbLoadErrors = RbRegistry::errors();
?>

<div class="content mt-3">
  <div class="row">
    <div class="col-12">
      <a href="<?= $us_url_root ?>users/admin.php?view=plugins">Return to the Plugin Manager</a>
      <h1>Report Builder</h1>
      <p>
        Reports are built from <strong>datasets</strong>. Each project registers its own datasets by
        dropping a PHP file into <code>usersc/report_datasets/</code> — see
        <code>usersc/report_datasets/containers.php</code> for an example and
        <code>usersc/plugins/report_builder/assets/includes/rb_registry.php</code> for every option.
        The builder can only query registered fields, and every value a user enters is sent to the
        database as a bound parameter.
      </p>

      <?php foreach ($rbLoadErrors as $file => $msg): ?>
        <div class="alert alert-danger"><strong><?= htmlspecialchars($file) ?> didn't load:</strong> <?= htmlspecialchars($msg) ?></div>
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
            <strong><?= htmlspecialchars($ds['label']) ?></strong>
            <code class="ms-2"><?= htmlspecialchars($key) ?></code>
            <?php if (!empty($ds['scope'])): ?><span class="badge bg-info ms-2">scoped per user</span><?php endif; ?>
            <?php if (isset($rbCheckResults[$key])): $r = $rbCheckResults[$key]; ?>
              <span class="badge <?= $r['ok'] ? 'bg-success' : 'bg-danger' ?> ms-2"><?= $r['ok'] ? 'OK' : 'FAILED' ?></span>
              <small class="ms-2"><?= htmlspecialchars($r['msg']) ?></small>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if ($ds['description']): ?><p><?= htmlspecialchars($ds['description']) ?></p><?php endif; ?>
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Filter</th><th>Group</th><th>Sum/Avg</th><th>Sort</th></tr></thead>
              <tbody>
              <?php foreach ($ds['fields'] as $f): ?>
                <tr>
                  <td><code><?= htmlspecialchars($f['key']) ?></code></td>
                  <td><?= htmlspecialchars($f['label']) ?></td>
                  <td><?= htmlspecialchars($f['type']) ?></td>
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
