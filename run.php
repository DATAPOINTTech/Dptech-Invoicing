<?php

declare(strict_types=1);

/**
 * DATAPOINT Invoicing System - PHP Runner
 * Starts the application web server locally.
 */

$port = getenv('PORT') ?: '8000';
$host = getenv('HOST') ?: '0.0.0.0';
$publicDir = __DIR__ . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'public';
$routerScript = $publicDir . DIRECTORY_SEPARATOR . 'index.php';

echo "========================================================\n";
echo "  DATAPOINT Business Management & Invoicing System (PHP)\n";
echo "========================================================\n";
echo "Server starting at http://{$host}:{$port}\n";
echo "Document root: {$publicDir}\n";
echo "Press Ctrl+C to stop.\n\n";

passthru(sprintf('php -S %s:%s -t %s %s', escapeshellarg($host), escapeshellarg((string) $port), escapeshellarg($publicDir), escapeshellarg($routerScript)));
