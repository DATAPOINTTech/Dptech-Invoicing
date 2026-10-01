<?php

declare(strict_types=1);

const MAX_IMPORT_SIZE = 10 * 1024 * 1024;

function _normalize_text(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $value = str_replace("\u{00A0}", ' ', $value);
    $value = preg_replace('/\r\n?/', "\n", $value) ?? $value;
    $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? $value;
    return trim($value);
}

function _number($value): ?float
{
    if ($value === null) {
        return null;
    }

    $text = preg_replace('/[^0-9.,-]/', '', str_replace(['(', ')'], ['-', ''], (string) $value)) ?? '';
    if ($text === '' || in_array($text, ['-', '.', ','], true)) {
        return null;
    }

    $text = str_replace(',', '', $text);
    $number = (float) $text;
    return is_finite($number) ? $number : null;
}

function _date(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $cleaned = _normalize_text($value);
    $cleaned = str_replace(',', '', $cleaned);
    $formats = ['d-m-y', 'd-m-Y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd M Y', 'd F Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $cleaned);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function _label_value(string $text, array $labels): ?string
{
    $labelsRe = implode('|', array_map(static fn($label) => preg_quote($label, '/'), $labels));
    $nextLabel = '(?:invoice\s*(?:date|no\.?|number)|due\s*date|payment\s*due|(?:bill(?:ed)?\s*to|customer|client|name)|(?:gst|sales\s*tax|tax\s*rate|vat)|(?:payment\s*terms?|terms|notes?|remarks?)|date)';
    $pattern = '/(?:' . $labelsRe . ')\s*(?:#|no\.?|number)?\s*[:\-]?\s*(.+?)(?=\s+(?:' . $nextLabel . ')\s*(?:#|no\.?|number)?\s*[:\-]|\n|$)/is';
    if (preg_match($pattern, $text, $match) !== 1) {
        return null;
    }

    $value = preg_replace('/^[\s:.-]+|[\s:.-]+$/', '', trim($match[1])) ?? trim($match[1]);
    return $value !== '' ? rtrim($value, ':') : null;
}

function _extract_pdf(string $content): array
{
    $text = '';
    $temp = tempnam(sys_get_temp_dir(), 'invoice_');
    if ($temp !== false) {
        file_put_contents($temp, $content);
        exec('pdftotext ' . escapeshellarg($temp) . ' - 2>/dev/null', $lines, $exitCode);
        @unlink($temp);
        if ($exitCode === 0 && !empty($lines)) {
            $text = _normalize_text(implode("\n", $lines));
        }
    }

    return [$text, []];
}

function _extract_docx(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'docx_');
    if ($path === false) {
        return ['', []];
    }

    file_put_contents($path, $content);
    $zip = new ZipArchive();
    $text = '';
    if ($zip->open($path) === true) {
        $xml = $zip->getFromName('word/document.xml');
        if ($xml !== false) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadXML($xml);
            $paragraphs = $dom->getElementsByTagName('w:p');
            $parts = [];
            foreach ($paragraphs as $paragraph) {
                $value = '';
                foreach ($paragraph->getElementsByTagName('w:t') as $node) {
                    $value .= $node->textContent;
                }
                $value = trim($value);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            $text = _normalize_text(implode("\n", $parts));
        }
        $zip->close();
    }
    @unlink($path);
    return [$text, []];
}

function parse_invoice_file(string $filename, string $content): array
{
    if (strlen($content) > MAX_IMPORT_SIZE) {
        throw new InvalidArgumentException('File is larger than the 10 MB import limit.');
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
        [$text, $rows] = _extract_pdf($content);
    } elseif (in_array($extension, ['docx', 'doc'], true)) {
        [$text, $rows] = _extract_docx($content);
    } else {
        throw new InvalidArgumentException('Only PDF and DOCX invoice files are supported.');
    }

    if ($text === '' && empty($rows)) {
        throw new InvalidArgumentException('No readable invoice data was found. Use a text-based PDF or DOCX file.');
    }

    $text = _normalize_text($text);
    $date_match = _label_value($text, ['invoice date', 'date']);
    $due_match = _label_value($text, ['due date', 'payment due']);
    $tax_match = _label_value($text, ['gst', 'sales tax', 'tax rate', 'vat']);
    $client = _label_value($text, ['bill to', 'billed to', 'customer', 'client', 'name']);
    if ($client !== null) {
        $client = preg_split('/\s{2,}/', $client)[0] ?? $client;
    }

    $tax_rate = _number($tax_match ?? '');
    return [
        'client_name' => $client,
        'invoice_date' => $date_match !== null ? _date($date_match) : null,
        'due_date' => $due_match !== null ? _date($due_match) : null,
        'tax_rate' => ($tax_rate !== null && $tax_rate >= 0 && $tax_rate <= 100) ? $tax_rate : null,
        'payment_terms' => _label_value($text, ['payment terms', 'terms']),
        'notes' => _label_value($text, ['notes', 'remarks']),
        'items' => [],
        'warnings' => ['No line items could be identified. Add them manually before saving.'],
    ];
}
