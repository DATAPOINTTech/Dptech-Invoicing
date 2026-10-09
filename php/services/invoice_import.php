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

    $cleaned = trim(preg_replace('/\s+/', ' ', str_replace(',', '', $value)));
    $cleaned = preg_replace('/^[^\w\d]+/', '', $cleaned);
    $formats = [
        'd-m-y', 'd-m-Y', 'Y-m-d', 'd/m/Y', 'd/m/y', 'm/d/Y', 'm/d/y',
        'd.m.Y', 'd.m.y', 'd M Y', 'd F Y', 'Y/m/d'
    ];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $cleaned);
        if ($date !== false) {
            $year = (int) $date->format('Y');
            if ($year < 70) {
                $date->modify('+2000 years');
            } elseif ($year < 100) {
                $date->modify('+1900 years');
            }
            return $date->format('Y-m-d');
        }
    }

    $ts = strtotime($cleaned);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return null;
}

function _decode_pdf_string(string $s): string
{
    $s = preg_replace_callback('/\\\([0-7]{1,3})/', function ($m) {
        return chr(octdec($m[1]));
    }, $s);
    return str_replace(
        ['\\\\', '\\(', '\\)', '\\n', '\\r', '\\t'],
        ['\\', '(', ')', "\n", "\r", "\t"],
        $s
    );
}

