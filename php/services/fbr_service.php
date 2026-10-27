<?php

declare(strict_types=1);

/**
 * DATAPOINT Invoicing System - FBR Pakistan Digital Invoicing & POS Integration Service
 * Compliant with FBR SRO 350(I)/2024, Sales Tax Act 1990, and FBR IMS (Invoice Monitoring System).
 */

require_once __DIR__ . '/qrcode_service.php';

/**
 * Retrieve active FBR configuration for a company or system-wide default.
 */
function fbr_get_config(PDO $db, ?int $companyId = null): array
{
    ensure_fbr_schema($db);

    $company = null;
    if ($companyId) {
        $stmt = $db->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $globalEnabled = get_app_setting($db, 'fbr_enabled', '1') === '1';
    $globalEnv = get_app_setting($db, 'fbr_environment', 'sandbox');
    $globalApiUrl = get_app_setting($db, 'fbr_api_url', 'https://ims.pral.com.pk/api/Live/PostData');
    $globalPosId = get_app_setting($db, 'fbr_pos_id', '100001');
    $globalToken = get_app_setting($db, 'fbr_bearer_token', '');
    $globalMode = get_app_setting($db, 'fbr_mode', 'simulation');

    // Company overrides if provided
    $enabled = $company && isset($company['fbr_enabled']) ? (bool) $company['fbr_enabled'] : $globalEnabled;
    $environment = $company && !empty($company['fbr_environment']) ? (string) $company['fbr_environment'] : $globalEnv;
    $posId = $company && !empty($company['fbr_pos_id']) ? (string) $company['fbr_pos_id'] : $globalPosId;
    $bearerToken = $company && !empty($company['fbr_bearer_token']) ? (string) $company['fbr_bearer_token'] : $globalToken;
    $apiUrl = $globalApiUrl;
    $mode = $globalMode;

    // Environment variable overrides
    if (getenv('FBR_ENABLED') !== false) {
        $enabled = (getenv('FBR_ENABLED') === '1' || strtolower((string)getenv('FBR_ENABLED')) === 'true');
    }
    if (getenv('FBR_ENVIRONMENT')) {
        $environment = (string) getenv('FBR_ENVIRONMENT');
    }
    if (getenv('FBR_API_URL')) {
        $apiUrl = (string) getenv('FBR_API_URL');
    }
    if (getenv('FBR_POS_ID')) {
        $posId = (string) getenv('FBR_POS_ID');
    }
    if (getenv('FBR_BEARER_TOKEN')) {
        $bearerToken = (string) getenv('FBR_BEARER_TOKEN');
    }
    if (getenv('FBR_MODE')) {
        $mode = (string) getenv('FBR_MODE');
    }

    return [
        'enabled' => $enabled,
        'environment' => $environment,
        'api_url' => $apiUrl,
        'pos_id' => $posId,
        'bearer_token' => $bearerToken,
        'mode' => $mode, // 'live' or 'simulation'
    ];
}

/**
 * Save global FBR settings into app_settings.
 */
function fbr_save_settings(PDO $db, array $settings): void
{
    ensure_fbr_schema($db);

    if (isset($settings['fbr_enabled'])) {
        set_app_setting($db, 'fbr_enabled', $settings['fbr_enabled'] ? '1' : '0');
    }
    if (isset($settings['fbr_environment'])) {
        set_app_setting($db, 'fbr_environment', (string) $settings['fbr_environment']);
    }
    if (isset($settings['fbr_api_url'])) {
        set_app_setting($db, 'fbr_api_url', trim((string) $settings['fbr_api_url']));
    }
    if (isset($settings['fbr_pos_id'])) {
        set_app_setting($db, 'fbr_pos_id', trim((string) $settings['fbr_pos_id']));
    }
    if (isset($settings['fbr_bearer_token'])) {
        set_app_setting($db, 'fbr_bearer_token', trim((string) $settings['fbr_bearer_token']));
    }
    if (isset($settings['fbr_mode'])) {
        set_app_setting($db, 'fbr_mode', trim((string) $settings['fbr_mode']));
    }
}

/**
 * Map payment terms / method string to standard FBR PaymentMode code:
 * 1: Cash, 2: Card, 3: Cheque, 4: Online / Bank Transfer, 5: Credit / Others
 */
function fbr_map_payment_mode(?string $terms): int
{
    if (empty($terms)) return 1;
    $t = strtolower($terms);
    if (str_contains($t, 'cash')) return 1;
    if (str_contains($t, 'card') || str_contains($t, 'visa') || str_contains($t, 'master')) return 2;
    if (str_contains($t, 'cheque') || str_contains($t, 'check') || str_contains($t, 'pay order')) return 3;
    if (str_contains($t, 'online') || str_contains($t, 'bank') || str_contains($t, 'transfer') || str_contains($t, 'ibft') || str_contains($t, 'raast')) return 4;
    return 5; // Credit / Net 30 / Other
}

/**
 * Format invoice and line items into FBR Digital Invoicing & POS JSON payload.
 */
function fbr_build_invoice_payload(array $inv, array $items, array $config): array
{
    $posIdInt = (int) ($config['pos_id'] ?: 100001);
    $invDate = !empty($inv['invoice_date']) ? (string)$inv['invoice_date'] : date('Y-m-d');
    $dateTime = date('Y-m-d H:i:s', strtotime($invDate . ' ' . date('H:i:s')));

    $subtotal = round((float)($inv['subtotal'] ?? 0), 2);
    $taxAmount = round((float)($inv['tax_amount'] ?? 0), 2);
    $totalAmount = round((float)($inv['total_amount'] ?? 0), 2);
    $discountAmount = round((float)($inv['discount_amount'] ?? 0), 2);

    $paymentMode = fbr_map_payment_mode($inv['payment_terms'] ?? null);

    $totalQty = 0.0;
    $fbrItems = [];

    foreach ($items as $idx => $it) {
        $qty = (float)($it['quantity'] ?? 1);
        $totalQty += $qty;
        $unitPrice = (float)($it['unit_price'] ?? 0);
        $saleVal = round($qty * $unitPrice, 2);
        $taxRate = (float)($it['tax_rate'] ?? ($inv['tax_rate'] ?? 18.0));
        $lineTax = round(($saleVal * $taxRate) / 100.0, 2);
        $lineTotal = round($saleVal + $lineTax, 2);

        $pctCode = !empty($it['pct_code'])
            ? (string)$it['pct_code']
            : (!empty($it['hs_code']) ? (string)$it['hs_code'] : '9999.9999');

        $fbrItems[] = [
            'ItemCode' => !empty($it['product_id']) ? ('PRD-' . $it['product_id']) : ('ITM-' . ($idx + 1)),
            'ItemName' => !empty($it['description']) ? trim((string)$it['description']) : 'Sales Item',
            'PCTCode' => $pctCode,
            'Quantity' => $qty,
            'TaxRate' => $taxRate,
            'SaleValue' => $saleVal,
            'TotalAmount' => $lineTotal,
            'TaxCharged' => $lineTax,
            'Discount' => 0.0,
            'FurtherTax' => 0.0,
            'InvoiceType' => 1,
        ];
    }

    if (empty($fbrItems)) {
        $fbrItems[] = [
            'ItemCode' => 'GEN-01',
            'ItemName' => $inv['title'] ?? 'General Sales Item',
            'PCTCode' => '9999.9999',
            'Quantity' => 1.0,
            'TaxRate' => (float)($inv['tax_rate'] ?? 18.0),
            'SaleValue' => $subtotal,
            'TotalAmount' => $totalAmount,
            'TaxCharged' => $taxAmount,
            'Discount' => $discountAmount,
            'FurtherTax' => 0.0,
            'InvoiceType' => 1,
        ];
        $totalQty = 1.0;
    }

    $buyerNtn = trim((string)($inv['client_ntn'] ?? ''));
    $buyerCnic = trim((string)($inv['client_cnic'] ?? ''));
    $buyerName = trim((string)($inv['client_name'] ?? 'Valued Customer'));
    $buyerPhone = trim((string)($inv['client_mobile'] ?? $inv['client_phone'] ?? ''));

    return [
        'InvoiceNumber' => '', // Assigned and returned by FBR
        'POSID' => $posIdInt,
        'USIN' => (string)($inv['invoice_no']),
        'DateTime' => $dateTime,
        'TotalSaleValue' => $subtotal,
        'TotalTaxCharged' => $taxAmount,
        'TotalQuantity' => $totalQty,
        'TotalBillAmount' => $totalAmount,
        'PaymentMode' => $paymentMode,
        'InvoiceType' => 1, // Standard Sales Tax Invoice
        'Discount' => $discountAmount,
        'FurtherTax' => 0.0,
        'BuyerNTN' => $buyerNtn !== '' ? $buyerNtn : null,
        'BuyerCNIC' => $buyerCnic !== '' ? $buyerCnic : null,
        'BuyerName' => $buyerName,
        'BuyerPhoneNumber' => $buyerPhone !== '' ? $buyerPhone : null,
        'Items' => $fbrItems,
    ];
}

/**
 * Approve an invoice so it is ready for posting on the FBR portal.
 */
function approve_invoice(PDO $db, int $invoiceId, ?int $userId = null): array
{
    ensure_fbr_schema($db);

    $stmt = $db->prepare('SELECT * FROM invoices WHERE id = :id');
    $stmt->execute(['id' => $invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        return ['success' => false, 'error' => 'Invoice not found'];
    }

    if ($inv['status'] === 'cancelled') {
        return ['success' => false, 'error' => 'Cannot approve a cancelled invoice'];
    }

    $newStatus = ($inv['status'] === 'draft') ? 'approved' : $inv['status'];

    $upd = $db->prepare('
        UPDATE invoices SET
            status = :st,
            approved_at = COALESCE(approved_at, CURRENT_TIMESTAMP),
            approved_by = COALESCE(approved_by, :uid),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ');
    $upd->execute([
        'st' => $newStatus,
        'uid' => $userId,
        'id' => $invoiceId,
    ]);

    return [
        'success' => true,
        'invoice_id' => $invoiceId,
        'status' => $newStatus,
        'ready_for_fbr' => true,
        'message' => 'Invoice approved successfully and is now ready for FBR portal posting.',
    ];
}

/**
 * Post approved invoice to FBR Pakistan Tax Collection Portal.
 */
function post_invoice_to_fbr(PDO $db, int $invoiceId, ?int $userId = null, bool $force = false): array
{
    ensure_fbr_schema($db);

    // 1. Fetch invoice and client details
    $stmt = $db->prepare('
        SELECT i.*,
               c.name AS client_name, c.company AS client_company, c.ntn AS client_ntn,
               c.strn AS client_strn, c.phone AS client_phone, c.mobile AS client_mobile,
               c.address AS client_address, c.city AS client_city
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.id = :id
    ');
    $stmt->execute(['id' => $invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        return ['success' => false, 'error' => 'Invoice not found'];
    }

    if ($inv['status'] === 'cancelled') {
        return ['success' => false, 'error' => 'Cancelled invoices cannot be posted to FBR.'];
    }

    // Workflow Check: Invoices must be approved and ready before posting
    $allowedStatuses = ['approved', 'sent', 'partially_paid', 'paid'];
    if (!in_array($inv['status'], $allowedStatuses, true)) {
        return [
            'success' => false,
            'error' => "Invoice is currently in '{$inv['status']}' status. Invoices must be approved before posting to the FBR portal.",
            'requires_approval' => true,
        ];
    }

    // Check if already posted successfully
    if (!$force && $inv['fbr_status'] === 'posted' && !empty($inv['fbr_invoice_no'])) {
        return [
            'success' => true,
            'already_posted' => true,
            'fbr_invoice_no' => $inv['fbr_invoice_no'],
            'fbr_status' => 'posted',
            'fbr_posted_at' => $inv['fbr_posted_at'],
            'fbr_qr_code' => $inv['fbr_qr_code'],
            'message' => "Invoice #{$inv['invoice_no']} is already posted on FBR portal with FBR Invoice #{$inv['fbr_invoice_no']}.",
        ];
    }

    // 2. Fetch invoice items
    $itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
    $itStmt->execute(['id' => $invoiceId]);
    $items = $itStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Get FBR configuration
    $companyId = !empty($inv['company_id']) ? (int)$inv['company_id'] : null;
    $config = fbr_get_config($db, $companyId);

    if (!$config['enabled']) {
        return [
            'success' => false,
            'error' => 'FBR Integration is currently disabled in system settings. Please enable it in Settings / Companies.',
        ];
    }

    $payload = fbr_build_invoice_payload($inv, $items, $config);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // 4. Handle Simulation Mode (Sandbox Testing)
    if ($config['mode'] === 'simulation' || empty($config['bearer_token'])) {
        $posIdStr = str_pad((string)$config['pos_id'], 6, '0', STR_PAD_LEFT);
        $dateStr = date('Ymd');
        $seq = str_pad((string)$invoiceId, 6, '0', STR_PAD_LEFT);
        $simulatedFbrNo = $posIdStr . $dateStr . $seq; // 18-20 digit official FBR style number

        $qrData = $simulatedFbrNo;
        $simResponse = json_encode([
            'InvoiceNumber' => $simulatedFbrNo,
            'Code' => '100',
            'Response' => 'Successfully Posted (FBR IMS Sandbox Simulation)',
            'Status' => 'Valid',
            'Environment' => $config['environment'],
            'POSID' => $config['pos_id'],
            'Timestamp' => date('Y-m-d H:i:s'),
        ]);

        $upd = $db->prepare('
            UPDATE invoices SET
                fbr_status = :status,
                fbr_invoice_no = :fbr_no,
                fbr_pos_id = :pos_id,
                fbr_code = :code,
                fbr_response = :resp,
                fbr_qr_code = :qr,
                fbr_posted_at = CURRENT_TIMESTAMP,
                fbr_posted_by = :uid,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $upd->execute([
            'status' => 'posted',
            'fbr_no' => $simulatedFbrNo,
            'pos_id' => $config['pos_id'],
            'code' => '100',
            'resp' => $simResponse,
            'qr' => $qrData,
            'uid' => $userId,
            'id' => $invoiceId,
        ]);

        return [
            'success' => true,
            'fbr_invoice_no' => $simulatedFbrNo,
            'fbr_status' => 'posted',
            'fbr_pos_id' => $config['pos_id'],
            'fbr_qr_code' => $qrData,
            'mode' => 'sandbox_simulation',
            'message' => "Successfully posted to FBR Pakistan Portal (Sandbox Simulation). Official FBR Invoice #: {$simulatedFbrNo}",
        ];
    }

    // 5. Live FBR API Request via cURL
    $apiUrl = $config['api_url'];
    $ch = curl_init($apiUrl);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . trim($config['bearer_token']),
        'Accept: application/json',
    ];

    $cacert = __DIR__ . '/cacert.pem';

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    if (file_exists($cacert)) {
        curl_setopt($ch, CURLOPT_CAINFO, $cacert);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

    $rawResponse = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResponse === false || $curlErr !== '') {
        $errorMsg = "Failed to connect to FBR server: {$curlErr}";
        $upd = $db->prepare('
            UPDATE invoices SET
                fbr_status = :status,
                fbr_response = :resp,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $upd->execute([
            'status' => 'failed',
            'resp' => json_encode(['error' => $errorMsg, 'http_code' => $httpCode, 'timestamp' => date('Y-m-d H:i:s')]),
            'id' => $invoiceId,
        ]);

        return [
            'success' => false,
            'fbr_status' => 'failed',
            'error' => $errorMsg,
            'suggestion' => 'Check internet connectivity, FBR API endpoint URL, and verify the PRAL server is online.',
        ];
    }

    $json = json_decode((string)$rawResponse, true);
    $fbrInvoiceNo = null;
    $isSuccess = false;
    $resCode = '0';

    if (is_array($json)) {
        $resCode = (string)($json['Code'] ?? $json['code'] ?? '');
        $fbrInvoiceNo = !empty($json['InvoiceNumber']) ? (string)$json['InvoiceNumber'] : (!empty($json['invoiceNumber']) ? (string)$json['invoiceNumber'] : null);
        
        // FBR success is typically Code "100" or presence of valid InvoiceNumber
        if ($resCode === '100' || (!empty($fbrInvoiceNo) && $resCode !== '101' && $resCode !== '102')) {
            $isSuccess = true;
        }
    } elseif (is_numeric(trim((string)$rawResponse))) {
        // Some FBR endpoints return raw invoice number string directly
        $fbrInvoiceNo = trim((string)$rawResponse);
        $isSuccess = true;
        $resCode = '100';
    }

    if ($isSuccess && !empty($fbrInvoiceNo)) {
        $qrData = $fbrInvoiceNo;
        $upd = $db->prepare('
            UPDATE invoices SET
                fbr_status = :status,
                fbr_invoice_no = :fbr_no,
                fbr_pos_id = :pos_id,
                fbr_code = :code,
                fbr_response = :resp,
                fbr_qr_code = :qr,
                fbr_posted_at = CURRENT_TIMESTAMP,
                fbr_posted_by = :uid,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $upd->execute([
            'status' => 'posted',
            'fbr_no' => $fbrInvoiceNo,
            'pos_id' => $config['pos_id'],
            'code' => $resCode ?: '100',
            'resp' => $rawResponse,
            'qr' => $qrData,
            'uid' => $userId,
            'id' => $invoiceId,
        ]);

        return [
            'success' => true,
            'fbr_invoice_no' => $fbrInvoiceNo,
            'fbr_status' => 'posted',
            'fbr_pos_id' => $config['pos_id'],
            'fbr_qr_code' => $qrData,
            'mode' => 'live',
            'message' => "Invoice successfully posted to FBR Pakistan Portal. Official FBR Invoice #: {$fbrInvoiceNo}",
        ];
    }

    // FBR Error response
    $errorDesc = $json['Response'] ?? $json['response'] ?? $json['Message'] ?? $json['message'] ?? $rawResponse;
    $upd = $db->prepare('
        UPDATE invoices SET
            fbr_status = :status,
            fbr_code = :code,
            fbr_response = :resp,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ');
    $upd->execute([
        'status' => 'failed',
        'code' => $resCode,
        'resp' => $rawResponse,
        'id' => $invoiceId,
    ]);

    return [
        'success' => false,
        'fbr_status' => 'failed',
        'error' => "FBR rejected invoice submission (Code {$resCode}): {$errorDesc}",
        'raw_response' => $rawResponse,
    ];
}
