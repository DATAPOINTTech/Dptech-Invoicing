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

// Ensure WhatsApp Baileys service is active
$waPort = 3001;
$waConn = @fsockopen('127.0.0.1', $waPort, $errNo, $errStr, 0.5);
if (is_resource($waConn)) {
    fclose($waConn);
    echo "WhatsApp Baileys Service: Active on port {$waPort}\n";
} else {
    $waScript = __DIR__ . DIRECTORY_SEPARATOR . 'whatsapp-service' . DIRECTORY_SEPARATOR . 'server.js';
    if (is_file($waScript)) {
        $waLog = dirname($waScript) . DIRECTORY_SEPARATOR . 'whatsapp.log';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B "" node "' . $waScript . '" > "' . $waLog . '" 2>&1', 'r'));
        } else {
            exec('node "' . $waScript . '" > "' . $waLog . '" 2>&1 &');
        }
    }
}

echo "Press Ctrl+C to stop.\n\n";

passthru(sprintf('php -S %s:%s -t %s %s', escapeshellarg($host), escapeshellarg((string) $port), escapeshellarg($publicDir), escapeshellarg($routerScript)));
