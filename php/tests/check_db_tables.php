<?php
require __DIR__ . '/../bootstrap.php';
$db = database_connection();
ensure_all_schema_tables($db);
echo "ensure_all_schema_tables called successfully.\n";

$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
echo "Current tables and row counts:\n";
foreach ($tables as $t) {
    $count = $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    echo sprintf("  %-20s: %d\n", $t, (int) $count);
}
