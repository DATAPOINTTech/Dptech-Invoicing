<?php

declare(strict_types=1);

function handle_suppliers_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/suppliers') {
        require_authenticated_user($db);
        $stmt = $db->query('SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name ASC, id DESC');
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/suppliers') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'suppliers', 'create');
        $body = request_json();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['detail' => 'name is required'], 422);
        }

        $columns = ['name', 'contact_person', 'email', 'phone', 'mobile', 'address', 'city', 'ntn', 'strn', 'is_active'];
        $values = ['name' => $name];
        foreach (array_slice($columns, 1) as $col) {
            if (array_key_exists($col, $body)) {
                $values[$col] = $col === 'is_active' ? (int) (bool) $body[$col] : $body[$col];
            }
        }
        $fields = array_keys($values);
        $stmt = $db->prepare('INSERT INTO suppliers (' . implode(',', $fields) . ', created_at, updated_at) VALUES (:' . implode(',:', $fields) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $stmt->execute($values);
        json_response(['id' => (int) $db->lastInsertId(), 'message' => 'Supplier created'], 201);
    }

    if (preg_match('#^/api/suppliers/([1-9][0-9]*)$#', $path, $match)) {
        $supplierId = (int) $match[1];
        if ($method === 'GET') {
            require_authenticated_user($db);
            $stmt = $db->prepare('SELECT * FROM suppliers WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $supplierId]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$supplier) {
                json_response(['detail' => 'Supplier not found'], 404);
            }
            json_response($supplier);
        }

        $user = require_authenticated_user($db);
        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'suppliers', 'delete');
            $stmt = $db->prepare('UPDATE suppliers SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['id' => $supplierId]);
            json_response(['message' => 'Supplier deactivated']);
        }

        if ($method === 'PUT') {
            require_permission_for($db, $user, 'suppliers', 'edit');
            $body = request_json();
            $allowed = ['name', 'contact_person', 'email', 'phone', 'mobile', 'address', 'city', 'ntn', 'strn', 'is_active'];
            $updates = [];
            $values = ['id' => $supplierId];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $body)) {
                    $updates[] = "{$col} = :{$col}";
                    $values[$col] = $col === 'is_active' ? (int) (bool) $body[$col] : $body[$col];
                }
            }
            if ($updates === []) {
                json_response(['detail' => 'No fields to update'], 422);
            }
            $updates[] = 'updated_at = CURRENT_TIMESTAMP';
            $stmt = $db->prepare('UPDATE suppliers SET ' . implode(', ', $updates) . ' WHERE id = :id');
            $stmt->execute($values);

            $stmt = $db->prepare('SELECT * FROM suppliers WHERE id = :id');
            $stmt->execute(['id' => $supplierId]);
            json_response($stmt->fetch(PDO::FETCH_ASSOC));
        }
    }

    return false;
}
