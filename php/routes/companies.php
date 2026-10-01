<?php

declare(strict_types=1);

function handle_companies_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/companies') {
        require_authenticated_user($db);
        $stmt = $db->query('
            SELECT c.*, 
                   COUNT(e.id) AS estimates_count,
                   COALESCE(SUM(e.total_amount), 0) AS total_estimates_amount
            FROM companies c
            LEFT JOIN estimates e ON e.company_id = c.id
            GROUP BY c.id
            ORDER BY c.sort_order ASC, c.id ASC
        ');
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/companies/upload-logo') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'edit');

        $uploadDir = dirname(__DIR__) . '/public/static/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $logoUrl = '';
        if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo((string) ($_FILES['logo']['name'] ?? 'logo.png'), PATHINFO_EXTENSION)) ?: 'png';
            $filename = 'logo_' . uniqid() . '.' . $ext;
            $dest = $uploadDir . DIRECTORY_SEPARATOR . $filename;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $logoUrl = '/static/uploads/' . $filename;
            }
        } else {
            $body = request_json();
            $base64 = $body['image_base64'] ?? '';
            if ($base64 !== '') {
                if (preg_match('/^data:image\/(\w+);base64,/', $base64, $m)) {
                    $ext = $m[1];
                    $base64 = substr($base64, strpos($base64, ',') + 1);
                } else {
                    $ext = 'png';
                }
                $decoded = base64_decode($base64);
                if ($decoded !== false) {
                    $filename = 'logo_' . uniqid() . '.' . $ext;
                    file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $filename, $decoded);
                    $logoUrl = '/static/uploads/' . $filename;
                }
            }
        }

        if ($logoUrl === '') {
            json_response(['detail' => 'No valid logo file or image data provided.'], 422);
        }

        json_response(['logo_url' => $logoUrl, 'message' => 'Logo uploaded successfully']);
    }

    if ($method === 'POST' && $path === '/api/companies/setup') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'edit');
        $body = request_json();
        $companies = $body['companies'] ?? [];

        if (!is_array($companies) || count($companies) === 0) {
            json_response(['detail' => 'companies list is required.'], 422);
        }

        $saved = [];
        foreach ($companies as $idx => $co) {
            $name = trim((string) ($co['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $id = !empty($co['id']) ? (int) $co['id'] : null;
            $sortOrder = $idx + 1;
            $defaultMarkup = $sortOrder === 1 ? 0.0 : ($sortOrder === 2 ? 2.0 : 3.0);
            $markup = isset($co['markup_percent']) ? (float) $co['markup_percent'] : $defaultMarkup;
            $logoUrl = (string) ($co['logo_url'] ?? '/static/img/logo.png');

            if ($id) {
                $stmt = $db->prepare('
                    UPDATE companies SET
                        name = :name,
                        code = :code,
                        logo_url = :logo,
                        email = :email,
                        phone = :phone,
                        mobile = :mobile,
                        address = :address,
                        city = :city,
                        ntn = :ntn,
                        strn = :strn,
                        terms = :terms,
                        is_default = :is_def,
                        markup_percent = :markup,
                        sort_order = :sort,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'code' => $co['code'] ?? ('CO' . $sortOrder),
                    'logo' => $logoUrl,
                    'email' => $co['email'] ?? null,
                    'phone' => $co['phone'] ?? null,
                    'mobile' => $co['mobile'] ?? null,
                    'address' => $co['address'] ?? null,
                    'city' => $co['city'] ?? 'Hyderabad',
                    'ntn' => $co['ntn'] ?? null,
                    'strn' => $co['strn'] ?? null,
                    'terms' => $co['terms'] ?? null,
                    'is_def' => $sortOrder === 1 ? 1 : 0,
                    'markup' => $markup,
                    'sort' => $sortOrder,
                ]);
                $saved[] = $id;
            } else {
                $stmt = $db->prepare('
                    INSERT INTO companies (
                        name, code, logo_url, email, phone, mobile, address, city, ntn, strn, terms, is_default, markup_percent, sort_order, created_at, updated_at
                    ) VALUES (
                        :name, :code, :logo, :email, :phone, :mobile, :address, :city, :ntn, :strn, :terms, :is_def, :markup, :sort, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ');
                $stmt->execute([
                    'name' => $name,
                    'code' => $co['code'] ?? ('CO' . $sortOrder),
                    'logo' => $logoUrl,
                    'email' => $co['email'] ?? null,
                    'phone' => $co['phone'] ?? null,
                    'mobile' => $co['mobile'] ?? null,
                    'address' => $co['address'] ?? null,
                    'city' => $co['city'] ?? 'Hyderabad',
                    'ntn' => $co['ntn'] ?? null,
                    'strn' => $co['strn'] ?? null,
                    'terms' => $co['terms'] ?? null,
                    'is_def' => $sortOrder === 1 ? 1 : 0,
                    'markup' => $markup,
                    'sort' => $sortOrder,
                ]);
                $saved[] = (int) $db->lastInsertId();
            }
        }

        json_response(['message' => 'Companies configuration saved successfully', 'saved_ids' => $saved]);
    }

    if ($method === 'POST' && $path === '/api/companies') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'create');
        $body = request_json();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['detail' => 'Company name is required.'], 422);
        }

        $count = (int) $db->query('SELECT COUNT(*) FROM companies')->fetchColumn();
        $sortOrder = $count + 1;
        $defaultMarkup = $sortOrder === 1 ? 0.0 : ($sortOrder === 2 ? 2.0 : 3.0);
        $markup = isset($body['markup_percent']) ? (float) $body['markup_percent'] : $defaultMarkup;

        $stmt = $db->prepare('
            INSERT INTO companies (
                name, code, logo_url, email, phone, mobile, address, city, ntn, strn, terms, is_default, markup_percent, sort_order, created_at, updated_at
            ) VALUES (
                :name, :code, :logo, :email, :phone, :mobile, :address, :city, :ntn, :strn, :terms, :is_def, :markup, :sort, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'name' => $name,
            'code' => $body['code'] ?? ('CO' . $sortOrder),
            'logo' => $body['logo_url'] ?? '/static/img/logo.png',
            'email' => $body['email'] ?? null,
            'phone' => $body['phone'] ?? null,
            'mobile' => $body['mobile'] ?? null,
            'address' => $body['address'] ?? null,
            'city' => $body['city'] ?? 'Hyderabad',
            'ntn' => $body['ntn'] ?? null,
            'strn' => $body['strn'] ?? null,
            'terms' => $body['terms'] ?? null,
            'is_def' => !empty($body['is_default']) ? 1 : 0,
            'markup' => $markup,
            'sort' => $sortOrder,
        ]);

        json_response(['id' => (int) $db->lastInsertId(), 'message' => 'Company created successfully'], 201);
    }

    if (preg_match('#^/api/companies/([1-9][0-9]*)$#', $path, $m)) {
        $coId = (int) $m[1];
        if ($method === 'GET') {
            require_authenticated_user($db);
            $stmt = $db->prepare('SELECT * FROM companies WHERE id = :id');
            $stmt->execute(['id' => $coId]);
            $co = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$co) {
                json_response(['detail' => 'Company not found'], 404);
            }
            json_response($co);
        }

        $user = require_authenticated_user($db);
        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'settings', 'delete');
            $stmt = $db->prepare('DELETE FROM companies WHERE id = :id');
            $stmt->execute(['id' => $coId]);
            json_response(['message' => 'Company deleted']);
        }

        if ($method === 'PUT') {
            require_permission_for($db, $user, 'settings', 'edit');
            $body = request_json();
            $fields = ['name', 'code', 'logo_url', 'email', 'phone', 'mobile', 'address', 'city', 'ntn', 'strn', 'terms', 'is_default', 'markup_percent', 'sort_order'];
            $updates = [];
            $values = ['id' => $coId];
            foreach ($fields as $f) {
                if (array_key_exists($f, $body)) {
                    $updates[] = "{$f} = :{$f}";
                    $values[$f] = $f === 'is_default' ? (int) (bool) $body[$f] : $body[$f];
                }
            }
            if ($updates !== []) {
                $updates[] = 'updated_at = CURRENT_TIMESTAMP';
                $stmt = $db->prepare('UPDATE companies SET ' . implode(', ', $updates) . ' WHERE id = :id');
                $stmt->execute($values);
            }
            json_response(['message' => 'Company updated']);
        }
    }

    return false;
}
