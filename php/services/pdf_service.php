<?php

declare(strict_types=1);

/**
 * DATAPOINT Invoicing System - Graphical Multi-Theme PDF Engine
 * Zero-dependency standards-compliant PDF-1.4 generator with transparent PNG/JPEG logo embedding,
 * multi-company color themes, itemized tables, and exact byte offsets.
 */

function resolve_company_logo_path(?string $logoUrl): ?string
{
    if (empty($logoUrl)) {
        $defaultPath = defined('PHP_APP_ROOT') ? PHP_APP_ROOT . '/public/static/img/logo.png' : __DIR__ . '/../public/static/img/logo.png';
        return file_exists($defaultPath) ? $defaultPath : null;
    }

    $candidates = [];

    if (file_exists($logoUrl)) {
        $candidates[] = $logoUrl;
    }

    $appRoot = defined('PHP_APP_ROOT') ? PHP_APP_ROOT : dirname(__DIR__);

    if (str_starts_with($logoUrl, '/static/')) {
        $candidates[] = $appRoot . '/public' . $logoUrl;
        $candidates[] = $appRoot . $logoUrl;
        $candidates[] = dirname($appRoot) . '/app' . $logoUrl;
    }

    $candidates[] = $appRoot . '/public/' . ltrim($logoUrl, '/');
    $candidates[] = $appRoot . '/' . ltrim($logoUrl, '/');

    foreach ($candidates as $cand) {
        if (file_exists($cand) && is_file($cand)) {
            return $cand;
        }
    }

    return null;
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

    // Company 2: M Tech Cybernet / TechPoint -> Modern Forest Emerald & Teal
    if (abs($markup - 2.0) < 0.1 || $cid === 2 || str_contains($name, 'cybernet') || str_contains($name, 'techpoint')) {
        return [
            'name' => 'Emerald & Forest Teal',
            'primary' => [0.02, 0.32, 0.22],      // #055238
            'accent' => [0.04, 0.65, 0.45],       // #0ba673
            'secondary' => [0.10, 0.75, 0.55],    // #1abf8c
            'light' => [0.92, 0.98, 0.95],        // #ebfaf2
            'tint' => [0.96, 0.99, 0.97],         // #f5fcf8
            'border' => [0.75, 0.88, 0.82],       // #bfdfd1
            'text_dark' => [0.05, 0.18, 0.12],
            'badge_bg' => [0.86, 0.96, 0.90],
            'badge_text' => [0.02, 0.42, 0.28],
            'tag' => null,
            'initials' => 'MTC',
        ];
    }

    // Company 3: Pakistan Technocrates / Apex -> Royal Purple & Majestic Violet
    if (abs($markup - 3.0) < 0.1 || $cid === 3 || str_contains($name, 'technocrates') || str_contains($name, 'apex')) {
        return [
            'name' => 'Royal Purple & Violet',
            'primary' => [0.28, 0.10, 0.45],      // #471a73
            'accent' => [0.55, 0.22, 0.85],       // #8c38d9
            'secondary' => [0.68, 0.35, 0.95],    // #ad59f2
            'light' => [0.96, 0.93, 0.99],        // #f5edfc
            'tint' => [0.98, 0.96, 1.00],         // #faf5ff
            'border' => [0.84, 0.78, 0.92],       // #d6c7eb
            'text_dark' => [0.16, 0.06, 0.26],
            'badge_bg' => [0.92, 0.86, 0.98],
            'badge_text' => [0.38, 0.12, 0.62],
            'tag' => null,
            'initials' => 'PTS',
        ];
    }

    // Company 1: DATAPOINT Technologies (Base / Default) -> Executive Sapphire & Navy
    return [
        'name' => 'Sapphire & Deep Navy',
        'primary' => [0.06, 0.16, 0.32],      // #0f2952
        'accent' => [0.12, 0.40, 0.85],       // #1e66d9
        'secondary' => [0.25, 0.50, 0.90],    // #4080e6
        'light' => [0.93, 0.96, 1.00],        // #edf5ff
        'tint' => [0.96, 0.98, 1.00],         // #f5f9ff
        'border' => [0.80, 0.86, 0.94],       // #ccdcf0
        'text_dark' => [0.08, 0.12, 0.20],
        'badge_bg' => [0.88, 0.93, 1.00],
        'badge_text' => [0.08, 0.25, 0.65],
        'tag' => null,
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
    private array $images = [];
    private array $pageImages = [];
    private array $theme = [];
    private string $companyName = 'DATAPOINT Technologies';

    public function __construct(array $theme = [], string $companyName = '')
    {
        $this->theme = $theme;
        if ($companyName !== '') {
            $this->companyName = $companyName;
        }
        $this->addPage();
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

    public function drawLine(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->append(sprintf("%.2F %.2F m\n%.2F %.2F l\nS\n", $x1, $y1, $x2, $y2));
    }

    public function drawText(float $x, float $y, string $text, string $font = 'F1', float $size = 10, string $align = 'left', ?float $width = null): void
    {
        $safe = $this->escape($text);
        $fontCode = in_array($font, ['F1', 'F2', 'F3'], true) ? $font : 'F1';

        if ($align === 'right' && $width !== null) {
            $approxTextWidth = strlen($safe) * ($size * 0.50);
            $x = $x + $width - $approxTextWidth;
        } elseif ($align === 'center' && $width !== null) {
            $approxTextWidth = strlen($safe) * ($size * 0.50);
            $x = $x + (($width - $approxTextWidth) / 2);
        }

        $cmd = sprintf("BT\n/%s %.2F Tf\n%.2F %.2F Td\n(%s) Tj\nET\n", $fontCode, $size, $x, $y, $safe);
        $this->append($cmd);
    }

    public function wrapText(string $text, float $maxWidth, float $size = 9.0): array
    {
        $words = preg_split('/\s+/', trim($text));
        if ($words === false || empty($words)) {
            return [''];
        }
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            $testWidth = strlen($testLine) * ($size * 0.50);
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
            $parsed = parse_png_for_pdf($filePath);
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

            // Build XObject resource dictionary for this page
            $xobjDict = '';
            foreach ($this->pageImages[$i] as $imgIndex) {
                if (isset($imageObjIds[$imgIndex])) {
                    $xobjDict .= " /I{$imgIndex} {$imageObjIds[$imgIndex]['img']} 0 R";
                }
            }
            $xobjectEntry = $xobjDict !== '' ? " /XObject <<{$xobjDict} >>" : "";

            // Page Footer
            $footerLine = sprintf(
                "%.3F %.3F %.3F RG\n0.80 w\n%.2F 36.00 m\n%.2F 36.00 l\nS\n",
                $this->theme['accent'][0] ?? 0.2,
                $this->theme['accent'][1] ?? 0.4,
                $this->theme['accent'][2] ?? 0.8,
                $this->marginLeft,
                $this->pageWidth - $this->marginRight
            );
            $footerCmd = sprintf(
                "BT\n/F1 8.00 Tf\n0.400 0.450 0.500 rg\n%.2F 24.00 Td\n(%s) Tj\nET\n",
                $this->marginLeft,
                $this->escape("Generated on " . date('Y-m-d H:i') . " • " . $this->companyName)
            );
            $footerPageNum = sprintf(
                "BT\n/F2 8.00 Tf\n0.300 0.350 0.400 rg\n%.2F 24.00 Td\n(%s) Tj\nET\n",
                $this->pageWidth - $this->marginRight - 65,
                $this->escape("Page " . ($i + 1) . " of " . $pageCount)
            );
            $streamContent = $this->pages[$i] . $footerLine . $footerCmd . $footerPageNum;
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

function generate_invoice_pdf(array $inv): string
{
    $companyName = !empty($inv['company_name']) ? (string)$inv['company_name'] : (getenv('COMPANY_NAME') ?: 'DATAPOINT Technologies');
    $companyAddress = !empty($inv['company_address']) ? (string)$inv['company_address'] : (getenv('COMPANY_ADDRESS') ?: 'G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh');
    $companyPhone = !empty($inv['company_phone']) ? (string)$inv['company_phone'] : (getenv('COMPANY_MOBILE') ?: getenv('COMPANY_PHONE') ?: '+923167788990');
    $companyEmail = !empty($inv['company_email']) ? (string)$inv['company_email'] : (getenv('COMPANY_EMAIL') ?: 'info@datapointtechnology.com');
    $companyNtn = !empty($inv['company_ntn']) ? (string)$inv['company_ntn'] : (getenv('COMPANY_NTN') ?: '');
    $companyStrn = !empty($inv['company_strn']) ? (string)$inv['company_strn'] : (getenv('COMPANY_STRN') ?: '');
    $logoUrl = $inv['company_logo'] ?? $inv['logo_url'] ?? null;

    $theme = get_company_color_theme($inv);
    $pdf = new DpPdfEngine($theme, $companyName);
    $left = $pdf->getMarginLeft();
    $width = $pdf->getPrintableWidth();

    // 1. Top Decorative Brand Bar
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawRect($left, 788, $width, 5, true, false);

    // 2. Header Container Frame (Light theme background)
    $headerBoxH = 78.0;
    $headerBoxY = 708.0;
    $pdf->setFillColorArray($theme['light']);
    $pdf->setStrokeColorArray($theme['border']);
    $pdf->setLineWidth(0.8);
    $pdf->drawRect($left, $headerBoxY, $width, $headerBoxH, true, true);

    // 3. Logo Placement or Monogram Badge
    $logoResolved = resolve_company_logo_path($logoUrl);
    $textStartX = $left + 14.0;

    if ($logoResolved) {
        $logoRes = $pdf->addImage($logoResolved, $left + 12, $headerBoxY + $headerBoxH - 12, 54, 54);
        if ($logoRes) {
            $textStartX = $left + 12 + 54 + 14.0;
        }
    } else {
        // Stylish Monogram Badge
        $pdf->setFillColorArray($theme['accent']);
        $pdf->drawRect($left + 12, $headerBoxY + 14, 50, 50, true, false);
        $pdf->setFillColor(1, 1, 1);
        $pdf->drawText($left + 12, $headerBoxY + 31, $theme['initials'] ?? 'DPT', 'F2', 15, 'center', 50);
        $textStartX = $left + 12 + 50 + 14.0;
    }

    // 4. Company Profile Text
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($textStartX, $headerBoxY + $headerBoxH - 20, strtoupper($companyName), 'F2', 13.5);
    $pdf->setFillColor(0.35, 0.40, 0.48);
    $pdf->drawText($textStartX, $headerBoxY + $headerBoxH - 34, $companyAddress, 'F1', 8.5);

    $contactStr = array_filter([$companyPhone, $companyEmail]);
    $pdf->setFillColorArray($theme['accent']);
    $pdf->drawText($textStartX, $headerBoxY + $headerBoxH - 47, implode('  •  ', $contactStr), 'F2', 8.5);

    if ($companyNtn !== '' || $companyStrn !== '') {
        $taxParts = [];
        if ($companyNtn !== '') $taxParts[] = 'NTN: ' . $companyNtn;
        if ($companyStrn !== '') $taxParts[] = 'STRN: ' . $companyStrn;
        $pdf->setFillColorArray($theme['badge_bg']);
        $pdf->drawRect($textStartX, $headerBoxY + 10, 200, 14, true, false);
        $pdf->setFillColorArray($theme['badge_text']);
        $pdf->drawText($textStartX + 6, $headerBoxY + 14, implode('  |  ', $taxParts), 'F2', 7.5);
    }

    // 5. Document Header Box (Right)
    $boxW = 165.0;
    $boxX = $left + $width - $boxW - 10.0;
    $pdf->setFillColor(1, 1, 1);
    $pdf->setStrokeColorArray($theme['border']);
    $pdf->setLineWidth(0.8);
    $pdf->drawRect($boxX, $headerBoxY + 8, $boxW, $headerBoxH - 16, true, true);

    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($boxX + 10, $headerBoxY + $headerBoxH - 24, 'TAX SALES INVOICE', 'F2', 10.5);

    $status = strtoupper((string)($inv['status'] ?? 'DRAFT'));
    $statusColor = $theme['accent'];
    if ($status === 'PAID') {
        $statusColor = [0.08, 0.60, 0.28];
    } elseif ($status === 'PARTIALLY_PAID') {
        $statusColor = [0.85, 0.50, 0.05];
    } elseif ($status === 'OVERDUE') {
        $statusColor = [0.85, 0.15, 0.15];
    }
    $pdf->setFillColorArray($statusColor);
    $pdf->drawText($boxX + $boxW - 60, $headerBoxY + $headerBoxH - 24, '[' . str_replace('_', ' ', $status) . ']', 'F2', 7.5, 'right', 50);

    $pdf->setFillColor(0.2, 0.25, 0.3);
    $pdf->drawText($boxX + 10, $headerBoxY + $headerBoxH - 38, 'Invoice #: ' . ($inv['invoice_no'] ?? 'N/A'), 'F2', 8.5);
    $pdf->drawText($boxX + 10, $headerBoxY + $headerBoxH - 50, 'Date: ' . ($inv['invoice_date'] ?? date('Y-m-d')), 'F1', 8.0);
    $pdf->drawText($boxX + 10, $headerBoxY + $headerBoxH - 62, 'Due Date: ' . ($inv['due_date'] ?? 'On Receipt'), 'F1', 8.0);

    // 6. Billed To Card
    $billY = 694.0;
    $billH = 50.0;
    $pdf->setFillColorArray($theme['light']);
    $pdf->drawRect($left, $billY - $billH, $width, $billH, true, false);
    $pdf->setFillColorArray($theme['accent']);
    $pdf->drawRect($left, $billY - $billH, 3.5, $billH, true, false);

    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($left + 12, $billY - 14, 'BILLED TO:', 'F2', 8.5);

    $clientName = $inv['client_name'] ?? 'Valued Customer';
    $clientCompany = $inv['client_company'] ?? '';
    $clientPhone = $inv['client_phone'] ?? $inv['client_mobile'] ?? '';
    $clientEmail = $inv['client_email'] ?? '';
    $clientAddress = $inv['client_address'] ?? '';
    $clientNtn = $inv['client_ntn'] ?? '';

    $pdf->setFillColor(0.12, 0.15, 0.20);
    $pdf->drawText($left + 12, $billY - 26, $clientName . ($clientCompany !== '' ? ' (' . $clientCompany . ')' : ''), 'F2', 9.5);

    $clientLine2 = array_filter([$clientPhone, $clientEmail, $clientNtn !== '' ? 'NTN: ' . $clientNtn : '']);
    $pdf->setFillColor(0.40, 0.45, 0.52);
    $pdf->drawText($left + 12, $billY - 37, implode('  •  ', $clientLine2), 'F1', 8.0);
    if ($clientAddress !== '') {
        $pdf->drawText($left + 12, $billY - 47, $clientAddress, 'F1', 8.0);
    }

    // 7. Line Items Table
    $tableTop = $billY - $billH - 12.0;
    $pdf->setY($tableTop);

    $colDesc = 250.0;
    $colQty = 45.0;
    $colUnit = 45.0;
    $colRate = 85.0;
    $colTotal = $width - $colDesc - $colQty - $colUnit - $colRate;

    $drawTableHeader = function(DpPdfEngine $p) use ($left, $width, $colDesc, $colQty, $colUnit, $colRate, $colTotal, $theme) {
        $y = $p->getY();
        $p->setFillColorArray($theme['primary']);
        $p->drawRect($left, $y - 18, $width, 20, true, false);

        $p->setFillColor(1, 1, 1);
        $p->drawText($left + 8, $y - 13, 'ITEM DESCRIPTION', 'F2', 8.5);
        $p->drawText($left + $colDesc, $y - 13, 'QTY', 'F2', 8.5, 'center', $colQty);
        $p->drawText($left + $colDesc + $colQty, $y - 13, 'UNIT', 'F2', 8.5, 'center', $colUnit);
        $p->drawText($left + $colDesc + $colQty + $colUnit, $y - 13, 'RATE (PKR)', 'F2', 8.5, 'right', $colRate - 5);
        $p->drawText($left + $colDesc + $colQty + $colUnit + $colRate, $y - 13, 'AMOUNT (PKR)', 'F2', 8.5, 'right', $colTotal - 8);
        $p->setY($y - 20);
    };

    $drawTableHeader($pdf);

    $items = $inv['items'] ?? [];
    $isOdd = false;

    foreach ($items as $item) {
        $desc = trim((string)($item['description'] ?? 'Product / Service'));
        $qty = (float)($item['quantity'] ?? 1);
        $unit = (string)($item['unit'] ?? 'pcs');
        $rate = (float)($item['unit_price'] ?? 0);
        $total = (float)($item['total_price'] ?? ($qty * $rate));

        $lines = $pdf->wrapText($desc, $colDesc - 15, 8.5);
        $rowHeight = max(18.0, count($lines) * 12.0 + 6.0);

        $pdf->checkPageBreak($rowHeight + 35, $drawTableHeader);
        $y = $pdf->getY();

        if ($isOdd) {
            $pdf->setFillColorArray($theme['tint']);
            $pdf->drawRect($left, $y - $rowHeight, $width, $rowHeight, true, false);
        }
        $isOdd = !$isOdd;

        $pdf->setFillColor(0.15, 0.15, 0.18);
        $textY = $y - 12;
        foreach ($lines as $line) {
            $pdf->drawText($left + 8, $textY, $line, 'F1', 8.5);
            $textY -= 12;
        }

        $pdf->drawText($left + $colDesc, $y - 12, (string)$qty, 'F1', 8.5, 'center', $colQty);
        $pdf->drawText($left + $colDesc + $colQty, $y - 12, $unit, 'F1', 8.5, 'center', $colUnit);
        $pdf->drawText($left + $colDesc + $colQty + $colUnit, $y - 12, number_format($rate, 2), 'F1', 8.5, 'right', $colRate - 5);
        $pdf->drawText($left + $colDesc + $colQty + $colUnit + $colRate, $y - 12, number_format($total, 2), 'F2', 8.5, 'right', $colTotal - 8);

        $pdf->setStrokeColorArray($theme['border']);
        $pdf->setLineWidth(0.4);
        $pdf->drawLine($left, $y - $rowHeight, $left + $width, $y - $rowHeight);

        $pdf->setY($y - $rowHeight);
    }

    // 8. Totals Breakdown Card
    $pdf->checkPageBreak(135.0);
    $y = $pdf->getY() - 10;

    $summaryW = 215.0;
    $summaryX = $left + $width - $summaryW;

    $subtotal = (float)($inv['subtotal'] ?? 0);
    $discountAmount = (float)($inv['discount_amount'] ?? 0);
    $taxRate = (float)($inv['tax_rate'] ?? 17);
    $taxAmount = (float)($inv['tax_amount'] ?? 0);
    $whtAmount = (float)($inv['withholding_tax_amount'] ?? 0);
    $fedAmount = (float)($inv['fed_amount'] ?? 0);
    $totalAmount = (float)($inv['total_amount'] ?? 0);
    $amountPaid = (float)($inv['amount_paid'] ?? 0);
    $balanceDue = (float)($inv['balance_due'] ?? max(0, $totalAmount - $amountPaid));

    $summaryRows = [
        ['Subtotal:', 'PKR ' . number_format($subtotal, 2), false],
    ];
    if ($discountAmount > 0) {
        $summaryRows[] = ['Discount:', '-PKR ' . number_format($discountAmount, 2), false];
    }
    if ($taxAmount > 0) {
        $summaryRows[] = ["GST ({$taxRate}%):", 'PKR ' . number_format($taxAmount, 2), false];
    }
    if ($whtAmount > 0) {
        $summaryRows[] = ['Withholding Tax:', '-PKR ' . number_format($whtAmount, 2), false];
    }
    if ($fedAmount > 0) {
        $summaryRows[] = ['FED:', 'PKR ' . number_format($fedAmount, 2), false];
    }
    $summaryRows[] = ['Total Amount:', 'PKR ' . number_format($totalAmount, 2), true];
    $summaryRows[] = ['Amount Paid:', 'PKR ' . number_format($amountPaid, 2), false];
    $summaryRows[] = ['Balance Due:', 'PKR ' . number_format($balanceDue, 2), true];

    foreach ($summaryRows as $sRow) {
        $label = $sRow[0];
        $val = $sRow[1];
        $isBold = $sRow[2];

        if ($label === 'Total Amount:') {
            $pdf->setFillColorArray($theme['primary']);
            $pdf->drawRect($summaryX, $y - 14, $summaryW, 17, true, false);
            $pdf->setFillColor(1, 1, 1);
            $pdf->drawText($summaryX + 6, $y - 11, $label, 'F2', 9.5);
            $pdf->drawText($summaryX + 6, $y - 11, $val, 'F2', 9.5, 'right', $summaryW - 12);
            $y -= 20;
            continue;
        }

        if ($label === 'Balance Due:') {
            if ($balanceDue <= 0.01) {
                $pdf->setFillColor(0.90, 0.97, 0.92);
                $pdf->drawRect($summaryX, $y - 14, $summaryW, 16, true, false);
                $pdf->setFillColor(0.1, 0.6, 0.2);
            } else {
                $pdf->setFillColor(0.99, 0.92, 0.92);
                $pdf->drawRect($summaryX, $y - 14, $summaryW, 16, true, false);
                $pdf->setFillColor(0.75, 0.15, 0.15);
            }
            $pdf->drawText($summaryX + 6, $y - 11, $label, 'F2', 9.5);
            $pdf->drawText($summaryX + 6, $y - 11, $val, 'F2', 9.5, 'right', $summaryW - 12);
            $y -= 19;
            continue;
        }

        $pdf->setFillColor(0.35, 0.35, 0.40);
        $pdf->drawText($summaryX + 6, $y - 11, $label, $isBold ? 'F2' : 'F1', 8.5);
        $pdf->drawText($summaryX + 6, $y - 11, $val, $isBold ? 'F2' : 'F1', 8.5, 'right', $summaryW - 12);
        $y -= 15;
    }

    // 9. Payment Instructions & Terms (Left)
    $termsW = $width - $summaryW - 20.0;
    $termsY = $pdf->getY() - 15;
    $pdf->setFillColorArray($theme['light']);
    $pdf->setStrokeColorArray($theme['border']);
    $pdf->drawRect($left, $termsY - 55, $termsW, 68, true, true);

    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($left + 8, $termsY + 2, 'PAYMENT TERMS & BANK INSTRUCTIONS', 'F2', 8.5);

    $termsText = !empty($inv['terms_conditions']) ? (string)$inv['terms_conditions'] : "1. Payment is due as per agreed billing cycle.\n2. Please quote Invoice Number in online bank transfers.";
    if (!empty($inv['notes'])) {
        $termsText .= "\n" . $inv['notes'];
    }
    $termsLines = explode("\n", $termsText);
    $ty = $termsY - 10;
    $pdf->setFillColor(0.40, 0.45, 0.50);
    foreach ($termsLines as $tl) {
        if ($ty < $termsY - 50) break;
        $pdf->drawText($left + 8, $ty, trim($tl), 'F1', 7.5);
        $ty -= 11;
    }

    return $pdf->render();
}

function generate_estimate_pdf(array $est): string
{
    $companyName = !empty($est['company_name']) ? (string)$est['company_name'] : (getenv('COMPANY_NAME') ?: 'DATAPOINT Technologies');
    $companyAddress = !empty($est['company_address']) ? (string)$est['company_address'] : (getenv('COMPANY_ADDRESS') ?: 'G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh');
    $companyPhone = !empty($est['company_phone']) ? (string)$est['company_phone'] : (getenv('COMPANY_MOBILE') ?: getenv('COMPANY_PHONE') ?: '+923167788990');
    $companyEmail = !empty($est['company_email']) ? (string)$est['company_email'] : (getenv('COMPANY_EMAIL') ?: 'info@datapointtechnology.com');
    $companyNtn = !empty($est['company_ntn']) ? (string)$est['company_ntn'] : (getenv('COMPANY_NTN') ?: '');
    $companyStrn = !empty($est['company_strn']) ? (string)$est['company_strn'] : (getenv('COMPANY_STRN') ?: '');
    $logoUrl = $est['company_logo'] ?? $est['logo_url'] ?? null;

    $theme = get_company_color_theme($est);
    $pdf = new DpPdfEngine($theme, $companyName);
    $left = $pdf->getMarginLeft();
    $width = $pdf->getPrintableWidth();

    // 1. Top Decorative Brand Bar
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawRect($left, 788, $width, 5, true, false);

    // 2. Header Container Frame (Light theme background)
    $headerBoxH = 78.0;
    $headerBoxY = 708.0;
    $pdf->setFillColorArray($theme['light']);
    $pdf->setStrokeColorArray($theme['border']);
    $pdf->setLineWidth(0.8);
    $pdf->drawRect($left, $headerBoxY, $width, $headerBoxH, true, true);

    // 3. Logo Placement or Monogram Badge
    $logoResolved = resolve_company_logo_path($logoUrl);
    $textStartX = $left + 14.0;

    if ($logoResolved) {
        $logoRes = $pdf->addImage($logoResolved, $left + 12, $headerBoxY + $headerBoxH - 12, 54, 54);
        if ($logoRes) {
            $textStartX = $left + 12 + 54 + 14.0;
        }
    } else {
        // Stylish Monogram Badge
        $pdf->setFillColorArray($theme['accent']);
        $pdf->drawRect($left + 12, $headerBoxY + 14, 50, 50, true, false);
        $pdf->setFillColor(1, 1, 1);
        $pdf->drawText($left + 12, $headerBoxY + 31, $theme['initials'] ?? 'DPT', 'F2', 15, 'center', 50);
        $textStartX = $left + 12 + 50 + 14.0;
    }

    // 4. Company Profile Text
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($textStartX, $headerBoxY + $headerBoxH - 20, strtoupper($companyName), 'F2', 13.5);
    $pdf->setFillColor(0.35, 0.40, 0.48);
    $pdf->drawText($textStartX, $headerBoxY + $headerBoxH - 34, $companyAddress, 'F1', 8.5);

    $contactStr = array_filter([$companyPhone, $companyEmail]);
    $pdf->setFillColorArray($theme['accent']);
    $pdf->drawText($textStartX, $headerBoxY + $headerBoxH - 47, implode('  •  ', $contactStr), 'F2', 8.5);

    if ($companyNtn !== '' || $companyStrn !== '') {
        $taxParts = [];
        if ($companyNtn !== '') $taxParts[] = 'NTN: ' . $companyNtn;
        if ($companyStrn !== '') $taxParts[] = 'STRN: ' . $companyStrn;
        $pdf->setFillColorArray($theme['badge_bg']);
        $pdf->drawRect($textStartX, $headerBoxY + 10, 200, 14, true, false);
        $pdf->setFillColorArray($theme['badge_text']);
        $pdf->drawText($textStartX + 6, $headerBoxY + 14, implode('  |  ', $taxParts), 'F2', 7.5);
    }

    // 5. Document Header Box (Right)
    $boxW = 165.0;
    $boxX = $left + $width - $boxW - 10.0;
    $pdf->setFillColor(1, 1, 1);
    $pdf->setStrokeColorArray($theme['border']);
    $pdf->setLineWidth(0.8);
    $pdf->drawRect($boxX, $headerBoxY + 8, $boxW, $headerBoxH - 16, true, true);

    // Card Heading: "ESTIMATE / QUOTATION"
    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($boxX + 10, $headerBoxY + $headerBoxH - 24, 'ESTIMATE / QUOTATION', 'F2', 10.0);

    $pdf->setFillColor(0.2, 0.25, 0.3);
    $pdf->drawText($boxX + 10, $headerBoxY + $headerBoxH - 38, 'Estimate #: ' . ($est['estimate_no'] ?? 'N/A'), 'F2', 8.5);
    $pdf->drawText($boxX + 10, $headerBoxY + $headerBoxH - 50, 'Date: ' . ($est['estimate_date'] ?? date('Y-m-d')), 'F1', 8.0);
    $pdf->drawText($boxX + 10, $headerBoxY + $headerBoxH - 62, 'Valid Until: ' . ($est['valid_until'] ?? '15 Days'), 'F1', 8.0);

    // 6. Proposal Prepared For Card
    $billY = 694.0;
    $billH = 50.0;
    $pdf->setFillColorArray($theme['light']);
    $pdf->drawRect($left, $billY - $billH, $width, $billH, true, false);
    $pdf->setFillColorArray($theme['accent']);
    $pdf->drawRect($left, $billY - $billH, 3.5, $billH, true, false);

    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($left + 12, $billY - 14, 'PROPOSAL PREPARED FOR:', 'F2', 8.5);

    $clientName = $est['client_name'] ?? 'Valued Customer';
    $clientCompany = $est['client_company'] ?? '';
    $clientPhone = $est['client_phone'] ?? '';
    $clientEmail = $est['client_email'] ?? '';
    $clientAddress = $est['client_address'] ?? '';

    $pdf->setFillColor(0.12, 0.15, 0.20);
    $pdf->drawText($left + 12, $billY - 26, $clientName . ($clientCompany !== '' ? ' (' . $clientCompany . ')' : ''), 'F2', 9.5);

    $clientLine2 = array_filter([$clientPhone, $clientEmail]);
    $pdf->setFillColor(0.40, 0.45, 0.52);
    $pdf->drawText($left + 12, $billY - 37, implode('  •  ', $clientLine2), 'F1', 8.0);
    if ($clientAddress !== '') {
        $pdf->drawText($left + 12, $billY - 47, $clientAddress, 'F1', 8.0);
    }

    // 7. Line Items Table
    $tableTop = $billY - $billH - 12.0;
    $pdf->setY($tableTop);

    $colDesc = 250.0;
    $colQty = 45.0;
    $colUnit = 45.0;
    $colRate = 85.0;
    $colTotal = $width - $colDesc - $colQty - $colUnit - $colRate;

    $drawTableHeader = function(DpPdfEngine $p) use ($left, $width, $colDesc, $colQty, $colUnit, $colRate, $colTotal, $theme) {
        $y = $p->getY();
        $p->setFillColorArray($theme['primary']);
        $p->drawRect($left, $y - 18, $width, 20, true, false);

        $p->setFillColor(1, 1, 1);
        $p->drawText($left + 8, $y - 13, 'ITEM & SPECIFICATION', 'F2', 8.5);
        $p->drawText($left + $colDesc, $y - 13, 'QTY', 'F2', 8.5, 'center', $colQty);
        $p->drawText($left + $colDesc + $colQty, $y - 13, 'UNIT', 'F2', 8.5, 'center', $colUnit);
        $p->drawText($left + $colDesc + $colQty + $colUnit, $y - 13, 'RATE (PKR)', 'F2', 8.5, 'right', $colRate - 5);
        $p->drawText($left + $colDesc + $colQty + $colUnit + $colRate, $y - 13, 'AMOUNT (PKR)', 'F2', 8.5, 'right', $colTotal - 8);
        $p->setY($y - 20);
    };

    $drawTableHeader($pdf);

    $items = $est['items'] ?? [];
    $isOdd = false;

    foreach ($items as $item) {
        $desc = trim((string)($item['description'] ?? 'Product / Service'));
        $qty = (float)($item['quantity'] ?? 1);
        $unit = (string)($item['unit'] ?? 'pcs');
        $rate = (float)($item['unit_price'] ?? 0);
        $total = (float)($item['total_price'] ?? ($qty * $rate));

        $lines = $pdf->wrapText($desc, $colDesc - 15, 8.5);
        $rowHeight = max(18.0, count($lines) * 12.0 + 6.0);

        $pdf->checkPageBreak($rowHeight + 35, $drawTableHeader);
        $y = $pdf->getY();

        if ($isOdd) {
            $pdf->setFillColorArray($theme['tint']);
            $pdf->drawRect($left, $y - $rowHeight, $width, $rowHeight, true, false);
        }
        $isOdd = !$isOdd;

        $pdf->setFillColor(0.15, 0.15, 0.18);
        $textY = $y - 12;
        foreach ($lines as $line) {
            $pdf->drawText($left + 8, $textY, $line, 'F1', 8.5);
            $textY -= 12;
        }

        $pdf->drawText($left + $colDesc, $y - 12, (string)$qty, 'F1', 8.5, 'center', $colQty);
        $pdf->drawText($left + $colDesc + $colQty, $y - 12, $unit, 'F1', 8.5, 'center', $colUnit);
        $pdf->drawText($left + $colDesc + $colQty + $colUnit, $y - 12, number_format($rate, 2), 'F1', 8.5, 'right', $colRate - 5);
        $pdf->drawText($left + $colDesc + $colQty + $colUnit + $colRate, $y - 12, number_format($total, 2), 'F2', 8.5, 'right', $colTotal - 8);

        $pdf->setStrokeColorArray($theme['border']);
        $pdf->setLineWidth(0.4);
        $pdf->drawLine($left, $y - $rowHeight, $left + $width, $y - $rowHeight);

        $pdf->setY($y - $rowHeight);
    }

    // 8. Totals Breakdown Card
    $pdf->checkPageBreak(115.0);
    $y = $pdf->getY() - 10;

    $summaryW = 215.0;
    $summaryX = $left + $width - $summaryW;

    $subtotal = (float)($est['subtotal'] ?? 0);
    $discountAmount = (float)($est['discount_amount'] ?? 0);
    $taxRate = (float)($est['tax_rate'] ?? 17);
    $taxAmount = (float)($est['tax_amount'] ?? 0);
    $totalAmount = (float)($est['total_amount'] ?? 0);

    $summaryRows = [
        ['Subtotal:', 'PKR ' . number_format($subtotal, 2), false],
    ];
    if ($discountAmount > 0) {
        $summaryRows[] = ['Discount:', '-PKR ' . number_format($discountAmount, 2), false];
    }
    if ($taxAmount > 0) {
        $summaryRows[] = ["GST ({$taxRate}%):", 'PKR ' . number_format($taxAmount, 2), false];
    }
    $summaryRows[] = ['Grand Total:', 'PKR ' . number_format($totalAmount, 2), true];

    foreach ($summaryRows as $sRow) {
        $label = $sRow[0];
        $val = $sRow[1];

        if ($label === 'Grand Total:') {
            $pdf->setFillColorArray($theme['primary']);
            $pdf->drawRect($summaryX, $y - 14, $summaryW, 17, true, false);
            $pdf->setFillColor(1, 1, 1);
            $pdf->drawText($summaryX + 6, $y - 11, $label, 'F2', 9.5);
            $pdf->drawText($summaryX + 6, $y - 11, $val, 'F2', 9.5, 'right', $summaryW - 12);
            $y -= 20;
            continue;
        }

        $pdf->setFillColor(0.35, 0.35, 0.40);
        $pdf->drawText($summaryX + 6, $y - 11, $label, 'F1', 8.5);
        $pdf->drawText($summaryX + 6, $y - 11, $val, 'F2', 8.5, 'right', $summaryW - 12);
        $y -= 15;
    }

    // 9. Terms & Conditions Box (Left)
    $termsW = $width - $summaryW - 20.0;
    $termsY = $pdf->getY() - 15;
    $pdf->setFillColorArray($theme['light']);
    $pdf->setStrokeColorArray($theme['border']);
    $pdf->drawRect($left, $termsY - 50, $termsW, 62, true, true);

    $pdf->setFillColorArray($theme['primary']);
    $pdf->drawText($left + 8, $termsY + 2, 'QUOTATION TERMS & VALIDITY', 'F2', 8.5);

    $termsText = !empty($est['terms_conditions']) ? (string)$est['terms_conditions'] : "1. Rates are valid for 15 days from estimate date.\n2. Work order must be confirmed in writing.\n3. Applicable taxes will be charged as per tax laws.";
    $termsLines = explode("\n", $termsText);
    $ty = $termsY - 10;
    $pdf->setFillColor(0.40, 0.45, 0.50);
    foreach ($termsLines as $tl) {
        if ($ty < $termsY - 45) break;
        $pdf->drawText($left + 8, $ty, trim($tl), 'F1', 7.5);
        $ty -= 11;
    }

    return $pdf->render();
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
