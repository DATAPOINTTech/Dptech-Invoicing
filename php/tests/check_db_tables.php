<?php
require __DIR__ . '/../bootstrap.php';
$db = database_connection();
ensure_all_schema_tables($db);
echo "ensure_all_schema_tables called successfully.\n";

$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
echo "Current tables:\n";
foreach ($tables as $t) {
    echo "- " . $t . "\n";
}
