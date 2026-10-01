<?php

declare(strict_types=1);

class MovementType
{
    public const SALE_OUT = 'SALE_OUT';
    public const RETURN_OUT = 'RETURN_OUT';
    public const TRANSFER = 'TRANSFER';
    public const PURCHASE_IN = 'PURCHASE_IN';
    public const ADJUSTMENT_IN = 'ADJUSTMENT_IN';
    public const ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';
}

function get_or_create_inventory($db, int $product_id): array
{
    if (is_object($db) && method_exists($db, 'query')) {
        $stmt = $db->prepare('SELECT * FROM inventory WHERE product_id = :product_id LIMIT 1');
        $stmt->execute(['product_id' => $product_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $stmt = $db->prepare('INSERT INTO inventory (product_id, quantity) VALUES (:product_id, 0)');
        $stmt->execute(['product_id' => $product_id]);
        $id = (int) $db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM inventory WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['product_id' => $product_id, 'quantity' => 0];
    }

    return ['product_id' => $product_id, 'quantity' => 0];
}

function update_stock($db, int $product_id, float $quantity, string $movement_type, ?string $reference_type = null, ?int $reference_id = null, ?string $notes = null, ?int $user_id = null): array
{
    $inv = get_or_create_inventory($db, $product_id);
    $current_quantity = (float) ($inv['quantity'] ?? 0);

    if (in_array($movement_type, [MovementType::SALE_OUT, MovementType::RETURN_OUT, MovementType::TRANSFER], true)) {
        if ($current_quantity < abs($quantity)) {
            throw new InvalidArgumentException('Insufficient stock for product ID ' . $product_id . '. Available: ' . $current_quantity . ', Requested: ' . abs($quantity));
        }
        $current_quantity -= abs($quantity);
    } else {
        $current_quantity += abs($quantity);
    }

    $inv['quantity'] = $current_quantity;
    if (is_object($db) && method_exists($db, 'prepare')) {
        $stmt = $db->prepare('UPDATE inventory SET quantity = :quantity WHERE product_id = :product_id');
        $stmt->execute(['quantity' => $current_quantity, 'product_id' => $product_id]);

        $move = $db->prepare('INSERT INTO stock_movements (product_id, quantity, movement_type, reference_type, reference_id, notes, created_by) VALUES (:product_id, :quantity, :movement_type, :reference_type, :reference_id, :notes, :created_by)');
        $move->execute([
            'product_id' => $product_id,
            'quantity' => $quantity,
            'movement_type' => $movement_type,
            'reference_type' => $reference_type,
            'reference_id' => $reference_id,
            'notes' => $notes,
            'created_by' => $user_id,
        ]);
    }

    return $inv;
}

function get_stock_level($db, int $product_id): float
{
    $inv = get_or_create_inventory($db, $product_id);
    return (float) ($inv['quantity'] ?? 0);
}

function get_low_stock_products($db): array
{
    if (is_object($db) && method_exists($db, 'query')) {
        $stmt = $db->query('SELECT p.* FROM products p INNER JOIN inventory i ON i.product_id = p.id WHERE i.quantity <= p.min_stock_level');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [];
}
