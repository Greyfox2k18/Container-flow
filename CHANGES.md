# Container Flow — Changes from Original Files
# This file documents every behavioural change from the base UserSpice install.
# When rebuilding any file from scratch, verify ALL items for that file are applied.

---

## container_create.php
- **Redirect after create** → `container_view.php?id=X` (NOT container_edit.php)
- Points award on create: `alterPoints($username, getContainerSetting('points_create_container',5), 'give', ...)`
- Carrier datalist: `<datalist id="carrier-suggestions">` from `getCarrierList()`

## container_edit.php
- Carrier datalist: same as container_create.php
- `getCarrierList()` called at top of file

## container_view.php
- Photo type dropdown uses `getPhotoTypes($container->customer_id, $container->type)` — NOT global `$photo_types`
- Reward modal HTML before `</body>` (NOT after `html_footer.php`)
- Points toast `<div id="pts-toast">` before reward modal
- Points badge showing `$user_pts` (from `$user->data()->plg_points`) near Next Status button
- On-load reward check via PHP: `if ($reward_on_load) showRewardModal(...)`
- Claim Prize link when `$reward_on_load` is true
- Upload AJAX response: shows toast via `showPtsToast`, triggers reward modal
- Next Status button: confirm dialog for in_progress→completed, photo gate, reward toast
- Off-canvas CSS uses `transform: translateX(100%)` for hidden state (NOT `right: -340px`)
- Review modal shares `.actions-offcanvas` class — same transform fix prevents bleeding

## container_dashboard.php (simple/mobile)
- **Mobile cards** → compact `.mob-tile` `<a>` links (whole tile taps to view page)
- Stats row collapsed by default — tap `#mobStatsToggle` to expand
- No "Container Tracking System" heading (removed)
- No "Containers" heading in mobile section (removed)
- Reward modal HTML before `<script>` tag (NOT after)
- Points badge shows `$user->data()->plg_points` (NOT `->points`)
- `!$is_supervisor` condition REMOVED from points badge visibility
- filterMobileCards() uses `.mob-tile` class (NOT `.mobile-container-card`)
- Show Reviewed checkbox filters `.mob-tile[data-status=reviewed]`
- Auto-refresh: smart reload timer (backs off when user is typing)

## container_dashboard_pro.php
- `showReviewed: false` in state default — reviewed containers hidden on load
- Show Reviewed checkbox in filter bar re-populates dropdowns via `populateFilterDropdowns()`
- `populateFilterDropdowns()` skips reviewed containers when `state.showReviewed === false`
- Filter bar has: date range, Client, Carrier, Created By selects, Show Reviewed checkbox
- Selection bar: "→ Next Status" button + Delete + Clear
- `doBulkNextStatus()` + bulk review overlay (`#cdBulkReviewOverlay`) both present
- Reward modal HTML placed BEFORE `<script>` tag (inside page body, not after footer)
- Points badge uses `$user->data()->plg_points` (NOT `->points`)
- `!$is_supervisor` condition REMOVED from points badge visibility
- Auto-refresh: `cfAutoRefresh()` polls `ajax/get_containers_json.php` every 60s
- `cfUpdateLastRefreshed()` updates `#cdLastRefreshed` span after each poll
- Sidebar 'Reviewed' nav auto-checks Show Reviewed checkbox

## container_portal.php
- Login required via `securePage()` — NOT token-based public page
- Checks `isClient()` permission
- Gets customer via `getClientCustomer($user_id)` — mapping table lookup
- Shows all containers for that customer with photo lightbox

## ajax/upload_photos.php
- No `isFloorWorker()` check — only `$user->isLoggedIn()`
- Uses `getPhotoTypes($container->customer_id, $container->type)` for type validation
- `upload_respond()` function for dual AJAX/form-post handling
- Points award: `alterPoints` + `checkPointsReward` + returns `points_earned` and `reward_info`
- Auto-advances pending→in_progress on first photo upload
- Detailed PHP error messages for upload failures (permission issues, etc.)

## ajax/delete_photo.php
- No `isFloorWorker()` check
- Deducts points from original uploader: `alterPoints($uploader->username, $pts, 'take', ...)`
- Uses `getContainerSetting('points_delete_photo', 10)` not hardcoded value

## ajax/container_next_status.php
- No `isFloorWorker()` check
- Photo gate: returns `photo_warning: true` if no photos when advancing to completed
- Calls `sendReadyForReviewNotification()` when status → completed
- Awards points via `alterPoints` + `checkPointsReward` when → completed
- Returns `notify_sent`, `notify_reason`, `points_earned`, `reward_earned`, `reward_info`

