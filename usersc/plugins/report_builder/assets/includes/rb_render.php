<?php
/**
 * Report Builder — block renderer.
 *
 * One layout in, three outputs: email-safe HTML (tables + inline CSS, stacked
 * vertically), the same HTML for the web preview, and a CSV per table block.
 *
 * Layout JSON (stored in plg_rb_reports.layout_json):
 * {
 *   "metrics": {                        named single numbers, usable by tiles,
 *     "awaiting": {                     show_if conditions and {tokens}
 *       "dataset": "containers", "fn": "count",          // count|count_distinct|sum|avg|min|max
 *       "field": null, "filters": [...], "date_window": {...}
 *     }
 *   },
 *   "report_filters": [                 applied to every block/metric on that dataset
 *     {"dataset": "containers", "field": "customer_id", "op": "in", "value": [3, 7]}
 *   ],
 *   "subject": [                        first rule whose "if" passes wins
 *     {"if": {"metric": "awaiting", "op": "gt", "value": 0}, "text": "{awaiting} Awaiting Review"},
 *     {"text": "Daily Digest — {date_short}"}
 *   ],
 *   "blocks": [ ...see below... ]
 * }
 *
 * Every block may have "show_if": {"metric": "...", "op": "eq|neq|gt|gte|lt|lte", "value": n}.
 *   header         eyebrow, title, subtitle
 *   summary_tiles  tiles: [{label, metric, color}]
 *   table          title, dataset, query {fields, filters, date_window, sort, limit,
 *                  group_by, aggregates}, labels {col: label}, hide_if_empty,
 *                  empty_text, csv (default true)
 *   grouped_table  as table, plus group_field — one sub-section per value
 *   chart          title, chart_type: bar|column|line, dataset, query {group_by: [x, split-by?],
 *                  aggregates: [one value], filters, date_window, sort, limit}, labels,
 *                  height (200|280|360), show_table (default true), hide_if_empty, csv.
 *                  bar = HTML bars (one series); column/line = PNG (inline cid: image in
 *                  email, data: URI in the web preview) + HTML legend + data table.
 *   text           body, style: normal|alert|info|success|muted|footer
 *   buttons        buttons: [{label, url, style: primary|secondary}]
 *
 * Tokens in any text: {date} {date_short} {time} {report_name} {brand}
 * {<metric>} and {s:<metric>} ("s" unless the metric is exactly 1).
 *
 * Everything from the layout is escaped on output; colours must be hex;
 * button URLs must be http(s) or a site-relative path.
 */
if (count(get_included_files()) == 1) die();

