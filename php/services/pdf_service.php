<?php

declare(strict_types=1);

/**
 * DATAPOINT Invoicing System - Graphical Multi-Theme PDF Engine
 * Zero-dependency standards-compliant PDF-1.4 generator with transparent PNG/JPEG logo embedding,
 * multi-company color themes, itemized tables, and exact byte offsets.
 */

function resolve_company_logo_path(?string $logoUrl): ?string
{
    $appRoot = defined('PHP_APP_ROOT') ? PHP_APP_ROOT : dirname(__DIR__);

    if (empty($logoUrl)) {
        $exactJpg = $appRoot . '/public/static/img/dptech_logo_exact.jpg';
        if (file_exists($exactJpg)) {
            return $exactJpg;
        }
        $defaultPng = $appRoot . '/public/static/img/logo.png';
        return file_exists($defaultPng) ? $defaultPng : null;
    }

    $candidates = [];

    if (file_exists($logoUrl)) {
        $candidates[] = $logoUrl;
    }

    if (str_starts_with($logoUrl, '/static/')) {
        $candidates[] = $appRoot . '/public' . $logoUrl;
        $candidates[] = $appRoot . $logoUrl;
    }

    $candidates[] = $appRoot . '/public/' . ltrim($logoUrl, '/');
    $candidates[] = $appRoot . '/' . ltrim($logoUrl, '/');

    foreach ($candidates as $cand) {
        if (file_exists($cand) && is_file($cand)) {
            return $cand;
        }
    }

    $exactJpg = $appRoot . '/public/static/img/dptech_logo_exact.jpg';
    if (file_exists($exactJpg)) {
        return $exactJpg;
    }

    return null;
}

function parse_image_for_pdf(string $file): ?array
{
    if (!file_exists($file)) {
        return null;
    }
    $info = @getimagesize($file);
    if ($info && ($info['mime'] ?? '') === 'image/jpeg') {
        $raw = file_get_contents($file);
        if ($raw === false || strlen($raw) === 0) {
            return null;
        }
        return [
            'width' => (int)$info[0],
            'height' => (int)$info[1],
            'data' => $raw,
            'filter' => '/DCTDecode',
            'decodeParms' => null,
            'colorSpace' => '/DeviceRGB',
            'smask' => null,
        ];
    }
    return parse_png_for_pdf($file);
}

function parse_png_for_pdf(string $file): ?array
{
    $f = @fopen($file, 'rb');
    if (!$f) {
        return null;
    }
    if (fread($f, 8) !== "\x89PNG\r\n\x1a\n") {
        fclose($f);
        return null;
    }

    $w = 0;
    $h = 0;
    $bpc = 8;
    $colorType = 0;
    $idat = '';

    while (!feof($f)) {
        $lenBytes = fread($f, 4);
        if (strlen($lenBytes) < 4) {
            break;
        }
        $len = unpack('N', $lenBytes)[1];
        $type = fread($f, 4);
        $data = $len > 0 ? fread($f, $len) : '';
        fread($f, 4); // Skip CRC

        if ($type === 'IHDR') {
            $w = unpack('N', substr($data, 0, 4))[1];
            $h = unpack('N', substr($data, 4, 4))[1];
            $bpc = ord($data[8]);
            $colorType = ord($data[9]);
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        } elseif ($type === 'IEND') {
            break;
        }
    }
    fclose($f);

    if ($w === 0 || $h === 0 || $idat === '') {
        return null;
    }

    $decompressed = @gzuncompress($idat);
    if ($decompressed === false) {
        return null;
    }

    // TrueColor (RGB) without alpha
    if ($colorType === 2) {
        return [
            'width' => $w,
            'height' => $h,
            'data' => $idat,
            'filter' => '/FlateDecode',
            'decodeParms' => "<< /Predictor 15 /Colors 3 /BitsPerComponent {$bpc} /Columns {$w} >>",
            'colorSpace' => '/DeviceRGB',
            'smask' => null,
        ];
    }

    // TrueColor with Alpha (RGBA)
    if ($colorType === 6) {
        $bpp = 4;
        $totalBytes = strlen($decompressed);
        $rgbData = '';
        $alphaData = '';
        $prevLine = str_repeat("\x00", $w * $bpp);
        $pos = 0;

        for ($y = 0; $y < $h; $y++) {
            if ($pos >= $totalBytes) break;
            $filter = ord($decompressed[$pos++]);
            $currRaw = substr($decompressed, $pos, $w * $bpp);
            $pos += $w * $bpp;

            $currUnfiltered = '';
            for ($x = 0; $x < $w * $bpp; $x++) {
                $rawVal = ord($currRaw[$x] ?? "\x00");
                $leftVal = ($x >= $bpp) ? ord($currUnfiltered[$x - $bpp]) : 0;
                $upVal = ord($prevLine[$x]);
                $upLeftVal = ($x >= $bpp) ? ord($prevLine[$x - $bpp]) : 0;

                switch ($filter) {
                    case 0: $val = $rawVal; break;
                    case 1: $val = ($rawVal + $leftVal) & 0xFF; break;
                    case 2: $val = ($rawVal + $upVal) & 0xFF; break;
                    case 3: $val = ($rawVal + (int)(($leftVal + $upVal) / 2)) & 0xFF; break;
                    case 4:
                        $p = $leftVal + $upVal - $upLeftVal;
                        $pa = abs($p - $leftVal);
                        $pb = abs($p - $upVal);
                        $pc = abs($p - $upLeftVal);
                        if ($pa <= $pb && $pa <= $pc) {
                            $pr = $leftVal;
                        } elseif ($pb <= $pc) {
                            $pr = $upVal;
                        } else {
                            $pr = $upLeftVal;
                        }
                        $val = ($rawVal + $pr) & 0xFF;
                        break;
                    default: $val = $rawVal; break;
                }
                $currUnfiltered .= chr($val);
            }
            $prevLine = $currUnfiltered;

            for ($x = 0; $x < $w; $x++) {
                $pixelIdx = $x * 4;
                $rgbData .= $currUnfiltered[$pixelIdx] . $currUnfiltered[$pixelIdx + 1] . $currUnfiltered[$pixelIdx + 2];
                $alphaData .= $currUnfiltered[$pixelIdx + 3];
            }
        }

        return [
            'width' => $w,
            'height' => $h,
            'data' => gzcompress($rgbData),
            'filter' => '/FlateDecode',
            'decodeParms' => null,
            'colorSpace' => '/DeviceRGB',
            'smask' => [
                'width' => $w,
                'height' => $h,
                'data' => gzcompress($alphaData),
                'colorSpace' => '/DeviceGray',
            ]
        ];
    }

    return null;
}

