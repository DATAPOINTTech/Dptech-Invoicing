<?php

declare(strict_types=1);

function handle_inventory_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/inventory') {
        require_authenticated_user($db);
        $stmt = $db->query('
            SELECT i.*, p.name AS product_name, p.sku, p.category, p.unit, p.min_stock_level, p.max_stock_level, p.unit_price, p.cost_price
            FROM inventory i
            JOIN products p ON p.id = i.product_id
            WHERE p.is_active = 1
            ORDER BY p.name ASC
        ');
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'GET' && $path === '/api/inventory/movements') {
        require_authenticated_user($db);
        $stmt = $db->query('
            SELECT sm.*, p.name AS product_name, u.full_name AS created_by_name
            FROM stock_movements sm
            JOIN products p ON p.id = sm.product_id
            LEFT JOIN users u ON u.id = sm.created_by
            ORDER BY sm.created_at DESC, sm.id DESC
            LIMIT 200
        ');
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if (preg_match('#^/api/inventory/movements/([1-9][0-9]*)$#', $path, $m)) {
        $movementId = (int) $m[1];
        $user = require_authenticated_user($db);

        if ($method === 'PUT') {
            require_permission_for($db, $user, 'inventory', 'edit');
            $body = request_json();
            $notes = (string) ($body['notes'] ?? '');
            $stmt = $db->prepare('UPDATE stock_movements SET notes = :n WHERE id = :id');
            $stmt->execute(['n' => $notes, 'id' => $movementId]);
            json_response(['message' => 'Movement updated']);
        }

        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'inventory', 'delete');
            $stmt = $db->prepare('DELETE FROM stock_movements WHERE id = :id');
            $stmt->execute(['id' => $movementId]);
            json_response(['message' => 'Movement deleted']);
        }
    }

    if ($method === 'POST' && $path === '/api/inventory/issue') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'inventory', 'create');
        $body = request_json();

        $productId = (int) ($body['product_id'] ?? 0);
        $quantity = (float) ($body['quantity'] ?? 0);
        $type = (string) ($body['movement_type'] ?? MovementType::SALE_OUT);
        $refType = isset($body['reference_type']) ? (string) $body['reference_type'] : null;
        $refId = isset($body['reference_id']) ? (int) $body['reference_id'] : null;
        $notes = isset($body['notes']) ? (string) $body['notes'] : null;

        if ($productId <= 0 || $quantity <= 0) {
            json_response(['detail' => 'product_id and a positive quantity are required.'], 422);
        }

        try {
            $updated = update_stock($db, $productId, $quantity, $type, $refType, $refId, $notes, $user->id);
            json_response(['message' => 'Stock updated', 'current_stock' => $updated['quantity']]);
        } catch (InvalidArgumentException $e) {
            json_response(['detail' => $e->getMessage()], 400);
        }
    }

    return false;
}
