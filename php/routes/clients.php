<?php

declare(strict_types=1);

function handle_clients_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/clients') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'clients', 'view');
        $stmt = $db->query('SELECT * FROM clients WHERE is_active = 1 ORDER BY name ASC, id DESC');
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/clients') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'clients', 'create');
        $body = request_json();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['detail' => 'name is required'], 422);
        }

        $columns = ['name', 'company', 'email', 'phone', 'mobile', 'address', 'city', 'province', 'ntn', 'strn', 'notes', 'is_active'];
        $values = ['name' => $name];
        foreach (array_slice($columns, 1) as $col) {
            if (array_key_exists($col, $body)) {
                $values[$col] = $col === 'is_active' ? (int) (bool) $body[$col] : $body[$col];
            }
        }
        $fields = array_keys($values);
        $stmt = $db->prepare('INSERT INTO clients (' . implode(',', $fields) . ', created_at, updated_at) VALUES (:' . implode(',:', $fields) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $stmt->execute($values);
        json_response(['id' => (int) $db->lastInsertId(), 'message' => 'Client created'], 201);
    }

    if (preg_match('#^/api/clients/([1-9][0-9]*)$#', $path, $match)) {
        $clientId = (int) $match[1];
        if ($method === 'GET') {
            $user = require_authenticated_user($db);
            require_permission_for($db, $user, 'clients', 'view');
            $stmt = $db->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                json_response(['detail' => 'Client not found'], 404);
            }
            json_response($client);
        }

        $user = require_authenticated_user($db);
        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'clients', 'delete');
            $stmt = $db->prepare('UPDATE clients SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['id' => $clientId]);
            json_response(['message' => 'Client deactivated']);
        }

        if ($method === 'PUT') {
            require_permission_for($db, $user, 'clients', 'edit');
            $body = request_json();
            $allowed = ['name', 'company', 'email', 'phone', 'mobile', 'address', 'city', 'province', 'ntn', 'strn', 'notes', 'is_active'];
            $updates = [];
            $values = ['id' => $clientId];
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
            $stmt = $db->prepare('UPDATE clients SET ' . implode(', ', $updates) . ' WHERE id = :id');
            $stmt->execute($values);

            $stmt = $db->prepare('SELECT * FROM clients WHERE id = :id');
            $stmt->execute(['id' => $clientId]);
            json_response($stmt->fetch(PDO::FETCH_ASSOC));
        }
    }

    return false;
}
