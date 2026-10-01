<?php

declare(strict_types=1);

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
    $normalized = strtr($data, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding !== 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($normalized, true) ?: '';
}

function signJwt(string $headerJson, string $payloadJson, string $secretKey, string $algorithm = 'HS256'): string
{
    $header = base64UrlEncode($headerJson);
    $payload = base64UrlEncode($payloadJson);
    $signatureInput = $header . '.' . $payload;

    switch (strtoupper($algorithm)) {
        case 'HS256':
            $signature = hash_hmac('sha256', $signatureInput, $secretKey, true);
            break;
        case 'HS384':
            $signature = hash_hmac('sha384', $signatureInput, $secretKey, true);
            break;
        case 'HS512':
            $signature = hash_hmac('sha512', $signatureInput, $secretKey, true);
            break;
        default:
            throw new InvalidArgumentException('Unsupported JWT algorithm: ' . $algorithm);
    }

    return $signatureInput . '.' . base64UrlEncode($signature);
}

function verifyJwt(string $token, string $secretKey, string $algorithm = 'HS256'): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $expectedSignatureInput = $headerPart . '.' . $payloadPart;

    switch (strtoupper($algorithm)) {
        case 'HS256':
            $expectedSignature = hash_hmac('sha256', $expectedSignatureInput, $secretKey, true);
            break;
        case 'HS384':
            $expectedSignature = hash_hmac('sha384', $expectedSignatureInput, $secretKey, true);
            break;
        case 'HS512':
            $expectedSignature = hash_hmac('sha512', $expectedSignatureInput, $secretKey, true);
            break;
        default:
            return null;
    }

    $actualSignature = base64UrlDecode($signaturePart);
    if (!hash_equals(bin2hex($expectedSignature), bin2hex($actualSignature))) {
        return null;
    }

    $header = json_decode(base64UrlDecode($headerPart), true);
    $payload = json_decode(base64UrlDecode($payloadPart), true);

    if (!is_array($payload) || !is_array($header)) {
        return null;
    }

    if (($header['typ'] ?? 'JWT') !== 'JWT' || strtoupper((string) ($header['alg'] ?? '')) !== strtoupper($algorithm)) {
        return null;
    }
    if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
        return null;
    }

    return $payload;
}

function verifyPassword(string $plainPassword, string $hashedPassword): bool
{
    return password_verify($plainPassword, $hashedPassword);
}

function getPasswordHash(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function createAccessToken(array $data, string $secretKey, int $expireMinutes = 60, string $algorithm = 'HS256'): string
{
    $toEncode = $data;
    $toEncode['exp'] = time() + ($expireMinutes * 60);

    return signJwt(
        json_encode(['alg' => $algorithm, 'typ' => 'JWT']),
        json_encode($toEncode),
        $secretKey,
        $algorithm
    );
}

function authenticateUser($db, string $username, string $password): ?object
{
    $user = null;

    if (is_object($db) && method_exists($db, 'query')) {
        $sql = 'SELECT * FROM users WHERE username = :username LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_OBJ);
    } elseif (is_array($db) && isset($db['users'])) {
        foreach ($db['users'] as $candidate) {
            if (($candidate['username'] ?? null) === $username) {
                $user = (object) $candidate;
                break;
            }
        }
    }

    if (!$user) {
        return null;
    }

    $hashedPassword = $user->hashed_password ?? $user->password ?? '';
    if (!verifyPassword($password, $hashedPassword)) {
        return null;
    }

    return $user;
}

function getCurrentUser($db, string $token, string $secretKey, string $algorithm = 'HS256')
{
    $payload = verifyJwt($token, $secretKey, $algorithm);
    if (!$payload || empty($payload['sub'])) {
        return null;
    }

    $username = $payload['sub'];

    if (is_object($db) && method_exists($db, 'query')) {
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_OBJ);
        return $user ?: null;
    }

    if (is_array($db) && isset($db['users'])) {
        foreach ($db['users'] as $candidate) {
            if (($candidate['username'] ?? null) === $username) {
                return (object) $candidate;
            }
        }
    }

    return null;
}

function requireRole(array $roles, callable $currentUserResolver, callable $onForbidden = null): callable
{
    return function () use ($roles, $currentUserResolver, $onForbidden) {
        $user = $currentUserResolver();
        if (!$user) {
            throw new RuntimeException('Could not validate credentials');
        }

        $userRole = $user->role ?? null;
        if (!in_array($userRole, $roles, true)) {
            if (is_callable($onForbidden)) {
                $onForbidden();
            }
            throw new RuntimeException('Not enough permissions');
        }

        return $user;
    };
}

function requirePermission(string $module, string $action, callable $currentUserResolver, callable $onForbidden = null): callable
{
    return function () use ($module, $action, $currentUserResolver, $onForbidden) {
        $user = $currentUserResolver();
        if (!$user) {
            throw new RuntimeException('Could not validate credentials');
        }

        $role = strtolower((string) ($user->role ?? ''));
        if ($role === 'admin') {
            return $user;
        }

        $permissions = $user->permissions ?? [];
        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true) ?: [];
        }
        if (isset($permissions['permissions']) && is_array($permissions['permissions'])) {
            $permissions = $permissions['permissions'];
        }

        if (empty($permissions[$module][$action])) {
            if (is_callable($onForbidden)) {
                $onForbidden();
            }
            throw new RuntimeException('Permission denied: ' . $module . '.' . $action);
        }

        return $user;
    };
}
