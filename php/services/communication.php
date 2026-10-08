<?php

declare(strict_types=1);

function send_email_pdf(string $to_email, string $subject, string $body, string $pdf_data, string $filename = 'invoice.pdf'): bool
{
    $host = trim((string) (env_value('EMAIL_HOST') ?: getenv('EMAIL_HOST') ?: ''));
    $port = (int) (env_value('EMAIL_PORT') ?: getenv('EMAIL_PORT') ?: '465');
    $user = trim((string) (env_value('EMAIL_USER') ?: getenv('EMAIL_USER') ?: ''));
    $pass = (string) (env_value('EMAIL_PASS') ?: getenv('EMAIL_PASS') ?: '');
    $fromName = trim((string) (env_value('COMPANY_NAME') ?: getenv('COMPANY_NAME') ?: 'DATAPOINT Technologies'));
    $companyAddress = trim((string) (env_value('COMPANY_ADDRESS') ?: getenv('COMPANY_ADDRESS') ?: ''));
    $companyPhone = trim((string) (env_value('COMPANY_PHONE') ?: getenv('COMPANY_PHONE') ?: ''));
    $companyEmail = trim((string) (env_value('COMPANY_EMAIL') ?: getenv('COMPANY_EMAIL') ?: $user));

    if ($host === '' || $user === '' || $pass === '') {
        throw new InvalidArgumentException('Email SMTP settings (EMAIL_HOST, EMAIL_USER, EMAIL_PASS) are not configured');
    }

    $message = '<html><body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.6; margin: 0; padding: 20px; background: #f8fafc;">';
    $message .= '<div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">';
    $message .= '<div style="background: #1e3a8a; color: white; padding: 24px; text-align: center;">';
    $message .= '<h2 style="margin: 0; font-size: 20px; font-weight: 700; letter-spacing: 0.5px;">' . htmlspecialchars($fromName) . '</h2>';
    $message .= '</div>';
    $message .= '<div style="padding: 28px 24px; font-size: 14px;">' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_HTML5)) . '</div>';
    $message .= '<div style="background: #f1f5f9; padding: 16px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;">';
    $message .= htmlspecialchars($companyAddress) . '<br>' . htmlspecialchars($companyPhone) . ' | ' . htmlspecialchars($companyEmail);
    $message .= '</div></div></body></html>';

    try {
        return send_smtp_socket(
            $host,
            $port,
            $user,
            $pass,
            $user,
            $fromName,
            $to_email,
            $subject,
            $message,
            $pdf_data,
            $filename
        );
    } catch (Throwable $e) {
        // Fallback to PHP built-in mail() if socket fails
        error_log('SMTP socket error: ' . $e->getMessage() . ', trying fallback mail()');
        $boundary = '=_Part_' . bin2hex(random_bytes(16));
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'From: ' . sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($fromName), $user),
            'Reply-To: ' . $user,
        ];
        $mailBody = "--{$boundary}\r\n";
        $mailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mailBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $mailBody .= chunk_split(base64_encode($message), 76, "\r\n") . "\r\n";
        if ($pdf_data !== '') {
            $mailBody .= "--{$boundary}\r\n";
            $mailBody .= 'Content-Type: application/pdf; name="' . basename($filename) . "\"\r\n";
            $mailBody .= "Content-Transfer-Encoding: base64\r\n";
            $mailBody .= 'Content-Disposition: attachment; filename="' . basename($filename) . "\"\r\n\r\n";
            $mailBody .= chunk_split(base64_encode($pdf_data), 76, "\r\n") . "\r\n";
        }
        $mailBody .= "--{$boundary}--\r\n";
        $fallback = @mail($to_email, $subject, $mailBody, implode("\r\n", $headers));
        if ($fallback) {
            return true;
        }
        throw new RuntimeException('SMTP email sending failed: ' . $e->getMessage());
    }
}