function get_company_color_theme(array $data): array
{
    $markup = isset($data['markup_percent']) ? (float)$data['markup_percent'] : 0.0;
    $cid = (int)($data['company_id'] ?? $data['id'] ?? 1);
    $name = strtolower((string)($data['company_name'] ?? $data['name'] ?? ''));
    $code = strtoupper((string)($data['company_code'] ?? $data['code'] ?? ''));

    // Company 2: Pakistan Technocrates Works & Services (PTC / +2%) -> Executive Charcoal Slate & Warm Bronze Gold
    if ($cid === 2 || $code === 'PTC' || str_contains($name, 'technocrates') || str_contains($name, 'services') || (abs($markup - 2.0) < 0.1 && !str_contains($name, 'datapoint'))) {
        return [
            'theme_key' => 'technocrates',
            'is_primary' => false,
            'name' => 'Charcoal Slate & Warm Bronze Gold',
            'doc_title' => 'COMMERCIAL QUOTATION',
            'slogan' => 'General Order Suppliers, Engineering Works & Technical Services',
            'primary' => [0.118, 0.161, 0.231],       // #1e293b Deep Slate Charcoal
            'accent' => [0.706, 0.325, 0.035],        // #b45309 Warm Antique Bronze Gold
            'secondary' => [0.851, 0.467, 0.024],     // #d97706 Warm Amber
            'header_bg' => [0.118, 0.161, 0.231],     // #1e293b Deep Charcoal header
            'row_alt' => [0.988, 0.980, 0.965],       // #fdfbf7 Warm Ivory alternating rows
            'row_border' => [0.867, 0.835, 0.792],    // #ded5ca Warm Taupe Sand borders
            'total_bar_bg' => [0.600, 0.250, 0.020],  // #9a3412 Solid Warm Bronze Amber grand total
            'gst_color' => [0.600, 0.120, 0.120],     // Deep Crimson GST label
            'terms_bg' => [0.988, 0.980, 0.965],      // Warm Ivory terms box
            'terms_border' => [0.867, 0.835, 0.792],  // Warm Taupe border
            'terms_title' => 'TERMS & TECHNICAL SPECIFICATIONS',
            'stamp_label' => 'AUTHORIZED SIGNATURE & STAMP',
            'stamp_shape' => 'double_rect',           // Formal rectangular double-line stamp
            'header_style' => 'corporate_letterhead', // Dual-stripe heavy slate + bronze rule
            'initials' => 'PTC',
        ];
    }

    // Company 3: M tech Cybernet and Electronics (MTC / +3%) -> Forest Emerald & Vibrant Teal
    if ($cid === 3 || $code === 'MTC' || str_contains($name, 'cybernet') || str_contains($name, 'electronics') || (abs($markup - 3.0) < 0.1 && !str_contains($name, 'datapoint'))) {
        return [
            'theme_key' => 'cybernet',
            'is_primary' => false,
            'name' => 'Forest Emerald & Vibrant Teal',
            'doc_title' => 'PROFORMA QUOTATION',
            'slogan' => 'Telecommunications, Network Infrastructure & Electronic Systems',
            'primary' => [0.024, 0.306, 0.231],       // #064e3b Deep Forest Pine
            'accent' => [0.020, 0.588, 0.412],        // #059669 Vibrant Emerald
            'secondary' => [0.051, 0.580, 0.533],     // #0d9488 Deep Teal
            'header_bg' => [0.024, 0.306, 0.231],     // #064e3b Deep Forest header
            'row_alt' => [0.941, 0.992, 0.957],       // #f0fdf4 Crisp Mint Ice alternating rows
            'row_border' => [0.655, 0.902, 0.780],    // #a7e6c7 Soft Mint borders
            'total_bar_bg' => [0.024, 0.306, 0.231],  // #064e3b Solid Forest Pine grand total
            'gst_color' => [0.750, 0.150, 0.150],     // Coral Crimson GST label
            'terms_bg' => [0.941, 0.992, 0.957],      // Mint Ice terms box
            'terms_border' => [0.655, 0.902, 0.780],  // Soft Mint border
            'terms_title' => 'COMMERCIAL TERMS & WARRANTY',
            'stamp_label' => 'VERIFIED & STAMPED',
            'stamp_shape' => 'rounded',
            'header_style' => 'tech_emerald',         // Modern crisp emerald rule
            'initials' => 'MTC',
        ];
    }

    // Default / Company 1: DATAPOINT Technologies (DPT / 0%) -> Executive Sapphire Navy & Ice Blue
    return [
        'theme_key' => 'datapoint',
        'is_primary' => true,
        'name' => 'Sapphire & Deep Navy',
        'doc_title' => 'QUOTATION',
        'slogan' => 'Offering complete Suite of IT and Security solutions',
        'primary' => [0.059, 0.231, 0.424],       // #0f3b6c Sapphire Navy
        'accent' => [0.008, 0.518, 0.780],        // #0284c7 Bright Cyan
        'secondary' => [0.220, 0.741, 0.973],     // #38bdf8 Sky Blue
        'header_bg' => [0.059, 0.231, 0.424],     // #0f3b6c Sapphire Navy header
        'row_alt' => [0.941, 0.969, 1.000],       // #f0f7ff Soft Ice Blue alternating rows
        'row_border' => [0.796, 0.835, 0.886],    // Soft Blue-Gray borders
        'total_bar_bg' => [0.027, 0.114, 0.216],  // #071d37 Dark Navy grand total
        'gst_color' => [0.753, 0.224, 0.169],     // Bold Crimson Red GST label
        'terms_bg' => [0.941, 0.969, 1.000],      // Ice Blue terms box
        'terms_border' => [0.796, 0.859, 0.941],  // Soft Slate Blue border
        'terms_title' => 'TERMS & CONDITIONS',
        'stamp_label' => 'STAMP',
        'stamp_shape' => 'rounded',
        'header_style' => 'datapoint_modern',
        'initials' => 'DPT',
    ];
}

class DpPdfEngine
{
    private float $pageWidth = 595.28;  // A4
    private float $pageHeight = 841.89; // A4
    private array $pages = [];
    private int $currentPage = -1;
    private float $currentY = 795.0;
    private float $marginLeft = 36.0;
    private float $marginRight = 36.0;
    private float $marginBottom = 45.0;
    private ?array $footerInfo = null;
    private ?array $footerBanner = null;
    private array $images = [];
    private array $pageImages = [];
    private array $theme = [];
    private string $companyName = 'DATAPOINT Technologies';
    private string $companyPhone = '';
    private string $companyNtn = '';

    public function __construct(array $theme = [], string $companyName = '', string $companyPhone = '', string $companyNtn = '')
    {
        $this->theme = $theme;
        if ($companyName !== '') {
            $this->companyName = $companyName;
        }
        $this->companyPhone = $companyPhone;
        $this->companyNtn = $companyNtn;
        $this->addPage();
    }

    public function setMargins(float $left, float $right, float $bottom = 45.0, float $top = 28.0): void
    {
        $this->marginLeft = $left;
        $this->marginRight = $right;
        $this->marginBottom = $bottom;
        $this->currentY = $this->pageHeight - $top;
    }

    public function setFooterInfo(?array $info): void
    {
        $this->footerInfo = $info;
    }

    public function setFooterBannerImage(string $filePath, float $x = 36.0, float $y = 28.0, float $w = 523.28, float $h = 45.0): void
    {
        if (!file_exists($filePath)) {
            return;
        }
        $imgIdx = null;
        foreach ($this->images as $idx => $stored) {
            if ($stored['path'] === $filePath) {
                $imgIdx = $idx + 1;
                break;
            }
        }
        if ($imgIdx === null) {
            $parsed = parse_image_for_pdf($filePath);
            if ($parsed) {
                $parsed['path'] = $filePath;
                $this->images[] = $parsed;
                $imgIdx = count($this->images);
            }
        }
        if ($imgIdx !== null) {
            $this->footerBanner = [
                'imgIdx' => $imgIdx,
                'x' => $x,
                'y' => $y,
                'w' => $w,
                'h' => $h,
            ];
        }
    }

