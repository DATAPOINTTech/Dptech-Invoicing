<?php
require_once __DIR__ . '/../bootstrap.php';
$db = database_connection();
echo "--- PRODUCTS COUNT ---\n";
echo $db->query("SELECT COUNT(*) FROM products")->fetchColumn() . "\n";
echo "--- PRICE LIST COUNT ---\n";
echo $db->query("SELECT COUNT(*) FROM price_list")->fetchColumn() . "\n";
echo "--- SAMPLE PRODUCTS ---\n";
print_r($db->query("SELECT id, name, category, unit_price FROM products LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));
echo "--- SAMPLE PRICE LIST ---\n";
print_r($db->query("SELECT id, name, category, unit_price FROM price_list LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));

