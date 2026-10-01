<?php

declare(strict_types=1);

function send_email_pdf(string $to_email, string $subject, string $body, string $pdf_data, string $filename = 'invoice.pdf'): bool
{
    if (empty(getenv('EMAIL_HOST')) || empty(getenv('EMAIL_USER')) || empty(getenv('EMAIL_PASS'))) {
        throw new InvalidArgumentException('Email settings not configured');
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . getenv('COMPANY_NAME') . ' <' . getenv('EMAIL_USER') . '>',
        'To: ' . $to_email,
        'Subject: ' . $subject,
    ];

    $message = '<html><body><div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
    $message .= '<div style="background: #2c3e50; color: white; padding: 20px; text-align: center;"><h2>' . getenv('COMPANY_NAME') . '</h2></div>';
    $message .= '<div style="padding: 20px;">' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_HTML5)) . '</div>';
    $message .= '<div style="background: #f8f9fa; padding: 10px; text-align: center; font-size: 12px; color: #666;">';
    $message .= getenv('COMPANY_ADDRESS') . '<br>' . getenv('COMPANY_PHONE') . ' | ' . getenv('COMPANY_EMAIL') . '</div>';
    $message .= '</div></body></html>';

    $bound = '----' . md5((string) microtime(true));
    $mailBody = "--{$bound}\r\n";
    $mailBody .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $mailBody .= $message . "\r\n";

    if ($pdf_data !== '') {
        $mailBody .= "--{$bound}\r\n";
        $mailBody .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
        $mailBody .= "Content-Transfer-Encoding: base64\r\n";
        $mailBody .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
        $mailBody .= chunk_split(base64_encode($pdf_data), 76, "\r\n") . "\r\n";
    }

    $mailBody .= "--{$bound}--\r\n";

    return mail($to_email, $subject, $mailBody, implode("\r\n", $headers));
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

function send_whatsapp_message(string $to_phone, string $message, ?string $pdf_bytes = null, string $pdf_filename = 'invoice.pdf'): array
{
    if (empty(getenv('WHATSAPP_API_KEY'))) {
        throw new InvalidArgumentException('WhatsApp API not configured');
    }

    $phone = _format_phone($to_phone);
    $headers = [
        'Authorization: Bearer ' . getenv('WHATSAPP_API_KEY'),
        'Content-Type: application/json',
    ];

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $phone,
        'type' => 'text',
        'text' => ['body' => $message],
    ];

    if (!empty($pdf_bytes)) {
        $upload_url = 'https://graph.facebook.com/v17.0/' . getenv('WHATSAPP_PHONE_NUMBER_ID') . '/media';
        $upload_fields = [
            'messaging_product' => 'whatsapp',
            'file' => new CURLFile('', 'application/pdf', $pdf_filename),
            'type' => 'application/pdf',
        ];

        $ch = curl_init($upload_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $upload_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . getenv('WHATSAPP_API_KEY')]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string) $resp, true);
        if (is_array($data) && !empty($data['id'])) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'document',
                'document' => [
                    'id' => $data['id'],
                    'caption' => $message,
                    'filename' => $pdf_filename,
                ],
            ];
        }
    }

    $ch = curl_init('https://graph.facebook.com/v17.0/' . getenv('WHATSAPP_PHONE_NUMBER_ID') . '/messages');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded) || empty($decoded['messages'])) {
        $error = $decoded['error']['message'] ?? (string) $response;
        return ['success' => false, 'error' => $error];
    }

    return ['success' => true, 'error' => null];
}
