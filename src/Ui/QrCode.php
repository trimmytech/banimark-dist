<?php

namespace Banimark\Ui;

/**
 * A QR code as inline SVG, with no library and no third-party image service:
 * the 2FA setup key must never leave the server, and a customer's CSP may
 * block any outside image anyway. Byte mode, error correction level M,
 * versions 1 to 10 (up to 213 bytes - an otpauth:// URI is well under),
 * the mask chosen by the standard's penalty rules. ISO/IEC 18004.
 */
final class QrCode
{
    /** Data codewords per version at level M, and the EC blocks (count, total codewords, data codewords). */
    private const VERSIONS = [
        1 => [16, [[1, 26, 16]]],
        2 => [28, [[1, 44, 28]]],
        3 => [44, [[1, 70, 44]]],
        4 => [64, [[2, 50, 32]]],
        5 => [86, [[2, 67, 43]]],
        6 => [108, [[4, 43, 27]]],
        7 => [124, [[4, 49, 31]]],
        8 => [154, [[2, 60, 38], [2, 61, 39]]],
        9 => [182, [[3, 58, 36], [2, 59, 37]]],
        10 => [216, [[4, 69, 43], [1, 70, 44]]],
    ];
    private const ALIGNMENT = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];
    private const VERSION_BITS = [7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3];

    /** @throws \InvalidArgumentException when the text does not fit */
    public static function svg(string $text, int $size = 180, string $dark = '#1b1f24', string $light = '#ffffff'): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $quiet = 4;
        $total = $n + 2 * $quiet;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $path .= 'M'.($x + $quiet).' '.($y + $quiet).'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$total.'" width="'.$size.'" height="'.$size.'" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="'.htmlspecialchars($light, ENT_QUOTES).'"/>'
            .'<path d="'.$path.'" fill="'.htmlspecialchars($dark, ENT_QUOTES).'"/></svg>';
    }

    /**
     * The module matrix: $m[y][x] = 1 for dark. Public so a test can decode it.
     *
     * @return array<int, array<int, int>>
     */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $len = count($bytes);
        $version = 0;
        foreach (self::VERSIONS as $v => [$capacity]) {
            // mode (4) + count (8 or 16) + data; the terminator may be shortened
            if (($v >= 10 ? 20 : 12) + $len * 8 <= $capacity * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new \InvalidArgumentException('Too much text for a QR code of this size (max 213 bytes).');
        }
        [$capacity, $blocks] = self::VERSIONS[$version];

        // ---- bit stream: mode, count, data, terminator, pad ----
        $bits = '0100'.str_pad(decbin($len), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        $data = [];
        foreach (str_split($bits, 8) as $chunk) {
            $data[] = bindec($chunk);
        }
        for ($i = 0; count($data) < $capacity; $i++) {
            $data[] = $i % 2 === 0 ? 0xEC : 0x11;
        }

        // ---- blocks, Reed-Solomon, interleave ----
        $dataBlocks = [];
        $ecBlocks = [];
        $pos = 0;
        foreach ($blocks as [$count, $total, $dataLen]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($data, $pos, $dataLen);
                $pos += $dataLen;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $total - $dataLen);
            }
        }
        $codewords = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $codewords[] = $block[$i];
                }
            }
        }
        $maxEc = max(array_map('count', $ecBlocks));
        for ($i = 0; $i < $maxEc; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $codewords[] = $block[$i];
                }
            }
        }

        // ---- the matrix: function patterns, then data, then the best mask ----
        $n = 17 + 4 * $version;
        $m = array_fill(0, $n, array_fill(0, $n, 0));
        $fixed = array_fill(0, $n, array_fill(0, $n, false));
        $set = function (int $x, int $y, int $v) use (&$m, &$fixed) { $m[$y][$x] = $v; $fixed[$y][$x] = true; };
        $finder = function (int $cx, int $cy) use ($set, $n) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
                        continue;
                    }
                    $d = max(abs($dx), abs($dy));
                    $set($x, $y, ($d === 2 || $d === 4) ? 0 : 1);
                }
            }
        };
        $finder(3, 3);
        $finder($n - 4, 3);
        $finder(3, $n - 4);
        foreach (self::ALIGNMENT[$version] as $cy) {
            foreach (self::ALIGNMENT[$version] as $cx) {
                if ($fixed[$cy][$cx]) {
                    continue; // overlaps a finder
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) === 1 ? 0 : 1);
                    }
                }
            }
        }
        for ($i = 8; $i < $n - 8; $i++) {
            $set($i, 6, $i % 2 === 0 ? 1 : 0);
            $set(6, $i, $i % 2 === 0 ? 1 : 0);
        }
        $set(8, $n - 8, 1); // the dark module
        // reserve the format areas (filled per mask below)
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $fixed[8][$i] = true;
                $fixed[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $fixed[8][$n - 1 - $i] = true;
            $fixed[$n - 1 - $i][8] = true;
        }
        if ($version >= 7) {
            $vb = self::VERSION_BITS[$version];
            for ($i = 0; $i < 18; $i++) {
                $bit = ($vb >> $i) & 1;
                $set(intdiv($i, 3), $n - 11 + $i % 3, $bit);
                $set($n - 11 + $i % 3, intdiv($i, 3), $bit);
            }
        }

        // data placement: two-module columns, zigzag, column 6 skipped
        $bitIndex = 0;
        $totalBits = count($codewords) * 8;
        $upward = true;
        for ($col = $n - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            for ($r = 0; $r < $n; $r++) {
                $y = $upward ? $n - 1 - $r : $r;
                foreach ([$col, $col - 1] as $x) {
                    if ($fixed[$y][$x]) {
                        continue;
                    }
                    $bit = $bitIndex < $totalBits ? ($codewords[intdiv($bitIndex, 8)] >> (7 - $bitIndex % 8)) & 1 : 0;
                    $m[$y][$x] = $bit;
                    $bitIndex++;
                }
            }
            $upward = !$upward;
        }

        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $c = self::applyMask($m, $fixed, $mask, $n);
            $score = self::penalty($c, $n);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }
        return $best;
    }

    private static function applyMask(array $m, array $fixed, int $mask, int $n): array
    {
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($fixed[$y][$x]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($flip) {
                    $m[$y][$x] ^= 1;
                }
            }
        }
        // format information: level M (00) + mask, BCH(15,5), xor 0x5412
        $f = $mask; // level M = 00 in the top bits
        $g = $f << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($g >> $i) & 1) {
                $g ^= 0x537 << ($i - 10);
            }
        }
        $format = (($f << 10) | $g) ^ 0x5412;
        for ($i = 0; $i < 15; $i++) {
            $bit = ($format >> $i) & 1;   // least significant bit first
            // around the top-left finder: down column 8, then left along row 8
            if ($i < 6) {
                $m[$i][8] = $bit;
            } elseif ($i === 6) {
                $m[7][8] = $bit;
            } elseif ($i === 7) {
                $m[8][8] = $bit;
            } elseif ($i === 8) {
                $m[8][7] = $bit;
            } else {
                $m[8][14 - $i] = $bit;
            }
            // the second copy: right along row 8 from the edge, then up column 8 from the bottom
            if ($i < 8) {
                $m[8][$n - 1 - $i] = $bit;
            } else {
                $m[$n - 15 + $i][8] = $bit;
            }
        }
        return $m;
    }

    /** The four penalty rules: runs, 2x2 blocks, finder look-alikes, dark balance. */
    private static function penalty(array $m, int $n): int
    {
        $score = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($i = 0; $i < $n; $i++) {
                $run = 0;
                $prev = -1;
                $line = [];
                for ($j = 0; $j < $n; $j++) {
                    $v = $pass === 0 ? $m[$i][$j] : $m[$j][$i];
                    $line[] = $v;
                    if ($v === $prev) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                    } else {
                        $run = 1;
                        $prev = $v;
                    }
                }
                $s = implode('', $line);
                $score += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
            }
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dark += $m[$y][$x];
                if ($x < $n - 1 && $y < $n - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $ratio = (int) (100 * $dark / ($n * $n));
        $score += 10 * min(abs(intdiv($ratio, 5) * 5 - 50) / 5, abs((intdiv($ratio, 5) + 1) * 5 - 50) / 5);
        return (int) $score;
    }

    /** @return int[] $ecLen error-correction codewords for the block */
    private static function reedSolomon(array $data, int $ecLen): array
    {
        static $exp = null, $log = null;
        if ($exp === null) {
            $exp = [];
            $log = [];
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
        }
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= $c === 0 ? 0 : $exp[($log[$c] + $i) % 255];
            }
            $gen = $next;
        }
        $rem = array_fill(0, $ecLen, 0);
        foreach ($data as $d) {
            $lead = $d ^ array_shift($rem);
            $rem[] = 0;
            if ($lead !== 0) {
                for ($j = 0; $j < $ecLen; $j++) {
                    if ($gen[$j + 1] !== 0) {
                        $rem[$j] ^= $exp[($log[$gen[$j + 1]] + $log[$lead]) % 255];
                    }
                }
            }
        }
        return $rem;
    }
}