## ajax/container_bulk_next_status.php
- No `isFloorWorker()` check
- Calls `sendReadyForReviewNotification()` when any container → completed
- Awards points via `alterPoints` when → completed

## includes/container_functions.php
- `CONTAINER_SITE_URL` constant defined
- `getContainerSetting()` / `setContainerSetting()` functions
- `checkPointsReward($user_id, $pts)` — checks `plg_points` column (NOT `points`)
- `isFloorWorker()` → `$user->isLoggedIn()` for current user
- `isSupervisor()` → uses `getContainerSetting("supervisor_permission_id", 3)` then `fetchPermissionUsers()` — configurable via Settings page
- `getSupervisorEmails()` → `fetchPermissionUsers(3)` (perm 3 = supervisor)
- `getAllContainers()` → correlated subquery for photo_count (NOT GROUP BY — breaks MySQL strict mode)
- `getPhotoTypes($customer_id, $container_type)` — per-client photo types with default fallback
- `getCarrierList()` — 26 predefined carriers merged with DB-used carriers
- `generatePortalToken()` / `verifyPortalToken()` / `getPortalUrl()` — HMAC portal tokens
- `isClient()` — checks `getContainerSetting('client_permission_id')`
- `getClientCustomer()` — looks up `container_client_users` table
- `sendReadyForReviewNotification()` — emails supervisors when floor worker marks complete
- `sendCompletionNotification()` — emails clients with photos; uses `resizeImageForEmail()` first
- `resizeImageForEmail()` — GD-based resize to 1600px max, JPEG 80% for email attachments

## cron/daily_digest.php
- Always sends — NO skip condition for "no open containers"
- Subject varies: Awaiting Review / Open Containers / All Clear
- Uses `getSupervisorEmails()` → `fetchPermissionUsers(3)`

---

## Database tables added (see SETUP.sql)
- `containers` — main table
- `container_photos` — photo records
- `container_activity_log` — audit trail
- `customers` — client records
- `container_settings` — key-value config
- `container_client_users` — maps UserSpice users to customers for portal
- `customer_photo_requirements` — per-client photo type config

## Key config values (container_settings table)
- `sparkpost_api_key` — SparkPost API key
- `sparkpost_from_email` / `sparkpost_from_name`
- `site_url` — https://container-flow.com
- `points_create_container` (5) / `points_upload_photo` (10) / `points_delete_photo` (10) / `points_complete_container` (20)
- `points_reward_threshold` (1000) / `points_review_url`
- `client_permission_id` — UserSpice permission ID for client portal access
- `portal_secret` — HMAC secret for token-based portal links

## UserSpice specifics
- Supervisor permission ID = **3** (via `fetchPermissionUsers(3)`)
- Points column = **`plg_points`** (NOT `points`)
- `hasPerm([10])` works for page-level supervisor checks
- `$user->isLoggedIn()` used for floor worker access (no specific perm needed)



---

## Email templates (usersc/includes/email_templates.php, usersc/email_template_edit.php)
- New `email_templates` table — editable HTML for the inbound/outbound completion-notification emails, `{{variable}}` tokens
- `sendCompletionNotification()` in `container_functions.php` now calls `getEmailTemplate()` + `renderEmailTemplate()` instead of building HTML inline
- Fixed a pre-existing bug: `$portal_url` was used but never assigned in `sendCompletionNotification()`
- `email_template_edit.php` reads `subject`/`html_body` from raw `$_POST`, not `Input::get()` — this framework's `Input::get()` auto-`htmlspecialchars()`s values, which double-encodes HTML on save
- `getEmailTemplate()` self-heals a previously double-encoded row on read (detects `&lt;` with no literal `<`)

