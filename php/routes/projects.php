<?php

declare(strict_types=1);

function handle_projects_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/projects') {
        require_authenticated_user($db);
        $stmt = $db->query('
            SELECT pr.*, c.name AS client_name, u.full_name AS created_by_name
            FROM projects pr
            LEFT JOIN clients c ON c.id = pr.client_id
            LEFT JOIN users u ON u.id = pr.created_by
            ORDER BY pr.created_at DESC, pr.id DESC
        ');
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/projects') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'projects', 'create');
        $body = request_json();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['detail' => 'name is required'], 422);
        }

        $stmt = $db->prepare('
            INSERT INTO projects (
                name, description, client_id, start_date, end_date, status, budget, notes, created_by, created_at, updated_at
            ) VALUES (
                :name, :desc, :cid, :sdate, :edate, :status, :budget, :notes, :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'name' => $name,
            'desc' => $body['description'] ?? null,
            'cid' => !empty($body['client_id']) ? (int) $body['client_id'] : null,
            'sdate' => $body['start_date'] ?? null,
            'edate' => $body['end_date'] ?? null,
            'status' => $body['status'] ?? 'planning',
            'budget' => (float) ($body['budget'] ?? 0),
            'notes' => $body['notes'] ?? null,
            'uid' => $user->id,
        ]);

        json_response(['id' => (int) $db->lastInsertId(), 'message' => 'Project created'], 201);
    }

    if (preg_match('#^/api/projects/([1-9][0-9]*)$#', $path, $m)) {
        $projectId = (int) $m[1];
        if ($method === 'GET') {
            require_authenticated_user($db);
            $stmt = $db->prepare('
                SELECT pr.*, c.name AS client_name
                FROM projects pr
                LEFT JOIN clients c ON c.id = pr.client_id
                WHERE pr.id = :id
            ');
            $stmt->execute(['id' => $projectId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                json_response(['detail' => 'Project not found'], 404);
            }
            json_response($p);
        }

        $user = require_authenticated_user($db);
        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'projects', 'delete');
            $stmt = $db->prepare('DELETE FROM projects WHERE id = :id');
            $stmt->execute(['id' => $projectId]);
            json_response(['message' => 'Project deleted']);
        }

        if ($method === 'PUT') {
            require_permission_for($db, $user, 'projects', 'edit');
            $body = request_json();
            $allowed = ['name', 'description', 'client_id', 'start_date', 'end_date', 'status', 'budget', 'notes'];
            $updates = [];
            $values = ['id' => $projectId];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $updates[] = "{$f} = :{$f}";
                    $values[$f] = $f === 'client_id' && empty($body[$f]) ? null : $body[$f];
                }
            }
            if ($updates !== []) {
                $updates[] = 'updated_at = CURRENT_TIMESTAMP';
                $stmt = $db->prepare('UPDATE projects SET ' . implode(', ', $updates) . ' WHERE id = :id');
                $stmt->execute($values);
            }
            json_response(['message' => 'Project updated']);
        }
    }

    return false;
}
