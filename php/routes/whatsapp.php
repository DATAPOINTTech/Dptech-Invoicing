<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/communication.php';
require_once __DIR__ . '/../services/pdf_service.php';

function handle_whatsapp_routes(string $method, string $path, PDO $db): bool
{
    // 1. WhatsApp status & QR code
    if ($method === 'GET' && $path === '/api/whatsapp/status') {
        $status = get_whatsapp_service_status();
        json_response($status);
    }

    // 2. Disconnect / Unlink WhatsApp account
    if ($method === 'POST' && $path === '/api/whatsapp/disconnect') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'edit');
        $resp = disconnect_whatsapp_account();
        json_response($resp);
    }

    // 3. Toggle Sales Agent Auto-responder
    if ($method === 'POST' && $path === '/api/whatsapp/agent/toggle') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'edit');
        $body = request_json();
        $enabled = isset($body['enabled']) ? (bool) $body['enabled'] : null;
        $resp = toggle_whatsapp_sales_agent($enabled);
        json_response($resp);
    }

    // 4. Send generic WhatsApp text or document
    if ($method === 'POST' && $path === '/api/whatsapp/send') {
        $user = require_authenticated_user($db);
        $body = request_json();
        $to = (string) ($body['to'] ?? '');
        $message = (string) ($body['message'] ?? '');
        $fileBase64 = $body['fileBase64'] ?? null;
        $filename = (string) ($body['filename'] ?? 'document.pdf');

        if ($to === '' || ($message === '' && !$fileBase64)) {
            json_response(['detail' => 'Recipient phone number and message/document are required'], 422);
        }

        $pdfBytes = $fileBase64 ? base64_decode((string)$fileBase64) : null;
        $res = send_whatsapp_message($to, $message, $pdfBytes, $filename);

        if (!$res['success']) {
            json_response(['detail' => $res['error'] ?? 'WhatsApp dispatch failed'], 400);
        }

        json_response(['message' => 'WhatsApp message dispatched successfully', 'data' => $res['data'] ?? []]);
    }

    // 5. Send Invoice via WhatsApp with PDF attachment
    if (preg_match('#^/api/invoices/([1-9][0-9]*)/whatsapp$#', $path, $m) && $method === 'POST') {
        $invoiceId = (int) $m[1];
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'invoices', 'view');

        $stmt = $db->prepare('
            SELECT i.*, c.name AS client_name, c.company AS client_company, c.phone AS client_phone, c.mobile AS client_mobile, c.email AS client_email, c.address AS client_address
            FROM invoices i
            LEFT JOIN clients c ON c.id = i.client_id
            WHERE i.id = :id
        ');
        $stmt->execute(['id' => $invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            json_response(['detail' => 'Invoice not found'], 404);
        }

        $itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
        $itStmt->execute(['id' => $invoiceId]);
        $inv['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

        $body = request_json();
        $targetPhone = !empty($body['recipient_phone']) 
            ? (string) $body['recipient_phone'] 
            : ($inv['client_mobile'] ?: $inv['client_phone'] ?: '');

        if ($targetPhone === '') {
            json_response(['detail' => 'No phone number found for this client. Please specify recipient_phone.'], 422);
        }

        $clientName = $inv['client_name'] ?: 'Valued Customer';
        $invNo = $inv['invoice_no'];
        $amountFmt = number_format((float) $inv['total_amount'], 2);
        $balFmt = number_format((float) ($inv['balance_due'] ?? $inv['total_amount']), 2);

        $defaultMessage = "Dear *{$clientName}*,\n\n" .
            "Please find attached your official Sales Tax Invoice *#{$invNo}* from DATAPOINT Technologies.\n\n" .
            "• Invoice Amount: *PKR {$amountFmt}*\n" .
            "• Balance Due: *PKR {$balFmt}*\n\n" .
            "Thank you for choosing DATAPOINT Technologies!";

        $caption = !empty($body['message']) ? (string) $body['message'] : $defaultMessage;

        // Generate official invoice PDF
        $pdfData = generate_invoice_pdf($inv);
        $cleanNo = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $invNo);
        $filename = "Invoice_{$cleanNo}.pdf";

        $res = send_whatsapp_message($targetPhone, $caption, $pdfData, $filename);

        if (!$res['success']) {
            json_response(['detail' => $res['error'] ?? 'Failed to send WhatsApp document'], 400);
        }

        // Update invoice status if it was draft
        $upd = $db->prepare("UPDATE invoices SET status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $upd->execute(['id' => $invoiceId]);

        json_response([
            'message' => "Invoice #{$invNo} sent via WhatsApp to {$targetPhone}",
            'whatsapp_response' => $res['data'] ?? []
        ]);
    }

    // 6. Send Estimate / Quotation via WhatsApp with PDF attachment
    if (preg_match('#^/api/estimates/([1-9][0-9]*)/whatsapp$#', $path, $m) && $method === 'POST') {
        $estimateId = (int) $m[1];
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'estimates', 'view');

        $stmt = $db->prepare('
            SELECT e.*, c.name AS client_name, c.company AS client_company, c.phone AS client_phone, c.mobile AS client_mobile, c.email AS client_email, c.address AS client_address
            FROM estimates e
            LEFT JOIN clients c ON c.id = e.client_id
            WHERE e.id = :id
        ');
        $stmt->execute(['id' => $estimateId]);
        $est = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$est) {
            json_response(['detail' => 'Quotation not found'], 404);
        }

        $itStmt = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id');
        $itStmt->execute(['id' => $estimateId]);
        $est['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

        $body = request_json();
        $targetPhone = !empty($body['recipient_phone']) 
            ? (string) $body['recipient_phone'] 
            : ($est['client_mobile'] ?: $est['client_phone'] ?: '');

        if ($targetPhone === '') {
            json_response(['detail' => 'No phone number found for this client. Please specify recipient_phone.'], 422);
        }

        $clientName = $est['client_name'] ?: 'Valued Customer';
        $estNo = $est['estimate_no'];
        $amountFmt = number_format((float) $est['total_amount'], 2);

        $coName = !empty($est['company_name']) ? (string)$est['company_name'] : 'DATAPOINT Technologies';
        $defaultMessage = "Dear *{$clientName}*,\n\n" .
            "Thank you for contacting {$coName}.\n" .
            "Please find attached our official Quotation *#{$estNo}*.\n\n" .
            "• Total Quote: *PKR {$amountFmt}*\n" .
            "• Valid Until: *" . ($est['valid_until'] ?: '15 Days') . "*\n\n" .
            "Please feel free to reach out if you have any questions or require revisions.";

        $caption = !empty($body['message']) ? (string) $body['message'] : $defaultMessage;

        // Generate official quotation PDF
        $pdfData = generate_estimate_pdf($est);
        $cleanNo = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $estNo);
        $filename = "Quotation_{$cleanNo}.pdf";

        $res = send_whatsapp_message($targetPhone, $caption, $pdfData, $filename);

        if (!$res['success']) {
            json_response(['detail' => $res['error'] ?? 'Failed to send quotation via WhatsApp'], 400);
        }

        // Update status to sent if draft
        $upd = $db->prepare("UPDATE estimates SET status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $upd->execute(['id' => $estimateId]);

        json_response([
            'message' => "Quotation #{$estNo} sent via WhatsApp to {$targetPhone}",
            'whatsapp_response' => $res['data'] ?? []
        ]);
    }

    // 7. Internal WhatsApp Agent Bridge (called by Baileys service for incoming messages)
    if ($method === 'POST' && $path === '/api/whatsapp/agent-bridge') {
        $body = request_json();
        $senderPhone = (string) ($body['sender_phone'] ?? '');
        $senderName = (string) ($body['sender_name'] ?? '');
        $message = (string) ($body['message'] ?? '');

        if ($message === '') {
            json_response(['success' => false, 'error' => 'Message is empty'], 422);
        }

        $agent = new SupportAgent();
        $response = $agent->handleWhatsAppIncoming($db, $senderPhone, $senderName, $message);
        json_response($response);
    }

    // 8. Clean Stale WhatsApp Session Keys (fixes Bad MAC without logging out)
    if ($method === 'POST' && $path === '/api/whatsapp/clean-sessions') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'edit');

        $authDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'whatsapp-service' . DIRECTORY_SEPARATOR . 'auth_info';
        $cleaned = 0;
        if (is_dir($authDir)) {
            $files = glob($authDir . DIRECTORY_SEPARATOR . 'session-*.json');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                        $cleaned++;
                    }
                }
            }
        }

        // Notify Baileys to reload
        $status = call_whatsapp_service('POST', '/clean-sessions', []);

        json_response([
            'success' => true,
            'message' => "Cleaned {$cleaned} stale session files. Encryption sessions refreshed.",
            'cleaned_count' => $cleaned,
            'service_status' => $status
        ]);
    }

    return false;
}