## Multi-warehouse (usersc/warehouses.php, usersc/includes/warehouses_migration.sql)
- New `warehouses` table — links a friendly name to an existing UserSpice tag (`plg_tags`, NOT the stock `tags` table this install doesn't use)
- New `containers.warehouse_id` column, nullable — unassigned containers stay visible to everyone
- `getAllContainers()` now filters by the logged-in user's warehouse tags via `filterContainersByWarehouseAccess()` — untagged users are unrestricted (opt-in restriction, not opt-in access)
- `getWarehousesForUser($user_id)` — scoped warehouse list (own tags, or all if untagged) — used for filter dropdowns and the create/edit warehouse picker so nobody sees warehouses they don't belong to
- `getSupervisorEmails($warehouse_id = null)` — optional warehouse filter, falls back to ALL supervisors if nobody's tagged for that warehouse yet (never sends to nobody)
- `sendReadyForReviewNotification()` passes the container's `warehouse_id` through
- `container_dashboard.php` / `container_dashboard_pro.php` — Warehouse column, filter dropdown, Pro dashboard edit-modal field
- `container_create.php` — Warehouse dropdown, auto-selects if the creator only has one warehouse tag

## Automated reports (usersc/reports_builder.php, usersc/includes/reports_functions.php)
- New tables: `report_definitions`, `report_recipients`, `report_run_log`
- Delivery modes: `scheduled` (cron, `usersc/cron/reports_cron.php`), `event` (status-change triggered), or `both`
- Event trigger is centralized inside `updateContainer()` — any status change from ANY caller fires `sendEventTriggeredReports()` automatically, no other files needed changes
- Content filters: type, status, client, warehouse, carrier; format: HTML table and/or CSV attachment
- `isScheduledReportDue()` checks the hour (not exact minute) so the cron can run as often as every 15 min without double-sending

## Missing-photos alerts (usersc/ajax/container_notify_missing.php)
- Built on the UserSpice **messaging** plugin (`usplugins/src/messaging`, `sendPlgMessage()`) — sent as `msg_type` 1 (alert). NOT the separate `messages` plugin (`messageUser()`) — that one is full user-to-user inbox threading, wrong fit for a one-way ping
- `getMissingPhotoTypes($container)` — diffs `getPhotoTypes()` against uploaded photos
- `getFloorWorkers()` — deliberately does NOT use `fetchPermissionUsers()` for perm IDs 10/11 (unreliable for this, per earlier note above on floor-worker detection) — filters all active users through `isFloorWorker()` instead
- Panel lives in the Pro dashboard's edit modal only; `container_dashboard.php` doesn't have one (no `container_view.php`-style actions surface available at time of writing)

## Unique identifier per client (usersc/customer_identifier_settings.php, usersc/includes/identifier_migration.sql)
- Dropped the hard `UNIQUE KEY container_number` on `containers` — it permanently blocked reusing a number even after that container was long done, which broke clients whose freight rides on reused trailers
- Replaced with app-level `findDuplicateIdentifier($field, $value, $exclude_id)` — scoped to currently-open containers only (`status != 'reviewed'`)
- New `customers.use_shipment_number_as_id` flag — flagged clients get checked on `shipment_number` instead of `container_number`, via `getIdentifierField($customer_id)`
- Checked in both `container_create.php` and `ajax/container_update.php`

## Report Builder plugin (usersc/plugins/report_builder/, usersc/report_datasets/)
Replaces the fixed-format reports with saved reports built from blocks. Stages 1–5 are in.
- **Stage 1 — datasets + safe query builder** (no DB changes; existing reports untouched). Verified on live: "Check datasets" OK
  - UserSpice plugin layout (info.xml, install/activate/uninstall/delete/migrate, configure.php) — UserSpice 6.1.2; stage 1 installed on live Oct 7, 2026
  - `.gitignore` now ignores `usersc/plugins/*` EXCEPT `report_builder/` so the plugin is tracked
  - Datasets register from `usersc/report_datasets/*.php` (outside the plugin folder so plugin updates never overwrite them) via `rb_register_dataset()`; loaded lazily, one bad file is reported on the configure page instead of breaking the rest
  - `usersc/report_datasets/containers.php` — containers + customers/warehouses/users joins; scope = `getUserWarehouseIds()` rule (tagged users see own warehouses + unassigned, untagged unrestricted — same as `filterContainersByWarehouseAccess()`)
  - `RbQuery::build()/run()` — fields, filters, date window, group by (with day/week/month/year buckets), count/count_distinct/sum/avg/min/max, sort, limit. Only registered field keys and fixed keywords reach SQL; every user value is a bound parameter
  - Date windows reproduce `getReportDateRangeBounds()` (plus this_week/last_week/this_year/custom)
  - `delete.php` deliberately does NOT drop `report_*` tables (live data predates the plugin)
  - Tests: `php usersc/plugins/report_builder/tests/run_tests.php` (CLI only, in-memory SQLite, folder denied by .htaccess)
  - Configure page "Check datasets against the database" runs each dataset with all fields (5 rows) to prove the config matches the live schema
- **Stage 2 — blocks, storage, delivery, daily digest preset**
  - Plugin owns its tables (migrate.php `00001`): `plg_rb_reports` (layout_json, schedule, scope_mode, attach_csv, last_sent_at), `plg_rb_recipients` (kind email/user/permission + note), `plg_rb_run_log`. The old `report_definitions`/`report_recipients`/`report_run_log` tables were never used (no reports) and are left alone
  - `RbRender` — one layout → email-safe HTML (also the web preview) + a CSV per table. Blocks: header, summary_tiles, table, grouped_table, text (normal/alert/info/success/muted/footer), buttons. Named metrics feed tiles, `show_if` conditions, `{tokens}` and subject rules. Report-level filters (e.g. client multi-select) apply to every block on that dataset. All layout text escaped; colours hex-only; button URLs http(s) or site-relative; CSV cells starting = + - @ are prefixed with '
  - `RbReports` — recipients (plain emails OK, `user:ID`, `perm:ID` resolved at send time; de-duped; notes), schedule (`isDue()` = same hour-based rules as `isScheduledReportDue()`), send now / test-to-me / scheduled, run log
  - Scope modes: `creator` (default), `recipient` (each user recipient gets their own warehouses — fixes the "digest shows all warehouses" outstanding item when chosen), `none`
  - Mailer/base URL/brand come from `usersc/report_builder_config.php` (Container Flow: `sendSparkPostEmail()`, `site_url` setting). Other projects supply their own; without one it falls back to UserSpice `email()`
  - Cron: `usersc/plugins/report_builder/cron/run.php` (CLI only), hourly
  - `usersc/report_presets/daily_digest.json` — the daily digest as blocks. Test runs the ORIGINAL `cron/daily_digest.php` unmodified side by side and requires identical subject, visible text, links and recipients across 4 scenarios
  - Configure page: create from preset, preview (sandboxed iframe), test to me, send now, activate/pause, edit schedule/recipients/scope/layout JSON (layout is test-rendered before save), run log. Text fields read from raw `$_POST` (Input::get escaping would double-encode)
  - `containers` dataset: added `assigned_to_name`, `drive_backed_up_at`; container # shows monospace; loads `container_functions.php` itself so the warehouse scope can't silently fail open
- **Stage 3 — editor UI** (`usersc/reports_builder.php` now hosts it)
  - `usersc/reports_builder.php` replaced: was the old fixed-form builder (never used — no reports existed); now a thin page that includes the plugin's editor (`assets/includes/rb_editor_page.php`). Same URL, same UserSpice page permission
  - `usersc/ajax/report_builder_api.php` — session/CSRF/JSON wrapper around `RbApi::handle()` (plugin). Reads `payload` from raw `$_POST` (Input::get would escape the JSON)
  - Editor (`assets/js/rb_editor.js`, `assets/css/rb_editor.css`, no build step): report list (create from preset, copy, test-to-me, send now, activate, delete); block palette; drag to reorder (SortableJS 1.15.2, bundled locally in `assets/js/`, MIT) plus ▲▼ buttons; per-block settings (columns + headings, filters by field type, date range, sort, totals/group-by with day/week/month/year, show-only-if); Metrics & filters tab (metrics, report-level filters e.g. client multi-select); Delivery tab (schedule, recipients by email/user/permission group with notes, data access, CSV, recent runs); Advanced tab (subject rules, layout JSON import/export). Live preview re-renders on the server ~0.7s after each change, shown in a sandboxed iframe
  - Permissions (`usersc/report_builder_config.php`): `can_build` / `can_send` = supervisors; `can_unscope` = supervisors with no warehouse tags. Master accounts can always do everything. A warehouse-restricted builder can't save or activate an "everything" report, and their previews are always limited to their own warehouses
  - Tests: `tests/test_stage3.php` (API: permissions, round trip, validation, scope rules); `tests/e2e/run_e2e.sh` — headless Chromium + Playwright against `php -S` with a fake UserSpice (dev machines only; tests/ is denied by .htaccess)
- **Stage 4 — reuse proof + packaging**
  - Built-in datasets shipped in the plugin (`assets/datasets/`), work on any UserSpice site: `users` (name, username, email, active, login count, last login, joined) and `user_logs` (UserSpice `logs` table + user name). Admins only — master accounts or permission 2 — via the new per-dataset `access` callback
  - Registry: built-in folders load after project folders; a project dataset with the same key replaces a built-in one; `'builtin_datasets' => false` in the config hides them. Dataset files now load with `include` (not `_once`) so they must not declare unguarded functions
  - Access enforced everywhere in the editor API: hidden from the dataset list, and preview/save refuse layouts using them (blocks, metrics or report filters). Reports that use them are hidden from other builders' list and can't be opened, copied, sent, paused or deleted by them
  - Preset `assets/presets/user_activity.json` — weekly (Mon 7 AM) to Administrators (perm 2): active users, signed in this week, login events, who signed in, activity by day and by type, CSVs attached. Project presets list first; same name overrides
  - Config `base_url` callable is now resolved only when a report renders (it was resolved whenever the config loaded — would have been a DB query per page once functions.php started reading config)
  - `README.md` — how to use the plugin on another project (config, datasets, page + AJAX wrappers, cron, presets); new logo; info.xml 0.4.0
- **Stage 5 — built-in email settings + charts**
  - Plugin settings page (Admin → Plugins → Report Builder → Settings; table `plg_rb_settings`, migration `00002`): email provider (SparkPost US/EU, Postmark, or UserSpice email), API key/token (stored, never shown back — only the last 4 characters), from/reply-to, site address, brand, header colour, permission levels for build / send / unrestricted reports, **Send test email**, and the cron line. A fresh UserSpice site needs only this + cron
  - Built-in senders `assets/includes/rb_mail.php` (ported from `usersc/includes/sparkpost_email.php`; Postmark one message per recipient like ours). Inline chart images go to SparkPost/Postmark as `cid:` attachments; other senders get `data:` images
  - `usersc/report_builder_config.php` still wins over the settings page. Container Flow now uses the built-in sender via `'mail'` → reads `email_provider`, `sparkpost_api_key`, `postmark_api_key`, `sparkpost_from_email/name` from `container_settings` (no re-entry). The old `'mailer'` closure around `sendSparkPostEmail()` is gone
  - **Fix:** the config's `base_url` callable could run before `container_functions.php` was loaded (cron) and return '' → button links in emails came out relative. It now loads the file itself
  - Settings form only changes fields that were posted (greyed-out config-file fields aren't posted and must not wipe saved values); multi-selects use a `*_present` marker
  - Built-in editor page `usersc/plugins/report_builder/reports.php` + endpoint `api.php` (login + CSRF; RbApi does permissions). Default `editor_url`. Container Flow keeps `usersc/reports_builder.php`
  - **Chart block** (`assets/includes/rb_chart.php`): column + line as GD PNGs (drawn 4×, saved 2×, DejaVu Sans bundled with its licence), bar as plain HTML table bars (works in Outlook). X axis + optional split-by (≤ 6 series, rest folded into Other for counts/sums) + one value; dates sorted and gaps filled across the whole date range; whole-number ticks for counts; HTML legend for ≥ 2 series; data table under the chart by default; alt text; CSV. Colours validated for colour-blind separation on white. Editor: chart type, X axis (+ day/week/month/year), value, split by, filters, date range, sort, height, table/CSV options
  - Preset `usersc/report_presets/in_progress.json` ("in_progress_list" from the spec): daily 7 AM to supervisors, **each sees only their warehouses** (scope = recipient); tiles, bar chart by client, grouped list with Assigned, 30-day column chart inbound vs outbound
  - Tests: 75 PHP (`run_tests.php`, incl. sender payloads via a stubbed transport, settings precedence, charts), `smoke_configure.php` (admin page), e2e incl. charts
- **Switching the daily digest over** (when happy with the preview/test): activate the "Daily Digest" report, add the plugin cron line, remove the `cron/daily_digest.php` cron line. `ajax/trigger_digest.php` (settings page button) still uses the old code until then
- **Finding:** the spec assumed `report_customers`, recipient `note`, `report_kind`, `layout_json` and client-digest functions already existed — they did not, and no reports had been made in the old builder, so nothing needed migrating. Live schema has no `completed_at`/`reviewed_at`; "completed" dates use `updated_at` like the digest

## Still outstanding
- `container_edit.php` was never uploaded this session — warehouse picker, identifier validation, and missing-photos alert are NOT wired into it if it's a separate page from the Pro dashboard modal
- Missing-photos alert action was requested for `container_view.php`'s existing Actions button — not yet done, file not uploaded
- Daily digest (`cron/daily_digest.php`) and the reports page still show all warehouses to all supervisors — not scoped to warehouse tags
