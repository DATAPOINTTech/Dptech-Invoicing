<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/qrcode_service.php';

$sampleFbrInvoice = '10000120261027110001';
$sampleVerificationUrl = 'https://e.fbr.gov.pk/esr/InvoiceVerification.aspx?InvoiceNo=4130643057439DIQ1FM16059066';
$matrix = PurePhpQr::encode($sampleFbrInvoice);
echo "Matrix size: " . count($matrix) . "x" . count($matrix[0]) . PHP_EOL;

$matrixUrl = PurePhpQr::encode($sampleVerificationUrl);
echo "URL Matrix size: " . count($matrixUrl) . "x" . count($matrixUrl[0]) . PHP_EOL;

$svg = PurePhpQr::toSvg($sampleVerificationUrl);
echo "SVG generated: " . strlen($svg) . " bytes" . PHP_EOL;

$uri = PurePhpQr::toDataUri($sampleVerificationUrl);
echo "Data URI generated: " . substr($uri, 0, 40) . "..." . PHP_EOL;

echo "SUCCESS!\n";

