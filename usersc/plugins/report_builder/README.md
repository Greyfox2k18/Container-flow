# Report Builder (UserSpice plugin)

Build email / web / CSV reports out of blocks — header, summary tiles, tables, grouped tables, text, buttons — from data you register once per project. Scheduled sending, recipients with notes, a drag-and-drop editor with live preview.

Tested on UserSpice 6.1.2, PHP 8.x, MySQL 8.

## Install on a new project

1. Copy this folder to `usersc/plugins/report_builder/`.
2. Admin → Plugins → install and activate **Report Builder**. Run its migrations; they create `plg_rb_reports`, `plg_rb_recipients`, `plg_rb_run_log`.
3. Create the four small project files below.
4. Add the cron line (see **Scheduling**).

That's it — the plugin ships two datasets that work on any UserSpice site (**Users** and **User activity log**, admins only), so you can build a report straight away.

### 1. `usersc/report_builder_config.php` — project hooks

```php
<?php
if (count(get_included_files()) == 1) die();
return [
    'mailer' => function (array $to, $subject, $html, array $attachments) {
        // $attachments: [['name' => 'x.csv', 'content' => raw bytes, 'type' => 'text/csv'], ...]
        foreach ($to as $addr) if (!email($addr, $subject, $html)) return ['success' => false, 'message' => "send to $addr failed"];
        return ['success' => true, 'message' => ''];
    },
    'base_url'   => 'https://example.com',        // or a function returning it; used for button links
    'brand'      => 'My Project',                  // {brand} token, header eyebrow
    'primary_color' => '#1e3a5f',
    'editor_url' => 'usersc/reports.php',          // the page in step 3 (for the admin "Open editor" button)
    'can_build'  => function ($uid) { return hasPerm([2], $uid); },   // open editor, save, test-send
    'can_send'   => function ($uid) { return hasPerm([2], $uid); },   // "send now" to real recipients
    'can_unscope'=> function ($uid) { return hasPerm([2], $uid); },   // reports that ignore dataset scope
    // 'builtin_datasets' => false,               // hide the built-in Users / User activity datasets
];
```

Without a config file only master accounts can use the builder, and mail goes through UserSpice's `email()` (no attachments).

### 2. `usersc/report_datasets/*.php` — your data (one file per dataset)

The builder can only query what you register here; users never write SQL.

```php
<?php
if (count(get_included_files()) == 1) die();
rb_register_dataset('orders', [
    'label' => 'Orders',
    'table' => 'orders', 'alias' => 'o',
    'joins' => ['c' => ['table' => 'customers', 'on' => 'c.id = o.customer_id']],   // LEFT by default
    'fields' => [
        'number'   => ['label' => 'Order #', 'type' => 'text', 'display' => 'mono'],
        'status'   => ['label' => 'Status', 'type' => 'enum', 'options' => ['open' => 'Open', 'shipped' => 'Shipped']],
        'customer' => ['label' => 'Customer', 'type' => 'text', 'expr' => 'c.name', 'join' => 'c'],
        'total'    => ['label' => 'Total', 'type' => 'number'],             // number = can be summed/averaged
        'placed_at'=> ['label' => 'Placed', 'type' => 'datetime'],
    ],
    'default_date_field' => 'placed_at',
    'default_fields'     => ['number', 'customer', 'status', 'total'],
    // Optional — who may use this dataset at all:
    'access' => function ($uid) { return hasPerm([2], $uid); },
    // Optional — row-level restriction for the person/report running it:
    'scope'  => function (array $ctx) {
        // $ctx['user_id'] — return null (no restriction), false (no rows), or
        return ['where' => 'o.region_id IN (SELECT region_id FROM user_regions WHERE user_id = ?)', 'params' => [$ctx['user_id']]];
    },
]);
```

Field types: `text`, `number`, `date`, `datetime`, `enum`, `bool`. Per field you can also set `filterable`, `groupable`, `sortable`, `aggregatable` (sensible defaults by type) and `options` for enums (array, or a function returning `[value => label]`). Table/column names and `expr`/`on` are trusted SQL written by you; everything a report author enters is validated against these keys and sent as bound parameters.

Rules for dataset files: only call `rb_register_dataset()` (wrap any helper function in `function_exists`). A project dataset with the same key as a built-in one replaces it. Admin → Plugins → Report Builder → **Check datasets against the database** runs every field once to catch typos.

### 3. The editor page, e.g. `usersc/reports.php`

```php
<?php
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
if (!securePage($_SERVER['PHP_SELF'])) die();
$rbApiUrl  = $us_url_root . 'usersc/ajax/report_builder_api.php';
$rbPageUrl = $us_url_root . 'usersc/reports.php';
include $abs_us_root . $us_url_root . 'usersc/plugins/report_builder/assets/includes/rb_editor_page.php';
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
```

### 4. The AJAX endpoint, `usersc/ajax/report_builder_api.php`

```php
<?php
require_once '../../users/init.php';
header('Content-Type: application/json');
if (!$user->isLoggedIn() || !Token::check(Input::get('csrf'))) { echo json_encode(['ok' => false, 'error' => 'Session expired — reload.']); exit; }
$payload = json_decode(is_string($_POST['payload'] ?? null) ? $_POST['payload'] : '{}', true);   // raw: Input::get escapes
echo json_encode(RbApi::handle(Input::get('action'), is_array($payload) ? $payload : [], $user->data()->id));
```

## Scheduling

```
0 * * * * /usr/bin/php /path/to/site/usersc/plugins/report_builder/cron/run.php >> /path/to/site/usersc/logs/report_builder.log 2>&1
```

Runs hourly; each report sends only in its own hour, once per day/week/month. Times are server time. Diagnostics:

- `php cron/run.php --list` — server time, every report's schedule, "due right now?", resolved recipients, recent runs
- `php cron/run.php --test=ID --to=you@example.com` — send one copy, print the mail service's reply

## Presets

JSON files in `usersc/report_presets/` (project) and `assets/presets/` (plugin) appear under **Create from preset**. A preset is a name, description, schedule, scope mode, recipients and a layout — the editor's Advanced tab shows the layout JSON of any report, so the easy way to make a preset is to build the report, copy its JSON, and wrap it.

## Recipients and data access

Recipients can be any email address, a user, or everyone holding a permission level (resolved at send time), each with a note. A report runs with its **creator's** dataset scope by default; **each recipient** gives every user recipient their own scoped copy; **no restriction** needs `can_unscope`. Builders only ever preview within their own scope, and can't open, copy or send reports that use datasets they can't access.

## Files

| Path | What |
|---|---|
| `assets/includes/rb_registry.php` | Dataset registry + config validation |
| `assets/includes/rb_query.php` | Safe query builder (filters, date windows, grouping, aggregates) |
| `assets/includes/rb_render.php` | Blocks → email HTML + CSV; block reference in the header comment |
| `assets/includes/rb_reports.php` | Storage, recipients, schedule, sending, config, permissions |
| `assets/includes/rb_api.php` | Editor API |
| `assets/includes/rb_editor_page.php`, `assets/js/`, `assets/css/` | Editor UI (SortableJS bundled, MIT) |
| `assets/datasets/`, `assets/presets/` | Built-in UserSpice datasets and presets |
| `cron/run.php` | Scheduled sends + diagnostics (CLI only) |
| `tests/` | `php tests/run_tests.php` (SQLite, no MySQL needed); `tests/e2e/run_e2e.sh` (Playwright). Dev machines only |
