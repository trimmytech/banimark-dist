<?php

namespace Banimark\Ui;

/**
 * Hand-rolled SVG/HTML charts - no charting library, no build step, works
 * offline inside any host app. Data colours come from the validated
 * categorical palette exposed as CSS custom properties (--s1..--s3), so light
 * and dark mode swap in one place and the brand purple never encodes data.
 *
 * Every form here ships its hover layer or its direct labels: a magnitude bar
 * always shows its value, so the palette's low-contrast slot never has to be
 * read by colour alone.
 */
class Chart
{
    private static int $seq = 0;

    /**
     * Area chart over time. One series needs no legend (the card title names
     * it); two get a legend plus a shared crosshair tooltip.
     *
     * @param string[] $labels x labels, one per point
     * @param array<int, array{name: string, color: string, values: array<int, int|float>}> $series
     */
    public static function area(array $labels, array $series, int $h = 190): string
    {
        $n = count($labels);
        if ($n === 0 || $series === []) {
            return self::empty('No activity yet');
        }
        $id = 'c'.(++self::$seq);
        $w = 720;
        $padL = 36; $padR = 10; $padT = 12; $padB = 24;
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;

        $max = 0;
        foreach ($series as $s) {
            $max = max($max, max($s['values'] ?: [0]));
        }
        $max = self::niceMax($max);
        $x = fn (int $i) => $padL + ($n <= 1 ? $plotW / 2 : ($i * $plotW / ($n - 1)));
        $y = fn ($v) => $padT + $plotH - ($max > 0 ? ($v / $max) * $plotH : 0);

        $svg = '<svg class="chart" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" role="img" height="'.$h.'">';
        $svg .= '<defs>';
        foreach ($series as $k => $s) {
            $svg .= '<linearGradient id="'.$id.'g'.$k.'" x1="0" x2="0" y1="0" y2="1">'
                .'<stop offset="0%" stop-color="'.self::e($s['color']).'" stop-opacity=".26"/>'
                .'<stop offset="100%" stop-color="'.self::e($s['color']).'" stop-opacity="0"/></linearGradient>';
        }
        $svg .= '</defs>';

        // recessive grid + y axis labels
        $svg .= '<g class="grid">';
        for ($g = 0; $g <= 3; $g++) {
            $gy = $padT + ($plotH * $g / 3);
            $svg .= '<line x1="'.$padL.'" x2="'.($w - $padR).'" y1="'.round($gy, 1).'" y2="'.round($gy, 1).'"/>';
        }
        $svg .= '</g><g class="axis">';
        for ($g = 0; $g <= 3; $g++) {
            $val = $max - ($max * $g / 3);
            $gy = $padT + ($plotH * $g / 3);
            $svg .= '<text x="'.($padL - 8).'" y="'.round($gy + 3.5, 1).'" text-anchor="end">'.self::num($val).'</text>';
        }
        // x labels: a handful, evenly spaced, so they never collide
        $step = max(1, (int) ceil($n / 5));
        for ($i = 0; $i < $n; $i += $step) {
            $svg .= '<text x="'.round($x($i), 1).'" y="'.($h - 6).'" text-anchor="middle">'.self::e($labels[$i]).'</text>';
        }
        $svg .= '</g>';

        foreach ($series as $k => $s) {
            $line = ''; $areaPath = '';
            foreach ($s['values'] as $i => $v) {
                $px = round($x($i), 1); $py = round($y($v), 1);
                $line .= ($i === 0 ? 'M' : 'L').$px.' '.$py.' ';
            }
            $areaPath = $line.'L'.round($x($n - 1), 1).' '.($padT + $plotH).' L'.round($x(0), 1).' '.($padT + $plotH).' Z';
            $svg .= '<path d="'.$areaPath.'" fill="url(#'.$id.'g'.$k.')"/>';
            $svg .= '<path d="'.trim($line).'" fill="none" stroke="'.self::e($s['color']).'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>';
        }

        // hover targets: one full-height column per point, wider than the mark
        $colW = $n > 1 ? $plotW / ($n - 1) : $plotW;
        for ($i = 0; $i < $n; $i++) {
            $parts = [];
            foreach ($series as $s) {
                $parts[] = self::e($s['name']).': '.self::num($s['values'][$i] ?? 0);
            }
            $tip = self::e($labels[$i]).' &middot; '.implode(' &middot; ', $parts);
            $svg .= '<g class="hv" data-tip="'.$tip.'">';
            $svg .= '<rect x="'.round($x($i) - $colW / 2, 1).'" y="'.$padT.'" width="'.round($colW, 1).'" height="'.$plotH.'" fill="transparent"/>';
            $svg .= '<line class="cross" x1="'.round($x($i), 1).'" x2="'.round($x($i), 1).'" y1="'.$padT.'" y2="'.($padT + $plotH).'" stroke="var(--muted)" stroke-width="1" stroke-dasharray="3 3" opacity="0"/>';
            foreach ($series as $s) {
                $svg .= '<circle class="dot" cx="'.round($x($i), 1).'" cy="'.round($y($s['values'][$i] ?? 0), 1).'" r="4.5" fill="'.self::e($s['color']).'" opacity="0"/>';
            }
            $svg .= '</g>';
        }
        $svg .= '</svg>';

        $legend = '';
        if (count($series) > 1) {
            $legend = '<div class="legend">';
            foreach ($series as $s) {
                $legend .= '<span><i style="background:'.self::e($s['color']).'"></i>'.self::e($s['name']).'</span>';
            }
            $legend .= '</div>';
        }
        return '<div class="chart-wrap">'.$svg.$legend.'</div>';
    }

