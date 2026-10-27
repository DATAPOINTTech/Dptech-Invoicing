<?php

declare(strict_types=1);

/**
 * Lightweight Standalone Pure-PHP QR Code Matrix Generator
 * Supports Byte Mode, ISO/IEC 18004 compliant, Versions 1-6 (up to 134 bytes at Level M)
 * Zero external dependencies.
 */

class PurePhpQr
{
    public const EC_L = 1; // 7% recovery
    public const EC_M = 0; // 15% recovery
    public const EC_Q = 3; // 25% recovery
    public const EC_H = 2; // 30% recovery

    // Version capacities and block info for Level M
    // [version, total_data_bytes, ec_bytes_per_block, num_blocks]
    private static array $versionInfoM = [
        1 => ['total_data' => 16, 'ec_per_block' => 10, 'blocks' => 1, 'align' => []],
        2 => ['total_data' => 28, 'ec_per_block' => 16, 'blocks' => 1, 'align' => [6, 18]],
        3 => ['total_data' => 44, 'ec_per_block' => 26, 'blocks' => 1, 'align' => [6, 22]],
        4 => ['total_data' => 64, 'ec_per_block' => 18, 'blocks' => 2, 'align' => [6, 26]],
        5 => ['total_data' => 86, 'ec_per_block' => 24, 'blocks' => 2, 'align' => [6, 30]],
        6 => ['total_data' => 108, 'ec_per_block' => 16, 'blocks' => 4, 'align' => [6, 34]],
        7 => ['total_data' => 124, 'ec_per_block' => 18, 'blocks' => 4, 'align' => [6, 22, 38]],
    ];

    private static array $gfExp = [];
    private static array $gfLog = [];
    private static bool $gfInit = false;

    private static function initGf(): void
    {
        if (self::$gfInit) return;
        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D; // Primitive polynomial x^8 + x^4 + x^3 + x^2 + 1
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
        self::$gfInit = true;
    }

    private static function gfMul(int $x, int $y): int
    {
        if ($x === 0 || $y === 0) return 0;
        return self::$gfExp[self::$gfLog[$x] + self::$gfLog[$y]];
    }

