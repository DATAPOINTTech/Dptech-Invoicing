<?php

declare(strict_types=1);

function handle_auth_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'POST' && $path === '/api/auth/login') {
        $payload = request_data();
        $username = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        if ($username === '' || $password === '') {
            json_response(['detail' => 'username and password are required.'], 422);
        }

        $user = authenticateUser($db, $username, $password);
        if ($user === null) {
            json_response(['detail' => 'Incorrect username or password.'], 401);
        }
        if (isset($user->is_active) && !$user->is_active) {
            json_response(['detail' => 'Account is deactivated'], 401);
        }

        $secretKey = (string) env_value('SECRET_KEY', 'datapoint-secret-key-change-in-production');
        $token = createAccessToken(
            ['sub' => $username, 'role' => strtolower((string) ($user->role ?? 'staff'))],
            $secretKey,
            (int) env_value('ACCESS_TOKEN_EXPIRE_MINUTES', '1440'),
            (string) env_value('ALGORITHM', 'HS256')
        );

        setcookie('token', $token, [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax'
        ]);
        $_COOKIE['token'] = $token;

        json_response([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => [
                'id' => (int) ($user->id ?? 0),
                'username' => $user->username ?? $username,
                'name' => $user->full_name ?? $username,
                'email' => $user->email ?? '',
                'role' => strtolower((string) ($user->role ?? 'staff')),
                'permissions' => is_string($user->permissions ?? null) ? json_decode($user->permissions, true) : ($user->permissions ?? []),
            ],
        ]);
    }

    // User creation (Admin only)
    if ($method === 'POST' && ($path === '/api/auth/register' || $path === '/api/users')) {
        $currentUser = require_authenticated_user($db);
        if (strtolower((string) ($currentUser->role ?? '')) !== 'admin') {
            json_response(['detail' => 'Only administrators can create users and assign permissions.'], 403);
        }

        $body = request_json();
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $fullName = trim((string) ($body['full_name'] ?? ''));
        $phone = isset($body['phone']) ? trim((string) $body['phone']) : null;
        $role = strtolower((string) ($body['role'] ?? 'staff'));
        $permissions = $body['permissions'] ?? [];
        if (is_array($permissions) && isset($permissions['permissions'])) {
            $permissions = $permissions['permissions'];
        }
        $permsJson = json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($username === '' || $email === '' || $password === '' || $fullName === '') {
            json_response(['detail' => 'Username, email, password, and full name are required.'], 422);
        }

        $stmt = $db->prepare('SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1');
        $stmt->execute(['u' => $username, 'e' => $email]);
        if ($stmt->fetch()) {
            json_response(['detail' => 'Username or email already exists'], 400);
        }

        $hash = getPasswordHash($password);
        $insert = $db->prepare('
            INSERT INTO users (username, email, hashed_password, full_name, phone, role, is_active, permissions, created_at, updated_at) 
            VALUES (:u, :e, :h, :fn, :ph, :r, 1, :perms, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $insert->execute([
            'u' => $username,
            'e' => $email,
            'h' => $hash,
            'fn' => $fullName,
            'ph' => $phone,
            'r' => $role,
            'perms' => $permsJson,
        ]);

        json_response(['message' => 'User created successfully', 'id' => (int) $db->lastInsertId()], 201);
    }

    if ($method === 'GET' && $path === '/api/users') {
        $currentUser = require_authenticated_user($db);
        if (strtolower((string) ($currentUser->role ?? '')) !== 'admin') {
            json_response(['detail' => 'Only administrators can access user management.'], 403);
        }

        $stmt = $db->query('SELECT id, username, email, full_name, phone, role, is_active, permissions, created_at, updated_at FROM users ORDER BY id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $raw = $row['permissions'];
            $parsed = !empty($raw) && is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
            if (isset($parsed['permissions']) && is_array($parsed['permissions'])) {
                $parsed = $parsed['permissions'];
            }
            $row['permissions'] = $parsed;
        }
        json_response($rows);
    }

    if (preg_match('#^/api/users/([1-9][0-9]*)(/.*)?$#', $path, $matches)) {
        $currentUser = require_authenticated_user($db);
        if (strtolower((string) ($currentUser->role ?? '')) !== 'admin') {
            json_response(['detail' => 'Only administrators can manage users and permissions.'], 403);
        }

        $userId = (int) $matches[1];
        $sub = $matches[2] ?? '';

        if ($sub === '' && $method === 'GET') {
            $stmt = $db->prepare('SELECT id, username, email, full_name, phone, role, is_active, permissions, created_at, updated_at FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['detail' => 'User not found'], 404);
            }
            $raw = $u['permissions'];
            $parsed = !empty($raw) && is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
            if (isset($parsed['permissions']) && is_array($parsed['permissions'])) {
                $parsed = $parsed['permissions'];
            }
            $u['permissions'] = $parsed;
            json_response($u);
        }

        if ($sub === '' && $method === 'PUT') {
            $body = request_json();
            $allowed = ['full_name', 'email', 'phone', 'role', 'is_active'];
            $updates = [];
            $params = ['id' => $userId];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) {
                    $updates[] = "{$field} = :{$field}";
                    $params[$field] = $field === 'is_active' ? (int) (bool) $body[$field] : $body[$field];
                }
            }
            if (!empty($body['password'])) {
                $updates[] = 'hashed_password = :hp';
                $params['hp'] = getPasswordHash((string) $body['password']);
            }
            if (isset($body['permissions'])) {
                $perms = $body['permissions'];
                if (is_array($perms) && isset($perms['permissions'])) {
                    $perms = $perms['permissions'];
                }
                $updates[] = 'permissions = :p';
                $params['p'] = json_encode($perms, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($updates !== []) {
                $updates[] = 'updated_at = CURRENT_TIMESTAMP';
                $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id');
                $stmt->execute($params);
            }
            json_response(['message' => 'User updated successfully']);
        }

        if ($sub === '' && $method === 'DELETE') {
            if ((int) $currentUser->id === $userId) {
                json_response(['detail' => 'You cannot delete your own admin account.'], 400);
            }
            $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            json_response(['message' => 'User deleted successfully']);
        }

        if ($sub === '/permissions' && $method === 'GET') {
            $stmt = $db->prepare('SELECT permissions FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $perms = $stmt->fetchColumn();
            $parsed = !empty($perms) && is_string($perms) ? json_decode($perms, true) : (is_array($perms) ? $perms : []);
            if (isset($parsed['permissions']) && is_array($parsed['permissions'])) {
                $parsed = $parsed['permissions'];
            }
            json_response(['permissions' => $parsed]);
        }

        if ($sub === '/permissions' && $method === 'PUT') {
            $body = request_json();
            $perms = $body['permissions'] ?? $body;
            if (is_array($perms) && isset($perms['permissions'])) {
                $perms = $perms['permissions'];
            }
            $permsJson = json_encode($perms, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare('UPDATE users SET permissions = :p, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['p' => $permsJson, 'id' => $userId]);
            json_response(['message' => 'Granular permissions updated successfully', 'permissions' => $perms]);
        }

        if ($sub === '/status' && $method === 'PUT') {
            $body = request_json();
            $isActive = isset($body['is_active']) ? (int) (bool) $body['is_active'] : 1;
            if ((int) $currentUser->id === $userId && !$isActive) {
                json_response(['detail' => 'You cannot deactivate your own admin account.'], 400);
            }
            $stmt = $db->prepare('UPDATE users SET is_active = :a, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['a' => $isActive, 'id' => $userId]);
            json_response(['message' => 'Status updated successfully']);
        }
    }

    return false;
}
