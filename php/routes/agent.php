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

        // 1. Check if message is a request to send invoice via WhatsApp
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
            } elseif ($useLatest || empty($ref)) {
                $inv = $db->query('SELECT i.*, c.name AS client_name, c.mobile AS client_mobile, c.phone AS client_phone, c.email AS client_email FROM invoices i LEFT JOIN clients c ON c.id = i.client_id ORDER BY i.created_at DESC, i.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            }

            if ($inv) {
                $itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
                $itStmt->execute(['id' => $inv['id']]);
                $inv['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

                $phone = !empty($action['target_phone']) 
                    ? $action['target_phone'] 
                    : (!empty($inv['client_mobile']) ? $inv['client_mobile'] : ($inv['client_phone'] ?? ''));
                $amountFormatted = number_format((float) $inv['total_amount'], 2);
                $balFormatted = number_format((float) ($inv['balance_due'] ?? $inv['total_amount']), 2);

                $caption = "Dear *" . ($inv['client_name'] ?: 'Valued Customer') . "*,\n\n"
                    . "Here is your official Sales Tax Invoice *#{$inv['invoice_no']}* from DATAPOINT Technologies.\n\n"
                    . "• Amount: *PKR {$amountFormatted}*\n"
                    . "• Balance Due: *PKR {$balFormatted}*\n"
                    . "• Status: *" . ucfirst($inv['status']) . "*\n\n"
                    . "Thank you for doing business with us!";

                $pdfBytes = generate_invoice_pdf($inv);
                $cleanNo = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $inv['invoice_no']);
                $filename = "Invoice_{$cleanNo}.pdf";

                $dispatchResult = null;
                if (!empty($phone)) {
                    $dispatchResult = send_whatsapp_message($phone, $caption, $pdfBytes, $filename);
                }

                $statusText = ($dispatchResult && !empty($dispatchResult['success']))
                    ? "🚀 **Delivered instantly to WhatsApp (+{$phone})!**"
                    : "⚠️ **WhatsApp Service Notice**: " . ($dispatchResult['error'] ?? 'Client has no phone number on record');

                $reply = "📄 **Invoice Dispatch Report**\n\n"
                    . "• **Invoice #:** `{$inv['invoice_no']}`\n"
                    . "• **Client:** {$inv['client_name']}\n"
                    . "• **Total Amount:** PKR {$amountFormatted}\n"
                    . "• **Recipient WhatsApp:** " . ($phone ?: 'Not configured') . "\n\n"
                    . "{$statusText}\n\n"
                    . "[📥 Click here to view / download PDF](/api/invoices/{$inv['id']}/pdf)";

                json_response([
                    'response' => $reply,
                    'action' => 'send_invoice',
                    'invoice' => [
                        'id' => $inv['id'],
                        'invoice_no' => $inv['invoice_no'],
                        'client_name' => $inv['client_name'],
                        'total' => $inv['total_amount'],
                        'phone' => $phone,
                        'dispatched' => (bool) ($dispatchResult['success'] ?? false)
                    ],
                ]);
            }
        }

        // 2. Check if message is a request to send quotation / estimate via WhatsApp
        $actionQuote = $agent->detectSendQuotation($message);
        if ($actionQuote !== null) {
            $ref = $actionQuote['estimate_ref'];
            $useLatest = (bool) $actionQuote['use_latest'];

            $est = null;
            if ($ref) {
                if (str_starts_with(strtoupper($ref), 'EST')) {
                    $st = $db->prepare('SELECT e.*, c.name AS client_name, c.mobile AS client_mobile, c.phone AS client_phone, c.email AS client_email FROM estimates e LEFT JOIN clients c ON c.id = e.client_id WHERE UPPER(e.estimate_no) = :no');
                    $st->execute(['no' => strtoupper($ref)]);
                    $est = $st->fetch(PDO::FETCH_ASSOC);
                } else {
                    $st = $db->prepare('SELECT e.*, c.name AS client_name, c.mobile AS client_mobile, c.phone AS client_phone, c.email AS client_email FROM estimates e LEFT JOIN clients c ON c.id = e.client_id WHERE e.id = :id');
                    $st->execute(['id' => (int) $ref]);
                    $est = $st->fetch(PDO::FETCH_ASSOC);
                }
            } elseif ($useLatest || empty($ref)) {
                $est = $db->query('SELECT e.*, c.name AS client_name, c.mobile AS client_mobile, c.phone AS client_phone, c.email AS client_email FROM estimates e LEFT JOIN clients c ON c.id = e.client_id ORDER BY e.created_at DESC, e.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            }

            if ($est) {
                $itStmt = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id');
                $itStmt->execute(['id' => $est['id']]);
                $est['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

                $phone = !empty($actionQuote['target_phone']) 
                    ? $actionQuote['target_phone'] 
                    : (!empty($est['client_mobile']) ? $est['client_mobile'] : ($est['client_phone'] ?? ''));
                $amountFormatted = number_format((float) $est['total_amount'], 2);

                $caption = "Dear *" . ($est['client_name'] ?: 'Valued Customer') . "*,\n\n"
                    . "Thank you for contacting DATAPOINT Technologies.\n"
                    . "Please find attached our official Quotation *#{$est['estimate_no']}*.\n\n"
                    . "• Total Quote: *PKR {$amountFormatted}*\n"
                    . "• Valid Until: *" . ($est['valid_until'] ?: '15 Days') . "*\n\n"
                    . "Please feel free to reach out if you have any questions or require revisions.";

                $pdfBytes = generate_estimate_pdf($est);
                $cleanNo = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $est['estimate_no']);
                $filename = "Quotation_{$cleanNo}.pdf";

                $dispatchResult = null;
                if (!empty($phone)) {
                    $dispatchResult = send_whatsapp_message($phone, $caption, $pdfBytes, $filename);
                }

                $statusText = ($dispatchResult && !empty($dispatchResult['success']))
                    ? "🚀 **Delivered instantly to WhatsApp (+{$phone})!**"
                    : "⚠️ **WhatsApp Service Notice**: " . ($dispatchResult['error'] ?? 'Client has no phone number on record');

                $reply = "📋 **Quotation Dispatch Report**\n\n"
                    . "• **Quotation #:** `{$est['estimate_no']}`\n"
                    . "• **Client:** {$est['client_name']}\n"
                    . "• **Quote Total:** PKR {$amountFormatted}\n"
                    . "• **Recipient WhatsApp:** " . ($phone ?: 'Not configured') . "\n\n"
                    . "{$statusText}\n\n"
                    . "[📥 Click here to view / download PDF](/api/estimates/{$est['id']}/pdf)";

                json_response([
                    'response' => $reply,
                    'action' => 'send_quotation',
                    'estimate' => [
                        'id' => $est['id'],
                        'estimate_no' => $est['estimate_no'],
                        'client_name' => $est['client_name'],
                        'total' => $est['total_amount'],
                        'phone' => $phone,
                        'dispatched' => (bool) ($dispatchResult['success'] ?? false)
                    ],
                ]);
            }
        }

        $agent->recordMessage($db, 0, 'client', 'You', $message, 'text', null, null, false);
        $reply = $agent->getResponse($message, $db);
        $agent->recordMessage($db, 0, 'bot', 'Support Assistant', $reply, 'text', null, null, false);
        json_response(['response' => $reply, 'action' => null]);
    }

    // 1. Get all conversations (WhatsApp & Web)
    if ($method === 'GET' && $path === '/api/agent/conversations') {
        require_authenticated_user($db);
        $convs = $db->query("
            SELECT c.*, 
                   cl.name AS linked_client_name, 
                   cl.company AS linked_client_company,
                   (SELECT COUNT(*) FROM agent_messages m WHERE m.conversation_id = c.id) AS message_count
            FROM agent_conversations c
            LEFT JOIN clients cl ON cl.id = c.client_id
            ORDER BY c.updated_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        json_response(['conversations' => $convs]);
    }

    // 2. Get messages for a specific conversation
    if (preg_match('#^/api/agent/conversations/([0-9]+)/messages$#', $path, $m) && $method === 'GET') {
        require_authenticated_user($db);
        $convId = (int) $m[1];
        $conv = null;

        if ($convId > 0) {
            $cSt = $db->prepare('
                SELECT c.*, cl.name AS linked_client_name, cl.company AS linked_client_company
                FROM agent_conversations c
                LEFT JOIN clients cl ON cl.id = c.client_id
                WHERE c.id = :id
            ');
            $cSt->execute(['id' => $convId]);
            $conv = $cSt->fetch(PDO::FETCH_ASSOC);

            $mSt = $db->prepare('SELECT * FROM agent_messages WHERE conversation_id = :id ORDER BY id ASC');
            $mSt->execute(['id' => $convId]);
            $messages = $mSt->fetchAll(PDO::FETCH_ASSOC);

            // Mark unread as read
            $db->prepare('UPDATE agent_conversations SET unread_count = 0 WHERE id = :id')->execute(['id' => $convId]);
        } else {
            // Internal Assistant messages (conversation_id = 0)
            $mSt = $db->prepare('SELECT * FROM agent_messages WHERE conversation_id = 0 ORDER BY id ASC LIMIT 100');
            $mSt->execute();
            $messages = $mSt->fetchAll(PDO::FETCH_ASSOC);
        }

        json_response(['conversation' => $conv, 'messages' => $messages]);
    }

    // 3. Admin / Staff reply to a conversation (dispatches on WhatsApp if WhatsApp conversation)
    if (preg_match('#^/api/agent/conversations/([0-9]+)/reply$#', $path, $m) && $method === 'POST') {
        $user = require_authenticated_user($db);
        $convId = (int) $m[1];
        $body = request_json();
        $replyText = trim((string) ($body['message'] ?? ''));

        if ($replyText === '') {
            json_response(['detail' => 'Reply message cannot be empty'], 422);
        }

        $senderName = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
        $agent = new SupportAgent();

        if ($convId === 0) {
            // Direct chat with AI assistant
            $agent->recordMessage($db, 0, 'agent', $senderName, $replyText, 'text', null, null, false);
            $botReply = $agent->getResponse($replyText, $db);
            $agent->recordMessage($db, 0, 'bot', 'Support Assistant', $botReply, 'text', null, null, false);
            json_response([
                'success' => true,
                'bot_reply' => $botReply
            ]);
        }

        $cSt = $db->prepare('SELECT * FROM agent_conversations WHERE id = :id');
        $cSt->execute(['id' => $convId]);
        $conv = $cSt->fetch(PDO::FETCH_ASSOC);

        if (!$conv) {
            json_response(['detail' => 'Conversation not found'], 404);
        }

        $dispatchResult = null;
        if ($conv['channel'] === 'whatsapp' && !empty($conv['sender_phone'])) {
            $dispatchResult = send_whatsapp_message($conv['sender_phone'], $replyText);
        }

        $msgId = $agent->recordMessage(
            $db,
            $convId,
            'agent',
            $senderName . ' (Support)',
            $replyText,
            'text',
            null,
            null,
            $conv['channel'] === 'whatsapp'
        );

        $db->prepare('UPDATE agent_conversations SET last_message = :msg, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['msg' => $replyText, 'id' => $convId]);

        json_response([
            'success' => true,
            'message_id' => $msgId,
            'whatsapp_dispatched' => (bool) ($dispatchResult['success'] ?? false),
            'dispatch_error' => $dispatchResult['error'] ?? null
        ]);
    }

    // 4. Live Synchronization Polling (for real-time updates)
    if ($method === 'GET' && $path === '/api/agent/sync-poll') {
        require_authenticated_user($db);
        $lastMsgId = (int) ($_GET['last_msg_id'] ?? 0);
        $convId = isset($_GET['conv_id']) ? (int) $_GET['conv_id'] : -1;

        $params = ['last_id' => $lastMsgId];
        $sql = 'SELECT * FROM agent_messages WHERE id > :last_id';
        if ($convId >= 0) {
            $sql .= ' AND conversation_id = :cid';
            $params['cid'] = $convId;
        }
        $sql .= ' ORDER BY id ASC LIMIT 50';

        $st = $db->prepare($sql);
        $st->execute($params);
        $newMessages = $st->fetchAll(PDO::FETCH_ASSOC);

        $unreadTotal = (int) $db->query('SELECT COALESCE(SUM(unread_count), 0) FROM agent_conversations')->fetchColumn();
        $totalConvs = (int) $db->query('SELECT COUNT(*) FROM agent_conversations')->fetchColumn();

        json_response([
            'new_messages' => $newMessages,
            'unread_total' => $unreadTotal,
            'total_conversations' => $totalConvs
        ]);
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