    /** Tiny trend line for a stat tile. Decorative support for the number beside it. */
    public static function spark(array $values, string $color = 'var(--s1)', int $w = 96, int $h = 26): string
    {
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $max = max($values); $min = min($values);
        $range = ($max - $min) ?: 1;
        $d = '';
        foreach ($values as $i => $v) {
            $px = round($i * $w / ($n - 1), 1);
            $py = round($h - 2 - (($v - $min) / $range) * ($h - 4), 1);
            $d .= ($i === 0 ? 'M' : 'L').$px.' '.$py.' ';
        }
        return '<svg class="spark" viewBox="0 0 '.$w.' '.$h.'" width="'.$w.'" height="'.$h.'" aria-hidden="true">'
            .'<path d="'.trim($d).'" fill="none" stroke="'.self::e($color).'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    /**
     * Part-to-whole as ONE stacked bar with a labelled legend - clearer than a
     * donut at this size, and every segment carries its own number.
     *
     * @param array<int, array{name: string, value: int, color: string}> $segments
     */
    public static function stack(array $segments): string
    {
        $total = 0;
        foreach ($segments as $s) {
            $total += $s['value'];
        }
        if ($total <= 0) {
            return self::empty('Nothing to split yet');
        }
        $bar = '<div style="display:flex;gap:2px;height:12px;border-radius:100px;overflow:hidden;margin:14px 0 4px;">';
        foreach ($segments as $s) {
            if ($s['value'] <= 0) {
                continue;
            }
            $pct = ($s['value'] / $total) * 100;
            $bar .= '<div title="'.self::e($s['name']).'" style="width:'.round($pct, 2).'%;background:'.self::e($s['color'])
                .';animation:growX .5s var(--ease) both;transform-origin:left;"></div>';
        }
        $bar .= '</div><div class="legend">';
        foreach ($segments as $s) {
            $pct = $total > 0 ? round(($s['value'] / $total) * 100) : 0;
            $bar .= '<span><i style="background:'.self::e($s['color']).'"></i>'.self::e($s['name'])
                .' <b style="color:var(--text);font-variant-numeric:tabular-nums;">'.self::num($s['value']).'</b>'
                .' <span style="color:var(--muted)">'.$pct.'%</span></span>';
        }
        return $bar.'</div>';
    }

    /**
     * Ranked magnitude bars. Each row is directly labelled with its value, so
     * the bar colour never has to carry the reading on its own.
     *
     * @param array<int, array{name: string, value: int}> $rows
     */
    public static function hbars(array $rows, string $color = 'var(--s1)', string $emptyText = 'Nothing recorded yet'): string
    {
        if ($rows === []) {
            return self::empty($emptyText);
        }
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, $r['value']);
        }
        $out = '<div class="hbar">';
        foreach ($rows as $i => $r) {
            $pct = $max > 0 ? ($r['value'] / $max) * 100 : 0;
            $out .= '<div class="row">'
                .'<span class="nm">'.self::e($r['name']).'</span>'
                .'<span class="vl">'.self::num($r['value']).'</span>'
                .'<span class="track"><span class="fill" style="width:'.round($pct, 1).'%;background:'.self::e($color)
                .';animation-delay:'.($i * 0.05).'s"></span></span></div>';
        }
        return $out.'</div>';
    }

    public static function empty(string $text, string $sub = ''): string
    {
        return '<div class="empty"><div class="ico">'
            .'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8">'
            .'<path d="M3 3v18h18" stroke-linecap="round"/><path d="M7 15l4-4 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            .'</div><b>'.self::e($text).'</b>'.($sub !== '' ? '<div>'.self::e($sub).'</div>' : '').'</div>';
    }

    private static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 3;
        }
        $mag = 10 ** floor(log10($max));
        foreach ([1, 1.5, 2, 3, 5, 7.5, 10] as $m) {
            if ($max <= $m * $mag) {
                return $m * $mag;
            }
        }
        return 10 * $mag;
    }

    private static function num(float $v): string
    {
        if ($v >= 1000000) {
            return rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.').'M';
        }
        if ($v >= 1000) {
            return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k';
        }
        return (string) (fmod($v, 1) == 0 ? (int) $v : round($v, 1));
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }
}
