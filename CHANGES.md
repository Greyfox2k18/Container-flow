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
