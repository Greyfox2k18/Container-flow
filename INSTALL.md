# Container Flow — Installation Guide

## What this is
Container photo tracking system built on UserSpice. Tracks inbound/outbound warehouse containers through a 4-stage workflow: Pending → In Progress → Completed → Reviewed.

## Requirements
- PHP 8.x with GD extension (for photo compression)
- MySQL 5.7+ (with ONLY_FULL_GROUP_BY — already handled by the code)
- UserSpice 5.x installed and configured
- SparkPost account for email

## Quick Install

### 1. Copy files
Copy the `usersc/` folder contents into your UserSpice installation's `usersc/` directory.

### 2. Run the SQL
Import `SETUP.sql` into your database. Creates all required tables.

### 3. Set up uploads directory
```bash
mkdir -p /var/www/container-flow.com/html/usersc/uploads
chown -R www-data:www-data /var/www/container-flow.com/html/usersc/uploads
chmod -R 775 /var/www/container-flow.com/html/usersc/uploads
mkdir -p /var/www/container-flow.com/html/usersc/logs
chmod 775 /var/www/container-flow.com/html/usersc/logs
```

### 4. Configure in UserSpice admin
- Create a **Supervisor** permission and note its ID (used in getSupervisorEmails — perm ID 3 in original install)
- Create a **Client** permission for portal access (configure its ID in Settings page)
- Assign permissions to users

### 5. Set up settings page
Visit `/usersc/container_settings.php` and configure:
- SparkPost API key + from email
- Site URL (https://container-flow.com)
- Points values for beta testing
- Client permission ID

### 6. Set up cron (daily digest at 6 AM)
```bash
crontab -e
# Add:
0 6 * * * php /var/www/container-flow.com/html/usersc/cron/daily_digest.php >> /var/www/container-flow.com/html/usersc/logs/daily_digest.log 2>&1
```

### 6b. Yard Board (door & yard tracking)
A separate product on the same site: it doesn't need a Container Flow photo record, client or label for anything on the board. When a container number on the board also has a Container Flow record, the two link up (a corner marker on the board, a yard badge on `container_view.php`). It shares only the site login, the Supervisor permission and warehouses.

No SQL to run — the yard tables are created automatically the first time anyone opens `yard_board.php`.
1. In UserSpice admin, add `yard_board.php`, `yard_history.php` and `yard_settings.php` as pages (floor workers + supervisors for the first two, supervisors for settings).
2. As a supervisor open **Yard Board → Setup**:
   - **Import the T-Card sheet**: in Google Sheets open the TODAY tab → File → Download → CSV, upload, check the preview, click *Import now*. Doors/yard spots are created from the sheet automatically.
   - Or add doors/spots by range (DR 1–14, F 1–47).
   - Set account colours. The yard keeps its own account list (separate from Container Flow clients); anything typed into ACCOUNT is added to it automatically.
3. Share `https://container-flow.com/usersc/yard_board.php` with the yard team. Every open board updates within ~5 seconds of any change. The site menu (`includes/container_navigation.php`) has separate Containers and Yard Board entries.

### 7. UserSpice page permissions
In UserSpice admin, configure which user groups can access each page. Typically:
- Supervisors: all pages
- Floor workers: dashboard, view, create, edit, upload
- Clients: container_portal.php only

## Key files
| File | Purpose |
|------|---------|
| `container_dashboard.php` | Mobile-first floor worker dashboard |
| `container_dashboard_pro.php` | Desktop supervisor dashboard with bulk actions |
| `container_view.php` | Container detail + photo upload |
| `container_create.php` | Create new container |
| `container_portal.php` | Client-facing portal (requires client permission) |
| `container_settings.php` | App configuration |
| `container_reports.php` | Analytics dashboard |
| `container_client_users.php` | Link UserSpice users to customers for portal access |
| `customer_photo_types.php` | Configure per-client photo requirements |
| `cron/daily_digest.php` | Daily summary email to supervisors |
| `yard_board.php` | Live door/yard board (replaces the T-Card sheet) |
| `yard_history.php` | Picked-up log + move/activity log, CSV export |
| `yard_settings.php` | Doors/yard spots, account colours, sheet import |

## UserSpice permission IDs (in this install)
- Supervisor = 3 (fetchPermissionUsers(3))
- Floor worker = any logged-in user (isLoggedIn())
- Client = configurable via settings page

## Notes
- The `plg_points` column in UserSpice's `users` table is used for the points system
- Google Drive backup uses OAuth — reconnect via `drive_oauth_connect.php` if token expires
- Client portal uses HMAC tokens OR UserSpice login (configured via `container_client_users.php`)
