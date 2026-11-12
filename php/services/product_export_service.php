<?php

declare(strict_types=1);

/**
 * Service to export products to Microsoft Excel (.xlsx), Word (.docx), and CSV formats.
 */

function get_all_active_products_for_export(PDO $db): array
{
    $hasInventory = db_table_exists($db, 'inventory');
    if ($hasInventory) {
        $stmt = $db->query('
            SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity 
            FROM products p 
            LEFT JOIN inventory i ON i.product_id = p.id 
            WHERE (p.is_active = 1 OR p.is_active IS NULL OR p.is_active = "1") 
            ORDER BY p.category ASC, p.name ASC
        ');
    } else {
        $stmt = $db->query('
            SELECT p.*, 0 AS stock_quantity 
            FROM products p 
            WHERE (p.is_active = 1 OR p.is_active IS NULL OR p.is_active = "1") 
            ORDER BY p.category ASC, p.name ASC
        ');
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Generate native Microsoft Excel OpenXML (.xlsx) binary.
 */
function export_products_to_excel(PDO $db): string
{
    $products = get_all_active_products_for_export($db);
    $profitMargin = (float) get_app_setting($db, 'product_profit_percent', '10.0');

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_exp_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create temporary Excel spreadsheet file.');
    }

    // 1. [Content_Types].xml
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    // 2. _rels/.rels
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rootRels);

    // 3. xl/_rels/workbook.xml.rels
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    // 4. xl/workbook.xml
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <bookViews>
    <workbookView xWindow="0" yWindow="0" windowWidth="20480" windowHeight="10240"/>
  </bookViews>
  <sheets>
    <sheet name="Product Rates" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';
    $zip->addFromString('xl/workbook.xml', $workbook);

    // 5. xl/styles.xml
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="1">
    <numFmt numFmtId="164" formatCode="#,##0.00"/>
  </numFmts>
  <fonts count="2">
    <font>
      <sz val="10"/>
      <name val="Calibri"/>
    </font>
    <font>
      <b/>
      <sz val="11"/>
      <color rgb="FFFFFFFF"/>
      <name val="Calibri"/>
    </font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill>
      <patternFill patternType="solid">
        <fgColor rgb="FF0F172A"/>
      </patternFill>
    </fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFCBD5E1"/></left>
      <right style="thin"><color rgb="FFCBD5E1"/></right>
      <top style="thin"><color rgb="FFCBD5E1"/></top>
      <bottom style="thin"><color rgb="FFCBD5E1"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="4">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
  </cellXfs>
</styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles);

    // 6. xl/worksheets/sheet1.xml
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <cols>
    <col min="1" max="1" width="34" customWidth="1"/>
    <col min="2" max="2" width="18" customWidth="1"/>
    <col min="3" max="3" width="18" customWidth="1"/>
    <col min="4" max="4" width="26" customWidth="1"/>
    <col min="5" max="5" width="28" customWidth="1"/>
    <col min="6" max="6" width="12" customWidth="1"/>
    <col min="7" max="7" width="14" customWidth="1"/>
    <col min="8" max="8" width="40" customWidth="1"/>
  </cols>
  <sheetData>';

    // Header row
    $sheetXml .= '<row r="1" ht="26" customHeight="1">';
    $headers = [
        'A' => 'Product Name',
        'B' => 'SKU',
        'C' => 'Category',
        'D' => 'Current Rate (Purchase Price)',
        'E' => 'Quotation Rate (+10% Profit)',
        'F' => 'Unit',
        'G' => 'Stock Quantity',
        'H' => 'Description'
    ];
    foreach ($headers as $col => $title) {
        $sheetXml .= '<c r="' . $col . '1" t="inlineStr" s="1"><is><t>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</t></is></c>';
    }
    $sheetXml .= '</row>';

    // Data rows
    $rowNum = 2;
    $mult = 1.0 + ($profitMargin / 100.0);
    foreach ($products as $p) {
        $cost = (float) ($p['cost_price'] ?? 0);
        $selling = $cost > 0 ? round($cost * $mult, 2) : (float) ($p['unit_price'] ?? 0);
        $stock = (float) ($p['stock_quantity'] ?? 0);

        $sheetXml .= '<row r="' . $rowNum . '">';
        // Name
        $sheetXml .= '<c r="A' . $rowNum . '" t="inlineStr" s="0"><is><t>' . htmlspecialchars((string)$p['name'], ENT_XML1, 'UTF-8') . '</t></is></c>';
        // SKU
        $sheetXml .= '<c r="B' . $rowNum . '" t="inlineStr" s="0"><is><t>' . htmlspecialchars((string)($p['sku'] ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';
        // Category
        $sheetXml .= '<c r="C' . $rowNum . '" t="inlineStr" s="0"><is><t>' . htmlspecialchars((string)($p['category'] ?? 'General'), ENT_XML1, 'UTF-8') . '</t></is></c>';
        // Cost Price (Numeric)
        $sheetXml .= '<c r="D' . $rowNum . '" s="2"><v>' . number_format($cost, 2, '.', '') . '</v></c>';
        // Selling Price (Numeric)
        $sheetXml .= '<c r="E' . $rowNum . '" s="2"><v>' . number_format($selling, 2, '.', '') . '</v></c>';
        // Unit
        $sheetXml .= '<c r="F' . $rowNum . '" t="inlineStr" s="0"><is><t>' . htmlspecialchars((string)($p['unit'] ?? 'pcs'), ENT_XML1, 'UTF-8') . '</t></is></c>';
        // Stock
        $sheetXml .= '<c r="G' . $rowNum . '" s="0"><v>' . $stock . '</v></c>';
        // Description
        $sheetXml .= '<c r="H' . $rowNum . '" t="inlineStr" s="0"><is><t>' . htmlspecialchars((string)($p['description'] ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';
        $sheetXml .= '</row>';
        $rowNum++;
    }

    $sheetXml .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

    $zip->close();
    $binary = file_get_contents($tmp);
    @unlink($tmp);
    return $binary;
}

/**
 * Generate native Microsoft Word OpenXML (.docx) binary.
 */
function export_products_to_docx(PDO $db): string
{
    $products = get_all_active_products_for_export($db);
    $profitMargin = (float) get_app_setting($db, 'product_profit_percent', '10.0');

    $tmp = tempnam(sys_get_temp_dir(), 'docx_exp_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create temporary Word document file.');
    }

    // 1. [Content_Types].xml
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    // 2. _rels/.rels
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rootRels);

    // 3. word/_rels/document.xml.rels
    $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    $zip->addFromString('word/_rels/document.xml.rels', $docRels);

    // 4. word/styles.xml
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault>
      <w:rPr>
        <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
        <w:sz w:val="20"/>
        <w:color w:val="1E293B"/>
      </w:rPr>
    </w:rPrDefault>
  </w:docDefaults>
</w:styles>';
    $zip->addFromString('word/styles.xml', $stylesXml);

    // 5. word/document.xml
    $totalCount = count($products);
    $dateStr = date('F d, Y - h:i A');

    $docXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <!-- Document Header / Title -->
    <w:p>
      <w:pPr>
        <w:jc w:val="center"/>
        <w:spacing w:after="80"/>
      </w:pPr>
      <w:r>
        <w:rPr>
          <w:b/>
          <w:sz w:val="36"/>
          <w:color w:val="0F172A"/>
        </w:rPr>
        <w:t>DATAPOINT TECHNOLOGIES</w:t>
      </w:r>
    </w:p>

    <w:p>
      <w:pPr>
        <w:jc w:val="center"/>
        <w:spacing w:after="160"/>
      </w:pPr>
      <w:r>
        <w:rPr>
          <w:b/>
          <w:sz w:val="26"/>
          <w:color w:val="0284C7"/>
        </w:rPr>
        <w:t>Official Products Catalog &amp; Live Rates Sheet</w:t>
      </w:r>
    </w:p>

    <!-- Meta details banner -->
    <w:p>
      <w:pPr>
        <w:jc w:val="both"/>
        <w:spacing w:after="240"/>
      </w:pPr>
      <w:r>
        <w:rPr><w:b/><w:sz w:val="18"/><w:color w:val="475569"/></w:rPr>
        <w:t>Export Date: </w:t>
      </w:r>
      <w:r>
        <w:rPr><w:sz w:val="18"/><w:color w:val="0F172A"/></w:rPr>
        <w:t>' . htmlspecialchars($dateStr, ENT_XML1, 'UTF-8') . '   |   </w:t>
      </w:r>
      <w:r>
        <w:rPr><w:b/><w:sz w:val="18"/><w:color w:val="475569"/></w:rPr>
        <w:t>Total Products: </w:t>
      </w:r>
      <w:r>
        <w:rPr><w:sz w:val="18"/><w:color w:val="0F172A"/></w:rPr>
        <w:t>' . $totalCount . ' Items   |   </w:t>
      </w:r>
      <w:r>
        <w:rPr><w:b/><w:sz w:val="18"/><w:color w:val="475569"/></w:rPr>
        <w:t>Quotation Profit Rule: </w:t>
      </w:r>
      <w:r>
        <w:rPr><w:b/><w:sz w:val="18"/><w:color w:val="16A34A"/></w:rPr>
        <w:t>+' . $profitMargin . '% Auto Applied</w:t>
      </w:r>
    </w:p>

    <!-- Products Table -->
    <w:tbl>
      <w:tblPr>
        <w:tblW w:w="9600" w:type="dxa"/>
        <w:tblBorders>
          <w:top w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
          <w:left w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
          <w:bottom w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
          <w:right w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
          <w:insideH w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
          <w:insideV w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
        </w:tblBorders>
      </w:tblPr>
      <w:tblGrid>
        <w:gridCol w:w="500"/>
        <w:gridCol w:w="2600"/>
        <w:gridCol w:w="1200"/>
        <w:gridCol w:w="1200"/>
        <w:gridCol w:w="1400"/>
        <w:gridCol w:w="1500"/>
        <w:gridCol w:w="600"/>
        <w:gridCol w:w="600"/>
      </w:tblGrid>

      <!-- Table Header Row -->
      <w:tr>
        <w:trPr><w:tblHeader/></w:trPr>
        ' . docx_header_cell('#', 500) . '
        ' . docx_header_cell('Product Name', 2600) . '
        ' . docx_header_cell('SKU', 1200) . '
        ' . docx_header_cell('Category', 1200) . '
        ' . docx_header_cell('Current Rate', 1400, 'right') . '
        ' . docx_header_cell('Quotation (+10%)', 1500, 'right') . '
        ' . docx_header_cell('Unit', 600, 'center') . '
        ' . docx_header_cell('Stock', 600, 'center') . '
      </w:tr>';

    // Table Data Rows
    $idx = 1;
    $mult = 1.0 + ($profitMargin / 100.0);
    foreach ($products as $p) {
        $cost = (float) ($p['cost_price'] ?? 0);
        $selling = $cost > 0 ? round($cost * $mult, 2) : (float) ($p['unit_price'] ?? 0);
        $stock = (float) ($p['stock_quantity'] ?? 0);

        $docXml .= '<w:tr>
          ' . docx_body_cell((string)$idx, 500, 'center') . '
          ' . docx_body_cell((string)$p['name'], 2600, 'left', true) . '
          ' . docx_body_cell((string)($p['sku'] ?: '-'), 1200, 'left') . '
          ' . docx_body_cell((string)($p['category'] ?: 'General'), 1200, 'left') . '
          ' . docx_body_cell('PKR ' . number_format($cost, 2), 1400, 'right') . '
          ' . docx_body_cell('PKR ' . number_format($selling, 2), 1500, 'right', false, '16A34A') . '
          ' . docx_body_cell((string)($p['unit'] ?: 'pcs'), 600, 'center') . '
          ' . docx_body_cell((string)$stock, 600, 'center') . '
        </w:tr>';
        $idx++;
    }

    $docXml .= '
    </w:tbl>

    <!-- Footer Note -->
    <w:p>
      <w:pPr>
        <w:spacing w:before="240" w:after="80"/>
      </w:pPr>
      <w:r>
        <w:rPr><w:i/><w:sz w:val="16"/><w:color w:val="64748B"/></w:rPr>
        <w:t>Generated by DATAPOINT Invoicing Suite (datapointtechnology.com). Current Rate represents Purchase Cost; Quotation Rate automatically includes 10% profit margin.</w:t>
      </w:r>
    </w:p>

    <!-- Page Setup: Landscape / Standard with margins -->
    <w:sectPr>
      <w:pgSz w:w="12240" w:h="15840" w:orient="portrait"/>
      <w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080"/>
    </w:sectPr>
  </w:body>
</w:document>';

    $zip->addFromString('word/document.xml', $docXml);

    $zip->close();
    $binary = file_get_contents($tmp);
    @unlink($tmp);
    return $binary;
}

function docx_header_cell(string $text, int $width, string $align = 'left'): string
{
    $xmlText = htmlspecialchars($text, ENT_XML1, 'UTF-8');
    return '<w:tc>
      <w:tcPr>
        <w:tcW w:w="' . $width . '" w:type="dxa"/>
        <w:shd w:val="clear" w:color="auto" w:fill="0F172A"/>
        <w:tcMar>
          <w:top w:w="120" w:type="dxa"/>
          <w:left w:w="120" w:type="dxa"/>
          <w:bottom w:w="120" w:type="dxa"/>
          <w:right w:w="120" w:type="dxa"/>
        </w:tcMar>
      </w:tcPr>
      <w:p>
        <w:pPr>
          <w:jc w:val="' . $align . '"/>
          <w:spacing w:before="0" w:after="0"/>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:b/>
            <w:sz w:val="18"/>
            <w:color w:val="FFFFFF"/>
          </w:rPr>
          <w:t>' . $xmlText . '</w:t>
        </w:r>
      </w:p>
    </w:tc>';
}

function docx_body_cell(string $text, int $width, string $align = 'left', bool $bold = false, string $color = '1E293B'): string
{
    $xmlText = htmlspecialchars($text, ENT_XML1, 'UTF-8');
    $boldXml = $bold ? '<w:b/>' : '';
    return '<w:tc>
      <w:tcPr>
        <w:tcW w:w="' . $width . '" w:type="dxa"/>
        <w:tcMar>
          <w:top w:w="100" w:type="dxa"/>
          <w:left w:w="100" w:type="dxa"/>
          <w:bottom w:w="100" w:type="dxa"/>
          <w:right w:w="100" w:type="dxa"/>
        </w:tcMar>
      </w:tcPr>
      <w:p>
        <w:pPr>
          <w:jc w:val="' . $align . '"/>
          <w:spacing w:before="0" w:after="0"/>
        </w:pPr>
        <w:r>
          <w:rPr>
            ' . $boldXml . '
            <w:sz w:val="18"/>
            <w:color w:val="' . $color . '"/>
          </w:rPr>
          <w:t>' . $xmlText . '</w:t>
        </w:r>
      </w:p>
    </w:tc>';
}
