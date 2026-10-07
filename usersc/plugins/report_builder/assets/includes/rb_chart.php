<?php
/**
 * Report Builder — chart drawing.
 *
 * bar     → plain HTML table bars (renders in every email client, incl. Outlook)
 * column  → PNG (GD)
 * line    → PNG (GD)
 *
 * PNGs are drawn at 4× and downsampled to 2× the display size, so edges and
 * text are smooth and stay sharp on high-density screens. Specs follow the
 * plugin's chart rules: categorical colours in fixed order (validated for
 * colour-blind separation on white), ≤24px bars with a 4px rounded data end,
 * 2px lines with ringed end dots, hairline gridlines, clean axis numbers,
 * x labels thinned rather than overlapped, text in ink colours (never the
 * series colour). Identity never relies on colour alone: ≥2 series get an
 * HTML legend, and the renderer adds a data table under each chart.
 */
if (count(get_included_files()) == 1) die();

if (!class_exists('RbChart')) {
class RbChart {
    /** Categorical slots, fixed order (validated: CVD ΔE ≥ 9.1, normal ≥ 19.6 on white). */
    const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
    const OTHER  = '#9a9893';   // folded "Other" series — de-emphasised gray
    const MAX_SERIES = 6;
    const INK = '#1f2937'; const INK2 = '#52514e'; const MUTED = '#898781';
    const GRID = '#e5e7eb'; const AXIS = '#c3c2b7'; const SURFACE = '#ffffff';
    const DISPLAY_W = 596;

    public static function available() {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    /** Series colour by position (entity order is decided by the caller). */
    public static function color($i, $name = null) {
        if ($name === 'Other') return self::OTHER;
        return self::SERIES[$i % count(self::SERIES)];
    }

    // ── HTML bar chart ──────────────────────────────────────────────────────

    /** $items: [[label, value]], one series. Values ≥ 0 draw; negatives draw as 0-length with the value shown. */
    public static function barsHtml(array $items, callable $fmt, $h) {
        $max = 0;
        foreach ($items as $it) $max = max($max, (float) $it[1]);
        $out = '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;font-size:12px;margin-bottom:8px;">';
        foreach ($items as $it) {
            $pct = $max > 0 ? max(0, min(100, round((float) $it[1] / $max * 100, 1))) : 0;
            $bar = $pct > 0
                ? '<td width="' . $pct . '%" style="background:' . self::SERIES[0] . ';height:14px;line-height:14px;font-size:0;border-radius:0 4px 4px 0;">&nbsp;</td>'
                : '';
            $out .= '<tr>'
                  . '<td style="width:34%;padding:4px 10px 4px 0;color:' . self::INK2 . ';text-align:right;vertical-align:middle;">' . $h($it[0]) . '</td>'
                  . '<td style="padding:4px 0;vertical-align:middle;"><table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;"><tr>'
                  . $bar
                  . '<td style="padding-left:6px;white-space:nowrap;color:' . self::INK . ';font-size:12px;">' . $h($fmt($it[1])) . '</td>'
                  . '</tr></table></td></tr>';
        }
        return $out . '</table>';
    }

    // ── PNG charts ──────────────────────────────────────────────────────────

    /**
     * $type 'column' | 'line'. $cats: x labels. $series: [['name' => , 'values' => [float|null per cat]]].
     * $fmt formats numbers for axis/labels. Returns PNG bytes (DISPLAY_W×$height CSS px at 2×).
     */
    public static function png($type, array $cats, array $series, callable $fmt, $height = 280) {
        $S = 4;                                   // supersample factor (drawn at 4×, saved at 2×)
        $W = self::DISPLAY_W * $S; $H = (int) $height * $S;
        $im = imagecreatetruecolor($W, $H);
        imagefill($im, 0, 0, self::col($im, self::SURFACE));
        $font = self::font();
        $fs = 11 * $S * 0.75;                     // 11 CSS px → points

        // Y scale (always includes 0).
        $vals = [];
        foreach ($series as $s) foreach ($s['values'] as $v) if ($v !== null) $vals[] = (float) $v;
        $lo = min(0, $vals ? min($vals) : 0); $hi = max(0, $vals ? max($vals) : 0);
        if ($hi == $lo) $hi = $lo + 1;
        $whole = true;
        foreach ($vals as $v) if (floor($v) != $v) { $whole = false; break; }
        [$lo, $hi, $step] = self::niceScale($lo, $hi, $whole);
        $ticks = [];
        for ($t = $lo; $t <= $hi + $step / 2; $t += $step) $ticks[] = round($t, 10);

        // Plot box.
        $labelW = 0;
        foreach ($ticks as $t) $labelW = max($labelW, self::textW($font, $fs, $fmt($t)));
        $endLabelW = 0;
        if ($type === 'line') foreach ($series as $s) {
            $last = self::lastValue($s['values']);
            if ($last !== null) $endLabelW = max($endLabelW, self::textW($font, $fs, $fmt($last)));
        }
        $L = $labelW + 10 * $S; $R = $W - max(14 * $S, $endLabelW + 18 * $S);
        $T = 12 * $S; $B = $H - 30 * $S;
        $y = function ($v) use ($lo, $hi, $T, $B) { return $B - ($v - $lo) / ($hi - $lo) * ($B - $T); };

        // Gridlines + y labels (hairline, solid, recessive).
        foreach ($ticks as $t) {
            $yy = (int) round($y($t));
            imagefilledrectangle($im, $L, $yy - (int) ($S / 2), $R, $yy + (int) ($S / 2) - 1, self::col($im, $t == 0 ? self::AXIS : self::GRID));
            self::text($im, $font, $fs, $L - 8 * $S, $yy, $fmt($t), self::MUTED, 'right');
        }

        $n = max(1, count($cats));
        $slot = ($R - $L) / $n;
        $xc = function ($i) use ($L, $slot) { return $L + $slot * ($i + 0.5); };

        // X labels, thinned to avoid collisions.
        $maxLW = 0;
        foreach ($cats as $c) $maxLW = max($maxLW, self::textW($font, $fs, $c));
        $every = max(1, (int) ceil(($maxLW + 12 * $S) / max(1, $slot)));
        $shown = -1;
        foreach ($cats as $i => $c) {
            // Every $every-th label; the last point on a line also gets one if it clears the previous label.
            $lastFits = $i === $n - 1 && $type === 'line' && $shown >= 0 && ($xc($i) - $xc($shown)) >= $maxLW + 12 * $S;
            if ($i % $every !== 0 && !$lastFits) continue;
            self::text($im, $font, $fs, (int) $xc($i), $B + 16 * $S, $c, self::MUTED, 'center');
            $shown = $i;
        }

        if ($type === 'column') {
            $k = count($series);
            $gap = 2 * $S;
            $barW = min(24 * $S, ($slot * 0.72 - $gap * ($k - 1)) / $k);
            $barW = max(2 * $S, $barW);
            $groupW = $barW * $k + $gap * ($k - 1);
            $zero = $y(0);
            foreach ($cats as $i => $c) {
                $x0 = $xc($i) - $groupW / 2;
                foreach ($series as $si => $s) {
                    $v = $s['values'][$i] ?? null;
                    if ($v === null || $v == 0) continue;
                    $x1 = $x0 + $si * ($barW + $gap);
                    self::roundBar($im, $x1, $x1 + $barW, $zero, $y($v), 4 * $S, self::col($im, self::color($si, $s['name'])));
                }
            }
            // Value on the cap: single series, few columns only (selective labels).
            if ($k === 1 && $n <= 12) foreach ($cats as $i => $c) {
                $v = $series[0]['values'][$i] ?? null;
                if ($v === null) continue;
                self::text($im, $font, $fs, (int) $xc($i), (int) ($y(max(0, $v)) - 9 * $S), $fmt($v), self::INK2, 'center');
            }
        } else {
            $th = 2 * $S;
            $ends = [];
            foreach ($series as $si => $s) {
                $c = self::col($im, self::color($si, $s['name']));
                $pts = [];
                foreach ($s['values'] as $i => $v) {
                    if ($v === null) { self::polyline($im, $pts, $th, $c); $pts = []; continue; }
                    $pts[] = [$xc($i), $y($v)];
                }
                self::polyline($im, $pts, $th, $c);
                $li = self::lastIndex($s['values']);
                if ($li !== null) $ends[] = [$xc($li), $y($s['values'][$li]), $c, $s['values'][$li]];
            }
            // End dots: r = 4px, 2px surface ring.
            foreach ($ends as $e) {
                imagefilledellipse($im, (int) $e[0], (int) $e[1], 12 * $S, 12 * $S, self::col($im, self::SURFACE));
                imagefilledellipse($im, (int) $e[0], (int) $e[1], 8 * $S, 8 * $S, $e[2]);
            }
            // End labels only when they don't collide; otherwise the legend + table carry it.
            $ys = array_map(function ($e) { return $e[1]; }, $ends);
            sort($ys);
            $clear = true;
            for ($i = 1; $i < count($ys); $i++) if ($ys[$i] - $ys[$i - 1] < 14 * $S) $clear = false;
            if ($clear) foreach ($ends as $e) self::text($im, $font, $fs, (int) ($e[0] + 10 * $S), (int) $e[1], $fmt($e[3]), self::INK2, 'left');
        }

        // Downsample 4× → 2×.
        $out = imagecreatetruecolor($W / 2, $H / 2);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $W / 2, $H / 2, $W, $H);
        imagedestroy($im);
        ob_start();
        imagepng($out, null, 9);
        imagedestroy($out);
        return ob_get_clean();
    }

    // ── drawing helpers ─────────────────────────────────────────────────────

    /** Bar from baseline $yb to value $yv; rounded on the data end only. */
    private static function roundBar($im, $x1, $x2, $yb, $yv, $r, $c) {
        $x1 = (int) round($x1); $x2 = (int) round($x2);
        $top = (int) round(min($yb, $yv)); $bot = (int) round(max($yb, $yv));
        $r = (int) min($r, ($x2 - $x1) / 2, $bot - $top);
        $up = $yv < $yb;
        if ($r < 1) { imagefilledrectangle($im, $x1, $top, $x2, $bot, $c); return; }
        if ($up) {
            imagefilledrectangle($im, $x1, $top + $r, $x2, $bot, $c);
            imagefilledrectangle($im, $x1 + $r, $top, $x2 - $r, $top + $r, $c);
            imagefilledellipse($im, $x1 + $r, $top + $r, 2 * $r, 2 * $r, $c);
            imagefilledellipse($im, $x2 - $r, $top + $r, 2 * $r, 2 * $r, $c);
        } else {
            imagefilledrectangle($im, $x1, $top, $x2, $bot - $r, $c);
            imagefilledrectangle($im, $x1 + $r, $bot - $r, $x2 - $r, $bot, $c);
            imagefilledellipse($im, $x1 + $r, $bot - $r, 2 * $r, 2 * $r, $c);
            imagefilledellipse($im, $x2 - $r, $bot - $r, 2 * $r, 2 * $r, $c);
        }
    }

    /** Thick polyline with round joins/caps (segments as polygons + discs at the points). */
    private static function polyline($im, array $pts, $th, $c) {
        $r = $th / 2;
        for ($i = 1; $i < count($pts); $i++) {
            [$ax, $ay] = $pts[$i - 1]; [$bx, $by] = $pts[$i];
            $dx = $bx - $ax; $dy = $by - $ay; $len = sqrt($dx * $dx + $dy * $dy) ?: 1;
            $nx = -$dy / $len * $r; $ny = $dx / $len * $r;
            imagefilledpolygon($im, [(int) ($ax + $nx), (int) ($ay + $ny), (int) ($bx + $nx), (int) ($by + $ny),
                                     (int) ($bx - $nx), (int) ($by - $ny), (int) ($ax - $nx), (int) ($ay - $ny)], $c);
        }
        foreach ($pts as $p) imagefilledellipse($im, (int) $p[0], (int) $p[1], (int) $th, (int) $th, $c);
    }

    private static function text($im, $font, $size, $x, $y, $s, $hex, $align) {
        $c = self::col($im, $hex);
        $s = (string) $s;
        if ($font) {
            $w = self::textW($font, $size, $s);
            $x = $align === 'right' ? $x - $w : ($align === 'center' ? $x - $w / 2 : $x);
            imagettftext($im, $size, 0, (int) $x, (int) ($y + $size * 0.45), $c, $font, $s);
        } else {
            // No FreeType: GD's built-in font, scaled up.
            $tw = imagefontwidth(3) * strlen($s); $th = imagefontheight(3);
            $tmp = imagecreatetruecolor(max(1, $tw), $th);
            imagefill($tmp, 0, 0, self::col($tmp, self::SURFACE));
            imagestring($tmp, 3, 0, 0, $s, self::col($tmp, $hex));
            $k = 3; $w = $tw * $k;
            $x = $align === 'right' ? $x - $w : ($align === 'center' ? $x - $w / 2 : $x);
            imagecopyresampled($im, $tmp, (int) $x, (int) ($y - $th * $k / 2), 0, 0, $w, $th * $k, $tw, $th);
            imagedestroy($tmp);
        }
    }

    private static function textW($font, $size, $s) {
        if (!$font) return imagefontwidth(3) * strlen((string) $s) * 3;
        $b = imagettfbbox($size, 0, $font, (string) $s);
        return $b ? abs($b[2] - $b[0]) : 0;
    }

    private static function font() {
        $f = dirname(__DIR__) . '/fonts/DejaVuSans.ttf';
        return function_exists('imagettftext') && is_file($f) ? $f : null;
    }

    private static function col($im, $hex) {
        $hex = ltrim($hex, '#');
        return imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    /** Clean axis: 4–6 ticks on 1/2/2.5/5 × 10^n steps. */
    public static function niceScale($lo, $hi, $wholeNumbers = false) {
        $raw = ($hi - $lo) / 4;
        $mag = pow(10, floor(log10($raw)));
        $step = $mag;
        foreach ([1, 2, 2.5, 5, 10] as $m) { if ($m * $mag >= $raw) { $step = $m * $mag; break; } }
        if ($wholeNumbers) $step = max(1, ceil($step));   // counts: no 0.5 / 2.5 ticks
        return [floor($lo / $step) * $step, ceil($hi / $step) * $step, $step];
    }

    private static function lastIndex(array $vals) {
        for ($i = count($vals) - 1; $i >= 0; $i--) if ($vals[$i] !== null) return $i;
        return null;
    }
    private static function lastValue(array $vals) {
        $i = self::lastIndex($vals);
        return $i === null ? null : $vals[$i];
    }
}
}
