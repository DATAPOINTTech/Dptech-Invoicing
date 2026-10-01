<?php

declare(strict_types=1);

function handle_products_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/products') {
        require_authenticated_user($db);
        $stmt = $db->query('SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON i.product_id = p.id WHERE p.is_active = 1 ORDER BY p.created_at DESC, p.id DESC');
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/products') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'products', 'create');
        $body = request_json();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['detail' => 'name is required'], 422);
        }

        $fields = ['name', 'description', 'category', 'sku', 'unit_price', 'cost_price', 'unit', 'tax_rate', 'tax_inclusive', 'hs_code', 'is_active', 'min_stock_level', 'max_stock_level'];
        $values = ['name' => $name];
        foreach (array_slice($fields, 1) as $f) {
            if (array_key_exists($f, $body)) {
                $values[$f] = in_array($f, ['tax_inclusive', 'is_active'], true) ? (int) (bool) $body[$f] : $body[$f];
            }
        }
        $cols = array_keys($values);
        $stmt = $db->prepare('INSERT INTO products (' . implode(',', $cols) . ', created_at, updated_at) VALUES (:' . implode(',:', $cols) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $stmt->execute($values);
        $productId = (int) $db->lastInsertId();

        // Create inventory row
        get_or_create_inventory($db, $productId);

        json_response(['id' => $productId, 'message' => 'Product created'], 201);
    }

    if (preg_match('#^/api/products/([1-9][0-9]*)$#', $path, $match)) {
        $productId = (int) $match[1];
        if ($method === 'GET') {
            require_authenticated_user($db);
            $stmt = $db->prepare('SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON i.product_id = p.id WHERE p.id = :id');
            $stmt->execute(['id' => $productId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                json_response(['detail' => 'Product not found'], 404);
            }
            json_response($p);
        }

        $user = require_authenticated_user($db);
        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'products', 'delete');
            $stmt = $db->prepare('UPDATE products SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['id' => $productId]);
            json_response(['message' => 'Product deactivated']);
        }

        if ($method === 'PUT') {
            require_permission_for($db, $user, 'products', 'edit');
            $body = request_json();
            $fields = ['name', 'description', 'category', 'sku', 'unit_price', 'cost_price', 'unit', 'tax_rate', 'tax_inclusive', 'hs_code', 'is_active', 'min_stock_level', 'max_stock_level'];
            $updates = [];
            $values = ['id' => $productId];
            foreach ($fields as $f) {
                if (array_key_exists($f, $body)) {
                    $updates[] = "{$f} = :{$f}";
                    $values[$f] = in_array($f, ['tax_inclusive', 'is_active'], true) ? (int) (bool) $body[$f] : $body[$f];
                }
            }
            if ($updates === []) {
                json_response(['detail' => 'No fields to update'], 422);
            }
            $updates[] = 'updated_at = CURRENT_TIMESTAMP';
            $stmt = $db->prepare('UPDATE products SET ' . implode(', ', $updates) . ' WHERE id = :id');
            $stmt->execute($values);

            $stmt = $db->prepare('SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON i.product_id = p.id WHERE p.id = :id');
            $stmt->execute(['id' => $productId]);
            json_response($stmt->fetch(PDO::FETCH_ASSOC));
        }
    }

    return false;
}
