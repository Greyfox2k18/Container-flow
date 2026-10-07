# Report Builder (UserSpice plugin)

Build email / web / CSV reports out of blocks — header, summary tiles, tables, grouped tables, charts, text, buttons — from data you register once per project. Scheduled sending, recipients with notes, a drag-and-drop editor with live preview.

Tested on UserSpice 6.1.2, PHP 8.x, MySQL 8. Needs the PHP GD extension for chart images (bar charts work without it) and cURL for SparkPost/Postmark.

## Quick start (works out of the box)

1. Copy this folder to `usersc/plugins/report_builder/`.
2. Admin → Plugins → install and activate **Report Builder** (its migrations create `plg_rb_reports`, `plg_rb_recipients`, `plg_rb_run_log`, `plg_rb_settings`).
3. Admin → Plugins → **Report Builder** → **Settings**:
   - **Email** — SparkPost (API key, US/EU), Postmark (server token), or UserSpice's own email settings; from address/name. **Send test email** checks it.
   - **Site address** — used for links in emails (cron has no web request to guess it from).
   - **Who can use it** — permission levels that can build, send, and make unrestricted reports. Master accounts always can. Default: Administrators (#2).
4. Add the cron line shown at the bottom of the Settings panel (see **Scheduling**).
5. Open the editor: **`/usersc/plugins/report_builder/reports.php`** (also linked from the plugin page).

The plugin ships two datasets that work on any UserSpice site — **Users** and **User activity log** (admins only) — and a **User Activity** preset, so you can build and send a report straight away. Add your own data with step 2 below.

## Making it part of your project (optional)

### 1. `usersc/report_builder_config.php` — project overrides

Anything set here wins over the Settings page (those fields show greyed out). Every key is optional.

```php
<?php
if (count(get_included_files()) == 1) die();
return [
    // Mail: reuse settings your project already stores…
    'mail' => function () {
        return ['provider' => 'sparkpost', 'sparkpost_api_key' => mySetting('sparkpost_key'),
                'from_email' => 'reports@example.com', 'from_name' => 'My Project'];
    },
    // …or a completely custom sender (receives [['name','content','type','inline','cid']] attachments):
    // 'mailer' => function (array $to, $subject, $html, array $attachments) { return ['success' => true, 'message' => '']; },
    // 'mailer_inline' => true,                   // only if your sender handles cid: inline images
    'base_url'   => 'https://example.com',        // or a function returning it
    'brand'      => 'My Project',                 // {brand} token, header eyebrow
    'primary_color' => '#1e3a5f',
    'editor_url' => 'usersc/reports.php',          // if you host the editor on your own page (step 3)
    'can_build'  => function ($uid) { return hasPerm([2], $uid); },   // open editor, save, test-send
    'can_send'   => function ($uid) { return hasPerm([2], $uid); },   // "send now" to real recipients
    'can_unscope'=> function ($uid) { return hasPerm([2], $uid); },   // reports that ignore dataset scope
    // 'builtin_datasets' => false,               // hide the built-in Users / User activity datasets
];
```

Senders: SparkPost and Postmark get chart images as inline (`cid:`) attachments — they show everywhere, including Gmail. With UserSpice's email or a custom sender (without `mailer_inline`) charts are embedded as `data:` images, which most desktop and phone mail apps show but Gmail on the web does not; CSV attachments need SparkPost or Postmark.

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

Rules for dataset files: only call `rb_register_dataset()` (wrap any helper function in `function_exists`). A project dataset with the same key as a built-in one replaces it. Plugin page → **Check datasets against the database** runs every field once to catch typos.

### 3. Your own editor page (optional)

The built-in page is `usersc/plugins/report_builder/reports.php`. To put the editor inside your own page (your menu, your page permissions):

```php
<?php
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
if (!securePage($_SERVER['PHP_SELF'])) die();
$rbApiUrl  = $us_url_root . 'usersc/plugins/report_builder/api.php';   // or your own wrapper around RbApi::handle()
$rbPageUrl = $us_url_root . 'usersc/reports.php';
include $abs_us_root . $us_url_root . 'usersc/plugins/report_builder/assets/includes/rb_editor_page.php';
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
```

## Blocks

| Block | What it shows |
|---|---|
| Header | Title band: small heading, title, subtitle |
| Summary tiles | Big numbers from **metrics** (named counts/sums/averages) |
| Table | A row per record, or totals grouped by a field (dates by day/week/month/year) |
| Grouped table | One section per value of a field (client, status…) |
| Chart | **Column** or **line** (PNG image) or **bar** (HTML, ranked). X axis + optional "split by" (≤ 6 series, the rest grouped as Other) + one value. Dates run left to right with missing days filled in. A data table under the chart is on by default |
| Text | Paragraph, alert, info, success, muted or footer |
| Buttons | Links back to your site |

Any block can be shown only when a metric condition holds ("only if something is awaiting review"). Text supports `{date}`, `{date_short}`, `{time}`, `{report_name}`, `{brand}`, `{metric}` and `{s:metric}` (plural s). Report filters apply to every block on a dataset (e.g. one report per client).

## Scheduling

```
0 * * * * /usr/bin/php /path/to/site/usersc/plugins/report_builder/cron/run.php >> /path/to/site/usersc/logs/report_builder.log 2>&1
```

Runs hourly; each report sends only in its own hour, once per day/week/month. Times are server time. Diagnostics:

- `php cron/run.php --list` — server time, every report's schedule, "due right now?", resolved recipients, recent runs
- `php cron/run.php --test=ID --to=you@example.com` — send one copy, print the mail service's reply

## Presets

JSON files in `usersc/report_presets/` (project, listed first) and `assets/presets/` (plugin) appear under **Create from preset**. A preset is a name, description, schedule, scope mode, recipients and a layout — the editor's Advanced tab shows the layout JSON of any report, so the easy way to make a preset is to build the report, copy its JSON, and wrap it.

## Recipients and data access

Recipients can be any email address, a user, or everyone holding a permission level (resolved at send time), each with a note. A report runs with its **creator's** dataset scope by default; **each recipient** gives every user recipient their own scoped copy; **no restriction** needs `can_unscope`. Builders only ever preview within their own scope, and can't open, copy or send reports that use datasets they can't access.

## Files

| Path | What |
|---|---|
| `reports.php`, `api.php` | Built-in editor page and its API endpoint |
| `configure.php` | Plugin page: settings, reports, datasets, raw JSON editor |
| `assets/includes/rb_registry.php` | Dataset registry + config validation |
| `assets/includes/rb_query.php` | Safe query builder (filters, date windows, grouping, aggregates) |
| `assets/includes/rb_render.php` | Blocks → email HTML + CSV; block reference in the header comment |
| `assets/includes/rb_chart.php` | Chart drawing (GD PNG for column/line, HTML for bar) |
| `assets/includes/rb_reports.php` | Storage, settings, recipients, schedule, sending, permissions |
| `assets/includes/rb_mail.php` | SparkPost / Postmark / UserSpice email senders |
| `assets/includes/rb_api.php` | Editor API |
| `assets/includes/rb_editor_page.php`, `assets/js/`, `assets/css/` | Editor UI (SortableJS bundled, MIT) |
| `assets/fonts/` | DejaVu Sans for chart text (Bitstream Vera licence, included) |
| `assets/datasets/`, `assets/presets/` | Built-in UserSpice datasets and presets |
| `cron/run.php` | Scheduled sends + diagnostics (CLI only) |
| `tests/` | `php tests/run_tests.php` (SQLite, no MySQL needed), `php tests/smoke_configure.php`, `tests/e2e/run_e2e.sh` (Playwright). Dev machines only |