function _parse_pdf_cmaps(string $pdfContent): array
{
    $cmapMap = [];
    if (!preg_match_all('/\/ToUnicode\s+(\d+)\s+(\d+)\s+R/', $pdfContent, $toUnicodeRefs)) {
        return [];
    }
    $objIds = array_unique($toUnicodeRefs[1]);

    foreach ($objIds as $objId) {
        if (preg_match('/' . $objId . '\s+0\s+obj[\s\S]*?stream[\r\n]+([\s\S]*?)[\r\n]+endstream/m', $pdfContent, $sm)) {
            $decomp = @gzuncompress($sm[1]);
            if ($decomp === false) $decomp = @gzinflate($sm[1]);
            if ($decomp === false && strlen($sm[1]) > 2) $decomp = @gzinflate(substr($sm[1], 2));
            if ($decomp === false) $decomp = $sm[1];

            if (preg_match_all('/beginbfchar[\r\n]+([\s\S]*?)[\r\n]+endbfchar/m', $decomp, $bfcharBlocks)) {
                foreach ($bfcharBlocks[1] as $block) {
                    if (preg_match_all('/<([0-9a-fA-F]+)>\s+<([0-9a-fA-F]+)>/', $block, $chars)) {
                        for ($i = 0; $i < count($chars[1]); $i++) {
                            $src = strtoupper($chars[1][$i]);
                            $dstCode = hexdec($chars[2][$i]);
                            if ($dstCode > 0) {
                                $cmapMap[$src] = mb_chr($dstCode, 'UTF-8');
                            }
                        }
                    }
                }
            }

            if (preg_match_all('/beginbfrange[\r\n]+([\s\S]*?)[\r\n]+endbfrange/m', $decomp, $bfrangeBlocks)) {
                foreach ($bfrangeBlocks[1] as $block) {
                    if (preg_match_all('/<([0-9a-fA-F]+)>\s+<([0-9a-fA-F]+)>\s+<([0-9a-fA-F]+)>/', $block, $ranges)) {
                        for ($i = 0; $i < count($ranges[1]); $i++) {
                            $start = hexdec($ranges[1][$i]);
                            $end = hexdec($ranges[2][$i]);
                            $dstStart = hexdec($ranges[3][$i]);
                            $hexLen = strlen($ranges[1][$i]);
                            for ($c = $start; $c <= $end; $c++) {
                                $srcHex = strtoupper(str_pad(dechex($c), $hexLen, '0', STR_PAD_LEFT));
                                $dstCode = $dstStart + ($c - $start);
                                if ($dstCode > 0) {
                                    $cmapMap[$srcHex] = mb_chr($dstCode, 'UTF-8');
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    return $cmapMap;
}

function _decode_pdf_hex(string $hex, array $cmaps): string
{
    $out = '';
    $hex = strtoupper($hex);
    $chunkSize = 4;
    for ($i = 0; $i < strlen($hex); $i += $chunkSize) {
        $chunk = substr($hex, $i, $chunkSize);
        if (isset($cmaps[$chunk])) {
            $out .= $cmaps[$chunk];
        } else {
            $code = hexdec($chunk);
            if ($code > 0) {
                $out .= mb_chr($code, 'UTF-8');
            }
        }
    }
    return $out;
}

function _extract_text_from_pdf_stream(string $stream, array $cmaps = []): string
{
    $decompressed = @gzuncompress($stream);
    if ($decompressed === false) {
        $decompressed = @gzinflate($stream);
    }
    if ($decompressed === false && strlen($stream) > 2) {
        $decompressed = @gzinflate(substr($stream, 2));
    }
    $content = ($decompressed !== false) ? $decompressed : $stream;

    $tokenRegex = '/(?:\(((?:[^()\\\\]|\\\\.)*)\)|<([0-9a-fA-F]+)>)/s';

    $text = '';
    if (preg_match_all('/BT[\s\S]*?ET/m', $content, $btMatches)) {
        foreach ($btMatches[0] as $bt) {
            $line = '';
            // Check for TJ arrays first
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $bt, $tjMatches)) {
                foreach ($tjMatches[1] as $arrayStr) {
                    if (preg_match_all($tokenRegex, $arrayStr, $tokens, PREG_SET_ORDER)) {
                        foreach ($tokens as $tok) {
                            if (!empty($tok[1]) || (isset($tok[1]) && $tok[1] === '0')) {
                                $line .= _decode_pdf_string($tok[1]);
                            } elseif (!empty($tok[2])) {
                                $line .= _decode_pdf_hex($tok[2], $cmaps);
                            }
                        }
                    }
                    $line .= ' ';
                }
            }
            // Check for single Tj strings
            if (preg_match_all('/(?:\(((?:[^()\\\\]|\\\\.)*)\)|<([0-9a-fA-F]+)>)\s*Tj/s', $bt, $tjMatches, PREG_SET_ORDER)) {
                foreach ($tjMatches as $tok) {
                    if (!empty($tok[1]) || (isset($tok[1]) && $tok[1] === '0')) {
                        $line .= _decode_pdf_string($tok[1]) . ' ';
                    } elseif (!empty($tok[2])) {
                        $line .= _decode_pdf_hex($tok[2], $cmaps) . ' ';
                    }
                }
            }
            if (trim($line) !== '') {
                $text .= trim($line) . "\n";
            }
        }
    }
    return $text;
}

function _is_known_unit(string $str): bool
{
    $units = ['pcs', 'no.', 'no', 'service', 'meter', 'm', 'box', 'set', 'roll', 'hour', 'day', 'job', 'unit', 'units', 'nos', 'pk', 'pack', 'kg'];
    return in_array(strtolower(trim($str, ' .')), $units, true);
}

function _parse_pdf_items(array $lines): array
{
    $startIndex = -1;
    $stopIndex = count($lines);

    $colHeaderRegex = '/^(?:#|item(?:\s*(?:name|description|desc))?|description|particulars?|model(?:\s*\/?\s*make)?|unit|qty|quantity|rate(?:\s*\(pkr\))?|\(?pkr\)?|price|unit\s*price|total(?:\s*\(pkr\))?)$/i';

    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/^(?:items?|active\s*components|particulars?)$/i', trim($lines[$i])) ||
            preg_match('/^(?:#\s*)?item\s*(?:name|description)/i', trim($lines[$i]))) {
            $startIndex = $i + 1;
            while ($startIndex < count($lines) && preg_match($colHeaderRegex, trim($lines[$startIndex]))) {
                $startIndex++;
            }
            break;
        }
    }

    if ($startIndex === -1) {
        for ($i = 0; $i < count($lines) - 1; $i++) {
            if (trim($lines[$i]) === '#' && preg_match('/item/i', $lines[$i + 1])) {
                $startIndex = $i + 1;
                while ($startIndex < count($lines) && preg_match($colHeaderRegex, trim($lines[$startIndex]))) {
                    $startIndex++;
                }
                break;
            }
        }
    }

    if ($startIndex === -1) {
        return [];
    }

    for ($i = $startIndex; $i < count($lines); $i++) {
        if (preg_match('/^(?:subtotal|grand\s*total|total|gst\s*@|gst\s*\(|notes|terms|stamp|payment\s*terms)/i', $lines[$i])) {
            $stopIndex = $i;
            break;
        }
    }

    $tableLines = array_slice($lines, $startIndex, $stopIndex - $startIndex);
    $tableLines = array_values(array_filter($tableLines, fn($l) => !preg_match($colHeaderRegex, trim($l))));

    $items = [];

    // Strategy A: Numbered item blocks: "1", "2", "3"...
    $numberedBlocks = [];
    $currentBlock = [];
    $expectedIndex = 1;
    $hasNumberedPattern = false;

    foreach ($tableLines as $line) {
        if (trim($line) === (string) $expectedIndex) {
            if (!empty($currentBlock)) {
                $numberedBlocks[] = $currentBlock;
            }
            $currentBlock = [];
            $expectedIndex++;
            $hasNumberedPattern = true;
        } else {
            $currentBlock[] = $line;
        }
    }
    if (!empty($currentBlock) && $hasNumberedPattern) {
        $numberedBlocks[] = $currentBlock;
    }

    if ($hasNumberedPattern && count($numberedBlocks) > 0) {
        foreach ($numberedBlocks as $block) {
            $bCount = count($block);
            if ($bCount < 2) {
                continue;
            }

            $bestMatch = null;
            // 1. Look for arithmetic triple: qty * rate = total
            for ($i = 0; $i < $bCount - 2; $i++) {
                $qty = _number($block[$i]);
                $rate = _number($block[$i + 1]);
                $tot = _number($block[$i + 2]);

                if ($qty !== null && $rate !== null && $tot !== null && $qty > 0 && $rate > 0) {
                    if (abs(($qty * $rate) - $tot) < 0.1 || abs(($qty * $tot) - $rate) < 0.1) {
                        $unit = ($i > 0 && _is_known_unit($block[$i - 1])) ? $block[$i - 1] : 'pcs';
                        $descIndex = ($i > 0 && _is_known_unit($block[$i - 1])) ? $i - 1 : $i;
                        $bestMatch = [
                            'descParts' => array_slice($block, 0, $descIndex),
                            'unit' => $unit,
                            'qty' => $qty,
                            'rate' => $rate,
                            'total' => $tot
                        ];
                        break;
                    }
                }
            }

            // 2. Fallback to tail values
            if ($bestMatch === null) {
                $total = null;
                $rate = null;
                $qty = 1.0;
                $unit = 'pcs';
                $tailIndex = $bCount;

                $val1 = _number($block[$bCount - 1]);
                $val2 = ($bCount >= 2) ? _number($block[$bCount - 2]) : null;
                $val3 = ($bCount >= 3) ? _number($block[$bCount - 3]) : null;

                if ($val1 !== null && $val2 !== null) {
                    $total = $val1;
                    $rate = $val2;
                    $tailIndex = $bCount - 2;

                    if ($val3 !== null && $val3 > 0 && $bCount >= 4) {
                        $qty = $val3;
                        $tailIndex = $bCount - 3;
                        if ($tailIndex > 0 && _is_known_unit($block[$tailIndex - 1])) {
                            $unit = $block[$tailIndex - 1];
                            $tailIndex--;
                        }
                    } elseif ($bCount >= 3 && _is_known_unit($block[$bCount - 3])) {
                        $unit = $block[$bCount - 3];
                        $tailIndex = $bCount - 3;
                        if ($rate > 0) {
                            $qty = round($total / $rate, 2);
                        }
                    }
                }
                $bestMatch = [
                    'descParts' => array_slice($block, 0, $tailIndex),
                    'unit' => $unit,
                    'qty' => $qty,
                    'rate' => $rate ?? ($qty > 0 && $total ? $total / $qty : 0),
                    'total' => $total
                ];
            }

            $descParts = $bestMatch['descParts'];
            $desc = '';
            $model = '';
            if (count($descParts) === 1) {
                $desc = $descParts[0];
            } elseif (count($descParts) === 2) {
                $desc = $descParts[0];
                $model = $descParts[1];
            } elseif (count($descParts) > 2) {
                $splitAt = -1;
                for ($p = 1; $p < count($descParts); $p++) {
                    if (preg_match('/^(?:hikvision|huawei|wd|cisco|d-link|tp-link|generic|model|schneider|dell|hp|lenovo)/i', $descParts[$p])) {
                        $splitAt = $p;
                        break;
                    }
                }
                if ($splitAt > 0) {
                    $desc = implode(' ', array_slice($descParts, 0, $splitAt));
                    $model = implode(' ', array_slice($descParts, $splitAt));
                } else {
                    $half = (int) ceil(count($descParts) / 2);
                    $desc = implode(' ', array_slice($descParts, 0, $half));
                    $model = implode(' ', array_slice($descParts, $half));
                }
            }

            if ($desc !== '') {
                $items[] = [
                    'description' => $desc,
                    'model_make' => $model,
                    'quantity' => $bestMatch['qty'],
                    'unit' => $bestMatch['unit'],
                    'unit_price' => $bestMatch['rate'],
                ];
            }
        }

        if (!empty($items)) {
            return $items;
        }
    }

    // Strategy B: Unit-anchored chunk matching (e.g. Desc, Qty, Unit, Rate, Total)
    $i = 0;
    while ($i < count($tableLines)) {
        $unitIdx = -1;
        for ($j = $i; $j < min($i + 6, count($tableLines)); $j++) {
            if (_is_known_unit($tableLines[$j])) {
                $unitIdx = $j;
                break;
            }
        }

        if ($unitIdx !== -1 && $unitIdx > $i && $unitIdx + 2 < count($tableLines)) {
            $unit = $tableLines[$unitIdx];
            $qty = _number($tableLines[$unitIdx - 1]) ?: 1.0;
            $rate = _number($tableLines[$unitIdx + 1]) ?: 0.0;
            $descLines = array_slice($tableLines, $i, ($unitIdx - 1) - $i);
            $desc = implode(' ', $descLines);

            if ($desc !== '') {
                $items[] = [
                    'description' => $desc,
                    'model_make' => '',
                    'quantity' => $qty,
                    'unit' => $unit,
                    'unit_price' => $rate,
                ];
                $i = $unitIdx + 3;
                continue;
            }
        }

        $i++;
    }

    if (!empty($items)) {
        return $items;
    }

    // Strategy C: Single-line row regex
    foreach ($tableLines as $line) {
        if (preg_match('~^(?:(\d+)\s+)?(.+?)\s+(\d+(?:\.\d+)?)\s+(pcs|no\.?|service|meter|box|set|roll|hour|day|job|unit|units)\s+([\d,]+(?:\.\d+)?)(?:\s+([\d,]+(?:\.\d+)?))?$~i', $line, $lm)) {
            $desc = trim($lm[2]);
            $qty = (float) $lm[3];
            $unit = trim($lm[4]);
            $rate = (float) str_replace(',', '', $lm[5]);
            $items[] = [
                'description' => $desc,
                'model_make' => '',
                'quantity' => $qty,
                'unit' => $unit,
                'unit_price' => $rate,
            ];
        }
    }

    return $items;
}

function _extract_pdf(string $content): array
{
    $cmaps = _parse_pdf_cmaps($content);
    $text = '';
    // 1. Pure PHP stream extraction
    if (preg_match_all('/stream[\r\n]+([\s\S]*?)[\r\n]+endstream/m', $content, $matches)) {
        foreach ($matches[1] as $stream) {
            $text .= _extract_text_from_pdf_stream($stream, $cmaps) . "\n";
        }
    }

    // 2. Fallback to pdftotext only if binary stream extraction returned nothing
    if (trim($text) === '' && function_exists('exec')) {
        $temp = tempnam(sys_get_temp_dir(), 'invoice_');
        if ($temp !== false) {
            file_put_contents($temp, $content);
            @exec('pdftotext ' . escapeshellarg($temp) . ' - 2>&1', $lines, $exitCode);
            @unlink($temp);
            if ($exitCode === 0 && !empty($lines)) {
                $text = implode("\n", $lines);
            }
        }
    }

    $rawLines = explode("\n", $text);
    $lines = [];
    foreach ($rawLines as $l) {
        $trimmed = trim($l);
        if ($trimmed !== '') {
            $lines[] = $trimmed;
        }
    }

    $items = _parse_pdf_items($lines);
    return [_normalize_text($text), $items];
}

function _extract_docx_xml_pure(string $binary): ?string
{
    $offset = 0;
    $len = strlen($binary);
    while ($offset + 30 <= $len) {
        $sig = substr($binary, $offset, 4);
        if ($sig !== "PK\x03\x04") {
            $offset++;
            continue;
        }
        $header = unpack('vversion/vflags/vmethod/vmodtime/vmoddate/Vcrc32/Vcomp_size/Vuncomp_size/vname_len/vextra_len', substr($binary, $offset + 4, 26));
        $name = substr($binary, $offset + 30, $header['name_len']);
        $dataOffset = $offset + 30 + $header['name_len'] + $header['extra_len'];
        $compData = substr($binary, $dataOffset, $header['comp_size']);

        if ($name === 'word/document.xml') {
            if ($header['method'] === 0) {
                return $compData;
            }
            if ($header['method'] === 8) {
                $decomp = @gzinflate($compData);
                if ($decomp !== false) {
                    return $decomp;
                }
            }
        }
        $offset = $dataOffset + $header['comp_size'];
    }
    return null;
}

function _parse_docx_items_from_xml(string $xml): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!@$dom->loadXML($xml)) {
        return [];
    }

    $tables = $dom->getElementsByTagName('tbl');
    $items = [];

    foreach ($tables as $tbl) {
        $rows = [];
        foreach ($tbl->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->getElementsByTagName('tc') as $tc) {
                $cellText = '';
                foreach ($tc->getElementsByTagName('t') as $t) {
                    $cellText .= $t->textContent . ' ';
                }
                $cells[] = trim($cellText);
            }
            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }

        if (count($rows) < 2) {
            continue;
        }

        $headerIndex = -1;
        $colMap = [];
        foreach ($rows as $idx => $r) {
            $joined = strtolower(implode(' ', $r));
            if (preg_match('/(item|desc|detail|qty|price|rate|amount|total)/', $joined)) {
                $headerIndex = $idx;
                foreach ($r as $cIdx => $val) {
                    $valLower = strtolower($val);
                    if (preg_match('/item|desc|detail|particular|product/i', $valLower) && !isset($colMap['desc'])) {
                        $colMap['desc'] = $cIdx;
                    } elseif (preg_match('/model|make|brand/i', $valLower) && !isset($colMap['model'])) {
                        $colMap['model'] = $cIdx;
                    } elseif (preg_match('/qty|quantity|count/i', $valLower) && !isset($colMap['qty'])) {
                        $colMap['qty'] = $cIdx;
                    } elseif (preg_match('/unit|uom/i', $valLower) && !isset($colMap['unit'])) {
                        $colMap['unit'] = $cIdx;
                    } elseif (preg_match('/price|rate|cost/i', $valLower) && !isset($colMap['price'])) {
                        $colMap['price'] = $cIdx;
                    } elseif (preg_match('/total|amount/i', $valLower) && !isset($colMap['total'])) {
                        $colMap['total'] = $cIdx;
                    }
                }
                break;
            }
        }

        if ($headerIndex === -1 || !isset($colMap['desc'])) {
            continue;
        }

        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $desc = $r[$colMap['desc']] ?? '';
            if ($desc === '') {
                continue;
            }
            if (preg_match('/^(subtotal|total|gst|tax|discount|vat|grand total)/i', trim($desc))) {
                continue;
            }

            $qtyStr = isset($colMap['qty']) ? ($r[$colMap['qty']] ?? '1') : '1';
            $priceStr = isset($colMap['price']) ? ($r[$colMap['price']] ?? '0') : '0';
            $unitStr = isset($colMap['unit']) ? ($r[$colMap['unit']] ?? 'pcs') : 'pcs';
            $modelStr = isset($colMap['model']) ? ($r[$colMap['model']] ?? '') : '';

            $items[] = [
                'description' => $desc,
                'model_make' => $modelStr,
                'quantity' => _number($qtyStr) ?: 1.0,
                'unit' => $unitStr ?: 'pcs',
                'unit_price' => _number($priceStr) ?: 0.0,
            ];
        }
    }
    return $items;
}

