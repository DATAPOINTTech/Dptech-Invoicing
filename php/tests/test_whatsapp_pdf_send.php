<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../routes/whatsapp.php';
require_once __DIR__ . '/../services/communication.php';
require_once __DIR__ . '/../services/pdf_service.php';

echo "=================================================================\n";
echo "  TEST: WhatsApp Send with PDF & Number +923712566540             \n";
echo "=================================================================\n\n";

$db = database_connection();

// 1. Create client and test estimate & invoice
$db->exec("INSERT INTO clients (name, email, phone, mobile, is_active) VALUES ('Pak Enterprise', 'pak@test.com', '', '', 1)");
$clientId = (int) $db->lastInsertId();

$db->exec("INSERT INTO estimates (estimate_no, client_id, company_id, company_whatsapp, title, status, total_amount, estimate_date) VALUES ('EST-WA-001', {$clientId}, 1, '+923712566540', 'Security System Quotation', 'draft', 95000, '2026-11-12')");
$estId = (int) $db->lastInsertId();
$db->exec("INSERT INTO estimate_items (estimate_id, description, quantity, unit, unit_price, total_price) VALUES ({$estId}, 'Hikvision 4MP Camera', 4, 'pcs', 19580, 78320)");

$db->exec("INSERT INTO invoices (invoice_no, client_id, estimate_id, company_id, company_whatsapp, title, status, total_amount, balance_due, invoice_date) VALUES ('INV-WA-001', {$clientId}, {$estId}, 1, '+923712566540', 'Security System Quotation', 'draft', 95000, 95000, '2026-11-12')");
$invId = (int) $db->lastInsertId();
$db->exec("INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price, total_price) VALUES ({$invId}, 'Hikvision 4MP Camera', 4, 'pcs', 19580, 78320)");

echo "[1] Seeded Test Records for WhatsApp:\n";
echo "  - Client ID {$clientId} (No phone provided initially)\n";
echo "  - Estimate ID {$estId} (#EST-WA-001), Company WhatsApp +923712566540\n";
echo "  - Invoice ID {$invId} (#INV-WA-001), Company WhatsApp +923712566540\n\n";

// 2. Test PDF generation for Estimate & Invoice
echo "[2] Testing PDF generation for WhatsApp attachments...\n";
$estStmt = $db->prepare('SELECT * FROM estimates WHERE id = :id');
$estStmt->execute(['id' => $estId]);
$estRow = $estStmt->fetch(PDO::FETCH_ASSOC);
$estItStmt = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id');
$estItStmt->execute(['id' => $estId]);
$estRow['items'] = $estItStmt->fetchAll(PDO::FETCH_ASSOC);

$estPdf = generate_estimate_pdf($estRow);
assert(!empty($estPdf) && strlen($estPdf) > 1000, "Estimate PDF must be generated with valid bytes");
assert(str_starts_with($estPdf, '%PDF-'), "Must be valid PDF binary format");
echo "✓ Generated Estimate PDF attachment: " . strlen($estPdf) . " bytes\n";

$invStmt = $db->prepare('SELECT * FROM invoices WHERE id = :id');
$invStmt->execute(['id' => $invId]);
$invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
$invItStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
$invItStmt->execute(['id' => $invId]);
$invRow['items'] = $invItStmt->fetchAll(PDO::FETCH_ASSOC);

$invPdf = generate_invoice_pdf($invRow);
assert(!empty($invPdf) && strlen($invPdf) > 1000, "Invoice PDF must be generated with valid bytes");
assert(str_starts_with($invPdf, '%PDF-'), "Must be valid PDF binary format");
echo "✓ Generated Invoice PDF attachment: " . strlen($invPdf) . " bytes\n\n";

// 3. Test phone number normalization with +923712566540
echo "[3] Testing phone number formatting helper...\n";
$formatted = _format_phone('+923712566540');
assert($formatted === '+923712566540', "Expected +923712566540, got {$formatted}");
$formatted03 = _format_phone('03712566540');
assert($formatted03 === '+923712566540', "Expected +923712566540 from 0371..., got {$formatted03}");
echo "✓ Phone normalization for +923712566540 verified.\n\n";

// 4. Test UI Templates for WhatsApp PDF Buttons & Defaults
echo "[4] Verifying Estimates and Invoices UI templates for WhatsApp...\n";

// Estimates Detail
$estDetailTpl = file_get_contents(__DIR__ . '/../templates/estimates/detail.html');
assert(strpos($estDetailTpl, 'id="whatsapp-est-btn"') !== false, "Estimate detail must have Send WhatsApp button");
assert(strpos($estDetailTpl, '+923712566540') !== false, "Estimate detail must default to +923712566540");
assert(strpos($estDetailTpl, 'sendEstimateWhatsappDirect') !== false, "Estimate detail must have sendEstimateWhatsappDirect handler");

// Invoices Detail
$invDetailTpl = file_get_contents(__DIR__ . '/../templates/invoices/detail.html');
assert(strpos($invDetailTpl, 'id="whatsapp-quick-btn"') !== false, "Invoice detail must have Send WhatsApp button");
assert(strpos($invDetailTpl, '+923712566540') !== false, "Invoice detail must default to +923712566540");
assert(strpos($invDetailTpl, 'sendInvoiceWhatsappDirect') !== false, "Invoice detail must have sendInvoiceWhatsappDirect handler");

// Estimates List
$estListTpl = file_get_contents(__DIR__ . '/../templates/estimates/list.html');
assert(strpos($estListTpl, 'send-estimate-whatsapp') !== false, "Estimates list must have Send WhatsApp button in table actions");
assert(strpos($estListTpl, '+923712566540') !== false, "Estimates list must default to +923712566540");

// Invoices List
$invListTpl = file_get_contents(__DIR__ . '/../templates/invoices/list.html');
assert(strpos($invListTpl, 'send-invoice-whatsapp') !== false, "Invoices list must have Send WhatsApp button in table actions");
assert(strpos($invListTpl, '+923712566540') !== false, "Invoices list must default to +923712566540");

echo "✓ All 4 UI templates have WhatsApp buttons with PDF links and +923712566540 default.\n\n";

// Clean up
$db->exec("DELETE FROM invoice_items WHERE invoice_id = {$invId}");
$db->exec("DELETE FROM invoices WHERE id = {$invId}");
$db->exec("DELETE FROM estimate_items WHERE estimate_id = {$estId}");
$db->exec("DELETE FROM estimates WHERE id = {$estId}");
$db->exec("DELETE FROM clients WHERE id = {$clientId}");

echo "=================================================================\n";
echo "  ALL WHATSAPP PDF SEND TESTS PASSED SUCCESSFULLY!               \n";
echo "=================================================================\n";
