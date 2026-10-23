<?php

declare(strict_types=1);

function handle_products_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/products/settings') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'products', 'view');
        $sheetUrl = get_app_setting($db, 'google_sheet_url', '');
        $lastSynced = get_app_setting($db, 'google_sheet_last_synced', '');
        $profitMargin = (float) get_app_setting($db, 'product_profit_percent', '10.0');
        json_response([
            'google_sheet_url' => $sheetUrl,
            'google_sheet_last_synced' => $lastSynced,
            'product_profit_percent' => $profitMargin,
        ]);
    }

    if ($method === 'POST' && $path === '/api/products/settings') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'products', 'edit');
        $body = request_json();
        if (isset($body['google_sheet_url'])) {
            $newSheetUrl = trim((string) $body['google_sheet_url']);
            if ($newSheetUrl !== '') {
                try {
                    assert_google_sheet_url($newSheetUrl);
                } catch (InvalidArgumentException $e) {
                    json_response(['detail' => $e->getMessage()], 422);
                }
            }
            set_app_setting($db, 'google_sheet_url', $newSheetUrl);
        }
        if (isset($body['product_profit_percent'])) {
            $margin = (float) $body['product_profit_percent'];
            if ($margin >= 0) {
                set_app_setting($db, 'product_profit_percent', (string) $margin);
            }
        }
        json_response([
            'message' => 'Settings updated',
            'google_sheet_url' => get_app_setting($db, 'google_sheet_url', ''),
            'google_sheet_last_synced' => get_app_setting($db, 'google_sheet_last_synced', ''),
            'product_profit_percent' => (float) get_app_setting($db, 'product_profit_percent', '10.0'),
        ]);
    }

    if ($method === 'POST' && $path === '/api/products/sync-google-sheet') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'products', 'edit');
        $body = request_json();
        $sheetUrl = trim((string) ($body['sheet_url'] ?? ''));
        if ($sheetUrl === '') {
            $sheetUrl = (string) get_app_setting($db, 'google_sheet_url', '');
        }
        if ($sheetUrl === '') {
            json_response(['detail' => 'Google Sheet URL is required. Please provide a sheet URL.'], 422);
        }

        $profitMargin = isset($body['profit_percent']) ? (float) $body['profit_percent'] : (float) get_app_setting($db, 'product_profit_percent', '10.0');

        try {
            $result = sync_products_from_google_sheet($db, $sheetUrl, $profitMargin);
            json_response($result);
        } catch (PDOException $e) {
            error_log('Sheet sync database error: ' . $e->getMessage());
            json_response(['detail' => 'Could not save the imported products. Please try again.'], 500);
        } catch (InvalidArgumentException | RuntimeException $e) {
            // Friendly messages written for the user (bad link, sheet not shared, no products found)
            json_response(['detail' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            error_log('Sheet sync error: ' . $e->getMessage());
            json_response(['detail' => 'The sheet could not be imported.'], 500);
        }
    }

    if ($method === 'POST' && $path === '/api/products/batch-update-rates') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'products', 'edit');
        $body = request_json();
        $items = $body['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            json_response(['detail' => 'items array is required'], 422);
        }

        $profitMargin = (float) get_app_setting($db, 'product_profit_percent', '10.0');
        $multiplier = 1.0 + ($profitMargin / 100.0);
        $updated = 0;

        $stmt = $db->prepare('UPDATE products SET cost_price = :cost, unit_price = :unit, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) continue;
            $cost = clean_numeric_rate($item['cost_price'] ?? 0);
            $selling = round($cost * $multiplier, 2);
            $stmt->execute([
                'cost' => $cost,
                'unit' => $selling,
                'id' => $id,
            ]);
            $updated++;
        }

        json_response([
            'success' => true,
            'message' => "Successfully updated rates for {$updated} products (+{$profitMargin}% profit applied).",
            'updated_count' => $updated,
        ]);
    }

    if ($method === 'GET' && $path === '/api/products/export-csv') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'products', 'view');
        $csv = export_products_to_csv($db);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="datapoint_product_rates_' . date('Ymd_His') . '.csv"');
        echo $csv;
        exit;
    }

    if ($method === 'GET' && $path === '/api/products') {
        // Everyone who can quote/invoice needs the product list, but the purchase (cost) price
        // is only for staff who may view the Products module.
        $user = require_authenticated_user($db);
        $stmt = $db->query('SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON i.product_id = p.id WHERE p.is_active = 1 ORDER BY p.category ASC, p.name ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!user_has_permission($user, 'products', 'view')) {
            $rows = array_map(static function (array $row): array {
                unset($row['cost_price']);
                return $row;
            }, $rows);
        }
        json_response($rows);
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
        $cost = (float) ($values['cost_price'] ?? 0);
        $unitPrice = (float) ($values['unit_price'] ?? 0);
        if ($cost > 0 && $unitPrice <= 0) {
            $profitMargin = (float) get_app_setting($db, 'product_profit_percent', '10.0');
            $values['unit_price'] = round($cost * (1.0 + ($profitMargin / 100.0)), 2);
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
            $viewer = require_authenticated_user($db);
            $stmt = $db->prepare('SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON i.product_id = p.id WHERE p.id = :id');
            $stmt->execute(['id' => $productId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                json_response(['detail' => 'Product not found'], 404);
            }
            if (!user_has_permission($viewer, 'products', 'view')) {
                unset($p['cost_price']);
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
            if (array_key_exists('cost_price', $body) && !array_key_exists('unit_price', $body)) {
                $profitMargin = (float) get_app_setting($db, 'product_profit_percent', '10.0');
                $updates[] = 'unit_price = :unit_price';
                $values['unit_price'] = round(((float) $body['cost_price']) * (1.0 + ($profitMargin / 100.0)), 2);
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
