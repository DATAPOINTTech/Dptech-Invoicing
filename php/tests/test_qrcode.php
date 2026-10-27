<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/qrcode_service.php';

$sampleFbrInvoice = '10000120261027110001';
$matrix = PurePhpQr::encode($sampleFbrInvoice);
echo "Matrix size: " . count($matrix) . "x" . count($matrix[0]) . PHP_EOL;

$svg = PurePhpQr::toSvg($sampleFbrInvoice);
echo "SVG generated: " . strlen($svg) . " bytes" . PHP_EOL;

$uri = PurePhpQr::toDataUri($sampleFbrInvoice);
echo "Data URI generated: " . substr($uri, 0, 40) . "..." . PHP_EOL;

echo "SUCCESS!\n";
