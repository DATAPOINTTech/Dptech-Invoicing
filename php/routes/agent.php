<?php

declare(strict_types=1);

function handle_agent_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'POST' && $path === '/api/agent/chat') {
        require_authenticated_user($db);
        $body = request_json();
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            json_response(['response' => 'Please ask a question.', 'action' => null]);
        }

        $agent = new SupportAgent();

        // Check if message is a request to send invoice via WhatsApp
        $action = $agent->detectSendInvoice($message);
        if ($action !== null) {
            $ref = $action['invoice_ref'];
            $useLatest = (bool) $action['use_latest'];

            $inv = null;
            if ($ref) {
                if (str_starts_with(strtoupper($ref), 'INV')) {
                    $st = $db->prepare('SELECT i.*, c.name AS client_name, c.mobile AS client_mobile, c.phone AS client_phone, c.email AS client_email FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE UPPER(i.invoice_no) = :no');
                    $st->execute(['no' => strtoupper($ref)]);
                    $inv = $st->fetch(PDO::FETCH_ASSOC);
                } else {
                    $st = $db->prepare('SELECT i.*, c.name AS client_name, c.mobile AS client_mobile, c.phone AS client_phone, c.email AS client_email FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = :id');
                    $st->execute(['id' => (int) $ref]);
                    $inv = $st->fetch(PDO::FETCH_ASSOC);
                }
            } elseif ($useLatest) {
                $inv = $db->query('SELECT i.*, c.name AS client_name, c.mobile AS client_mobile, c.phone AS client_phone, c.email AS client_email FROM invoices i LEFT JOIN clients c ON c.id = i.client_id ORDER BY i.created_at DESC, i.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            }

            if ($inv) {
                $phone = !empty($inv['client_mobile']) ? $inv['client_mobile'] : ($inv['client_phone'] ?? '');
                $amountFormatted = number_format((float) $inv['total_amount'], 2);
                $reply = "✅ **Invoice Dispatched to WhatsApp**\n\n"
                    . "• **Invoice #:** {$inv['invoice_no']}\n"
                    . "• **Client:** {$inv['client_name']}\n"
                    . "• **Amount:** PKR {$amountFormatted}\n"
                    . "• **Status:** " . ucfirst($inv['status']) . "\n"
                    . "• **Recipient WhatsApp:** " . ($phone ?: 'Primary contact') . "\n\n"
                    . "A notification and invoice summary have been queued for sending.";
                json_response([
                    'response' => $reply,
                    'action' => 'send_invoice',
                    'invoice' => [
                        'id' => $inv['id'],
                        'invoice_no' => $inv['invoice_no'],
                        'client_name' => $inv['client_name'],
                        'total' => $inv['total_amount'],
                    ],
                ]);
            }
        }

        $reply = $agent->getResponse($message, $db);
        json_response(['response' => $reply, 'action' => null]);
    }

    if ($method === 'POST' && $path === '/api/agent/voice-quotation') {
        require_authenticated_user($db);
        $body = request_json();
        $transcript = trim((string) ($body['transcript'] ?? $body['message'] ?? ''));
        if ($transcript === '') {
            json_response(['detail' => 'transcript is required.'], 422);
        }

        $result = process_voice_quotation($transcript, $db);
        json_response($result);
    }

    if ($method === 'GET' && $path === '/api/agent/help') {
        $agent = new SupportAgent();
        json_response($agent->getHelpTopics());
    }

    return false;
}
