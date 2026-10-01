<?php

declare(strict_types=1);

function handle_prices_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/prices/categories') {
        require_authenticated_user($db);
        $stmt = $db->query('SELECT DISTINCT category FROM price_list WHERE category IS NOT NULL AND category != "" ORDER BY category ASC');
        json_response($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($method === 'GET' && $path === '/api/prices') {
        require_authenticated_user($db);
        $search = $_GET['search'] ?? null;
        $category = $_GET['category'] ?? null;
        $where = ['is_active = 1'];
        $params = [];
        if ($search !== null && $search !== '') {
            $where[] = 'name LIKE :s';
            $params['s'] = '%' . $search . '%';
        }
        if ($category !== null && $category !== '') {
            $where[] = 'category = :c';
            $params['c'] = $category;
        }

        $sql = 'SELECT * FROM price_list WHERE ' . implode(' AND ', $where) . ' ORDER BY category ASC, name ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/prices') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'prices', 'create');
        $body = request_json();

        $name = trim((string) ($body['name'] ?? ''));
        $price = (float) ($body['unit_price'] ?? 0);
        if ($name === '' || $price <= 0) {
            json_response(['detail' => 'name and positive unit_price are required.'], 422);
        }

        $date = (string) ($body['effective_date'] ?? date('Y-m-d'));
        $stmt = $db->prepare('
            INSERT INTO price_list (
                name, description, category, unit, unit_price, currency, effective_date, source, image_url, is_active, created_at, updated_at
            ) VALUES (
                :name, :desc, :cat, :unit, :price, :curr, :edate, :src, :img, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'name' => $name,
            'desc' => $body['description'] ?? null,
            'cat' => $body['category'] ?? 'General',
            'unit' => $body['unit'] ?? 'pcs',
            'price' => $price,
            'curr' => $body['currency'] ?? 'PKR',
            'edate' => $date,
            'src' => $body['source'] ?? 'Manual',
            'img' => $body['image_url'] ?? null,
        ]);

        json_response(['id' => (int) $db->lastInsertId(), 'message' => 'Price item created'], 201);
    }

    if ($method === 'POST' && $path === '/api/prices/scrape') {
        require_authenticated_user($db);
        $body = request_json();
        $url = trim((string) ($body['url'] ?? ''));
        if ($url === '') {
            json_response(['detail' => 'url is required.'], 422);
        }

        $items = scrape_price_page($url);
        json_response(['source_url' => $url, 'items' => $items, 'count' => count($items)]);
    }

    if ($method === 'POST' && $path === '/api/prices/import/scraped') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'prices', 'create');
        $body = request_json();
        $items = $body['items'] ?? [];
        $source = (string) ($body['source'] ?? 'Web Scraper');
        $saved = 0;

        $stmt = $db->prepare('
            INSERT INTO price_list (
                name, description, category, unit, unit_price, currency, effective_date, source, image_url, is_active, created_at, updated_at
            ) VALUES (
                :name, :desc, :cat, :unit, :price, :curr, CURRENT_DATE, :src, :img, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $price = (float) ($item['unit_price'] ?? 0);
            if ($name !== '' && $price > 0) {
                $stmt->execute([
                    'name' => $name,
                    'desc' => $item['description'] ?? null,
                    'cat' => $item['category'] ?? 'General',
                    'unit' => $item['unit'] ?? 'pcs',
                    'price' => $price,
                    'curr' => $item['currency'] ?? 'PKR',
                    'src' => $source,
                    'img' => $item['image_url'] ?? null,
                ]);
                $saved++;
            }
        }
        json_response(['saved' => $saved, 'message' => "Imported {$saved} prices"]);
    }

    if (preg_match('#^/api/prices/([1-9][0-9]*)$#', $path, $m)) {
        $priceId = (int) $m[1];
        if ($method === 'GET') {
            require_authenticated_user($db);
            $stmt = $db->prepare('SELECT * FROM price_list WHERE id = :id');
            $stmt->execute(['id' => $priceId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                json_response(['detail' => 'Price item not found'], 404);
            }
            json_response($item);
        }

        $user = require_authenticated_user($db);
        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'prices', 'delete');
            $stmt = $db->prepare('DELETE FROM price_list WHERE id = :id');
            $stmt->execute(['id' => $priceId]);
            json_response(['message' => 'Price item deleted']);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            require_permission_for($db, $user, 'prices', 'edit');
            $body = request_json();
            $fields = ['name', 'description', 'category', 'unit', 'unit_price', 'currency', 'effective_date', 'source', 'image_url', 'is_active'];
            $updates = [];
            $values = ['id' => $priceId];
            foreach ($fields as $f) {
                if (array_key_exists($f, $body)) {
                    $updates[] = "{$f} = :{$f}";
                    $values[$f] = $f === 'is_active' ? (int) (bool) $body[$f] : $body[$f];
                }
            }
            if ($updates !== []) {
                $updates[] = 'updated_at = CURRENT_TIMESTAMP';
                $stmt = $db->prepare('UPDATE price_list SET ' . implode(', ', $updates) . ' WHERE id = :id');
                $stmt->execute($values);
            }
            json_response(['message' => 'Price item updated']);
        }
    }

    return false;
}
