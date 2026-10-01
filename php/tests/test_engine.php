<?php

declare(strict_types=1);

class DpPdfEngine
{
    private float $pageWidth = 595.28;
    private float $pageHeight = 841.89;
    private array $pages = [];
    private int $currentPage = -1;
    private float $currentY = 780.0;
    private float $marginLeft = 40.0;
    private float $marginRight = 40.0;
    private float $marginBottom = 60.0;

    public function __construct()
    {
        $this->addPage();
    }

    public function getPageWidth(): float
    {
        return $this->pageWidth;
    }

    public function getPrintableWidth(): float
    {
        return $this->pageWidth - $this->marginLeft - $this->marginRight;
    }

    public function getY(): float
    {
        return $this->currentY;
    }

    public function setY(float $y): void
    {
        $this->currentY = $y;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->currentPage = count($this->pages) - 1;
        $this->currentY = 790.0;
    }

    public function ensureSpace(float $heightNeeded, ?callable $onNewPageHeader = null): void
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
        // Replace non-ascii chars safely for standard WinAnsiEncoding
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text) ?: $text;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    public function setFillColor(float $r, float $g, float $b): void
    {
        $this->append(sprintf("%.3F %.3F %.3F rg\n", max(0, min(1, $r)), max(0, min(1, $g)), max(0, min(1, $b))));
    }

    public function setStrokeColor(float $r, float $g, float $b): void
    {
        $this->append(sprintf("%.3F %.3F %.3F RG\n", max(0, min(1, $r)), max(0, min(1, $g)), max(0, min(1, $b))));
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
        
        // Approximate width for alignment: average char width ~ 0.5 * size for standard Helvetica
        if ($align === 'right' && $width !== null) {
            $approxTextWidth = strlen($safe) * ($size * 0.52);
            $x = $x + $width - $approxTextWidth;
        } elseif ($align === 'center' && $width !== null) {
            $approxTextWidth = strlen($safe) * ($size * 0.52);
            $x = $x + (($width - $approxTextWidth) / 2);
        }

        $cmd = sprintf("BT\n/%s %.2F Tf\n%.2F %.2F Td\n(%s) Tj\nET\n", $fontCode, $size, $x, $y, $safe);
        $this->append($cmd);
    }

    public function drawTextMultiline(float $x, float $y, string $text, float $maxWidth, string $font = 'F1', float $size = 10, float $lineHeight = 12): float
    {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $candidate = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            $candidateWidth = strlen($candidate) * ($size * 0.52);
            if ($candidateWidth > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $candidate;
            }
        }
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        $currY = $y;
        foreach ($lines as $line) {
            $this->drawText($x, $currY, $line, $font, $size);
            $currY -= $lineHeight;
        }

        return count($lines) * $lineHeight;
    }

    public function render(): string
    {
        $pageCount = count($this->pages);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $nextObjId = 1;

        // 1 0 obj: Catalog
        $catalogObj = $nextObjId++;
        $offsets[$catalogObj] = strlen($out);
        $pagesObj = $nextObjId++;
        $out .= "{$catalogObj} 0 obj\n<< /Type /Catalog /Pages {$pagesObj} 0 R >>\nendobj\n";

        // Collect page object IDs
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

        // 2 0 obj: Pages object
        $offsets[$pagesObj] = strlen($out);
        $kidsStr = implode(' 0 R ', $pageObjIds) . ' 0 R';
        $out .= "{$pagesObj} 0 obj\n<< /Type /Pages /Kids [{$kidsStr}] /Count {$pageCount} >>\nendobj\n";

        // Pages and Content Streams
        for ($i = 0; $i < $pageCount; $i++) {
            $pId = $pageObjIds[$i];
            $sId = $streamObjIds[$i];
            $streamContent = $this->pages[$i];
            $streamLen = strlen($streamContent);

            // Page Object
            $offsets[$pId] = strlen($out);
            $out .= "{$pId} 0 obj\n<< /Type /Page /Parent {$pagesObj} 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] /Contents {$sId} 0 R /Resources << /Font << /F1 {$fontRegular} 0 R /F2 {$fontBold} 0 R /F3 {$fontOblique} 0 R >> /ProcSet [/PDF /Text] >> >>\nendobj\n";

            // Stream Object
            $offsets[$sId] = strlen($out);
            $out .= "{$sId} 0 obj\n<< /Length {$streamLen} >>\nstream\n{$streamContent}\nendstream\nendobj\n";
        }

        // Fonts Objects
        $offsets[$fontRegular] = strlen($out);
        $out .= "{$fontRegular} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        $offsets[$fontBold] = strlen($out);
        $out .= "{$fontBold} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        $offsets[$fontOblique] = strlen($out);
        $out .= "{$fontOblique} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>\nendobj\n";

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

$engine = new DpPdfEngine();
$engine->setFillColor(0.08, 0.18, 0.36);
$engine->drawRect(40, 770, 515, 45, true, false);
$engine->setFillColor(1, 1, 1);
$engine->drawText(55, 792, "DATAPOINT TECHNOLOGIES", "F2", 15);
$engine->drawText(55, 778, "Official Invoicing & Taxation Engine", "F1", 9);

$engine->setFillColor(0.1, 0.1, 0.1);
$engine->setStrokeColor(0.85, 0.85, 0.85);
$engine->drawRect(40, 680, 515, 70, false, true);

$engine->drawText(50, 730, "TAX INVOICE", "F2", 12);
$engine->drawText(50, 715, "Invoice #: INV-2026-0001", "F1", 10);
$engine->drawText(50, 700, "Date: 2026-09-30", "F1", 10);

$res = $engine->render();
file_put_contents(__DIR__ . '/test_engine.pdf', $res);
echo "Engine generated valid PDF: " . strlen($res) . " bytes\n";
