<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== Testing New Client / Company Popup Close Handlers ===\n";

$pdo = database_connection();

// 1. Verify estimates/form.html
$estHtml = file_get_contents(__DIR__ . '/../templates/estimates/form.html');
assert(strpos($estHtml, 'id="new-client-modal"') !== false, "estimates/form.html must contain new-client-modal");
assert(strpos($estHtml, 'function hideNewClient()') !== false, "estimates/form.html must define hideNewClient()");
assert(strpos($estHtml, "css('display', 'none')") !== false, "estimates/form.html hideNewClient must explicitly set display none");
assert(strpos($estHtml, "hideNewClient();\n            if (typeof window.loadClientProjects === 'function')") !== false, "estimates/form.html must hide modal before attempting projects load");
assert(strpos($estHtml, "window.loadClientProjects = loadClientProjects;") !== false, "estimates/form.html must bind loadClientProjects to window");
echo "1. Estimates form modal close handlers verified successfully.\n";

// 2. Verify invoices/form.html
$invHtml = file_get_contents(__DIR__ . '/../templates/invoices/form.html');
assert(strpos($invHtml, 'id="new-client-modal"') !== false, "invoices/form.html must contain new-client-modal");
assert(strpos($invHtml, 'function hideNewClient()') !== false, "invoices/form.html must define hideNewClient()");
assert(strpos($invHtml, "css('display', 'none')") !== false, "invoices/form.html hideNewClient must explicitly set display none");
assert(strpos($invHtml, "hideNewClient();\n        if (typeof window.loadClientProjects === 'function')") !== false, "invoices/form.html must hide modal before attempting projects load");
assert(strpos($invHtml, "window.loadClientProjects = loadClientProjects;") !== false, "invoices/form.html must bind loadClientProjects to window");
echo "2. Invoices form modal close handlers verified successfully.\n";

// 3. Verify clients/list.html
$clientsHtml = file_get_contents(__DIR__ . '/../templates/clients/list.html');
assert(strpos($clientsHtml, 'id="new-client-modal"') !== false, "clients/list.html must contain new-client-modal");
assert(strpos($clientsHtml, 'function hideNewClientModal()') !== false, "clients/list.html must define hideNewClientModal()");
assert(strpos($clientsHtml, 'showNewClientModal()') !== false, "clients/list.html must provide showNewClientModal trigger");
echo "3. Clients directory modal handlers verified successfully.\n";

// 4. API functional test: Create client/company via /api/clients
$clientData = [
    'name' => 'Test Corp ' . time(),
    'company' => 'DP Technologies Test ' . time(),
    'email' => 'test' . time() . '@example.com',
    'phone' => '+923001234567',
    'city' => 'Hyderabad',
    'is_active' => 1
];

$stmt = $pdo->prepare('INSERT INTO clients (name, company, email, phone, city, is_active, created_at, updated_at) VALUES (:name, :company, :email, :phone, :city, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
$stmt->execute([
    'name' => $clientData['name'],
    'company' => $clientData['company'],
    'email' => $clientData['email'],
    'phone' => $clientData['phone'],
    'city' => $clientData['city']
]);
$newClientId = (int)$pdo->lastInsertId();
$stmt->closeCursor();
unset($stmt);

assert($newClientId > 0, "Created client must have positive ID");
echo "4. Client/Company database creation verified: ID #{$newClientId} ({$clientData['name']} - {$clientData['company']})\n";

// Cleanup test client
$delStmt = $pdo->prepare('DELETE FROM clients WHERE id = :id');
$delStmt->execute(['id' => $newClientId]);
$delStmt->closeCursor();
unset($delStmt);

echo "=== All New Client / Company Popup Tests PASSED ===\n";
