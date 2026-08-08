<?php
/**
 * Warehouses — Container Tracking System
 * Supervisor-only page for registering UserSpice tags as warehouses.
 * Run 05_warehouses_migration.sql once before using this page.
 *
 * How this feature works end-to-end:
 *   1. Create a tag in UserSpice for each physical warehouse (however
 *      your install manages tags — Admin > Users > tag a user, etc.).
 *   2. Register that tag here with a friendly warehouse name.
 *   3. Tag each user/supervisor with the warehouse(s) they work.
 *   4. Assign a warehouse to each container (on the create/edit form —
 *      not wired up yet on this pass, see chat).
 *   5. Dashboards automatically only show containers in a user's
 *      tagged warehouse(s); review-ready emails only go to supervisors
 *      tagged for that container's warehouse.
 *   A user with no warehouse tags, or a container with no warehouse
 *   set, is unrestricted — this is opt-in restriction, not a lockout.
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

$errors  = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Token::check(Input::get('csrf'))) {
    $action = Input::get('action');

    if ($action === 'create') {
        $tag_id = (int) Input::get('tag_id');
        $name   = trim(Input::get('name'));

        if (!$tag_id) {
            $errors[] = 'Choose a tag to register as a warehouse.';
        } elseif (empty($name)) {
            $errors[] = 'Warehouse name is required.';
        } else {
            try {
                createWarehouse($tag_id, $name);
                $message = 'Warehouse added.';
            } catch (\Throwable $e) {
                $errors[] = 'Could not add warehouse — that tag may already be registered. (' . $e->getMessage() . ')';
            }
        }
    } elseif ($action === 'update') {
        $warehouse_id = (int) Input::get('warehouse_id');
        $name         = trim(Input::get('name'));
        $active       = Input::get('active') ? 1 : 0;

        if ($warehouse_id && $name) {
            updateWarehouse($warehouse_id, ['name' => $name, 'active' => $active]);
            $message = 'Warehouse updated.';
        }
    } elseif ($action === 'delete') {
        $warehouse_id = (int) Input::get('warehouse_id');
        if ($warehouse_id) {
            deleteWarehouse($warehouse_id);
            $message = 'Warehouse removed. Containers that were assigned to it are now unassigned (visible to everyone).';
        }
    }
}

$warehouses = getWarehouses(false); // include inactive, for management
$all_tags   = getAllUserSpiceTags();
$registered_tag_ids = array_map(fn($w) => (int) $w->tag_id, $warehouses);
$csrf = Token::generate();
?>
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="page-header">Warehouses</h1>
                <p class="text-muted">
                    Each warehouse maps to a UserSpice tag. Tag a user with that tag to scope their
                    dashboard and review-notification emails to that warehouse. Users with no
                    warehouse tags, and containers with no warehouse assigned, are visible to everyone.
                </p>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul style="margin:0;">
                <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-5">
                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title">Add a warehouse</h3></div>
                    <div class="panel-body">
                        <?php
                        $available_tags = array_filter($all_tags, fn($t) => !in_array((int) $t->id, $registered_tag_ids, true));
                        ?>
                        <?php if (empty($all_tags)): ?>
                        <p class="text-muted">
                            No UserSpice tags found. Create a tag first (Admin &gt; Users &gt; tag a user, or
                            wherever your install manages tags), then come back here to register it.
                        </p>
                        <?php elseif (empty($available_tags)): ?>
                        <p class="text-muted">Every existing tag is already registered as a warehouse below.</p>
                        <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="create">

                            <div class="form-group">
                                <label for="tag_id">UserSpice tag</label>
                                <select class="form-control" id="tag_id" name="tag_id" required>
                                    <option value="">-- Select a tag --</option>
                                    <?php foreach ($available_tags as $t): ?>
                                    <option value="<?php echo (int) $t->id; ?>"><?php echo htmlspecialchars($t->tag); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="name">Warehouse name</label>
                                <input type="text" class="form-control" id="name" name="name" required
                                       placeholder="e.g. Seattle">
                            </div>

                            <button type="submit" class="btn btn-success">
                                <i class="fa fa-plus"></i> Add Warehouse
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title">Registered warehouses</h3></div>
                    <div class="panel-body">
                        <?php if (empty($warehouses)): ?>
                        <p class="text-muted">No warehouses registered yet.</p>
                        <?php else: ?>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Tag</th>
                                    <th>Tagged users</th>
                                    <th>Active</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($warehouses as $w):
                                    $tag_name = '(deleted tag)';
                                    foreach ($all_tags as $t) { if ((int)$t->id === (int)$w->tag_id) { $tag_name = $t->tag; break; } }
                                    $tagged_count = 0;
                                    if (function_exists('usersWithTag')) {
                                        $tagged_count = count((array) usersWithTag((int) $w->tag_id));
                                    }
                                ?>
                                <tr>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="warehouse_id" value="<?php echo (int) $w->id; ?>">
                                        <td style="min-width:160px;">
                                            <input type="text" class="form-control input-sm" name="name"
                                                   value="<?php echo htmlspecialchars($w->name); ?>">
                                        </td>
                                        <td><code><?php echo htmlspecialchars($tag_name); ?></code></td>
                                        <td><?php echo $tagged_count; ?> user<?php echo $tagged_count === 1 ? '' : 's'; ?></td>
                                        <td>
                                            <input type="checkbox" name="active" value="1" <?php echo $w->active ? 'checked' : ''; ?>>
                                        </td>
                                        <td style="white-space:nowrap;">
                                            <button type="submit" class="btn btn-xs btn-default">Save</button>
                                    </form>
                                            <form method="post" style="display:inline;"
                                                  onsubmit="return confirm('Remove this warehouse? Containers assigned to it become unassigned (visible to everyone), and it will no longer restrict any users.');">
                                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="warehouse_id" value="<?php echo (int) $w->id; ?>">
                                                <button type="submit" class="btn btn-xs btn-danger">Remove</button>
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