    public function getPageWidth(): float { return $this->pageWidth; }
    public function getPrintableWidth(): float { return $this->pageWidth - $this->marginLeft - $this->marginRight; }
    public function getMarginLeft(): float { return $this->marginLeft; }
    public function getY(): float { return $this->currentY; }
    public function setY(float $y): void { $this->currentY = $y; }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->currentPage = count($this->pages) - 1;
        $this->pageImages[$this->currentPage] = [];
        $this->currentY = 795.0;
    }

    public function checkPageBreak(float $heightNeeded, ?callable $onNewPageHeader = null): void
    {
        if (($this->currentY - $heightNeeded) < $this->marginBottom) {
            $this->addPage();
            if ($onNewPageHeader !== null) {
                $onNewPageHeader($this);
            }
        }
    }

    private function append(string $cmd): void
    {
        $this->pages[$this->currentPage] .= $cmd;
    }

    public function escape(string $text): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false || $converted === '') {
            $converted = $text;
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    public function setFillColor(float $r, float $g, float $b): void
    {
        $this->append(sprintf("%.3F %.3F %.3F rg\n", max(0, min(1, $r)), max(0, min(1, $g)), max(0, min(1, $b))));
    }

    public function setFillColorArray(array $rgb): void
    {
        $this->setFillColor($rgb[0], $rgb[1], $rgb[2]);
    }

    public function setStrokeColor(float $r, float $g, float $b): void
    {
        $this->append(sprintf("%.3F %.3F %.3F RG\n", max(0, min(1, $r)), max(0, min(1, $g)), max(0, min(1, $b))));
    }

    public function setStrokeColorArray(array $rgb): void
    {
        $this->setStrokeColor($rgb[0], $rgb[1], $rgb[2]);
    }

    public function setLineWidth(float $w): void
    {
        $this->append(sprintf("%.2F w\n", $w));
    }

    public function drawRect(float $x, float $y, float $w, float $h, bool $fill = true, bool $stroke = false): void
    {
        $cmd = sprintf("%.2F %.2F %.2F %.2F re\n", $x, $y, $w, $h);
        if ($fill && $stroke) {
            $cmd .= "B\n";
        } elseif ($fill) {
            $cmd .= "f\n";
        } else {
            $cmd .= "S\n";
        }
        $this->append($cmd);
    }

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $r = 4.0, bool $fill = true, bool $stroke = false): void
    {
        $r = min($r, $w / 2, $h / 2);
        $k = 0.552284749831 * $r;
        $cmd = sprintf("%.2F %.2F m\n", $x + $r, $y);
        $cmd .= sprintf("%.2F %.2F l\n", $x + $w - $r, $y);
        $cmd .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r);
        $cmd .= sprintf("%.2F %.2F l\n", $x + $w, $y + $h - $r);
        $cmd .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x + $w, $y + $h - $r + $k, $x + $w, $y + $h, $x + $w - $r, $y + $h);
        $cmd .= sprintf("%.2F %.2F l\n", $x + $r, $y + $h);
        $cmd .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r);
        $cmd .= sprintf("%.2F %.2F l\n", $x, $y + $r);
        $cmd .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y);
        $cmd .= "h\n";
        if ($fill && $stroke) {
            $cmd .= "B\n";
        } elseif ($fill) {
            $cmd .= "f\n";
        } else {
            $cmd .= "S\n";
        }
        $this->append($cmd);
    }

    public function drawCircle(float $cx, float $cy, float $r, bool $fill = true, bool $stroke = false): void
    {
        $k = 0.552284749831 * $r;
        $cmd = sprintf("%.2F %.2F m\n", $cx, $cy - $r);
        $cmd .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy);
        $cmd .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r);
        $cmd .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy);
        $cmd .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r);
        $cmd .= "h\n";
        if ($fill && $stroke) {
            $cmd .= "B\n";
        } elseif ($fill) {
            $cmd .= "f\n";
        } else {
            $cmd .= "S\n";
        }
        $this->append($cmd);
    }

    public function drawLine(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->append(sprintf("%.2F %.2F m\n%.2F %.2F l\nS\n", $x1, $y1, $x2, $y2));
    }

    public function drawQrCode(float $x, float $y, string $text, float $size = 50.0): void
    {
        require_once __DIR__ . '/qrcode_service.php';
        $matrix = PurePhpQr::encode($text);
        $n = count($matrix);
        if ($n === 0) return;
        $quiet = 1;
        $totalCells = $n + ($quiet * 2);
        $cellW = $size / $totalCells;

        // Clean white background
        $this->setFillColor(1, 1, 1);
        $this->drawRect($x, $y, $size, $size, true, false);

        // Draw dark modules
        $this->setFillColor(0.06, 0.09, 0.16);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $modX = $x + (($c + $quiet) * $cellW);
                    $modY = $y + (($n - 1 - $r + $quiet) * $cellW);
                    $this->drawRect($modX, $modY, $cellW + 0.12, $cellW + 0.12, true, false);
                }
            }
        }
    }

    public function getTextWidth(string $text, string $font = 'F1', float $size = 10.0): float
    {
        static $widthsF1 = [
            32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
            40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
            48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
            56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
            64 => 1015,
            65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722,
            73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
            81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667,
            89 => 667, 90 => 611,
            91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 222,
            97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556, 104 => 556,
            105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556,
            113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500,
            121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584
        ];
        static $widthsF2 = [
            32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238,
            40 => 389, 41 => 389, 42 => 500, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
            48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
            56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611,
            64 => 975,
            65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722,
            73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
            81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667,
            89 => 667, 90 => 611,
            91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556, 96 => 278,
            97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611, 104 => 611,
            105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611, 111 => 611, 112 => 611,
            113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778, 120 => 556,
            121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584
        ];

        $fontCode = in_array($font, ['F1', 'F2', 'F3'], true) ? $font : 'F1';
        $table = ($fontCode === 'F2') ? $widthsF2 : $widthsF1;
        $defaultWidth = ($fontCode === 'F2') ? 600 : 500;
        $units = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ascii = ord($text[$i]);
            $units += $table[$ascii] ?? $defaultWidth;
        }
        return ($units / 1000.0) * $size;
    }

    public function drawText(float $x, float $y, string $text, string $font = 'F1', float $size = 10, string $align = 'left', ?float $width = null): void
    {
        $safe = $this->escape($text);
        $fontCode = in_array($font, ['F1', 'F2', 'F3'], true) ? $font : 'F1';

        if ($align === 'right' && $width !== null) {
            $textWidth = $this->getTextWidth($text, $fontCode, $size);
            $x = $x + $width - $textWidth;
        } elseif ($align === 'center' && $width !== null) {
            $textWidth = $this->getTextWidth($text, $fontCode, $size);
            $x = $x + (($width - $textWidth) / 2);
        }

        $cmd = sprintf("BT\n/%s %.2F Tf\n%.2F %.2F Td\n(%s) Tj\nET\n", $fontCode, $size, $x, $y, $safe);
        $this->append($cmd);
    }

    public function wrapText(string $text, float $maxWidth, float $size = 9.0, string $font = 'F1'): array
    {
        $words = preg_split('/\s+/', trim($text));
        if ($words === false || empty($words)) {
            return [''];
        }
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            $testWidth = $this->getTextWidth($testLine, $font, $size);
            if ($testWidth > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }
        return $lines;
    }

    public function addImage(string $filePath, float $x, float $y, float $maxW, float $maxH): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $imgIdx = null;
        foreach ($this->images as $idx => $stored) {
            if ($stored['path'] === $filePath) {
                $imgIdx = $idx + 1;
                $imgData = $stored;
                break;
            }
        }

        if ($imgIdx === null) {
            $parsed = parse_image_for_pdf($filePath);
            if (!$parsed) {
                return null;
            }
            $parsed['path'] = $filePath;
            $this->images[] = $parsed;
            $imgIdx = count($this->images);
            $imgData = $parsed;
        }

        $scale = min($maxW / $imgData['width'], $maxH / $imgData['height']);
        $w = $imgData['width'] * $scale;
        $h = $imgData['height'] * $scale;
        $bottomY = $y - $h;

        $cmd = sprintf("q\n%.2F 0 0 %.2F %.2F %.2F cm\n/I%d Do\nQ\n", $w, $h, $x, $bottomY, $imgIdx);
        $this->append($cmd);

        if (!in_array($imgIdx, $this->pageImages[$this->currentPage], true)) {
            $this->pageImages[$this->currentPage][] = $imgIdx;
        }

        return ['width' => $w, 'height' => $h, 'bottomY' => $bottomY];
    }

    public function render(): string
    {
        $pageCount = count($this->pages);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $nextObjId = 1;

        // Catalog
        $catalogObj = $nextObjId++;
        $offsets[$catalogObj] = strlen($out);
        $pagesObj = $nextObjId++;
        $out .= "{$catalogObj} 0 obj\n<< /Type /Catalog /Pages {$pagesObj} 0 R >>\nendobj\n";

        // Pre-allocate page & stream IDs
        $pageObjIds = [];
        $streamObjIds = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $pageObjIds[$i] = $nextObjId++;
            $streamObjIds[$i] = $nextObjId++;
        }

        // Fonts
        $fontRegular = $nextObjId++;
        $fontBold = $nextObjId++;
        $fontOblique = $nextObjId++;

        // Image XObjects and SMasks
        $imageObjIds = [];
        foreach ($this->images as $idx => $img) {
            $imgObj = $nextObjId++;
            $maskObj = !empty($img['smask']) ? $nextObjId++ : null;
            $imageObjIds[$idx + 1] = [
                'img' => $imgObj,
                'mask' => $maskObj
            ];
        }

        // Pages Object
        $offsets[$pagesObj] = strlen($out);
        $kidsStr = implode(' 0 R ', $pageObjIds) . ' 0 R';
        $out .= "{$pagesObj} 0 obj\n<< /Type /Pages /Kids [{$kidsStr}] /Count {$pageCount} >>\nendobj\n";

        // Pages and Content Streams
        for ($i = 0; $i < $pageCount; $i++) {
            $pId = $pageObjIds[$i];
            $sId = $streamObjIds[$i];

            // If footerBanner is set, ensure it's in this page's images
            $footerCmd = '';
            if ($this->footerBanner !== null) {
                $fIdx = $this->footerBanner['imgIdx'];
                if (!in_array($fIdx, $this->pageImages[$i], true)) {
                    $this->pageImages[$i][] = $fIdx;
                }
                $footerCmd = sprintf(
                    "q\n%.2F 0 0 %.2F %.2F %.2F cm\n/I%d Do\nQ\n",
                    $this->footerBanner['w'],
                    $this->footerBanner['h'],
                    $this->footerBanner['x'],
                    $this->footerBanner['y'],
                    $fIdx
                );
            } else {
                $footerLine = sprintf(
                    "%.3F %.3F %.3F RG\n1.20 w\n%.2F 36.00 m\n%.2F 36.00 l\nS\n",
                    $this->theme['accent'][0] ?? 0.2,
                    $this->theme['accent'][1] ?? 0.4,
                    $this->theme['accent'][2] ?? 0.8,
                    $this->marginLeft,
                    $this->pageWidth - $this->marginRight
                );
                $infoParts = array_filter([
                    $this->companyName,
                    $this->companyPhone ? 'Tel: ' . $this->companyPhone : null,
                    $this->companyNtn ? 'NTN: ' . $this->companyNtn : null
                ]);
                $footerText = sprintf(
                    "BT\n/F1 8.00 Tf\n0.350 0.400 0.450 rg\n%.2F 24.00 Td\n(%s) Tj\nET\n",
                    $this->marginLeft,
                    $this->escape(implode(' • ', $infoParts))
                );
                $footerPageNum = sprintf(
                    "BT\n/F2 8.00 Tf\n%.3F %.3F %.3F rg\n%.2F 24.00 Td\n(%s) Tj\nET\n",
                    $this->theme['primary'][0] ?? 0.2,
                    $this->theme['primary'][1] ?? 0.2,
                    $this->theme['primary'][2] ?? 0.2,
                    $this->pageWidth - $this->marginRight - 65,
                    $this->escape("Page " . ($i + 1) . " of " . $pageCount)
                );
                $footerCmd = $footerLine . $footerText . $footerPageNum;
            }

            // Build XObject resource dictionary for this page
            $xobjDict = '';
            foreach ($this->pageImages[$i] as $imgIndex) {
                if (isset($imageObjIds[$imgIndex])) {
                    $xobjDict .= " /I{$imgIndex} {$imageObjIds[$imgIndex]['img']} 0 R";
                }
            }
            $xobjectEntry = $xobjDict !== '' ? " /XObject <<{$xobjDict} >>" : "";

            $streamContent = $this->pages[$i] . $footerCmd;
            $streamLen = strlen($streamContent);

            $offsets[$pId] = strlen($out);
            $out .= "{$pId} 0 obj\n<< /Type /Page /Parent {$pagesObj} 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] /Contents {$sId} 0 R /Resources << /Font << /F1 {$fontRegular} 0 R /F2 {$fontBold} 0 R /F3 {$fontOblique} 0 R >>{$xobjectEntry} /ProcSet [/PDF /Text /ImageC] >> >>\nendobj\n";

            $offsets[$sId] = strlen($out);
            $out .= "{$sId} 0 obj\n<< /Length {$streamLen} >>\nstream\n{$streamContent}\nendstream\nendobj\n";
        }

        // Fonts
        $offsets[$fontRegular] = strlen($out);
        $out .= "{$fontRegular} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        $offsets[$fontBold] = strlen($out);
        $out .= "{$fontBold} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        $offsets[$fontOblique] = strlen($out);
        $out .= "{$fontOblique} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Images & SMasks
        foreach ($this->images as $idx => $img) {
            $imgIdx = $idx + 1;
            $ids = $imageObjIds[$imgIdx];
            $imgObj = $ids['img'];
            $maskObj = $ids['mask'];

            $imgLen = strlen($img['data']);
            $smaskRef = $maskObj ? " /SMask {$maskObj} 0 R" : "";
            $decParms = !empty($img['decodeParms']) ? " /DecodeParms {$img['decodeParms']}" : "";

            $offsets[$imgObj] = strlen($out);
            $out .= "{$imgObj} 0 obj\n<< /Type /XObject /Subtype /Image /Width {$img['width']} /Height {$img['height']} /ColorSpace {$img['colorSpace']} /BitsPerComponent 8 /Filter {$img['filter']}{$decParms}{$smaskRef} /Length {$imgLen} >>\nstream\n" . $img['data'] . "\nendstream\nendobj\n";

            if ($maskObj) {
                $maskLen = strlen($img['smask']['data']);
                $offsets[$maskObj] = strlen($out);
                $out .= "{$maskObj} 0 obj\n<< /Type /XObject /Subtype /Image /Width {$img['smask']['width']} /Height {$img['smask']['height']} /ColorSpace {$img['smask']['colorSpace']} /BitsPerComponent 8 /Filter /FlateDecode /Length {$maskLen} >>\nstream\n" . $img['smask']['data'] . "\nendstream\nendobj\n";
            }
        }

        // xref table
        $totalObjs = $nextObjId - 1;
        $xrefOffset = strlen($out);
        $out .= "xref\n0 " . ($totalObjs + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $totalObjs; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . ($totalObjs + 1) . " /Root {$catalogObj} 0 R >>\n";
        $out .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $out;
    }
}

