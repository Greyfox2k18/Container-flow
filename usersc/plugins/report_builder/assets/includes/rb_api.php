<?php
/**
 * Report Builder — editor API. One handler for every editor action.
 *
 * A project exposes it through a tiny AJAX wrapper that does the session,
 * CSRF and JSON plumbing, e.g. usersc/ajax/report_builder_api.php:
 *
 *   echo json_encode(RbApi::handle(Input::get('action'), json_decode($_POST['payload'] ?? '{}', true) ?: [], $user->data()->id));
 *
 * Every action re-checks permissions (RbReports::canBuild / canSend /
 * canUnscope). Layouts are validated by test-rendering before they are saved.
 * Returns ['ok' => true, ...] or ['ok' => false, 'error' => message].
 */
if (count(get_included_files()) == 1) die();

if (!class_exists('RbApi')) {
class RbApi {
    const MAX_USERS = 2000;

    public static function handle($action, array $p, $user_id) {
        $user_id = (int) $user_id;
        try {
            if (!$user_id || !RbReports::canBuild($user_id)) return self::err("You don't have permission to build reports.");
            switch ($action) {
                case 'meta':          return self::ok(self::meta($user_id));
                case 'list':          return self::ok(['reports' => self::listReports()]);
                case 'load':          return self::load($p);
                case 'preview':       return self::preview($p, $user_id);
                case 'save':          return self::save($p, $user_id);
                case 'toggle':        return self::toggle($p, $user_id);
                case 'delete':        return self::deleteReport($p);
                case 'duplicate':     return self::duplicate($p, $user_id);
                case 'create_preset': return self::createPreset($p, $user_id);
                case 'send_test':     return self::send($p, $user_id, true);
                case 'send_now':      return self::send($p, $user_id, false);
                case 'run_log':       return self::ok(['log' => RbReports::runLog((int) ($p['id'] ?? 0), 20)]);
            }
            return self::err('Unknown action.');
        } catch (RbQueryException $e) {
            return self::err($e->getMessage());
        } catch (\Throwable $e) {
            error_log('Report builder API (' . $action . '): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return self::err('Something went wrong — details are in the PHP error log.');
        }
    }

    // ── actions ─────────────────────────────────────────────────────────────

    private static function meta($user_id) {
        $datasets = [];
        foreach (RbRegistry::all() as $key => $ds) {
            $fields = [];
            foreach ($ds['fields'] as $f) {
                $opts = $f['type'] === 'enum' ? RbRegistry::fieldOptions($f) : null;
                $fields[] = [
                    'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'],
                    'filterable' => $f['filterable'], 'groupable' => $f['groupable'],
                    'aggregatable' => $f['aggregatable'], 'sortable' => $f['sortable'],
                    // Ordered list so JS keeps the option order (JSON objects with numeric keys don't).
                    'options' => $opts === null ? null : array_map(function ($v, $l) { return ['value' => (string) $v, 'label' => (string) $l]; }, array_keys($opts), $opts),
                ];
            }
            $datasets[] = ['key' => $key, 'label' => $ds['label'], 'description' => $ds['description'],
                           'default_fields' => $ds['default_fields'], 'default_date_field' => $ds['default_date_field'], 'fields' => $fields];
        }
        $db = DB::getInstance();
        $perms = [];
        try { $perms = $db->query('SELECT id, name FROM permissions ORDER BY id')->results(true) ?: []; } catch (\Throwable $e) {}
        $users = [];
        try {
            $users = $db->query("SELECT id, fname, lname, email FROM users WHERE active = 1 AND email <> '' ORDER BY fname, lname LIMIT " . self::MAX_USERS)->results(true) ?: [];
        } catch (\Throwable $e) {}
        $presets = [];
        foreach (RbReports::presets() as $k => $pr) $presets[] = ['key' => $k, 'name' => $pr['name'], 'description' => $pr['description'] ?? ''];

        return [
            'datasets'    => $datasets,
            'permissions' => array_map(function ($r) { return ['id' => (int) $r['id'], 'name' => $r['name']]; }, $perms),
            'users'       => array_map(function ($r) { return ['id' => (int) $r['id'], 'name' => trim($r['fname'] . ' ' . $r['lname']), 'email' => $r['email']]; }, $users),
            'presets'     => $presets,
            'ops'         => RbQuery::OPS_BY_TYPE,
            'aggregates'  => RbQuery::AGGREGATES,
            'buckets'     => RbQuery::BUCKETS,
            'date_ranges' => RbQuery::DATE_RANGES,
            'text_styles' => RbRender::TEXT_STYLES,
            'can_send'    => RbReports::canSend($user_id),
            'can_unscope' => RbReports::canUnscope($user_id),
        ];
    }

    private static function listReports() {
        $out = [];
        foreach (RbReports::all() as $r) {
            $out[] = [
                'id' => (int) $r->id, 'name' => $r->name, 'description' => $r->description,
                'active' => (bool) $r->active, 'schedule' => RbReports::scheduleText($r),
                'recipients' => count(RbReports::recipients($r->id)), 'last_sent_at' => $r->last_sent_at,
            ];
        }
        return $out;
    }

    private static function load(array $p) {
        $r = RbReports::get((int) ($p['id'] ?? 0));
        if (!$r) return self::err('Report not found.');
        return self::ok([
            'report' => self::reportFields($r),
            'layout' => json_decode((string) $r->layout_json, true) ?: ['blocks' => []],
            'recipients' => array_map(function ($x) {
                return ['kind' => $x->kind, 'email' => $x->email, 'user_id' => $x->user_id ? (int) $x->user_id : null,
                        'permission_id' => $x->permission_id ? (int) $x->permission_id : null, 'note' => $x->note];
            }, RbReports::recipients($r->id)),
        ]);
    }

    private static function preview(array $p, $user_id) {
        $existing = !empty($p['id']) ? RbReports::get((int) $p['id']) : null;
        $report = self::draftReport($p, $existing, $user_id);
        $out = RbReports::render($report, self::viewerCtx($report, $user_id));
        return self::ok([
            'html' => $out['html'], 'subject' => $out['subject'], 'row_count' => $out['row_count'],
            'attachments' => array_column($out['attachments'], 'name'), 'metrics' => $out['metrics'],
        ]);
    }

    private static function save(array $p, $user_id) {
        $id = (int) ($p['id'] ?? 0);
        $existing = $id ? RbReports::get($id) : null;
        if ($id && !$existing) return self::err('Report not found.');
        $f = (array) ($p['report'] ?? []);
        $name = mb_substr(trim((string) ($f['name'] ?? '')), 0, 150);
        if ($name === '') return self::err('Give the report a name.');
        $scope = in_array($f['scope_mode'] ?? '', RbReports::SCOPE_MODES, true) ? $f['scope_mode'] : 'creator';
        if ($scope === 'none' && !RbReports::canUnscope($user_id)) {
            return self::err("You can't make a report that ignores data restrictions. Choose \"Report creator's\" or \"Each recipient's\" access.");
        }

        // Validate layout + recipients before touching the database.
        $report = self::draftReport($p, $existing, $user_id);
        RbReports::render($report, self::viewerCtx($report, $user_id));
        $recipients = array_values(array_filter((array) ($p['recipients'] ?? []), 'is_array'));
        foreach ($recipients as $r) {
            if (($r['kind'] ?? '') === 'email' && !filter_var(trim((string) ($r['email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
                return self::err("'" . mb_substr((string) ($r['email'] ?? ''), 0, 80) . "' isn't a valid email address.");
            }
        }

        $data = [
            'name'                  => $name,
            'description'           => mb_substr(trim((string) ($f['description'] ?? '')), 0, 255) ?: null,
            'active'                => !empty($f['active']) ? 1 : 0,
            'layout_json'           => $report->layout_json,
            'schedule_frequency'    => in_array($f['schedule_frequency'] ?? '', RbReports::FREQUENCIES, true) ? $f['schedule_frequency'] : null,
            'schedule_day_of_week'  => (int) ($f['schedule_day_of_week'] ?? 1),
            'schedule_day_of_month' => (int) ($f['schedule_day_of_month'] ?? 1),
            'schedule_hour'         => (int) ($f['schedule_hour'] ?? 6),
            'scope_mode'            => $scope,
            'attach_csv'            => !empty($f['attach_csv']) ? 1 : 0,
        ];
        if (!$id) $data['created_by'] = $user_id;
        $id = RbReports::save($data, $id ?: null);
        $problems = RbReports::saveRecipients($id, $recipients);
        return self::ok(['id' => $id, 'warnings' => $problems]);
    }

    private static function toggle(array $p, $user_id) {
        $r = RbReports::get((int) ($p['id'] ?? 0));
        if (!$r) return self::err('Report not found.');
        if (!$r->active && $r->scope_mode === 'none' && !RbReports::canUnscope($user_id)) {
            return self::err("This report ignores data restrictions; only someone with full access can activate it.");
        }
        RbReports::save(['active' => $r->active ? 0 : 1], $r->id);
        return self::ok(['active' => !$r->active]);
    }

    private static function deleteReport(array $p) {
        $r = RbReports::get((int) ($p['id'] ?? 0));
        if (!$r) return self::err('Report not found.');
        RbReports::delete($r->id);
        return self::ok([]);
    }

    private static function duplicate(array $p, $user_id) {
        $r = RbReports::get((int) ($p['id'] ?? 0));
        if (!$r) return self::err('Report not found.');
        $scope = $r->scope_mode === 'none' && !RbReports::canUnscope($user_id) ? 'creator' : $r->scope_mode;
        $id = RbReports::save([
            'name' => mb_substr('Copy of ' . $r->name, 0, 150), 'description' => $r->description, 'active' => 0,
            'layout_json' => $r->layout_json, 'schedule_frequency' => $r->schedule_frequency,
            'schedule_day_of_week' => $r->schedule_day_of_week, 'schedule_day_of_month' => $r->schedule_day_of_month,
            'schedule_hour' => $r->schedule_hour, 'attach_csv' => $r->attach_csv, 'scope_mode' => $scope, 'created_by' => $user_id,
        ]);
        RbReports::saveRecipients($id, array_map(function ($x) {
            return ['kind' => $x->kind, 'email' => $x->email, 'user_id' => $x->user_id, 'permission_id' => $x->permission_id, 'note' => $x->note];
        }, RbReports::recipients($r->id)));
        return self::ok(['id' => $id]);
    }

    private static function createPreset(array $p, $user_id) {
        $presets = RbReports::presets();
        $key = (string) ($p['preset'] ?? '');
        if (!isset($presets[$key])) return self::err('Unknown preset.');
        $preset = $presets[$key];
        if (($preset['scope_mode'] ?? '') === 'none' && !RbReports::canUnscope($user_id)) $preset['scope_mode'] = 'recipient';
        return self::ok(['id' => RbReports::createFromPreset($preset, $user_id)]);
    }

    private static function send(array $p, $user_id, $test) {
        $r = RbReports::get((int) ($p['id'] ?? 0));
        if (!$r) return self::err('Report not found.');
        if (!$test && !RbReports::canSend($user_id)) return self::err("You don't have permission to send reports to their recipients.");
        $opts = [];
        if ($test) {
            $me = DB::getInstance()->query('SELECT email FROM users WHERE id = ?', [$user_id])->first();
            if (!$me || !filter_var($me->email, FILTER_VALIDATE_EMAIL)) return self::err('Your account has no valid email address.');
            $opts['only_to'] = ['email' => $me->email, 'user_id' => $user_id];
        }
        $res = RbReports::run($r, $test ? 'test' : 'manual', $opts);
        return $res['success'] ? self::ok(['message' => $test ? "Test sent to {$opts['only_to']['email']}." : $res['message']]) : self::err('Send failed: ' . $res['message']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** An unsaved report object from editor input, for preview/validation. */
    private static function draftReport(array $p, $existing, $user_id) {
        $layout = $p['layout'] ?? null;
        if (!is_array($layout)) throw new RbQueryException('The layout is missing.');
        $f = (array) ($p['report'] ?? []);
        return (object) [
            'id'           => $existing ? (int) $existing->id : 0,
            'name'         => (string) ($f['name'] ?? ($existing->name ?? 'Untitled report')),
            'layout_json'  => json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'scope_mode'   => in_array($f['scope_mode'] ?? '', RbReports::SCOPE_MODES, true) ? $f['scope_mode'] : 'creator',
            'created_by'   => $existing ? (int) $existing->created_by : $user_id,
            'last_sent_at' => $existing->last_sent_at ?? null,
            'created_at'   => $existing->created_at ?? date('Y-m-d H:i:s'),
            'attach_csv'   => !empty($f['attach_csv']),
        ];
    }

    /**
     * Preview scope: people who can see everything preview with the report's
     * own setting; anyone restricted always previews within their own access,
     * so the editor can't be used to look past it.
     */
    private static function viewerCtx($report, $user_id) {
        if (!RbReports::canUnscope($user_id)) return ['user_id' => $user_id];
        return RbReports::scopeCtx($report, $user_id);
    }

    private static function reportFields($r) {
        return [
            'id' => (int) $r->id, 'name' => $r->name, 'description' => (string) $r->description, 'active' => (bool) $r->active,
            'schedule_frequency' => $r->schedule_frequency, 'schedule_day_of_week' => (int) $r->schedule_day_of_week,
            'schedule_day_of_month' => (int) ($r->schedule_day_of_month ?: 1), 'schedule_hour' => (int) $r->schedule_hour,
            'scope_mode' => $r->scope_mode, 'attach_csv' => (bool) $r->attach_csv, 'last_sent_at' => $r->last_sent_at,
        ];
    }

    private static function ok(array $data) { return ['ok' => true] + $data; }
    private static function err($msg) { return ['ok' => false, 'error' => $msg]; }
}
}