if (!class_exists('RbRender')) {
class RbRender {
    const BLOCK_TYPES = ['header', 'summary_tiles', 'table', 'grouped_table', 'chart', 'text', 'buttons'];
    const CHART_TYPES = ['bar', 'column', 'line'];
    const CHART_MAX_CATS = ['bar' => 15, 'column' => 40, 'line' => 120];
    const TEXT_STYLES = ['normal', 'alert', 'info', 'success', 'muted', 'footer'];
    const COND_OPS    = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'];
    const MAX_TEXT    = 5000;

    private $layout;
    private $o;
    private $metrics     = [];
    private $attachments = [];
    private $images      = [];   // chart PNGs: [['cid', 'name', 'content', 'type']]
    private $rowCount    = 0;
    private $optCache    = [];

    /**
     * $opts: ctx (scope/run context for RbQuery), now (DateTime), report_name,
     * brand, base_url, primary_color, executor, dialect.
     * Returns ['html', 'subject', 'attachments' => [['name','content','type']], 'metrics', 'row_count'].
     * Throws RbQueryException for an invalid layout.
     */
    public static function render(array $layout, array $opts = []) {
        $r = new self($layout, $opts);
        return $r->run();
    }

    private function __construct(array $layout, array $opts) {
        $this->layout = $layout;
        $now = $opts['now'] ?? new \DateTime();
        $primary = $opts['primary_color'] ?? '#1e3a5f';
        $this->o = [
            'ctx'         => ($opts['ctx'] ?? []) + ['now' => $now],
            'now'         => $now,
            'report_name' => (string) ($opts['report_name'] ?? ''),
            'brand'       => (string) ($opts['brand'] ?? ''),
            'base_url'    => rtrim((string) ($opts['base_url'] ?? ''), '/'),
            'primary'     => self::isColor($primary) ? $primary : '#1e3a5f',
            'executor'    => $opts['executor'] ?? null,
            'dialect'     => $opts['dialect'] ?? 'mysql',
            // 'web' embeds chart images as data: URIs (preview); 'email' references them as cid: inline images.
            'mode'        => ($opts['mode'] ?? 'web') === 'email' ? 'email' : 'web',
        ];
    }

    private function run() {
        foreach ((array) ($this->layout['metrics'] ?? []) as $key => $m) {
            if (!preg_match(RbRegistry::KEY_RE, (string) $key)) throw new RbQueryException("Invalid metric name '" . self::clip($key) . "'.");
            if (!is_array($m)) throw new RbQueryException("Metric '$key' is invalid.");
            $this->metrics[$key] = $this->metricValue($m);
        }

        // Render blocks, then wrap runs of "body" blocks in one white panel.
        $bands = [];
        foreach ((array) ($this->layout['blocks'] ?? []) as $i => $b) {
            if (!is_array($b)) throw new RbQueryException('Block ' . ($i + 1) . ' is invalid.');
            $type = $b['type'] ?? '';
            if (!in_array($type, self::BLOCK_TYPES, true)) throw new RbQueryException("Unknown block type '" . self::clip($type) . "'.");
            if (isset($b['show_if']) && !$this->condition($b['show_if'])) continue;
            $html = $this->{'block_' . $type}($b);
            if ($html === '') continue;
            $band = $type === 'header' ? 'header' : ($type === 'summary_tiles' ? 'tiles'
                  : (($type === 'text' && ($b['style'] ?? '') === 'footer') ? 'footer' : 'body'));
            $last = count($bands) - 1;
            if ($band === 'body' && $last >= 0 && $bands[$last][0] === 'body') {
                $bands[$last][1] .= $html;
            } else {
                $bands[] = [$band, $html];
            }
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:660px;margin:0 auto;color:#1f2937;">';
        foreach ($bands as [$band, $inner]) {
            switch ($band) {
                case 'header': $html .= '<div style="background:' . $this->o['primary'] . ';padding:24px 32px;">' . $inner . '</div>'; break;
                case 'tiles':  $html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:20px 32px;">' . $inner . '</div>'; break;
                case 'footer': $html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:12px 32px;">' . $inner . '</div>'; break;
                default:       $html .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:28px 32px;">' . $inner . '</div>';
            }
        }
        $html .= '</div>';

        return [
            'html'        => $html,
            'subject'     => $this->subject(),
            'attachments' => $this->attachments,
            'images'      => $this->images,
            'metrics'     => $this->metrics,
            'row_count'   => $this->rowCount,
        ];
    }

    // ── blocks ──────────────────────────────────────────────────────────────

    private function block_header(array $b) {
        $out = '';
        $eyebrow = array_key_exists('eyebrow', $b) ? (string) $b['eyebrow'] : '{brand}';
        if ($eyebrow !== '') $out .= '<p style="margin:0 0 2px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7aafd4;">' . $this->text($eyebrow) . '</p>';
        $out .= '<h1 style="margin:0 0 4px;font-size:22px;font-weight:700;color:#fff;">' . $this->text($b['title'] ?? '{report_name}') . '</h1>';
        if (($b['subtitle'] ?? '') !== '') $out .= '<p style="margin:0;font-size:13px;color:#9bbdd6;">' . $this->text($b['subtitle']) . '</p>';
        return $out;
    }

    private function block_summary_tiles(array $b) {
        $tiles = (array) ($b['tiles'] ?? []);
        if (empty($tiles)) return '';
        $out = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>';
        foreach ($tiles as $t) {
            $key = $t['metric'] ?? null;
            if (!is_string($key) || !array_key_exists($key, $this->metrics)) throw new RbQueryException("Tile uses unknown metric '" . self::clip($key) . "'.");
            $color = self::isColor($t['color'] ?? '') ? $t['color'] : '#374151';
            $out .= '<td style="text-align:center;padding:12px 8px;border-top:3px solid ' . $color . ';">'
                  . '<div style="font-size:30px;font-weight:700;color:' . $color . ';line-height:1;">' . self::h(self::num($this->metrics[$key])) . '</div>'
                  . '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-top:4px;">' . $this->text($t['label'] ?? $key) . '</div></td>';
        }
        return $out . '</tr></table>';
    }

    private function block_table(array $b) {
        [$ds, $res] = $this->blockQuery($b);
        if (empty($res['rows']) && !empty($b['hide_if_empty'])) return '';
        $this->rowCount += count($res['rows']);
        $cols = $this->columns($ds, $res, $b);
        $this->addCsv($b, $cols, $res['rows']);

        $out = $this->heading($b['title'] ?? '');
        if (empty($res['rows'])) {
            return $out . '<p style="margin:0 0 24px;font-size:13px;color:#9ca3af;">' . $this->text($b['empty_text'] ?? 'Nothing to show.') . '</p>';
        }
        return $out . $this->tableHtml($cols, $res['rows']);
    }

    private function block_grouped_table(array $b) {
        $ds = $this->dataset($b);
        $gkey = $b['group_field'] ?? null;
        if (!is_string($gkey) || !isset($ds['fields'][$gkey]) || !$ds['fields'][$gkey]['groupable']) {
            throw new RbQueryException("Grouped table needs a groupable 'group by' field.");
        }
        $q = (array) ($b['query'] ?? []);
        if (!empty($q['group_by']) || !empty($q['aggregates'])) throw new RbQueryException('A grouped table lists rows; use a table block for totals.');
        $fields = array_values(array_diff((array) ($q['fields'] ?? $ds['default_fields']), [$gkey]));
        $q['fields'] = array_merge([$gkey], $fields);
        $q['sort'] = array_merge([['key' => $gkey, 'dir' => 'asc']], (array) ($q['sort'] ?? []));
        $b['query'] = $q;

        [$ds, $res] = $this->blockQuery($b);
        if (empty($res['rows']) && !empty($b['hide_if_empty'])) return '';
        $this->rowCount += count($res['rows']);
        $all = $this->columns($ds, $res, $b);
        $this->addCsv($b, $all, $res['rows']);

        $out = $this->heading($b['title'] ?? '');
        if (empty($res['rows'])) {
            return $out . '<p style="margin:0 0 24px;font-size:13px;color:#9ca3af;">' . $this->text($b['empty_text'] ?? 'Nothing to show.') . '</p>';
        }

        $groups = [];
        foreach ($res['rows'] as $r) $groups[(string) $r[$gkey]][] = $r;
        $opts = RbRegistry::fieldOptions($ds['fields'][$gkey]);
        if (is_array($ds['fields'][$gkey]['options']) && $opts) {
            // Static enum: groups follow the option order (e.g. status workflow), not A–Z.
            $order = array_flip(array_map('strval', array_keys($opts)));
            uksort($groups, function ($a, $b) use ($order) {
                return ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b);
            });
        }
        $inner = $all;
        unset($inner[$gkey]);
        foreach ($groups as $val => $rows) {
            $label = $this->cell($all[$gkey], $rows[0][$gkey], 'html');
            $out .= '<h4 style="margin:0 0 8px;font-size:14px;font-weight:700;color:' . $this->o['primary'] . ';">' . $label
                  . ' <span style="font-weight:400;color:#6b7280;">(' . count($rows) . ')</span></h4>';
            $out .= $this->tableHtml($inner, $rows);
        }
        return $out;
    }

    private function block_chart(array $b) {
        $ds = $this->dataset($b);
        $type = in_array($b['chart_type'] ?? 'column', self::CHART_TYPES, true) ? $b['chart_type'] : 'column';
        $q = (array) ($b['query'] ?? []);
        $groups = array_values(array_map(function ($g) { return is_string($g) ? ['field' => $g] : (array) $g; }, (array) ($q['group_by'] ?? [])));
        $aggs = array_values((array) ($q['aggregates'] ?? []));
        if (count($groups) < 1 || count($groups) > 2) throw new RbQueryException('A chart needs an X-axis field, and optionally one "split by" field.');
        if (count($aggs) !== 1 || !is_array($aggs[0])) throw new RbQueryException('A chart shows exactly one value (e.g. Count).');
        if ($type === 'bar' && count($groups) > 1) throw new RbQueryException('Bar charts show one series — use a column or line chart to split by a second field.');
        $alias = function ($g) { return $g['field'] . (!empty($g['bucket']) ? '__' . $g['bucket'] : ''); };
        $a = $aggs[0];
        $vKey = (($a['fn'] ?? '') === 'count' && empty($a['field'])) ? 'count__all' : ($a['fn'] ?? '') . '__' . ($a['field'] ?? '');
        $xKey = $alias($groups[0]);
        $sKey = isset($groups[1]) ? $alias($groups[1]) : null;
        $xf = $ds['fields'][$groups[0]['field']] ?? null;
        $xIsTime = $xf && in_array($xf['type'], ['date', 'datetime'], true);
        if (empty($q['sort'])) $q['sort'] = $xIsTime ? [['key' => $xKey, 'dir' => 'asc']] : [['key' => $vKey, 'dir' => 'desc']];
        $q['group_by'] = $groups;
        $q['limit'] = $q['limit'] ?? 2000;
        unset($q['fields']);
        $b['query'] = $q;

        [$ds, $res] = $this->blockQuery($b);
        $rows = $res['rows'];
        if (empty($rows) && !empty($b['hide_if_empty'])) return '';
        $out = $this->heading($b['title'] ?? '');
        if (empty($rows)) return $out . '<p style="margin:0 0 24px;font-size:13px;color:#9ca3af;">' . $this->text($b['empty_text'] ?? 'Nothing to show.') . '</p>';
        $this->rowCount += count($rows);

        $cols = $this->columns($ds, $res, $b);
        $additive = in_array($a['fn'] ?? '', ['count', 'sum', 'count_distinct'], true);
        $notes = [];

        // Categories (x) in result order; time axes get missing buckets filled in.
        $catKeys = [];
        foreach ($rows as $r) { $k = (string) $r[$xKey]; if (!in_array($k, $catKeys, true)) $catKeys[] = $k; }
        $bucket = $groups[0]['bucket'] ?? null;
        if ($xIsTime && $additive && in_array($bucket, ['day', 'week', 'month'], true)) {
            // Span the whole date range when it's on the X field, so quiet days show as zero.
            $span = [null, null];
            $dw = $q['date_window'] ?? null;
            if (is_array($dw) && !empty($dw['range']) && $dw['range'] !== 'all_time' && ($dw['field'] ?? $ds['default_date_field']) === $groups[0]['field']) {
                $span = RbQuery::dateBounds($dw, $this->o['ctx']);
            }
            $catKeys = self::fillTime($catKeys, $bucket, $span[0], $span[1]);
        }

        // Series: by total, biggest first; past 6 fold into "Other" (or drop, for non-additive values).
        $seriesKeys = [''];
        if ($sKey) {
            $tot = [];
            foreach ($rows as $r) { $k = (string) $r[$sKey]; $tot[$k] = ($tot[$k] ?? 0) + (float) $r[$vKey]; }
            arsort($tot);
            $seriesKeys = array_map('strval', array_keys($tot));
        }
        $fold = [];
        if (count($seriesKeys) > RbChart::MAX_SERIES) {
            $fold = array_slice($seriesKeys, RbChart::MAX_SERIES - 1);
            $seriesKeys = array_slice($seriesKeys, 0, RbChart::MAX_SERIES - 1);
            if ($additive) $seriesKeys[] = "\0other";
            else $notes[] = count($fold) . ' smaller series not shown.';
        }

        // Value matrix.
        $grid = [];
        foreach ($rows as $r) {
            $s = $sKey ? (string) $r[$sKey] : '';
            if (in_array($s, $fold, true)) { if (!$additive) continue; $s = "\0other"; }
            $x = (string) $r[$xKey];
            $grid[$s][$x] = ($additive && isset($grid[$s][$x]) ? $grid[$s][$x] : 0) + (float) $r[$vKey];
            if (!$additive) $grid[$s][$x] = (float) $r[$vKey];
        }

        // Too many categories: keep the most recent (time) or the top ones (+ "Other").
        $cap = self::CHART_MAX_CATS[$type];
        if (count($catKeys) > $cap) {
            if ($xIsTime) {
                $notes[] = 'Showing the most recent ' . $cap . ' of ' . count($catKeys) . '.';
                $catKeys = array_slice($catKeys, -$cap);
            } else {
                $rest = array_slice($catKeys, $cap - 1);
                $catKeys = array_slice($catKeys, 0, $cap - 1);
                if ($additive) {
                    $catKeys[] = "\0other";
                    foreach ($grid as $s => $vals) foreach ($rest as $x) if (isset($vals[$x])) $grid[$s]["\0other"] = ($grid[$s]["\0other"] ?? 0) + $vals[$x];
                }
                $notes[] = count($rest) . ' more ' . ($additive ? 'grouped as Other.' : 'not shown.');
            }
        }

        $label = function ($colKey, $k) use ($cols, $bucket, $xKey) {
            if ($k === "\0other") return 'Other';
            if ($colKey === $xKey && $bucket) return self::bucketLabel($k, $bucket);
            if ($k === '') return '—';
            $c = $cols[$colKey];
            if ($c['type'] === 'date' || $c['type'] === 'datetime') return ($t = strtotime($k)) ? date('M j', $t) : $k;
            return html_entity_decode(strip_tags($this->cell($c, $k, 'html')), ENT_QUOTES, 'UTF-8');
        };
        $cats = array_map(function ($k) use ($label, $xKey) { return $label($xKey, $k); }, $catKeys);
        $series = [];
        foreach ($seriesKeys as $s) {
            $vals = [];
            foreach ($catKeys as $x) $vals[] = isset($grid[$s][$x]) ? $grid[$s][$x] : ($additive ? 0 : null);
            $series[] = ['name' => $s === '' ? $cols[$vKey]['label'] : ($s === "\0other" ? 'Other' : $label($sKey, $s)), 'values' => $vals];
        }
        $fmt = function ($v) { return self::chartNum($v); };
        $h = function ($s) { return self::h($s); };

        // The chart itself.
        if ($type === 'bar' || !RbChart::available()) {
            if ($type !== 'bar') $notes[] = 'Chart images need the PHP GD extension — showing bars instead.';
            $items = [];
            foreach ($cats as $i => $c) $items[] = [$c, $series[0]['values'][$i] ?? 0];
            $out .= RbChart::barsHtml($items, $fmt, $h);
        } else {
            $height = (int) ($b['height'] ?? 280);
            if (!in_array($height, [200, 280, 360], true)) $height = 280;
            $png = RbChart::png($type, $cats, $series, $fmt, $height);
            $cid = 'rbchart' . (count($this->images) + 1) . '_' . substr(md5($png), 0, 10);
            $this->images[] = ['cid' => $cid, 'name' => $cid . '.png', 'content' => $png, 'type' => 'image/png'];
            $src = $this->o['mode'] === 'email' ? 'cid:' . $cid : 'data:image/png;base64,' . base64_encode($png);
            $out .= '<img src="' . $src . '" width="' . RbChart::DISPLAY_W . '" alt="' . self::h($this->chartAlt($type, $cols[$vKey]['label'], $cols[$xKey]['label'], $cats, $series)) . '"'
                  . ' style="display:block;width:100%;max-width:' . RbChart::DISPLAY_W . 'px;height:auto;border:0;margin:0 0 6px;">';
        }
        if (count($series) > 1) {
            $out .= '<p style="margin:0 0 8px;font-size:12px;color:#52514e;">';
            foreach ($series as $i => $s) {
                $out .= '<span style="white-space:nowrap;margin-right:14px;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' . RbChart::color($i, $s['name']) . ';margin-right:5px;vertical-align:-1px;"></span>' . self::h($s['name']) . '</span> ';
            }
            $out .= '</p>';
        }
        foreach ($notes as $n) $out .= '<p style="margin:0 0 6px;font-size:11px;color:#9ca3af;">' . self::h($n) . '</p>';

        // Data table twin (accessibility + exact numbers) and CSV.
        $tCols = ['x' => ['key' => 'x', 'label' => $cols[$xKey]['label'], 'type' => 'text', 'field' => null]];
        foreach ($series as $i => $s) $tCols['s' . $i] = ['key' => 's' . $i, 'label' => $s['name'], 'type' => 'number', 'field' => null];
        $tRows = [];
        foreach ($cats as $ci => $c) {
            $r = ['x' => $c];
            foreach ($series as $i => $s) $r['s' . $i] = $s['values'][$ci];
            $tRows[] = $r;
        }
        if (!array_key_exists('show_table', $b) || $b['show_table']) $out .= $this->tableHtml($tCols, $tRows);
        else $out .= '<div style="height:16px;line-height:16px;">&nbsp;</div>';
        $this->addCsv($b, $tCols, $tRows);
        return $out;
    }

    private function chartAlt($type, $vLabel, $xLabel, array $cats, array $series) {
        $vals = $series[0]['values'];
        $max = null; $at = null;
        foreach ($vals as $i => $v) if ($v !== null && ($max === null || $v > $max)) { $max = $v; $at = $cats[$i]; }
        return ucfirst($type) . " chart of $vLabel by $xLabel" . (count($series) > 1 ? ', ' . count($series) . ' series' : '')
             . ($at !== null ? ". Highest: $at (" . self::chartNum($max) . ')' : '') . '. Exact values are in the table below.';
    }

    private static function chartNum($v) {
        if ($v === null) return '';
        $f = (float) $v;
        if (floor($f) == $f) return number_format($f);
        return rtrim(rtrim(number_format($f, 2), '0'), '.');
    }

    private static function bucketLabel($k, $bucket) {
        switch ($bucket) {
            case 'month': return ($t = strtotime($k . '-01')) ? date('M Y', $t) : $k;
            case 'year':  return (string) $k;
            default:      return ($t = strtotime($k)) ? date('M j', $t) : $k;   // day, week (Monday)
        }
    }

    /**
     * Fill gaps so the time axis is even: from the date range start (or the first
     * bucket) to the range end (or the last bucket).
     */
    private static function fillTime(array $keys, $bucket, $from = null, $to = null) {
        $parse = function ($k) use ($bucket) { return \DateTime::createFromFormat('!Y-m-d', $bucket === 'month' ? $k . '-01' : $k); };
        $norm = function (\DateTime $d) use ($bucket) {
            $d = (clone $d)->setTime(0, 0);
            if ($bucket === 'week') $d->modify('-' . ((int) $d->format('N') - 1) . ' days');   // Monday, like the SQL bucket
            if ($bucket === 'month') $d->modify('first day of this month');
            return $d;
        };
        $a = $from ? $norm($from) : ($keys ? $parse($keys[0]) : null);
        $z = $to ? $norm($to) : ($keys ? $parse(end($keys)) : null);
        if ($keys && $a && ($k0 = $parse($keys[0])) && $k0 < $a) $a = $k0;
        if ($keys && $z && ($kn = $parse(end($keys))) && $kn > $z) $z = $kn;
        if (!$a || !$z || $a > $z) return $keys;
        $step = ['day' => '+1 day', 'week' => '+7 days', 'month' => '+1 month'][$bucket];
        $fmt = $bucket === 'month' ? 'Y-m' : 'Y-m-d';
        $out = [];
        for ($d = clone $a, $n = 0; $d <= $z && $n < 1000; $d->modify($step), $n++) $out[] = $d->format($fmt);
        return count($out) >= count($keys) && !array_diff($keys, $out) ? $out : $keys;
    }

    private function block_text(array $b) {
        $style = in_array($b['style'] ?? 'normal', self::TEXT_STYLES, true) ? ($b['style'] ?? 'normal') : 'normal';
        $body = nl2br($this->text(mb_substr((string) ($b['body'] ?? ''), 0, self::MAX_TEXT)), false);
        if ($body === '') return '';
        switch ($style) {
            case 'alert':   return '<div style="border-left:3px solid #dc2626;background:#fef2f2;padding:10px 14px;margin-bottom:14px;"><p style="margin:0;font-size:13px;color:#991b1b;font-weight:600;">' . $body . '</p></div>';
            case 'info':    return '<div style="border-left:3px solid #2563eb;background:#eff6ff;padding:10px 14px;margin-bottom:14px;"><p style="margin:0;font-size:13px;color:#1e40af;">' . $body . '</p></div>';
            case 'success': return '<p style="text-align:center;color:#15803d;font-size:14px;padding:12px 0;font-weight:600;">' . $body . '</p>';
            case 'muted':   return '<p style="margin:0 0 14px;font-size:12px;color:#6b7280;">' . $body . '</p>';
            case 'footer':  return '<p style="margin:0;font-size:11px;color:#9ca3af;">' . $body . '</p>';
            default:        return '<p style="margin:0 0 14px;font-size:14px;line-height:1.5;">' . $body . '</p>';
        }
    }

    private function block_buttons(array $b) {
        $out = '';
        foreach ((array) ($b['buttons'] ?? []) as $btn) {
            $url = $this->url($btn['url'] ?? '');
            if ($url === null) continue;
            $p = $this->o['primary'];
            $style = ($btn['style'] ?? 'primary') === 'secondary'
                ? "display:inline-block;background:#fff;color:$p;border:1px solid $p;padding:10px 24px;text-decoration:none;font-weight:700;font-size:13px;"
                : "display:inline-block;background:$p;color:#fff;padding:11px 24px;text-decoration:none;font-weight:700;font-size:13px;margin-right:10px;";
            $out .= '<a href="' . self::h($url) . '" style="' . $style . '">' . $this->text($btn['label'] ?? 'Open') . '</a>';
        }
        return $out === '' ? '' : '<div style="margin:8px 0 4px;">' . $out . '</div>';
    }

    // ── data ────────────────────────────────────────────────────────────────

    private function dataset(array $b) {
        $key = $b['dataset'] ?? null;
        $ds = is_string($key) ? RbRegistry::get($key) : null;
        if (!$ds) throw new RbQueryException("Unknown dataset '" . self::clip($key) . "'.");
        return $ds;
    }

    private function blockQuery(array $b) {
        $ds = $this->dataset($b);
        $spec = (array) ($b['query'] ?? []);
        $spec['filters'] = $this->withReportFilters($ds['key'], (array) ($spec['filters'] ?? []));
        $res = RbQuery::run($ds['key'], $spec, $this->o['ctx'], $this->o['executor'], $this->o['dialect']);
        return [$ds, $res];
    }

    private function metricValue(array $m) {
        $ds = $this->dataset($m);
        $spec = [
            'aggregates'  => [['fn' => $m['fn'] ?? 'count', 'field' => $m['field'] ?? null]],
            'filters'     => $this->withReportFilters($ds['key'], (array) ($m['filters'] ?? [])),
            'date_window' => $m['date_window'] ?? null,
        ];
        $res = RbQuery::run($ds['key'], $spec, $this->o['ctx'], $this->o['executor'], $this->o['dialect']);
        $row = $res['rows'][0] ?? [];
        $v = reset($row);
        return is_numeric($v) ? $v + 0 : 0;
    }

    private function withReportFilters($dataset_key, array $filters) {
        foreach ((array) ($this->layout['report_filters'] ?? []) as $f) {
            if (is_array($f) && ($f['dataset'] ?? null) === $dataset_key) {
                unset($f['dataset']);
                $filters[] = $f;
            }
        }
        return $filters;
    }

    /** [key => ['label', 'type', 'field' => dataset field|null]] for a query result. */
    private function columns(array $ds, array $res, array $b) {
        $labels = (array) ($b['labels'] ?? []);
        $cols = [];
        foreach ($res['columns'] as $key => $c) {
            $field = $ds['fields'][$key] ?? null;
            $cols[$key] = [
                'key'   => $key,
                'label' => isset($labels[$key]) && is_string($labels[$key]) ? mb_substr($labels[$key], 0, 100) : $c['label'],
                'type'  => $c['type'],
                'field' => ($field && $field['type'] === $c['type']) ? $field : null,
            ];
        }
        return $cols;
    }

    // ── formatting ──────────────────────────────────────────────────────────

    private function tableHtml(array $cols, array $rows) {
        $th = 'text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;font-size:11px;text-transform:uppercase;';
        $out = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-bottom:24px;"><tr style="background:#f8fafc;">';
        foreach ($cols as $c) {
            $align = $c['type'] === 'number' ? 'text-align:right;' : '';
            $out .= '<th style="' . $th . $align . '">' . self::h($c['label']) . '</th>';
        }
        $out .= '</tr>';
        foreach (array_values($rows) as $i => $r) {
            $out .= '<tr style="background:' . ($i % 2 === 0 ? '#fff' : '#f8fafc') . ';border-top:1px solid #f1f5f9;">';
            foreach ($cols as $key => $c) {
                $out .= '<td style="' . $this->cellStyle($c) . '">' . $this->cell($c, $r[$key] ?? null, 'html') . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</table>';
    }

    private function cellStyle(array $c) {
        if ($c['field'] && $c['field']['display'] === 'mono') return 'padding:8px 10px;font-weight:700;font-family:Courier New,monospace;font-size:12px;';
        if ($c['type'] === 'number') return 'padding:8px 10px;text-align:right;';
        if (in_array($c['type'], ['date', 'datetime'], true)) return 'padding:8px 10px;color:#6b7280;';
        return 'padding:8px 10px;';
    }

    /** Format one value. $mode 'html' returns escaped HTML; 'csv' returns plain text. */
    private function cell(array $c, $v, $mode) {
        $html = $mode === 'html';
        if ($v === null || $v === '') return $html ? '—' : '';
        switch ($c['type']) {
            case 'enum':
                if ($c['field']) {
                    $k = $c['field']['key'];
                    if (!isset($this->optCache[$k])) $this->optCache[$k] = RbRegistry::fieldOptions($c['field']) ?: [];
                    if (isset($this->optCache[$k][$v])) $v = $this->optCache[$k][$v];
                }
                break;
            case 'date':
                if ($html && ($t = strtotime($v)) !== false) $v = date('M j, Y', $t);
                break;
            case 'datetime':
                if ($html && ($t = strtotime($v)) !== false) $v = date('M j, g:i A', $t);
                break;
            case 'bool':
                $v = $v ? 'Yes' : 'No';
                break;
            case 'number':
                $v = self::num($v);
                break;
        }
        return $html ? self::h($v) : (string) $v;
    }

    private function addCsv(array $b, array $cols, array $rows) {
        if (array_key_exists('csv', $b) && !$b['csv']) return;
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, array_column($cols, 'label'), ',', '"', '');
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $key => $c) {
                $s = $this->cell($c, $r[$key] ?? null, 'csv');
                // Stop spreadsheet apps treating a value as a formula.
                if ($s !== '' && strpbrk($s[0], '=+-@') !== false && !is_numeric($s)) $s = "'" . $s;
                $line[] = $s;
            }
            fputcsv($fh, $line, ',', '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($b['title'] ?? '') ?: 'report'), '_')) ?: 'report';
        $name = $base . '_' . $this->o['now']->format('Y-m-d') . '.csv';
        $n = 2;
        while (in_array($name, array_column($this->attachments, 'name'), true)) {
            $name = $base . '_' . $n++ . '_' . $this->o['now']->format('Y-m-d') . '.csv';
        }
        $this->attachments[] = ['name' => $name, 'content' => $csv, 'type' => 'text/csv'];
    }

    private function heading($title) {
        if ((string) $title === '') return '';
        return '<h3 style="margin:0 0 12px;font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">' . $this->text($title) . '</h3>';
    }

    // ── conditions, tokens, subject ─────────────────────────────────────────

    private function condition($c) {
        if (!is_array($c)) throw new RbQueryException('Invalid show-if condition.');
        $key = $c['metric'] ?? null;
        if (!is_string($key) || !array_key_exists($key, $this->metrics)) throw new RbQueryException("Condition uses unknown metric '" . self::clip($key) . "'.");
        $op = $c['op'] ?? 'gt';
        if (!in_array($op, self::COND_OPS, true)) throw new RbQueryException("Unknown condition '" . self::clip($op) . "'.");
        $a = $this->metrics[$key];
        $v = is_numeric($c['value'] ?? null) ? $c['value'] + 0 : 0;
        switch ($op) {
            case 'eq':  return $a == $v;
            case 'neq': return $a != $v;
            case 'gt':  return $a > $v;
            case 'gte': return $a >= $v;
            case 'lt':  return $a < $v;
            default:    return $a <= $v;
        }
    }

    private function subject() {
        foreach ((array) ($this->layout['subject'] ?? []) as $rule) {
            if (!is_array($rule) || !isset($rule['text'])) continue;
            if (isset($rule['if']) && !$this->condition($rule['if'])) continue;
            return mb_substr(trim(preg_replace('/\s+/u', ' ', $this->tokens((string) $rule['text']))), 0, 200);
        }
        return $this->o['report_name'] . ' — ' . $this->o['now']->format('M j, Y');
    }

    /** Escape, then replace tokens. */
    private function text($s) {
        return $this->tokens(self::h((string) $s), true);
    }

    private function tokens($s, $escaped = false) {
        $now = $this->o['now'];
        return preg_replace_callback('/\{(s:)?([a-z][a-z0-9_]*)\}/', function ($m) use ($now, $escaped) {
            $plural = $m[1] !== '';
            $key = $m[2];
            if ($plural) {
                return array_key_exists($key, $this->metrics) ? ($this->metrics[$key] == 1 ? '' : 's') : $m[0];
            }
            switch ($key) {
                case 'date':        $v = $now->format('l, F j, Y'); break;
                case 'date_short':  $v = $now->format('M j, Y'); break;
                case 'time':        $v = $now->format('g:i A'); break;
                case 'report_name': $v = $this->o['report_name']; break;
                case 'brand':       $v = $this->o['brand']; break;
                default:
                    if (!array_key_exists($key, $this->metrics)) return $m[0];
                    $v = self::num($this->metrics[$key]);
            }
            return $escaped ? self::h($v) : $v;
        }, $s);
    }

    private function url($u) {
        $u = trim((string) $u);
        if ($u === '') return null;
        if (preg_match('#^https?://[^\s"\'<>]+$#i', $u)) return $u;
        if (preg_match('#^/?[A-Za-z0-9_\-./?=&%\#]+$#', $u) && strpos($u, '//') === false) {
            return $this->o['base_url'] . '/' . ltrim($u, '/');
        }
        return null; // javascript:, data:, protocol-relative, etc.
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private static function h($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function num($v) {
        if (!is_numeric($v)) return (string) $v;
        $f = $v + 0;
        return is_float($f) && floor($f) != $f ? number_format($f, 2) : (string) (int) round($f);
    }

    private static function isColor($c) {
        return is_string($c) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c);
    }

    private static function clip($v) {
        $s = is_scalar($v) ? (string) $v : gettype($v);
        return htmlspecialchars(mb_substr($s, 0, 40), ENT_QUOTES, 'UTF-8');
    }
}
}
