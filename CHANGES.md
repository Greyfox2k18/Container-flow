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

## Yard Board (usersc/yard_board.php, yard_history.php, yard_settings.php, includes/yard_functions.php)
- **Laid out exactly like the TODAY tab**: one row per door/yard spot with DR | CONTAINER | STATUS | DATE IN | MT DATE | LD DATE | DRIVER | ACCOUNT | LFD | Drayman | DC NOTES, and the incoming block (Container | Customer | STATUS | ETA | LOC) on the right. Sheet colours: grey header, green door labels, blue yard labels (every 3rd darker), account colour fills the row's cells, HOT = yellow container/driver cells, past LFD = red, HOT incoming STATUS = red, LOC = yellow
- Every cell is typed into directly (Enter / ↑ / ↓ move between rows, Esc reverts). Typing a number into an empty row creates it there; typing a number that's waiting on the incoming list places that container. Clearing a CONTAINER cell = picked up (with Undo); clearing an incoming Container cell removes it from the list
- Move by dragging the ⠿ grip onto another row (occupied row = swap, incoming block = back to Incoming). Touch screens: tap the grip, then tap the target row's DR cell
- Incoming list = `yard_units.on_list` (+ `list_note` for its STATUS column). A container stays listed after it arrives, LOC showing where it went, like the sheet's lookup formula
- **In/out tracking**: `yard_stints` holds one row per stay at a door or yard spot (in_at/out_at, in_by/out_by). Opened on place/move/swap/restore, closed on move/swap/pickup/delete. `yard_units.arrived_at` = gate in, `picked_up_at` = gate out. All timestamps come from PHP (`yardNow()`) so they share one clock
- Upgrading: the first page load creates `yard_stints` and opens a stay for everything already on the board, dated from DATE IN and flagged `in_estimated` ("time not recorded"); imports do the same
- Edits are logged per save with sheet column names (`DRIVER: (blank) → SISI`); a container # change is its own `renamed` event, and its stays follow the new number
- Board: ◷ on each row (or LOC in the incoming block) opens the history panel: gate in/out, time on site, every door/yard stay with in/out times and who, and every change. Hovering the DR cell shows when the container went into that spot; hovering DATE IN shows the gate-in time
- `yard_history.php?view=inout`: door (or all spot) in/out times with time there, CSV export; Picked Up view gains Gate in + time on site
- - HOT is derived from the text: "HOT" in DRIVER, DC NOTES or the incoming STATUS
- Dates typed without a year (10/9) pick the year nearest today
- Live replacement for the Kent T-Card Google Sheet: doors (DR01–DR14) and yard spots (F01–F47), polled every 5s via `ajax/yard_data.php` (returns `{unchanged:true}` when the board version hasn't moved, full payload every ~2 min so Container Flow status changes show too)
- All writes go through `ajax/yard_action.php` (save / move / pickup / restore / check / delete)
- Tables `yard_locations`, `yard_units`, `yard_events` + `customers.yard_color` are created on demand by `ensureYardTables()` — same self-migrating pattern as the SKU scan tool. DDL also in `13_yard_migration.sql`
- One container per spot is enforced by `UNIQUE KEY uk_location (location_id)`; swaps park the occupant at NULL first
- Edits carry `version` (= `updated_at`) and are rejected if someone else changed the card in between
- Auto-dates on live edits only: Date In when placed, MT Date on → Empty, LD Date on → Loaded — never overwrites a typed date, disabled during CSV import
- Yard check ticks set `checked_at` without touching `updated_at` (so they don't look like edits) and log a `checked` event so other boards refresh
- **Separate product from Container Flow photos, shared clients.** Yard containers are not linked to photo records and never need one; the only connection is by container number at read time (a corner marker on the CONTAINER cell when a photo record with that number exists, and a yard badge on `container_view.php`, which is read-only and never creates yard tables). If the photo tables don't exist, the board still works (`yardHasContainerFlow()`)
- ACCOUNT (and the incoming Customer column) is a dropdown of Container Flow clients (`yard_units.customer_id`, scoped by `getCustomersForUser()`); the client's name is copied to `account` for history, and the board shows the client's current name. Colours are `customers.yard_color`, set in Setup → Client colours. Accounts on an imported sheet that aren't clients stay as plain text and are listed in the import preview
- Delete: anyone for Incoming entries, supervisors only once a container has been on site (use Picked up instead)
- CSV import of the sheet's TODAY tab (preview first, never overwrites an occupied spot, idempotent)
- Site menu: separate Yard Board entry in `includes/container_navigation.php`. No cross-buttons between the two products' pages (`container_dashboard.php` is unchanged)

## Still outstanding
- `container_edit.php` was never uploaded this session — warehouse picker, identifier validation, and missing-photos alert are NOT wired into it if it's a separate page from the Pro dashboard modal
- Missing-photos alert action was requested for `container_view.php`'s existing Actions button — not yet done, file not uploaded
- Daily digest (`cron/daily_digest.php`) and the reports page still show all warehouses to all supervisors — not scoped to warehouse tags