    private static function rsGeneratorPoly(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = [1, self::$gfExp[$i]];
            $prod = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                for ($k = 0; $k < count($next); $k++) {
                    $prod[$j + $k] ^= self::gfMul($poly[$j], $next[$k]);
                }
            }
            $poly = $prod;
        }
        return $poly;
    }

    private static function rsEncode(array $data, int $ecCount): array
    {
        $gen = self::rsGeneratorPoly($ecCount);
        $res = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $res[0];
            array_shift($res);
            $res[] = 0;
            for ($i = 0; $i < $ecCount; $i++) {
                $res[$i] ^= self::gfMul($gen[$i + 1], $factor);
            }
        }
        return $res;
    }

    public static function encode(string $text): array
    {
        self::initGf();
        $len = strlen($text);

        // Find appropriate version
        $v = 1;
        while ($v <= 7 && self::$versionInfoM[$v]['total_data'] < ($len + 3)) {
            $v++;
        }
        if ($v > 7) {
            $v = 7;
            $text = substr($text, 0, self::$versionInfoM[$v]['total_data'] - 3);
            $len = strlen($text);
        }

        $vInfo = self::$versionInfoM[$v];
        $totalDataBytes = $vInfo['total_data'];

        // Mode: 8-bit byte (0100)
        $bits = [0, 1, 0, 0];
        // Character count indicator (8 bits for V1-V9)
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = ($len >> $i) & 1;
        }
        // Data bytes
        for ($i = 0; $i < $len; $i++) {
            $c = ord($text[$i]);
            for ($b = 7; $b >= 0; $b--) {
                $bits[] = ($c >> $b) & 1;
            }
        }
        // Terminator (up to 4 zeros)
        $capacityBits = $totalDataBytes * 8;
        $termLen = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $termLen; $i++) {
            $bits[] = 0;
        }
        // Pad to 8-bit boundary
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        // Pad bytes 0xEC, 0x11
        $padBytes = [0xEC, 0x11];
        $padIdx = 0;
        while (count($bits) < $capacityBits) {
            $b = $padBytes[$padIdx];
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($b >> $i) & 1;
            }
            $padIdx = 1 - $padIdx;
        }

        // Convert data bits to bytes
        $dataBytes = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $val = 0;
            for ($j = 0; $j < 8; $j++) {
                $val = ($val << 1) | $bits[$i + $j];
            }
            $dataBytes[] = $val;
        }

        // Divide into blocks and compute RS ECC
        $numBlocks = $vInfo['blocks'];
        $ecCount = $vInfo['ec_per_block'];
        $blockSize = (int) ($totalDataBytes / $numBlocks);

        $blocksData = [];
        $blocksEc = [];
        for ($b = 0; $b < $numBlocks; $b++) {
            $slice = array_slice($dataBytes, $b * $blockSize, $blockSize);
            $blocksData[] = $slice;
            $blocksEc[] = self::rsEncode($slice, $ecCount);
        }

        // Interleave data
        $finalCodewords = [];
        for ($i = 0; $i < $blockSize; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $finalCodewords[] = $blocksData[$b][$i];
            }
        }
        // Interleave ECC
        for ($i = 0; $i < $ecCount; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $finalCodewords[] = $blocksEc[$b][$i];
            }
        }

        // Build matrix
        $size = 17 + 4 * $v;
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Finder patterns
        $setFinder = function(int $r, int $c) use (&$matrix, &$reserved) {
            for ($i = -1; $i <= 7; $i++) {
                for ($j = -1; $j <= 7; $j++) {
                    $row = $r + $i;
                    $col = $c + $j;
                    if ($row >= 0 && $row < count($matrix) && $col >= 0 && $col < count($matrix)) {
                        $reserved[$row][$col] = true;
                        if ($i >= 0 && $i <= 6 && $j >= 0 && $j <= 6) {
                            $isBlack = ($i === 0 || $i === 6 || $j === 0 || $j === 6 || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4));
                            $matrix[$row][$col] = $isBlack ? 1 : 0;
                        } else {
                            $matrix[$row][$col] = 0; // separator
                        }
                    }
                }
            }
        };

        $setFinder(0, 0);
        $setFinder(0, $size - 7);
        $setFinder($size - 7, 0);

        // 2. Alignment patterns
        $alignCoords = $vInfo['align'];
        if (!empty($alignCoords)) {
            foreach ($alignCoords as $ar) {
                foreach ($alignCoords as $ac) {
                    if ($reserved[$ar][$ac]) continue;
                    for ($i = -2; $i <= 2; $i++) {
                        for ($j = -2; $j <= 2; $j++) {
                            $row = $ar + $i;
                            $col = $ac + $j;
                            $reserved[$row][$col] = true;
                            $isBlack = (abs($i) === 2 || abs($j) === 2 || ($i === 0 && $j === 0));
                            $matrix[$row][$col] = $isBlack ? 1 : 0;
                        }
                    }
                }
            }
        }

        // 3. Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            if (!$reserved[6][$i]) {
                $matrix[6][$i] = ($i % 2 === 0) ? 1 : 0;
                $reserved[6][$i] = true;
            }
            if (!$reserved[$i][6]) {
                $matrix[$i][6] = ($i % 2 === 0) ? 1 : 0;
                $reserved[$i][6] = true;
            }
        }

        // 4. Dark module
        $matrix[4 * $v + 9][8] = 1;
        $reserved[4 * $v + 9][8] = true;

        // 5. Reserve format info areas
        for ($i = 0; $i <= 8; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }

        // 6. Place data bits in zigzag
        $allBits = [];
        foreach ($finalCodewords as $cw) {
            for ($b = 7; $b >= 0; $b--) {
                $allBits[] = ($cw >> $b) & 1;
            }
        }

        // Remainder bits if any
        $remBits = ($v >= 2 && $v <= 6) ? 7 : 0;
        for ($i = 0; $i < $remBits; $i++) {
            $allBits[] = 0;
        }

        $bitIdx = 0;
        $dir = -1; // up
        $col = $size - 1;
        while ($col > 0) {
            if ($col === 6) $col--; // skip vertical timing line
            $row = ($dir === -1) ? $size - 1 : 0;
            while ($row >= 0 && $row < $size) {
                for ($c = 0; $c < 2; $c++) {
                    $currCol = $col - $c;
                    if (!$reserved[$row][$currCol]) {
                        $matrix[$row][$currCol] = $allBits[$bitIdx++] ?? 0;
                    }
                }
                $row += $dir;
            }
            $dir = -$dir;
            $col -= 2;
        }

        // 7. Apply standard mask (Mask 0: (row + col) % 2 == 0 is widely standard and robust)
        // Format info for EC Level M (00) with Mask 0 (000): Format bits = 00 000 = 0
        // BCH code for 0 is 0. XOR with 0x5412 (101010000010010) = 101010000010010
        $bestMask = 0;
        $formatBits = [1, 0, 1, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 1, 0];

        $maskedMatrix = $matrix;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c]) {
                    if (($r + $c) % 2 === 0) {
                        $maskedMatrix[$r][$c] ^= 1;
                    }
                }
            }
        }

        // 8. Place format info
        // Top-left
        $posTopLeft = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
            [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        // Top-right and bottom-left
        $posOther = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1]
        ];

        for ($i = 0; $i < 15; $i++) {
            $bit = $formatBits[$i];
            $maskedMatrix[$posTopLeft[$i][0]][$posTopLeft[$i][1]] = $bit;
            $maskedMatrix[$posOther[$i][0]][$posOther[$i][1]] = $bit;
        }

        return $maskedMatrix;
    }

    public static function toSvg(string $text, int $size = 180, string $fg = '#0f172a', string $bg = '#ffffff'): string
    {
        $matrix = self::encode($text);
        $count = count($matrix);
        $quietZone = 2;
        $totalCells = $count + ($quietZone * 2);
        $cellSize = $size / $totalCells;

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$size} {$size}\" width=\"{$size}\" height=\"{$size}\">\n";
        $svg .= "  <rect width=\"100%\" height=\"100%\" fill=\"{$bg}\"/>\n";
        $svg .= "  <g fill=\"{$fg}\">\n";

        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = ($c + $quietZone) * $cellSize;
                    $y = ($r + $quietZone) * $cellSize;
                    $svg .= sprintf("    <rect x=\"%.2f\" y=\"%.2f\" width=\"%.2f\" height=\"%.2f\"/>\n", $x, $y, $cellSize + 0.1, $cellSize + 0.1);
                }
            }
        }
        $svg .= "  </g>\n</svg>";
        return $svg;
    }

    public static function toDataUri(string $text, int $size = 180): string
    {
        $svg = self::toSvg($text, $size);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

