<?php
/**
 * Report Builder — saved reports, recipients, scheduling and delivery.
 *
 * Tables (created by migrate.php): plg_rb_reports, plg_rb_recipients, plg_rb_run_log.
 *
 * Project hooks come from usersc/report_builder_config.php, which returns:
 *   [
 *     'mailer'        => function (array $to, $subject, $html, array $attachments) {
 *                            return ['success' => bool, 'message' => string]; },
 *     'base_url'      => 'https://example.com' or a callable returning it,
 *     'brand'         => 'Container Flow',
 *     'primary_color' => '#1e3a5f',
 *   ]
 * Attachments are [['name', 'content' (raw), 'type']]. Without a mailer the
 * plugin falls back to UserSpice's email() (no attachments).
 *
 * Recipients: kind 'email' (anyone, no account needed), 'user' (a UserSpice
 * user), or 'permission' (every active user holding a permission level, resolved
 * at send time). Each can carry a note saying why they get it.
 *
 * Scope modes — whose data access a scheduled send runs with:
 *   creator    the report creator's dataset scope (default)
 *   recipient  each user/permission recipient gets their own scoped copy;
 *              plain-email recipients get the creator's scope
 *   none       unscoped — everything the filters match
 */
if (count(get_included_files()) == 1) die();

if (!class_exists('RbReports')) {
class RbReports {
    const T_REPORTS    = 'plg_rb_reports';
    const T_RECIPIENTS = 'plg_rb_recipients';
    const T_LOG        = 'plg_rb_run_log';
    const FREQUENCIES  = ['daily', 'weekly', 'monthly'];
    const SCOPE_MODES  = ['creator', 'recipient', 'none'];
    const RECIPIENT_KINDS = ['email', 'user', 'permission'];

    private static $config = null;
    /** Tests can point this at a fixture config file. */
    public static $configFile = null;

    // ── config ──────────────────────────────────────────────────────────────

    public static function config() {
        if (self::$config !== null) return self::$config;
        $file = self::$configFile ?? dirname(__DIR__, 4) . '/report_builder_config.php';
        $cfg = [];
        if (is_file($file)) {
            $loaded = (function ($file) { return include $file; })($file);
            if (is_array($loaded)) $cfg = $loaded;
        }
        $base = $cfg['base_url'] ?? '';
        if (is_callable($base)) $base = call_user_func($base);
        return self::$config = [
            'mailer'        => $cfg['mailer'] ?? null,
            'base_url'      => (string) $base,
            'brand'         => (string) ($cfg['brand'] ?? ''),
            'primary_color' => (string) ($cfg['primary_color'] ?? '#1e3a5f'),
        ];
    }

    public static function resetConfig() { self::$config = null; }

    // ── CRUD ────────────────────────────────────────────────────────────────

    public static function all() {
        return DB::getInstance()->query('SELECT * FROM ' . self::T_REPORTS . ' ORDER BY name')->results() ?: [];
    }

    public static function get($id) {
        return DB::getInstance()->query('SELECT * FROM ' . self::T_REPORTS . ' WHERE id = ?', [(int) $id])->first() ?: null;
    }

    /** Insert (no id) or update. Returns the report id. $data keys are whitelisted. */
    public static function save(array $data, $id = null) {
        $allowed = ['name', 'description', 'active', 'layout_json', 'schedule_frequency', 'schedule_day_of_week',
                    'schedule_day_of_month', 'schedule_hour', 'attach_csv', 'scope_mode', 'created_by'];
        $row = array_intersect_key($data, array_flip($allowed));
        if (isset($row['schedule_frequency']) && !in_array($row['schedule_frequency'], self::FREQUENCIES, true)) $row['schedule_frequency'] = null;
        if (isset($row['scope_mode']) && !in_array($row['scope_mode'], self::SCOPE_MODES, true)) $row['scope_mode'] = 'creator';
        if (isset($row['schedule_hour'])) $row['schedule_hour'] = max(0, min(23, (int) $row['schedule_hour']));
        if (isset($row['schedule_day_of_week'])) $row['schedule_day_of_week'] = max(0, min(6, (int) $row['schedule_day_of_week']));
        if (isset($row['schedule_day_of_month'])) $row['schedule_day_of_month'] = max(1, min(31, (int) $row['schedule_day_of_month']));
        $db = DB::getInstance();
        if ($id) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->update(self::T_REPORTS, (int) $id, $row);
            return (int) $id;
        }
        $db->insert(self::T_REPORTS, $row);
        return (int) $db->lastId();
    }

    public static function delete($id) {
        $db = DB::getInstance();
        $db->query('DELETE FROM ' . self::T_RECIPIENTS . ' WHERE report_id = ?', [(int) $id]);
        $db->query('DELETE FROM ' . self::T_LOG . ' WHERE report_id = ?', [(int) $id]);
        $db->query('DELETE FROM ' . self::T_REPORTS . ' WHERE id = ?', [(int) $id]);
    }

    public static function recipients($report_id) {
        return DB::getInstance()->query('SELECT * FROM ' . self::T_RECIPIENTS . ' WHERE report_id = ? ORDER BY id', [(int) $report_id])->results() ?: [];
    }

    /**
     * Replace a report's recipients. Each item: ['kind', 'email'|'user_id'|'permission_id', 'note'].
     * Invalid items are skipped; returns the list of problems.
     */
    public static function saveRecipients($report_id, array $items) {
        $db = DB::getInstance();
        $db->query('DELETE FROM ' . self::T_RECIPIENTS . ' WHERE report_id = ?', [(int) $report_id]);
        $problems = [];
        foreach ($items as $it) {
            $kind = $it['kind'] ?? 'email';
            $row = ['report_id' => (int) $report_id, 'kind' => $kind, 'note' => mb_substr(trim((string) ($it['note'] ?? '')), 0, 255) ?: null];
            if ($kind === 'email') {
                $email = trim((string) ($it['email'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $problems[] = "Invalid email '$email'"; continue; }
                $row['email'] = $email;
            } elseif ($kind === 'user' && (int) ($it['user_id'] ?? 0) > 0) {
                $row['user_id'] = (int) $it['user_id'];
            } elseif ($kind === 'permission' && (int) ($it['permission_id'] ?? 0) > 0) {
                $row['permission_id'] = (int) $it['permission_id'];
            } else {
                $problems[] = 'Skipped an invalid recipient';
                continue;
            }
            $db->insert(self::T_RECIPIENTS, $row);
        }
        return $problems;
    }

    /**
     * Expand recipients to people: [['email', 'name', 'user_id' (null for plain emails), 'note']],
     * de-duplicated by email (first entry wins).
     */
    public static function resolveRecipients($report_id) {
        $db = DB::getInstance();
        $out = [];
        $add = function ($email, $name, $user_id, $note) use (&$out) {
            $email = trim((string) $email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
            $k = strtolower($email);
            if (!isset($out[$k])) $out[$k] = ['email' => $email, 'name' => trim((string) $name), 'user_id' => $user_id, 'note' => $note];
        };
        foreach (self::recipients($report_id) as $r) {
            if ($r->kind === 'email') {
                $add($r->email, '', null, $r->note);
                continue;
            }
            if ($r->kind === 'user') {
                $ids = [(int) $r->user_id];
            } elseif ($r->kind === 'permission') {
                $ids = function_exists('fetchPermissionUsers')
                    ? array_map('intval', array_column((array) fetchPermissionUsers((int) $r->permission_id), 'user_id'))
                    : [];
            } else {
                continue;
            }
            if (empty($ids)) continue;
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $users = $db->query("SELECT id, email, fname, lname FROM users WHERE id IN ($ph) AND active = 1 ORDER BY lname, fname", $ids)->results() ?: [];
            foreach ($users as $u) $add($u->email, $u->fname . ' ' . $u->lname, (int) $u->id, $r->note);
        }
        return array_values($out);
    }

    // ── schedule ────────────────────────────────────────────────────────────

    /**
     * Due this hour and not already sent this period. Same rules as the
     * original isScheduledReportDue(): matches the hour, not the minute, so
     * the cron can run every 15–60 minutes without double-sending.
     */
    public static function isDue($report, \DateTime $now) {
        if (empty($report->active) || !in_array($report->schedule_frequency, self::FREQUENCIES, true)) return false;
        if ((int) $now->format('G') !== (int) $report->schedule_hour) return false;
        $last = $report->last_sent_at ? new \DateTime($report->last_sent_at) : null;
        switch ($report->schedule_frequency) {
            case 'daily':
                return !$last || $last->format('Y-m-d') !== $now->format('Y-m-d');
            case 'weekly':
                if ((int) $now->format('w') !== (int) $report->schedule_day_of_week) return false;
                return !$last || $last->format('Y-m-d') !== $now->format('Y-m-d');
            case 'monthly':
                $effective = min((int) $report->schedule_day_of_month, (int) $now->format('t'));
                if ((int) $now->format('j') !== $effective) return false;
                return !$last || $last->format('Y-m') !== $now->format('Y-m');
        }
        return false;
    }

    /** Cron entry point. Returns the number of reports run. */
    public static function runDue(?\DateTime $now = null) {
        $now = $now ?: new \DateTime();
        $n = 0;
        foreach (self::all() as $r) {
            if (!self::isDue($r, $now)) continue;
            self::run($r, 'scheduled', ['now' => $now]);
            $n++;
        }
        return $n;
    }

    // ── rendering & sending ─────────────────────────────────────────────────

    /** Render a report for one viewer/scope. $ctx is the RbQuery run context. */
    public static function render($report, array $ctx = [], array $opts = []) {
        $layout = json_decode((string) $report->layout_json, true);
        if (!is_array($layout)) throw new RbQueryException('This report has no valid layout yet.');
        $cfg = self::config();
        $ctx += ['last_sent_at' => $report->last_sent_at ?? null, 'created_at' => $report->created_at ?? null];
        return RbRender::render($layout, $opts + [
            'ctx'           => $ctx,
            'report_name'   => $report->name,
            'brand'         => $cfg['brand'],
            'base_url'      => $cfg['base_url'],
            'primary_color' => $cfg['primary_color'],
        ]);
    }

    /** Run context for a scope mode. $viewer_id is used by 'recipient' mode. */
    public static function scopeCtx($report, $viewer_id = null) {
        switch ($report->scope_mode ?? 'creator') {
            case 'none':      return ['unscoped' => true];
            case 'recipient': return ['user_id' => $viewer_id ?: (int) $report->created_by];
            default:          return ['user_id' => (int) $report->created_by];
        }
    }

    /**
     * Render and send. $trigger: scheduled | manual | test.
     * $opts: now (DateTime); only_to ['email', 'user_id'] for a test send.
     * Returns ['success' => bool, 'message' => string, 'recipients' => int, 'rows' => int].
     */
    public static function run($report, $trigger, array $opts = []) {
        $now = $opts['now'] ?? new \DateTime();
        $recipients = isset($opts['only_to'])
            ? [['email' => $opts['only_to']['email'], 'name' => '', 'user_id' => $opts['only_to']['user_id'] ?? null, 'note' => 'test']]
            : self::resolveRecipients($report->id);
        if (empty($recipients)) {
            self::log($report->id, $trigger, 0, 0, false, 'No recipients');
            return ['success' => false, 'message' => 'No recipients (or none with a valid email).', 'recipients' => 0, 'rows' => 0];
        }

        // One render per distinct scope; recipients sharing a scope share an email.
        $groups = [];
        foreach ($recipients as $r) {
            $viewer = ($report->scope_mode ?? '') === 'recipient' ? $r['user_id'] : null;
            $groups[(string) $viewer][] = $r;
        }

        $mailer = self::config()['mailer'] ?: [__CLASS__, 'userspiceMailer'];
        $ok = true; $errors = []; $replies = []; $rows = 0; $sent = 0;
        foreach ($groups as $viewer => $people) {
            try {
                $out = self::render($report, self::scopeCtx($report, $viewer === '' ? null : (int) $viewer), ['now' => $now]);
            } catch (\Throwable $e) {
                $ok = false;
                $errors[] = $e->getMessage();
                break; // same layout for every group — it will fail for all of them
            }
            $rows += $out['row_count'];
            $subject = $trigger === 'test' ? '[TEST] ' . $out['subject'] : $out['subject'];
            $attachments = !empty($report->attach_csv) ? $out['attachments'] : [];
            try {
                $res = call_user_func($mailer, array_column($people, 'email'), $subject, $out['html'], $attachments);
            } catch (\Throwable $e) {
                $res = ['success' => false, 'message' => $e->getMessage()];
            }
            if (!empty($res['success'])) {
                $sent += count($people);
                if (!empty($res['message'])) $replies[] = $res['message'];
            } else {
                $ok = false;
                $errors[] = $res['message'] ?? 'Mailer failed';
            }
        }

        $msg = $ok ? "Sent to $sent recipient(s)" . ($replies ? ' — ' . implode('; ', array_unique($replies)) : '') . '.'
                   : implode('; ', array_unique($errors));
        self::log($report->id, $trigger, $sent, $rows, $ok, $ok ? null : $msg);
        if ($ok && $trigger !== 'test') {
            DB::getInstance()->update(self::T_REPORTS, (int) $report->id, ['last_sent_at' => $now->format('Y-m-d H:i:s')]);
        }
        return ['success' => $ok, 'message' => $msg, 'recipients' => $sent, 'rows' => $rows];
    }

    /** Fallback mailer: UserSpice's email(), one message per address, no attachments. */
    public static function userspiceMailer(array $to, $subject, $html, array $attachments) {
        if (!function_exists('email')) return ['success' => false, 'message' => 'No mailer configured (usersc/report_builder_config.php).'];
        foreach ($to as $addr) {
            if (!email($addr, $subject, $html)) return ['success' => false, 'message' => "UserSpice email() failed for $addr"];
        }
        return ['success' => true, 'message' => ''];
    }

    public static function log($report_id, $trigger, $recipient_count, $row_count, $success, $error = null) {
        try {
            DB::getInstance()->insert(self::T_LOG, [
                'report_id'       => (int) $report_id,
                'trigger_type'    => $trigger,
                'recipient_count' => (int) $recipient_count,
                'row_count'       => (int) $row_count,
                'success'         => $success ? 1 : 0,
                'error_message'   => $error,
            ]);
        } catch (\Throwable $e) {
            error_log('Report builder: run log failed: ' . $e->getMessage());
        }
    }

    public static function runLog($report_id, $limit = 20) {
        return DB::getInstance()->query('SELECT * FROM ' . self::T_LOG . ' WHERE report_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [(int) $report_id])->results() ?: [];
    }

    // ── presets ─────────────────────────────────────────────────────────────

    /** usersc/report_presets/*.json — [basename => decoded preset]. */
    public static function presets() {
        $out = [];
        foreach (glob(dirname(__DIR__, 4) . '/report_presets/*.json') ?: [] as $f) {
            $p = json_decode((string) file_get_contents($f), true);
            if (is_array($p) && !empty($p['name']) && isset($p['layout'])) $out[basename($f, '.json')] = $p;
        }
        return $out;
    }

    /** Create an INACTIVE report from a preset array. Returns the new id. */
    public static function createFromPreset(array $p, $user_id) {
        $id = self::save([
            'name'                  => mb_substr((string) $p['name'], 0, 150),
            'description'           => isset($p['description']) ? mb_substr((string) $p['description'], 0, 255) : null,
            'active'                => 0,
            'layout_json'           => json_encode($p['layout']),
            'schedule_frequency'    => $p['schedule']['frequency'] ?? null,
            'schedule_day_of_week'  => $p['schedule']['day_of_week'] ?? null,
            'schedule_day_of_month' => $p['schedule']['day_of_month'] ?? null,
            'schedule_hour'         => $p['schedule']['hour'] ?? 6,
            'attach_csv'            => !empty($p['attach_csv']) ? 1 : 0,
            'scope_mode'            => $p['scope_mode'] ?? 'creator',
            'created_by'            => (int) $user_id,
        ]);
        self::saveRecipients($id, (array) ($p['recipients'] ?? []));
        return $id;
    }
}
}