function send_smtp_socket(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $pdfData = '',
    string $pdfFilename = 'invoice.pdf'
): bool {
    $isSsl = ($port === 465);
    $transport = $isSsl ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException("Could not connect to SMTP server {$host}:{$port} - {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, 20);

    $readResponse = function() use ($socket): string {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $response;
    };

    $sendCommand = function(string $cmd, array $expectedCodes) use ($socket, $readResponse): string {
        fwrite($socket, $cmd . "\r\n");
        $resp = $readResponse();
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException("SMTP Command [{$cmd}] failed. Expected " . implode('/', $expectedCodes) . ", got: " . trim($resp));
        }
        return $resp;
    };

    // 1. Initial Greeting
    $greeting = $readResponse();
    if ((int)substr($greeting, 0, 3) !== 220) {
        throw new RuntimeException("SMTP greeting failed: " . trim($greeting));
    }

    // 2. EHLO
    $sendCommand("EHLO [127.0.0.1]", [250]);

    // 3. STARTTLS if port 587
    if ($port === 587) {
        $sendCommand("STARTTLS", [220]);
        $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto) {
            throw new RuntimeException("Failed to establish TLS encryption via STARTTLS.");
        }
        $sendCommand("EHLO [127.0.0.1]", [250]);
    }

    // 4. AUTH LOGIN
    if ($user !== '' && $pass !== '') {
        $sendCommand("AUTH LOGIN", [334]);
        $sendCommand(base64_encode($user), [334]);
        $sendCommand(base64_encode($pass), [235]);
    }

    // 5. Envelope
    $sendCommand("MAIL FROM:<{$user}>", [250]);
    $sendCommand("RCPT TO:<{$toEmail}>", [250, 251]);

    // 6. DATA
    $sendCommand("DATA", [354]);

    // 7. Compose MIME Message
    $boundary = '----=_NextPart_' . bin2hex(random_bytes(16));
    $cleanFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($pdfFilename));
    if (!str_ends_with(strtolower($cleanFilename), '.pdf')) {
        $cleanFilename .= '.pdf';
    }

    $headers = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($fromName), $user);
    $headers[] = 'Reply-To: ' . $user;
    $headers[] = 'To: ' . $toEmail;
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
    $headers[] = 'X-Mailer: DATAPOINT-Invoicing/1.0';

    $body = "This is a multi-part message in MIME format.\r\n\r\n";

    // HTML Part
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody), 76, "\r\n") . "\r\n";

    // PDF Part
    if ($pdfData !== '') {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/pdf; name=\"{$cleanFilename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$cleanFilename}\"\r\n\r\n";
        $body .= chunk_split(base64_encode($pdfData), 76, "\r\n") . "\r\n";
    }

    $body .= "--{$boundary}--\r\n";

    $fullPayload = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    // Dot-stuffing (RFC 5321): any line beginning with a dot must be prefixed with an extra dot
    $lines = explode("\r\n", $fullPayload);
    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;
        }
    }
    $stuffedPayload = implode("\r\n", $lines) . "\r\n.\r\n";

    // Critical: loop until ALL bytes are written to the socket without truncation
    $totalBytes = strlen($stuffedPayload);
    $bytesWritten = 0;
    while ($bytesWritten < $totalBytes) {
        $written = fwrite($socket, substr($stuffedPayload, $bytesWritten));
        if ($written === false || $written === 0) {
            throw new RuntimeException("Failed to write to SMTP socket after {$bytesWritten} of {$totalBytes} bytes.");
        }
        $bytesWritten += $written;
    }

    $dataResp = $readResponse();
    if ((int)substr($dataResp, 0, 3) !== 250) {
        throw new RuntimeException("SMTP delivery rejected by server: " . trim($dataResp));
    }

    // 8. QUIT
    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

function _format_phone(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';
    if (str_starts_with($digits, '92') && strlen($digits) === 12) {
        return '+' . $digits;
    }
    if (str_starts_with($digits, '0') && strlen($digits) === 11) {
        return '+92' . substr($digits, 1);
    }
    if (str_starts_with($digits, '92') && strlen($digits) === 13) {
        return '+' . $digits;
    }
    if (str_starts_with($digits, '0') && strlen($digits) === 12) {
        return '+92' . substr($digits, 2);
    }
    if (str_starts_with($digits, '0092')) {
        return '+' . substr($digits, 2);
    }

    return '+' . $digits;
}

function whatsapp_service_base_url(): string
{
    return trim((string) (env_value('WHATSAPP_SERVICE_URL') ?: getenv('WHATSAPP_SERVICE_URL') ?: 'http://127.0.0.1:3001'));
}

function get_whatsapp_service_status(): array
{
    $url = rtrim(whatsapp_service_base_url(), '/') . '/status';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$response) {
        return [
            'success' => false,
            'state' => 'service_offline',
            'message' => 'WhatsApp Baileys service is offline or starting up.',
            'qr' => null,
            'user' => null,
            'salesAgentEnabled' => false,
        ];
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : ['success' => false, 'state' => 'error', 'qr' => null];
}

function disconnect_whatsapp_account(): array
{
    $url = rtrim(whatsapp_service_base_url(), '/') . '/disconnect';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string)$response, true);
    return is_array($data) ? $data : ['success' => false, 'error' => 'Failed to reach WhatsApp service'];
}

function toggle_whatsapp_sales_agent(?bool $enabled = null): array
{
    $url = rtrim(whatsapp_service_base_url(), '/') . '/toggle-agent';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = $enabled !== null ? ['enabled' => $enabled] : [];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string)$response, true);
    return is_array($data) ? $data : ['success' => false, 'error' => 'Failed to reach WhatsApp service'];
}

function send_whatsapp_message(string $to_phone, string $message, ?string $pdf_bytes = null, string $pdf_filename = 'invoice.pdf'): array
{
    $cleanPhone = _format_phone($to_phone);
    $baseUrl = rtrim(whatsapp_service_base_url(), '/');

    if (!empty($pdf_bytes)) {
        $endpoint = $baseUrl . '/send-document';
        $payload = [
            'to' => $cleanPhone,
            'caption' => $message,
            'filename' => $pdf_filename,
            'fileBase64' => base64_encode($pdf_bytes),
            'mimeType' => 'application/pdf',
        ];
    } else {
        $endpoint = $baseUrl . '/send-message';
        $payload = [
            'to' => $cleanPhone,
            'message' => $message,
        ];
    }

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => 'WhatsApp microservice connection error: ' . $curlErr];
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data) || ($status !== 200 && empty($data['success']))) {
        $msg = $data['error'] ?? 'HTTP ' . $status . ' from WhatsApp service';
        return ['success' => false, 'error' => $msg];
    }

    return ['success' => true, 'data' => $data, 'error' => null];
}