function render_branded_sales_tax_document(array $doc, string $type = 'INVOICE'): string
{
    $companyName = !empty($doc['company_name']) ? (string)$doc['company_name'] : (getenv('COMPANY_NAME') ?: 'DATAPOINT Technologies');
    $companyAddress = !empty($doc['company_address']) ? (string)$doc['company_address'] : (getenv('COMPANY_ADDRESS') ?: 'G32 Shayas Residence, Jamshoro Road, Citizen Colony, Hyderabad, Sindh');
    $companyPhone = !empty($doc['company_phone']) ? (string)$doc['company_phone'] : (getenv('COMPANY_MOBILE') ?: getenv('COMPANY_PHONE') ?: '0316 7788990');
    $companyEmail = !empty($doc['company_email']) ? (string)$doc['company_email'] : (getenv('COMPANY_EMAIL') ?: 'info@datapointtechnology.com');
    $companyNtn = !empty($doc['company_ntn']) ? (string)$doc['company_ntn'] : (getenv('COMPANY_NTN') ?: '7178396-5');
    $companyStrn = !empty($doc['company_strn']) ? (string)$doc['company_strn'] : (getenv('COMPANY_STRN') ?: '3277876124452');
    $logoUrl = $doc['company_logo'] ?? $doc['logo_url'] ?? null;

    // Refresh company details directly from companies table as adjusted in Companies Setup page
    if (!empty($doc['company_id'])) {
        try {
            $db = database_connection();
            $coStmt = $db->prepare('SELECT * FROM companies WHERE id = :cid LIMIT 1');
            $coStmt->execute(['cid' => (int)$doc['company_id']]);
            $co = $coStmt->fetch(PDO::FETCH_ASSOC);
            if ($co) {
                if (!empty($co['name'])) $companyName = (string)$co['name'];
                if (!empty($co['address'])) $companyAddress = (string)$co['address'];
                if (!empty($co['phone']) || !empty($co['mobile'])) $companyPhone = (string)($co['phone'] ?: $co['mobile']);
                if (!empty($co['email'])) $companyEmail = (string)$co['email'];
                if (!empty($co['ntn'])) $companyNtn = (string)$co['ntn'];
                if (!empty($co['strn'])) $companyStrn = (string)$co['strn'];
                if (!empty($co['logo_url'])) $logoUrl = (string)$co['logo_url'];
                $doc['company_name'] = $companyName;
                $doc['company_code'] = $co['code'] ?? null;
            }
        } catch (\Throwable $e) {}
    } elseif (!empty($doc['company_name'])) {
        try {
            $db = database_connection();
            $coStmt = $db->prepare('SELECT * FROM companies WHERE name = :cname OR code = :cname LIMIT 1');
            $coStmt->execute(['cname' => (string)$doc['company_name']]);
            $co = $coStmt->fetch(PDO::FETCH_ASSOC);
            if ($co) {
                if (!empty($co['name'])) $companyName = (string)$co['name'];
                if (!empty($co['address'])) $companyAddress = (string)$co['address'];
                if (!empty($co['phone']) || !empty($co['mobile'])) $companyPhone = (string)($co['phone'] ?: $co['mobile']);
                if (!empty($co['email'])) $companyEmail = (string)$co['email'];
                if (!empty($co['ntn'])) $companyNtn = (string)$co['ntn'];
                if (!empty($co['strn'])) $companyStrn = (string)$co['strn'];
                if (!empty($co['logo_url'])) $logoUrl = (string)$co['logo_url'];
                $doc['company_name'] = $companyName;
                $doc['company_code'] = $co['code'] ?? null;
            }
        } catch (\Throwable $e) {}
    }

    $theme = get_company_color_theme($doc);
    $pdf = new DpPdfEngine($theme, $companyName, $companyPhone, $companyNtn);

    // Margins: left=36, right=36, bottom=85, top=25
    $left = 36.0;
    $right = 36.0;
    $pdf->setMargins($left, $right, 85.0, 25.0);
    $width = $pdf->getPrintableWidth(); // 523.28 pt

    // Attach footer banner image ONLY if primary company (DATAPOINT)
    if (!empty($theme['is_primary'])) {
        $footerBannerPath = resolve_company_logo_path('/static/img/dptech_footer_banner.jpg');
        if ($footerBannerPath && file_exists($footerBannerPath)) {
            $pdf->setFooterBannerImage($footerBannerPath, $left, 28.0, $width, 45.0);
        }
    }

    // Determine Document Title
    if ($type === 'INVOICE') {
        $docTitle = !empty($doc['invoice_heading']) ? (string)$doc['invoice_heading'] : (!empty($theme['is_primary']) ? 'SALES TAX INVOICE' : 'COMMERCIAL INVOICE');
        $docSubtitle = 'SALES TAX INVOICE ACT 1990';
    } else {
        $docTitle = !empty($doc['estimate_heading']) ? (string)$doc['estimate_heading'] : (!empty($doc['title_heading']) ? (string)$doc['title_heading'] : ($theme['doc_title'] ?? 'QUOTATION'));
        $docSubtitle = 'OFFICIAL ESTIMATE / PROPOSAL';
    }

    // Right Column for Document Title (strictly justified within right area width)
    $rightAreaW = 200.0;
    $rightAreaX = $left + $width - $rightAreaW;

    $docTitleSize = 18.0;
    while ($pdf->getTextWidth($docTitle, 'F2', $docTitleSize) > ($rightAreaW - 4.0) && $docTitleSize > 10.0) {
        $docTitleSize -= 0.5;
    }

    // Left Column for Logo & Company Name (from Companies Setup)
    $leftAreaMaxW = $rightAreaX - $left - 12.0;
    $logoResolved = resolve_company_logo_path($logoUrl);

    if ($logoResolved) {
        $logoMaxW = 85.0;
        $logoMaxH = 48.0;
        $logoInfo = $pdf->addImage($logoResolved, $left, 816.0, $logoMaxW, $logoMaxH);
        $actualLogoW = $logoInfo ? $logoInfo['width'] : $logoMaxW;
        $companyTextX = $left + $actualLogoW + 10.0;
        $companyMaxW = ($rightAreaX - 10.0) - $companyTextX;
    } else {
        $pdf->setFillColorArray($theme['primary']);
        $pdf->drawRoundedRect($left, 768.0, 48.0, 44.0, 4.0, true, false);
        $pdf->setFillColor(1, 1, 1);
        $pdf->drawText($left, 782.0, $theme['initials'] ?? 'DPT', 'F2', 15.0, 'center', 48.0);
        $companyTextX = $left + 58.0;
        $companyMaxW = ($rightAreaX - 10.0) - $companyTextX;
    }

    // 1. Top Header: Company Name (from Companies Setup) & Document Title (justified)
    $compFontSize = 14.5;
    while ($pdf->getTextWidth($companyName, 'F2', $compFontSize) > ($companyMaxW - 4.0) && $compFontSize > 9.0) {
        $compFontSize -= 0.5;
    }
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($companyTextX, 796.0, $companyName, 'F2', $compFontSize);

    // Document Title on top right (right-aligned to exact page margin)
    $pdf->drawText($rightAreaX, 796.0, $docTitle, 'F2', $docTitleSize, 'right', $rightAreaW);

    // 2. Slogan & Document Subtitle
    $slogan = $theme['slogan'] ?? 'Offering complete Suite of IT and Security solutions';
    $sloganSize = 8.0;
    while ($pdf->getTextWidth($slogan, 'F2', $sloganSize) > ($companyMaxW - 4.0) && $sloganSize > 6.5) {
        $sloganSize -= 0.5;
    }
    $pdf->setFillColorArray($theme['accent']);
    $pdf->drawText($companyTextX, 783.0, $slogan, 'F2', $sloganSize);
    $pdf->drawText($rightAreaX, 783.0, $docSubtitle, 'F2', 8.5, 'right', $rightAreaW);

    // 3. Address & NTN
    $addrText = $companyAddress;
    $addrSize = 7.2;
    while ($pdf->getTextWidth($addrText, 'F1', $addrSize) > ($companyMaxW - 4.0) && $addrSize > 5.5) {
        $addrSize -= 0.5;
    }
    $pdf->setFillColor(0.35, 0.40, 0.48);
    $pdf->drawText($companyTextX, 771.0, $addrText, 'F1', $addrSize);
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($rightAreaX, 771.0, "NTN: {$companyNtn}", 'F2', 8.5, 'right', $rightAreaW);

    // 4. Contact & STRN
    $contactText = "Tel: {$companyPhone} | Email: {$companyEmail}";
    $contactSize = 7.0;
    while ($pdf->getTextWidth($contactText, 'F1', $contactSize) > ($companyMaxW - 4.0) && $contactSize > 5.5) {
        $contactSize -= 0.5;
    }
    $pdf->setFillColor(0.35, 0.40, 0.48);
    $pdf->drawText($companyTextX, 760.0, $contactText, 'F1', $contactSize);
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($rightAreaX, 760.0, "STRN: {$companyStrn}", 'F1', 8.0, 'right', $rightAreaW);

    // Distinct divider rule under the header
    if (($theme['header_style'] ?? '') === 'corporate_letterhead') {
        // PTC: Dual rule (Thick Slate Charcoal bar + Thin Warm Bronze Gold rule)
        $pdf->setStrokeColorArray($theme['primary']);
        $pdf->setLineWidth(2.0);
        $pdf->drawLine($left, 752.0, $left + $width, 752.0);

        $pdf->setStrokeColorArray($theme['accent']);
        $pdf->setLineWidth(0.8);
        $pdf->drawLine($left, 749.0, $left + $width, 749.0);
    } elseif (($theme['header_style'] ?? '') === 'tech_emerald') {
        // MTC: Crisp Emerald Teal accent line
        $pdf->setStrokeColorArray($theme['accent']);
        $pdf->setLineWidth(1.6);
        $pdf->drawLine($left, 751.0, $left + $width, 751.0);
    } else {
        // DPT: High-tech sapphire / blue rule
        $pdf->setStrokeColorArray($theme['primary']);
        $pdf->setLineWidth(1.2);
        $pdf->drawLine($left, 751.0, $left + $width, 751.0);
    }

    // 5. Metadata Section (Client & Document info)
    $metaY = 736.0;

    // Left Column: Client Details
    $clientName = !empty($doc['client_name']) ? (string)$doc['client_name'] : 'Valued Customer';
    $clientCompany = !empty($doc['client_company']) ? (string)$doc['client_company'] : '';
    $clientPhone = $doc['client_phone'] ?? $doc['client_mobile'] ?? '';
    $clientAddress = $doc['client_address'] ?? '';
    $toLabel = ($type === 'INVOICE') ? 'Invoice To' : 'Quotation To';

    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($left, $metaY, $toLabel, 'F2', 9.0);
    $pdf->drawText($left + 65.0, $metaY, ': ' . $clientName . ($clientCompany !== '' ? " ({$clientCompany})" : ''), 'F2', 9.0);

    $clientSub = array_filter([$clientPhone, $clientAddress]);
    if (!empty($clientSub)) {
        $pdf->setFillColor(0.35, 0.40, 0.48);
        $pdf->drawText($left + 65.0, $metaY - 13.0, ': ' . implode(' | ', $clientSub), 'F1', 8.0);
    }

    // Right Column: Date & Document Number
    $rawDate = $doc['invoice_date'] ?? $doc['estimate_date'] ?? date('Y-m-d');
    $dateTs = strtotime((string)$rawDate);
    $formattedDate = $dateTs ? date('d/m/Y', $dateTs) : (string)$rawDate;
    $docNo = ($type === 'INVOICE') ? ($doc['invoice_no'] ?? 'N/A') : ($doc['estimate_no'] ?? 'N/A');
    $numLabel = ($type === 'INVOICE') ? 'INV: #' : 'EST: #';

    $rightMetaW = 180.0;
    $rightMetaX = $left + $width - $rightMetaW;
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($rightMetaX, $metaY, 'Date: ' . $formattedDate, 'F1', 9.0, 'right', $rightMetaW);
    $pdf->drawText($rightMetaX, $metaY - 13.0, $numLabel . ' ' . $docNo, 'F2', 9.5, 'right', $rightMetaW);
    if ($type === 'INVOICE' && !empty($doc['fbr_invoice_no'])) {
        $pdf->setFillColor(0.06, 0.48, 0.28);
        $pdf->drawText($rightMetaX, $metaY - 24.0, 'FBR Inv #: ' . $doc['fbr_invoice_no'], 'F2', 8.0, 'right', $rightMetaW);
    }

    // 6. Section Title Banner
    $bannerY = $metaY - 34.0;
    $sectionTitle = !empty($doc['title']) ? strtoupper(trim((string)$doc['title'])) : 'ACTIVE COMPONENTS-ZONE-1';
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawRect($left, $bannerY - 16.0, $width, 18.0, true, false);

    if (($theme['header_style'] ?? '') === 'corporate_letterhead') {
        $pdf->setFillColorArray($theme['accent']);
        $pdf->drawRect($left, $bannerY - 16.0, 5.0, 18.0, true, false);
    } elseif (($theme['header_style'] ?? '') === 'tech_emerald') {
        $pdf->setFillColorArray($theme['secondary']);
        $pdf->drawRect($left, $bannerY - 16.0, 5.0, 18.0, true, false);
    }

    $pdf->setFillColor(1, 1, 1);
    $pdf->drawText($left, $bannerY - 12.0, $sectionTitle, 'F2', 9.5, 'center', $width);

    // 7. Line Items Table
    $tableTop = $bannerY - 20.0;
    $pdf->setY($tableTop);

    $colNum = 24.0;
    $colDesc = 165.0;
    $colModel = 145.0;
    $colUnit = 35.0;
    $colQty = 30.0;
    $colRate = 55.0;
    $colTotal = $width - $colNum - $colDesc - $colModel - $colUnit - $colQty - $colRate; // 69.28 pt

    $drawTableHeader = function(DpPdfEngine $p) use ($left, $width, $colNum, $colDesc, $colModel, $colUnit, $colQty, $colRate, $colTotal, $theme) {
        $y = $p->getY();
        $p->setFillColorArray($theme['header_bg']);
        $p->drawRect($left, $y - 18.0, $width, 18.0, true, false);

        if (($theme['header_style'] ?? '') === 'corporate_letterhead') {
            $p->setStrokeColorArray($theme['accent']);
            $p->setLineWidth(1.8);
            $p->drawLine($left, $y, $left + $width, $y);
        } elseif (($theme['header_style'] ?? '') === 'tech_emerald') {
            $p->setStrokeColorArray($theme['secondary']);
            $p->setLineWidth(1.2);
            $p->drawLine($left, $y - 18.0, $left + $width, $y - 18.0);
        }

        $p->setFillColor(1, 1, 1);
        $x = $left;
        $p->drawText($x, $y - 13.0, '#', 'F2', 8.0, 'center', $colNum);
        $x += $colNum;
        $p->drawText($x + 4.0, $y - 13.0, 'Item Description', 'F2', 8.0);
        $x += $colDesc;
        $p->drawText($x + 4.0, $y - 13.0, 'Model / Make', 'F2', 8.0);
        $x += $colModel;
        $p->drawText($x, $y - 13.0, 'Unit', 'F2', 8.0, 'center', $colUnit);
        $x += $colUnit;
        $p->drawText($x, $y - 13.0, 'Qty', 'F2', 8.0, 'center', $colQty);
        $x += $colQty;
        $p->drawText($x, $y - 13.0, 'Rate (PKR)', 'F2', 8.0, 'right', $colRate - 3.0);
        $x += $colRate;
        $p->drawText($x, $y - 13.0, 'Total (PKR)', 'F2', 8.0, 'right', $colTotal - 4.0);

        $p->setY($y - 18.0);
    };

    $drawTableHeader($pdf);

    $items = $doc['items'] ?? [];
    $isOdd = false;
    $itemIdx = 1;

    foreach ($items as $item) {
        $desc = trim((string)($item['description'] ?? 'Item'));
        $modelMake = trim((string)($item['model_make'] ?? ''));
        $unit = (string)($item['unit'] ?? 'No.');
        $qty = (float)($item['quantity'] ?? 1);
        $rate = (float)($item['unit_price'] ?? 0);
        // Tax is NOT on individual items; line total is strictly Qty * Rate
        $total = round($qty * $rate, 2);

        $descLines = $pdf->wrapText($desc, $colDesc - 10.0, 8.0);
        $modelLines = $pdf->wrapText($modelMake !== '' ? $modelMake : '-', $colModel - 10.0, 7.5);
        $maxLines = max(count($descLines), count($modelLines));
        $rowH = max(18.0, $maxLines * 11.0 + 6.0);

        $pdf->checkPageBreak($rowH + 30.0, $drawTableHeader);
        $y = $pdf->getY();

        if ($isOdd) {
            $pdf->setFillColorArray($theme['row_alt']);
            $pdf->drawRect($left, $y - $rowH, $width, $rowH, true, false);
        }
        $isOdd = !$isOdd;

        $pdf->setFillColor(0.20, 0.25, 0.32);

        // #
        $x = $left;
        $pdf->drawText($x, $y - 12.0, (string)$itemIdx, 'F1', 8.0, 'center', $colNum);
        $x += $colNum;

        // Description
        $textY = $y - 12.0;
        foreach ($descLines as $dl) {
            $pdf->drawText($x + 4.0, $textY, $dl, 'F1', 8.0);
            $textY -= 11.0;
        }
        $x += $colDesc;

        // Model / Make
        $textY = $y - 12.0;
        foreach ($modelLines as $ml) {
            $pdf->drawText($x + 4.0, $textY, $ml, 'F1', 7.5);
            $textY -= 11.0;
        }
        $x += $colModel;

        // Unit
        $pdf->drawText($x, $y - 12.0, $unit, 'F1', 8.0, 'center', $colUnit);
        $x += $colUnit;

        // Qty
        $pdf->drawText($x, $y - 12.0, (string)$qty, 'F2', 8.0, 'center', $colQty);
        $x += $colQty;

        // Rate
        $pdf->drawText($x, $y - 12.0, number_format($rate, 2), 'F1', 8.0, 'right', $colRate - 3.0);
        $x += $colRate;

        // Total
        $pdf->drawText($x, $y - 12.0, number_format($total, 2), 'F2', 8.0, 'right', $colTotal - 4.0);

        // Grid border
        $pdf->setStrokeColorArray($theme['row_border']);
        $pdf->setLineWidth(0.4);
        $pdf->drawLine($left, $y - $rowH, $left + $width, $y - $rowH);

        $pdf->setY($y - $rowH);
        $itemIdx++;
    }

    // 6. Summary Block printed at the end of the columns
    $pdf->checkPageBreak(130.0);
    $y = $pdf->getY() - 4.0;

    // Subtotal: sum of all items (Qty * Rate), without individual item tax
    $calcSubtotal = 0.0;
    foreach ($items as $it) {
        $calcSubtotal += round(((float)($it['quantity'] ?? 0)) * ((float)($it['unit_price'] ?? 0)), 2);
    }
    $subtotal = (float)($doc['subtotal'] ?? 0);
    if ($subtotal <= 0.0 || abs($subtotal - $calcSubtotal) > 0.05) {
        $subtotal = $calcSubtotal;
    }

    $taxRate = isset($doc['tax_rate']) && $doc['tax_rate'] !== null && $doc['tax_rate'] !== '' ? (float)$doc['tax_rate'] : 18.0;

    $discPercent = (float)($doc['discount_percent'] ?? 0);
    $discAmount = (float)($doc['discount_amount'] ?? 0);
    if ($discPercent > 0 && $discAmount <= 0) {
        $discAmount = round($subtotal * ($discPercent / 100.0), 2);
    }

    $taxable = max(0.0, round($subtotal - $discAmount, 2));
    $taxAmount = round($taxable * ($taxRate / 100.0), 2);
    $totalAmount = round($taxable + $taxAmount, 2);

    $summaryValX = $left + $width - $colTotal;
    $summaryValW = $colTotal;
    $summaryLabelW = 150.0;
    $summaryLabelX = $summaryValX - $summaryLabelW;

    // Total Amount / Subtotal
    $pdf->setFillColorArray($theme['primary']);
    $subLabel = ($discAmount > 0) ? 'Subtotal:' : 'Total Amount:';
    $pdf->drawText($summaryLabelX, $y - 11.0, $subLabel, 'F2', 9.5, 'right', $summaryLabelW - 8.0);
    $pdf->drawText($summaryValX, $y - 11.0, number_format($subtotal, 2), 'F2', 9.0, 'right', $summaryValW - 4.0);
    $y -= 15.0;

    // If discount was applied
    if ($discAmount > 0) {
        $pdf->setFillColor(0.8, 0.4, 0.0);
        $pdf->drawText($summaryLabelX, $y - 11.0, sprintf('Discount (%g%%):', $discPercent), 'F2', 9.0, 'right', $summaryLabelW - 8.0);
        $pdf->drawText($summaryValX, $y - 11.0, '-' . number_format($discAmount, 2), 'F2', 9.0, 'right', $summaryValW - 4.0);
        $y -= 15.0;

        $pdf->setFillColorArray($theme['primary']);
        $pdf->drawText($summaryLabelX, $y - 11.0, 'Net Total Amount:', 'F2', 9.5, 'right', $summaryLabelW - 8.0);
        $pdf->drawText($summaryValX, $y - 11.0, number_format($taxable, 2), 'F2', 9.0, 'right', $summaryValW - 4.0);
        $y -= 15.0;
    }

    // 18% Tax added to total amount at the end of columns
    $pdf->setFillColorArray($theme['gst_color'] ?? [0.753, 0.224, 0.169]);
    $gstLabel = sprintf('GST @ %g%%', $taxRate);
    $pdf->drawText($summaryLabelX, $y - 11.0, $gstLabel, 'F2', 9.5, 'right', $summaryLabelW - 8.0);
    $pdf->drawText($summaryValX, $y - 11.0, number_format($taxAmount, 2), 'F2', 9.0, 'right', $summaryValW - 4.0);
    $y -= 17.0;

    // Grand Total Solid Bar: Total Amount + Tax 18%
    $barW = 240.0;
    $barX = $left + $width - $barW;
    $pdf->setFillColorArray($theme['total_bar_bg']);
    $pdf->drawRect($barX, $y - 16.0, $barW, 19.0, true, false);
    $pdf->setFillColor(1, 1, 1);
    $pdf->drawText($barX + 8.0, $y - 12.0, 'GRAND TOTAL (PKR)', 'F2', 10.0);
    $pdf->drawText($barX, $y - 12.0, number_format($totalAmount, 2), 'F2', 10.5, 'right', $barW - 8.0);
    $y -= 26.0;

    // 7. Stamp Box (Right), FBR Verification Box (Middle if posted), & Terms (Left)
    $hasFbr = ($type === 'INVOICE' && !empty($doc['fbr_invoice_no']));
    $stampW = $hasFbr ? 110.0 : 118.0;
    $stampH = 75.0;
    $stampX = $left + $width - $stampW;
    $stampY = $y - $stampH;

    if (($theme['stamp_shape'] ?? '') === 'double_rect') {
        // PTC formal double rectangular stamp box
        $pdf->setStrokeColorArray($theme['primary']);
        $pdf->setLineWidth(1.2);
        $pdf->drawRect($stampX, $stampY, $stampW, $stampH, false, true);

        $pdf->setStrokeColorArray($theme['accent']);
        $pdf->setLineWidth(0.6);
        $pdf->drawRect($stampX + 3.0, $stampY + 3.0, $stampW - 6.0, $stampH - 6.0, false, true);

        $pdf->setFillColorArray($theme['primary']);
        $pdf->drawText($stampX, $stampY + ($stampH / 2.0) + 3.0, 'AUTHORIZED', 'F2', 8.5, 'center', $stampW);
        $pdf->drawText($stampX, $stampY + ($stampH / 2.0) - 8.0, 'SIGNATURE & STAMP', 'F2', 8.0, 'center', $stampW);
    } else {
        // Rounded Stamp Border
        $pdf->setStrokeColorArray($theme['accent']);
        $pdf->setLineWidth(1.2);
        $pdf->drawRoundedRect($stampX, $stampY, $stampW, $stampH, 6.0, false, true);

        $pdf->setFillColorArray($theme['primary']);
        $pdf->drawText($stampX, $stampY + ($stampH / 2.0) - 4.0, $theme['stamp_label'] ?? 'STAMP', 'F2', 9.5, 'center', $stampW);
    }

    // FBR Digital Invoicing & Tax Asaan Verification Box
    if ($hasFbr) {
        $fbrBoxW = 95.0;
        $fbrBoxH = $stampH;
        $fbrBoxX = $stampX - $fbrBoxW - 10.0;
        $termsW = $fbrBoxX - $left - 10.0;

        $pdf->setFillColorArray($theme['terms_bg']);
        $pdf->setStrokeColor(0.12, 0.55, 0.35); // subtle green border
        $pdf->setLineWidth(0.8);
        $pdf->drawRoundedRect($fbrBoxX, $stampY, $fbrBoxW, $fbrBoxH, 4.0, true, true);

        // Header caption
        $pdf->setFillColor(0.08, 0.45, 0.25);
        $pdf->drawText($fbrBoxX, $stampY + $fbrBoxH - 10.0, 'FBR DIGITAL INVOICE', 'F2', 6.5, 'center', $fbrBoxW);

        // Vector QR Code
        $qrCodeData = (string)($doc['fbr_qr_code'] ?: $doc['fbr_invoice_no']);
        $qrSize = 51.0;
        $qrX = $fbrBoxX + (($fbrBoxW - $qrSize) / 2.0);
        $qrY = $stampY + 12.0;
        $pdf->drawQrCode($qrX, $qrY, $qrCodeData, $qrSize);

        // Footer caption
        $pdf->setFillColor(0.30, 0.35, 0.42);
        $pdf->drawText($fbrBoxX, $stampY + 3.0, 'SCAN WITH TAX ASAAN', 'F2', 5.5, 'center', $fbrBoxW);
    } else {
        $termsW = $stampX - $left - 15.0;
    }

    // Terms / Notes Box (Left)
    $termsH = $stampH;
    $termsY = $stampY;

    $pdf->setFillColorArray($theme['terms_bg']);
    $pdf->setStrokeColorArray($theme['terms_border']);
    $pdf->setLineWidth(0.6);
    $pdf->drawRoundedRect($left, $termsY, $termsW, $termsH, 4.0, true, true);

    if (($theme['header_style'] ?? '') === 'corporate_letterhead') {
        $pdf->setFillColorArray($theme['accent']);
        $pdf->drawRect($left, $termsY, 4.0, $termsH, true, false);
    }

    $pdf->setFillColorArray($theme['primary']);
    $termsHeader = $theme['terms_title'] ?? ($type === 'INVOICE' ? 'PAYMENT TERMS & NOTES' : 'TERMS & CONDITIONS');
    $pdf->drawText($left + 8.0, $termsY + $termsH - 14.0, $termsHeader, 'F2', 8.5);

    $termsRaw = !empty($doc['terms_conditions']) ? (string)$doc['terms_conditions'] : (!empty($doc['notes']) ? (string)$doc['notes'] : "1. Rates are inclusive/exclusive of taxes as indicated.\n2. Work order / PO confirmed in writing.\n3. Goods once sold are subject to manufacturer warranty.");
    if (!empty($doc['notes']) && $doc['terms_conditions'] !== $doc['notes']) {
        $termsRaw .= "\n" . $doc['notes'];
    }
    $termsLines = explode("\n", $termsRaw);
    $tY = $termsY + $termsH - 26.0;
    $pdf->setFillColor(0.35, 0.40, 0.48);
    foreach ($termsLines as $tl) {
        if ($tY < $termsY + 8.0) break;
        $pdf->drawText($left + 8.0, $tY, trim($tl), 'F1', 7.5);
        $tY -= 10.5;
    }

    return $pdf->render();
}

function generate_invoice_pdf(array $inv): string
{
    return render_branded_sales_tax_document($inv, 'INVOICE');
}

function generate_estimate_pdf(array $est): string
{
    return render_branded_sales_tax_document($est, 'ESTIMATE');
}

function build_pdf_string(array $lines): string
{
    $theme = get_company_color_theme([]);
    $pdf = new DpPdfEngine($theme);
    $left = $pdf->getMarginLeft();
    $y = 770.0;

    foreach ($lines as $line) {
        $pdf->drawText($left, $y, $line, 'F1', 9.5);
        $y -= 14;
        if ($y < 55) {
            $pdf->addPage();
            $y = 770.0;
        }
    }

    return $pdf->render();
}
