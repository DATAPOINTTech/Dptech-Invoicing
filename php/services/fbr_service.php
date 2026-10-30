<?php

declare(strict_types=1);

/**
 * DATAPOINT Invoicing System - FBR Pakistan Digital Invoicing (DI) API Integration Service
 * Official integration for FBR Digital Invoicing API (SRO 350(I)/2024 & PRAL Gateway):
 *  - Post Invoices: https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb
 *  - Validate Invoices: https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb
 *  - Default Sandbox Token: 18d1e4a2-145a-3ec1-b20b-02ccaab53457
 */

require_once __DIR__ . '/qrcode_service.php';

const FBR_DEFAULT_SANDBOX_POST_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb';
const FBR_DEFAULT_SANDBOX_VALIDATE_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb';
const FBR_DEFAULT_PROD_POST_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata';
const FBR_DEFAULT_PROD_VALIDATE_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata';
const FBR_DEFAULT_SANDBOX_TOKEN = '18d1e4a2-145a-3ec1-b20b-02ccaab53457';
const FBR_DEFAULT_VERIFY_URL = 'https://e.fbr.gov.pk/esr/InvoiceVerification.aspx?InvoiceNo={INVOICE_NO}';

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
    $globalApiUrl = get_app_setting($db, 'fbr_api_url', FBR_DEFAULT_SANDBOX_POST_URL);
    $globalValidateUrl = get_app_setting($db, 'fbr_validate_url', FBR_DEFAULT_SANDBOX_VALIDATE_URL);
    $globalVerifyUrl = get_app_setting($db, 'fbr_verify_url', FBR_DEFAULT_VERIFY_URL);
    $globalPosId = get_app_setting($db, 'fbr_pos_id', '100001');
    $globalToken = get_app_setting($db, 'fbr_bearer_token', FBR_DEFAULT_SANDBOX_TOKEN);
    $globalMode = get_app_setting($db, 'fbr_mode', 'live');

    // Company overrides if provided
    $enabled = $company && isset($company['fbr_enabled']) ? (bool) $company['fbr_enabled'] : $globalEnabled;
    $environment = $company && !empty($company['fbr_environment']) ? (string) $company['fbr_environment'] : $globalEnv;
    $posId = $company && !empty($company['fbr_pos_id']) ? (string) $company['fbr_pos_id'] : $globalPosId;
    $bearerToken = $company && !empty($company['fbr_bearer_token']) ? (string) $company['fbr_bearer_token'] : $globalToken;
    $apiUrl = $globalApiUrl;
    $validateUrl = $globalValidateUrl;
    $verifyUrl = $globalVerifyUrl;
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
    if (getenv('FBR_VALIDATE_URL')) {
        $validateUrl = (string) getenv('FBR_VALIDATE_URL');
    }
    if (getenv('FBR_VERIFY_URL')) {
        $verifyUrl = (string) getenv('FBR_VERIFY_URL');
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

    if (empty($bearerToken)) {
        $bearerToken = FBR_DEFAULT_SANDBOX_TOKEN;
    }
    if (empty($verifyUrl)) {
        $verifyUrl = FBR_DEFAULT_VERIFY_URL;
    }

    // Determine correct endpoint according to environment if not explicitly customized
    if ($environment === 'sandbox' && (empty($apiUrl) || str_contains($apiUrl, 'ims.pral.com.pk'))) {
        $apiUrl = FBR_DEFAULT_SANDBOX_POST_URL;
        $validateUrl = FBR_DEFAULT_SANDBOX_VALIDATE_URL;
    } elseif ($environment === 'production' && (empty($apiUrl) || str_contains($apiUrl, 'postinvoicedata_sb'))) {
        $apiUrl = FBR_DEFAULT_PROD_POST_URL;
        $validateUrl = FBR_DEFAULT_PROD_VALIDATE_URL;
    }

    return [
        'enabled' => $enabled,
        'environment' => $environment,
        'api_url' => $apiUrl,
        'validate_url' => $validateUrl,
        'verify_url' => $verifyUrl,
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
    if (isset($settings['fbr_validate_url'])) {
        set_app_setting($db, 'fbr_validate_url', trim((string) $settings['fbr_validate_url']));
    }
    if (isset($settings['fbr_verify_url'])) {
        set_app_setting($db, 'fbr_verify_url', trim((string) $settings['fbr_verify_url']));
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
 * Resolve any QR string or invoice number into an official FBR verification URL.
 */
function fbr_resolve_qr_verification_url(string $qrOrNo, ?string $templateUrl = null): string
{
    $val = trim($qrOrNo);
    if ($val === '') {
        return '';
    }
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
        return $val;
    }
    $tpl = $templateUrl ?: FBR_DEFAULT_VERIFY_URL;
    if (str_contains($tpl, '{INVOICE_NO}')) {
        return str_replace('{INVOICE_NO}', rawurlencode($val), $tpl);
    }
    if (str_contains($tpl, '{FBR_NO}')) {
        return str_replace('{FBR_NO}', rawurlencode($val), $tpl);
    }
    $sep = str_contains($tpl, '?') ? '&' : '?';
    return $tpl . $sep . 'InvoiceNo=' . rawurlencode($val);
}

/**
 * Build the full FBR invoice verification URL for a given FBR invoice number.
 */
function fbr_build_verification_url(?PDO $db, string $fbrInvoiceNo, ?int $companyId = null): string
{
    $cleanNo = trim($fbrInvoiceNo);
    if ($cleanNo === '') {
        return '';
    }
    $template = null;
    if ($db !== null) {
        $config = fbr_get_config($db, $companyId);
        $template = $config['verify_url'] ?? null;
    }
    return fbr_resolve_qr_verification_url($cleanNo, $template);
}

/**
 * Return the QR code payload (the verification URL) for an invoice record.
 * If fbr_qr_code already contains a full URL (http:// or https://), return it.
 * Otherwise, build the official FBR verification URL from fbr_invoice_no.
 */
function fbr_get_invoice_qr_target(?PDO $db, array $inv): string
{
    $storedQr = trim((string)($inv['fbr_qr_code'] ?? ''));
    if ($storedQr !== '' && (str_starts_with($storedQr, 'http://') || str_starts_with($storedQr, 'https://'))) {
        return $storedQr;
    }
    $fbrNo = trim((string)($inv['fbr_invoice_no'] ?? ($storedQr ?: '')));
    if ($fbrNo === '') {
        return '';
    }
    $companyId = !empty($inv['company_id']) ? (int)$inv['company_id'] : null;
    return fbr_build_verification_url($db, $fbrNo, $companyId);
}

/**
 * Normalize and map user unit abbreviations to official FBR DI API UoM descriptions.
 * FBR enforces strict allowed UoM values per HS Code.
 */
function fbr_normalize_uom(?string $unit): string
{
    if (empty($unit)) {
        return 'Numbers, pieces, units';
    }
    $u = strtolower(trim($unit));
    if (str_contains($u, 'number') || str_contains($u, 'piece') || str_contains($u, 'unit') || str_contains($u, 'item')) {
        return 'Numbers, pieces, units';
    }
    if (in_array($u, ['pcs', 'pc', 'piece', 'pieces', 'unit', 'units', 'item', 'items', 'no', 'nos', 'number', 'numbers', 'set', 'sets', 'pack', 'pkg', 'lot', 'box'], true)) {
        return 'Numbers, pieces, units';
    }
    if (in_array($u, ['kg', 'kgs', 'kilogram', 'kilograms'], true)) {
        return 'Kilograms';
    }
    if (in_array($u, ['m', 'mtr', 'meter', 'meters'], true)) {
        return 'Meters';
    }
    if (in_array($u, ['l', 'ltr', 'liter', 'liters', 'litre'], true)) {
        return 'Liters';
    }
    if (in_array($u, ['foot', 'feet', 'ft'], true)) {
        return 'Feet';
    }
    return 'Numbers, pieces, units';
}

/**
 * Format invoice and line items into official FBR Digital Invoicing (DI) API schema:
 * invoiceType, sellerNTNCNIC, buyerNTNCNIC, scenarioId, items...
 */
function fbr_build_digital_invoicing_payload(array $inv, array $items, array $config, array $company = []): array
{
    // Clean seller NTN/CNIC (FBR requires exactly 7 digits for NTN or 13 digits for CNIC)
    $rawSellerNtn = !empty($company['ntn']) ? $company['ntn'] : (!empty($inv['company_ntn']) ? $inv['company_ntn'] : '7178396');
    $cleanSeller = preg_replace('/[^0-9]/', '', (string)$rawSellerNtn) ?: '7178396';
    if (strlen($cleanSeller) === 8) {
        $cleanSeller = substr($cleanSeller, 0, 7);
    } elseif (strlen($cleanSeller) > 7 && strlen($cleanSeller) !== 13) {
        $cleanSeller = substr($cleanSeller, 0, 7);
    }
    $sellerNTN = $cleanSeller ?: '7178396';
    $sellerName = !empty($company['name']) ? (string)$company['name'] : (!empty($inv['company_name']) ? (string)$inv['company_name'] : 'DATAPOINT Technologies');
    $sellerProvince = !empty($company['province']) ? (string)$company['province'] : 'Sindh';
    $sellerAddress = !empty($company['address']) ? (string)$company['address'] : 'Hyderabad, Sindh';

    // Buyer info & B2B / B2C detection
    $rawBuyerNtn = !empty($inv['client_ntn']) ? (string)$inv['client_ntn'] : (!empty($inv['client_cnic']) ? (string)$inv['client_cnic'] : '');
    $cleanBuyerNtn = preg_replace('/[^0-9]/', '', $rawBuyerNtn);
    if (strlen($cleanBuyerNtn) === 8) {
        $cleanBuyerNtn = substr($cleanBuyerNtn, 0, 7);
    } elseif (strlen($cleanBuyerNtn) > 7 && strlen($cleanBuyerNtn) !== 13) {
        $cleanBuyerNtn = substr($cleanBuyerNtn, 0, 7);
    }
    $isRegistered = ($cleanBuyerNtn !== '' && (strlen($cleanBuyerNtn) === 7 || strlen($cleanBuyerNtn) === 13));
    $buyerType = $isRegistered ? 'Registered' : 'Unregistered';
    $buyerNTNCNIC = $isRegistered ? $cleanBuyerNtn : '1000000000000'; // Standard FBR unregistered consumer dummy NTN
    $scenarioId = $isRegistered ? 'SN001' : 'SN002'; // SN001: Registered B2B, SN002: Unregistered B2C

    $buyerName = !empty($inv['client_name']) ? (string)$inv['client_name'] : 'Walk-in / Valued Customer';
    $buyerProvince = !empty($inv['client_province']) ? (string)$inv['client_province'] : 'Sindh';
    $buyerAddress = !empty($inv['client_address']) ? (string)$inv['client_address'] : 'Karachi';

    $invDate = !empty($inv['invoice_date']) ? (string)$inv['invoice_date'] : date('Y-m-d');
    $invRefNo = !empty($inv['invoice_no']) ? (string)$inv['invoice_no'] : ('INV-' . date('Ymd-His'));

    $fbrItems = [];
    foreach ($items as $idx => $it) {
        $qty = (float)($it['quantity'] ?? 1);
        $unitPrice = (float)($it['unit_price'] ?? 0);
        $valExcl = round($qty * $unitPrice, 2);
        $taxRate = (float)($it['tax_rate'] ?? ($inv['tax_rate'] ?? 18.0));
        $taxAmt = round(($valExcl * $taxRate) / 100.0, 2);
        $totalVal = round($valExcl + $taxAmt, 2);

        $rawHs = !empty($it['pct_code']) ? (string)$it['pct_code'] : (!empty($it['hs_code']) ? (string)$it['hs_code'] : '8528.5900');
        // Ensure standard FBR HS code format: exactly 4 digits, dot, 4 digits (e.g. 8528.5900)
        $digitsOnly = preg_replace('/[^0-9]/', '', $rawHs);
        if (strlen($digitsOnly) >= 6) {
            $cleanHs = substr($digitsOnly, 0, 4) . '.' . substr($digitsOnly, 4, 4);
        } elseif (strlen($digitsOnly) >= 4) {
            $cleanHs = substr($digitsOnly, 0, 4) . '.' . substr($digitsOnly, 4);
        } else {
            $cleanHs = '8528.5900';
        }

        $rateStr = ((int)$taxRate == $taxRate ? (int)$taxRate : number_format($taxRate, 2)) . '%';

        $fbrItems[] = [
            'hsCode' => $cleanHs,
            'productDescription' => !empty($it['description']) ? trim((string)$it['description']) : 'Sales Item',
            'rate' => $rateStr,
            'uoM' => fbr_normalize_uom($it['unit'] ?? ''),
            'quantity' => $qty,
            'totalValues' => $totalVal,
            'valueSalesExcludingST' => $valExcl,
            'fixedNotifiedValueOrRetailPrice' => 0.0,
            'salesTaxApplicable' => $taxAmt,
            'salesTaxWithheldAtSource' => 0.0,
            'extraTax' => 0.0,
            'furtherTax' => 0.0,
            'sroScheduleNo' => '',
            'fedPayable' => 0.0,
            'discount' => 0.0,
            'saleType' => 'Goods at standard rate (default)',
            'sroItemSerialNo' => '',
        ];
    }

    if (empty($fbrItems)) {
        $sub = round((float)($inv['subtotal'] ?? 0), 2);
        $tax = round((float)($inv['tax_amount'] ?? 0), 2);
        $tot = round((float)($inv['total_amount'] ?? 0), 2);
        $tr = (float)($inv['tax_rate'] ?? 18.0);
        $rateStr = ((int)$tr == $tr ? (int)$tr : number_format($tr, 2)) . '%';
        $fbrItems[] = [
            'hsCode' => '8528.5900',
            'productDescription' => !empty($inv['title']) ? (string)$inv['title'] : 'General Sales Invoice',
            'rate' => $rateStr,
            'uoM' => 'Numbers, pieces, units',
            'quantity' => 1.0,
            'totalValues' => $tot,
            'valueSalesExcludingST' => $sub,
            'fixedNotifiedValueOrRetailPrice' => 0.0,
            'salesTaxApplicable' => $tax,
            'salesTaxWithheldAtSource' => 0.0,
            'extraTax' => 0.0,
            'furtherTax' => 0.0,
            'sroScheduleNo' => '',
            'fedPayable' => 0.0,
            'discount' => 0.0,
            'saleType' => 'Goods at standard rate (default)',
            'sroItemSerialNo' => '',
        ];
    }

    return [
        'invoiceType' => 'Sale Invoice',
        'invoiceDate' => $invDate,
        'sellerNTNCNIC' => $sellerNTN,
        'sellerBusinessName' => $sellerName,
        'sellerProvince' => $sellerProvince,
        'sellerAddress' => $sellerAddress,
        'buyerNTNCNIC' => $buyerNTNCNIC,
        'buyerBusinessName' => $buyerName,
        'buyerProvince' => $buyerProvince,
        'buyerAddress' => $buyerAddress,
        'buyerRegistrationType' => $buyerType,
        'invoiceRefNo' => $invRefNo,
        'scenarioId' => $scenarioId,
        'items' => $fbrItems,
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
 * Execute HTTP POST request against FBR Digital Invoicing Gateway.
 */
function fbr_execute_http_post(string $url, array $payload, string $bearerToken, int $timeout = 25): array
{
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . trim($bearerToken),
        'Accept: application/json',
    ];

    $cacert = __DIR__ . '/cacert.pem';

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'raw' => $rawResponse !== false ? (string)$rawResponse : '',
        'curl_error' => $curlErr,
        'http_code' => $httpCode,
    ];
}

/**
 * Pre-validate invoice data against FBR validate endpoint:
 * https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb
 */
function validate_invoice_with_fbr(PDO $db, int $invoiceId): array
{
    ensure_fbr_schema($db);

    $stmt = $db->prepare('
        SELECT i.*,
               c.name AS client_name, c.company AS client_company, c.ntn AS client_ntn,
               c.strn AS client_strn, c.phone AS client_phone, c.mobile AS client_mobile,
               c.address AS client_address, c.city AS client_city, c.province AS client_province
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.id = :id
    ');
    $stmt->execute(['id' => $invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        return ['success' => false, 'error' => 'Invoice not found'];
    }

    $itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
    $itStmt->execute(['id' => $invoiceId]);
    $items = $itStmt->fetchAll(PDO::FETCH_ASSOC);

    $companyId = !empty($inv['company_id']) ? (int)$inv['company_id'] : null;
    $config = fbr_get_config($db, $companyId);

    $company = [];
    if ($companyId) {
        $coStmt = $db->prepare('SELECT * FROM companies WHERE id = :id');
        $coStmt->execute(['id' => $companyId]);
        $company = $coStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $payload = fbr_build_digital_invoicing_payload($inv, $items, $config, $company);
    $valUrl = $config['validate_url'] ?: FBR_DEFAULT_SANDBOX_VALIDATE_URL;

    $resp = fbr_execute_http_post($valUrl, $payload, $config['bearer_token']);

    if ($resp['raw'] === '' || $resp['curl_error'] !== '') {
        return [
            'success' => false,
            'error' => "Connection to FBR validation gateway failed: {$resp['curl_error']}",
            'http_code' => $resp['http_code'],
        ];
    }

    $json = json_decode($resp['raw'], true);
    if (!is_array($json)) {
        return [
            'success' => false,
            'error' => "Unexpected non-JSON response from FBR: {$resp['raw']}",
            'http_code' => $resp['http_code'],
        ];
    }

    $val = $json['validationResponse'] ?? [];
    $statusCode = (string)($val['statusCode'] ?? '');
    $status = (string)($val['status'] ?? '');
    $error = (string)($val['error'] ?? '');

    if ($statusCode === '00' || $status === 'Valid') {
        return [
            'success' => true,
            'status' => 'Valid',
            'statusCode' => '00',
            'message' => 'Invoice data passed all FBR validation checks successfully! Ready for submission.',
            'raw' => $json,
        ];
    }

    // Collect line item errors if any
    $itemErrors = [];
    if (!empty($val['invoiceStatuses']) && is_array($val['invoiceStatuses'])) {
        foreach ($val['invoiceStatuses'] as $st) {
            if (!empty($st['error'])) {
                $itemErrors[] = "Item #{$st['itemSNo']}: {$st['error']}";
            }
        }
    }

    $fullError = $error !== '' ? $error : ($val['status'] ?? 'Invalid invoice structure');
    if (!empty($itemErrors)) {
        $fullError .= ' (' . implode('; ', $itemErrors) . ')';
    }

    return [
        'success' => false,
        'status' => $status ?: 'Invalid',
        'statusCode' => $statusCode,
        'errorCode' => $val['errorCode'] ?? null,
        'error' => $fullError,
        'raw' => $json,
    ];
}

/**
 * Post approved invoice to FBR Pakistan Digital Invoicing Portal:
 * https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb
 */
function post_invoice_to_fbr(PDO $db, int $invoiceId, ?int $userId = null, bool $force = false): array
{
    ensure_fbr_schema($db);

    // 1. Fetch invoice and client details
    $stmt = $db->prepare('
        SELECT i.*,
               c.name AS client_name, c.company AS client_company, c.ntn AS client_ntn,
               c.strn AS client_strn, c.phone AS client_phone, c.mobile AS client_mobile,
               c.address AS client_address, c.city AS client_city, c.province AS client_province
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

    $company = [];
    if ($companyId) {
        $coStmt = $db->prepare('SELECT * FROM companies WHERE id = :id');
        $coStmt->execute(['id' => $companyId]);
        $company = $coStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $payload = fbr_build_digital_invoicing_payload($inv, $items, $config, $company);
    $postUrl = $config['api_url'] ?: FBR_DEFAULT_SANDBOX_POST_URL;

    // 4. Handle Simulation Mode if explicitly enabled
    if ($config['mode'] === 'simulation') {
        $dateStr = date('Ymd');
        $simulatedFbrNo = '4130643' . $dateStr . 'SIM' . str_pad((string)$invoiceId, 6, '0', STR_PAD_LEFT);
        $qrData = fbr_build_verification_url($db, $simulatedFbrNo, $companyId);
        $simResponse = json_encode([
            'invoiceNumber' => $simulatedFbrNo,
            'dated' => date('Y-m-d H:i:s'),
            'sourceInvoiceNo' => $inv['invoice_no'],
            'validationResponse' => ['statusCode' => '00', 'status' => 'Valid', 'error' => ''],
            'environment' => 'sandbox_simulation',
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
            'code' => '00',
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
            'fbr_verify_url' => $qrData,
            'mode' => 'sandbox_simulation',
            'message' => "Successfully posted to FBR Pakistan Portal (Sandbox Simulation). Official FBR Invoice #: {$simulatedFbrNo}",
        ];
    }

    // 5. Live Request to FBR Digital Invoicing Gateway
    $resp = fbr_execute_http_post($postUrl, $payload, $config['bearer_token']);

    if ($resp['raw'] === '' || $resp['curl_error'] !== '') {
        $errorMsg = "Failed to connect to FBR gateway: {$resp['curl_error']}";
        $upd = $db->prepare('
            UPDATE invoices SET
                fbr_status = :status,
                fbr_response = :resp,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $upd->execute([
            'status' => 'failed',
            'resp' => json_encode(['error' => $errorMsg, 'http_code' => $resp['http_code'], 'timestamp' => date('Y-m-d H:i:s')]),
            'id' => $invoiceId,
        ]);

        return [
            'success' => false,
            'fbr_status' => 'failed',
            'error' => $errorMsg,
            'suggestion' => 'Check internet connectivity, verify FBR API endpoint URL and Token.',
        ];
    }

    $json = json_decode($resp['raw'], true);
    $fbrInvoiceNo = null;
    $isSuccess = false;
    $resCode = '01';

    if (is_array($json)) {
        $fbrInvoiceNo = !empty($json['invoiceNumber']) ? (string)$json['invoiceNumber'] : (!empty($json['InvoiceNumber']) ? (string)$json['InvoiceNumber'] : null);
        $val = $json['validationResponse'] ?? [];
        $resCode = (string)($val['statusCode'] ?? ($json['Code'] ?? '01'));
        $valStatus = (string)($val['status'] ?? '');

        if (!empty($fbrInvoiceNo) && ($resCode === '00' || $valStatus === 'Valid' || $resCode === '100')) {
            $isSuccess = true;
        }
    }

    if ($isSuccess && !empty($fbrInvoiceNo)) {
        $qrData = fbr_build_verification_url($db, $fbrInvoiceNo, $companyId);
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
            'code' => $resCode,
            'resp' => $resp['raw'],
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
            'fbr_verify_url' => $qrData,
            'mode' => 'live',
            'message' => "Invoice successfully posted to FBR Pakistan Portal. Official FBR Invoice #: {$fbrInvoiceNo}",
        ];
    }

    // FBR Error response
    $val = is_array($json) ? ($json['validationResponse'] ?? []) : [];
    $itemErrors = [];
    if (!empty($val['invoiceStatuses']) && is_array($val['invoiceStatuses'])) {
        foreach ($val['invoiceStatuses'] as $st) {
            if (!empty($st['error'])) {
                $itemErrors[] = "Item #{$st['itemSNo']}: {$st['error']}";
            }
        }
    }
    $errorDesc = !empty($val['error']) ? $val['error'] : (!empty($val['status']) ? $val['status'] : ($json['Response'] ?? $json['response'] ?? $json['Message'] ?? $resp['raw']));
    if (!empty($itemErrors)) {
        $errorDesc .= ' (' . implode('; ', $itemErrors) . ')';
    }
    $errCode = !empty($val['errorCode']) ? $val['errorCode'] : $resCode;

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
        'code' => (string)$errCode,
        'resp' => $resp['raw'],
        'id' => $invoiceId,
    ]);

    return [
        'success' => false,
        'fbr_status' => 'failed',
        'error' => "FBR rejected invoice submission (Code {$errCode}): {$errorDesc}",
        'raw_response' => $resp['raw'],
    ];
}