function _extract_docx(string $content): array
{
    $xml = null;
    if (class_exists('ZipArchive')) {
        $path = tempnam(sys_get_temp_dir(), 'docx_');
        if ($path !== false) {
            file_put_contents($path, $content);
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $xml = $zip->getFromName('word/document.xml') ?: null;
                $zip->close();
            }
            @unlink($path);
        }
    }

    if ($xml === null) {
        $xml = _extract_docx_xml_pure($content);
    }

    if ($xml === null) {
        return ['', []];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!@$dom->loadXML($xml)) {
        return ['', []];
    }

    $paragraphs = $dom->getElementsByTagName('p');
    $parts = [];
    foreach ($paragraphs as $paragraph) {
        $value = '';
        foreach ($paragraph->getElementsByTagName('t') as $node) {
            $value .= $node->textContent;
        }
        $value = trim($value);
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $text = _normalize_text(implode("\n", $parts));
    $items = _parse_docx_items_from_xml($xml);
    return [$text, $items];
}

function _extract_header_field(string $text, array $labels): ?string
{
    $labelsRe = implode('|', array_map(static fn($l) => preg_quote($l, '~'), $labels));

    if (preg_match('~(?:^|\n)\s*(?:' . $labelsRe . ')\s*(?:#|no\.?|number)?\s*[:\-@]?\s*([^\r\n]+)~i', $text, $m)) {
        $val = trim($m[1]);
        $val = preg_replace('/^[\s:.\-@]+|[\s:.-]+$/', '', $val);
        if ($val !== '' && !preg_match('/^(?:date|gst|bill|invoice|estimate|quotation|#)/i', $val)) {
            return $val;
        }
    }

    if (preg_match('~(?:^|\n)\s*(?:' . $labelsRe . ')\s*(?:#|no\.?|number)?\s*[\r\n]+\s*[:\-@]?\s*([^\r\n]+)~i', $text, $m)) {
        $val = trim($m[1]);
        $val = preg_replace('/^[\s:.\-@]+|[\s:.-]+$/', '', $val);
        if ($val !== '' && !preg_match('/^(?:date|gst|bill|invoice|estimate|quotation|#)/i', $val)) {
            return $val;
        }
    }

    return null;
}

function _extract_doc_number(string $text): ?string
{
    if (preg_match('~(?:^|\n)\s*(?:invoice|inv|estimate|est|quotation)\s*(?:no\.?|#|number|:\s*#)\s*[:\-]?\s*[:\-]?\s*([A-Za-z0-9\-_/]+)~i', $text, $m)) {
        return trim($m[1]);
    }
    if (preg_match('~(?:^|\n)\s*(?:invoice|inv|estimate|est|quotation)\s*(?:no\.?|#|number|:\s*#)\s*[\r\n]+\s*[:\-]?\s*([A-Za-z0-9\-_/]+)~i', $text, $m)) {
        return trim($m[1]);
    }
    return null;
}

function _extract_tax_rate(string $text): ?float
{
    if (preg_match('/(?:gst|sales\s*tax|vat|tax)\s*(?:@|:|-)?\s*\(?\s*(\d+(?:\.\d+)?)\s*%/i', $text, $m)) {
        return (float) $m[1];
    }
    if (preg_match('/(?:gst|sales\s*tax|vat|tax)\s*[:\-]?\s*(\d+(?:\.\d+)?)/i', $text, $m)) {
        return (float) $m[1];
    }
    return null;
}

function parse_invoice_file(string $filename, string $content, ?PDO $db = null): array
{
    // Gracefully handle argument order inversion
    if (strlen($filename) > strlen($content) && strlen($filename) > 300) {
        $tmp = $filename;
        $filename = $content;
        $content = $tmp;
    }

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
    $date_match = _extract_header_field($text, ['invoice date', 'estimate date', 'quotation date', 'date']);
    $due_match = _extract_header_field($text, ['due date', 'valid until', 'validity', 'payment due']);
    $tax_rate = _extract_tax_rate($text);
    $doc_number = _extract_doc_number($text);

    $client = _extract_header_field($text, ['bill to', 'billed to', 'invoice to', 'quotation to', 'customer', 'client', 'name']);
    if ($client !== null) {
        $client = preg_split('/\s{2,}|\|/', $client)[0] ?? $client;
        $client = trim($client);
    }

    $title = _extract_header_field($text, ['title', 'subject', 'project']);
    if ($title === null) {
        // Check if there is an uppercase subject banner before table header (e.g. "ACTIVE COMPONENTS-ZONE-1")
        if (preg_match('/(?:^|\n)\s*([A-Z0-9\s\-]{6,50})\s*(?:\n\s*#|\n\s*item\s*description|\n\s*items)/i', $text, $tm)) {
            $cand = trim($tm[1]);
            if (!preg_match('/(?:sales tax|invoice|quotation|ntn|strn)/i', $cand)) {
                $title = $cand;
            }
        }
    }

    $payment_terms = _extract_header_field($text, ['payment terms & notes', 'payment terms', 'terms & conditions', 'terms']);
    $notes = _extract_header_field($text, ['notes', 'remarks']);

    // Match client in database if DB handle is provided
    $clientId = null;
    if ($db !== null && $client !== null && $client !== '') {
        try {
            $stmt = $db->prepare('SELECT id FROM clients WHERE LOWER(name) = :n1 OR LOWER(company) = :c1 OR name LIKE :n2 OR company LIKE :c2 LIMIT 1');
            $stmt->execute([
                ':n1' => strtolower($client),
                ':c1' => strtolower($client),
                ':n2' => '%' . $client . '%',
                ':c2' => '%' . $client . '%',
            ]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $clientId = (int) $found['id'];
            }
        } catch (Throwable $e) {
            // Non-fatal if DB query fails
        }
    }

    $warnings = [];
    if (empty($rows)) {
        $warnings[] = 'No line items could be automatically identified. Please add items manually.';
    }

    return [
        'client_id' => $clientId,
        'client_name' => $client,
        'invoice_no' => $doc_number,
        'estimate_no' => $doc_number,
        'invoice_date' => $date_match !== null ? _date($date_match) : null,
        'estimate_date' => $date_match !== null ? _date($date_match) : null,
        'due_date' => $due_match !== null ? _date($due_match) : null,
        'valid_until' => $due_match !== null ? _date($due_match) : null,
        'title' => $title,
        'tax_rate' => ($tax_rate !== null && $tax_rate >= 0 && $tax_rate <= 100) ? $tax_rate : null,
        'payment_terms' => $payment_terms,
        'terms_conditions' => $payment_terms,
        'notes' => $notes,
        'items' => $rows,
        'warnings' => $warnings,
    ];
}
