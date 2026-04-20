<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';

$env = app_config(dirname(__DIR__));
$pdo = db_connect($env);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function now_iso(): string
{
    return gmdate('c');
}

function read_path(): array
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $clean = parse_url($uri, PHP_URL_PATH) ?: '/';
    $pos = strpos($clean, '/api/');
    if ($pos === false) {
        return [];
    }
    $path = substr($clean, $pos + 5);
    $path = trim($path, '/');
    if ($path === '') {
        return [];
    }
    return array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));
}

function route_fail(): void
{
    json_response(404, ['message' => 'Route not found']);
}

function audit_log(PDO $pdo, ?string $userId, string $action, string $entityType, ?string $entityId, array $meta = []): void
{
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (id, user_id, action, entity_type, entity_id, meta_json, created_at)
        VALUES (UUID(), :user_id, :action, :entity_type, :entity_id, :meta_json, NOW())
    ");
    $stmt->execute([
        'user_id' => $userId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE)
    ]);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$segments = read_path();

if (count($segments) === 0) {
    json_response(200, ['name' => $env['APP_NAME'], 'status' => 'ok', 'timestamp' => now_iso()]);
}

if ($segments[0] === 'auth' && ($segments[1] ?? '') === 'login' && $method === 'POST') {
    $body = parse_json_body();
    $email = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($email === '' || $password === '') {
        json_response(422, ['message' => 'email and password are required']);
    }

    $stmt = $pdo->prepare('SELECT id, name, email, role, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    if (!$user || !hash_equals((string)$user['password_hash'], hash('sha256', $password))) {
        json_response(401, ['message' => 'Invalid credentials']);
    }

    $token = issue_token($pdo, (string)$user['id'], (int)$env['TOKEN_TTL_HOURS']);
    audit_log($pdo, (string)$user['id'], 'LOGIN', 'User', (string)$user['id']);
    json_response(200, [
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);
}

$auth = require_auth($pdo);

if ($segments[0] === 'equipment' && count($segments) === 1 && $method === 'GET') {
    $params = [];
    $where = ['e.archived = 0'];

    $q = trim((string)($_GET['q'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $location = trim((string)($_GET['location'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));

    if ($q !== '') {
        $where[] = '(e.name LIKE :q OR e.model LIKE :q OR e.serial_number LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
    if ($category !== '') {
        $where[] = 'e.category = :category';
        $params['category'] = $category;
    }
    if ($location !== '') {
        $where[] = 'e.location = :location';
        $params['location'] = $location;
    }
    if ($status !== '') {
        $where[] = 'e.status = :status';
        $params['status'] = $status;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 50)));
    $offset = ($page - 1) * $pageSize;

    $sql = 'SELECT e.* FROM equipment e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.created_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    json_response(200, $stmt->fetchAll());
}

if ($segments[0] === 'equipment' && count($segments) === 1 && $method === 'POST') {
    require_roles($auth, ['LAB_MANAGER', 'ADMIN']);
    $body = parse_json_body();
    $required = ['name', 'model', 'serial_number', 'category', 'location'];
    foreach ($required as $field) {
        if (trim((string)($body[$field] ?? '')) === '') {
            json_response(422, ['message' => "Missing field: {$field}"]);
        }
    }
    $status = (string)($body['status'] ?? 'OPERATIONAL');
    $allowed = ['OPERATIONAL', 'UNDER_MAINTENANCE', 'FAULTY', 'DECOMMISSIONED'];
    if (!in_array($status, $allowed, true)) {
        json_response(422, ['message' => 'Invalid status']);
    }

    $stmt = $pdo->prepare("
        INSERT INTO equipment (id, name, model, serial_number, category, location, purchase_date, status, next_service_date, archived, created_at, updated_at)
        VALUES (UUID(), :name, :model, :serial_number, :category, :location, :purchase_date, :status, :next_service_date, 0, NOW(), NOW())
    ");
    $stmt->execute([
        'name' => trim((string)$body['name']),
        'model' => trim((string)$body['model']),
        'serial_number' => trim((string)$body['serial_number']),
        'category' => trim((string)$body['category']),
        'location' => trim((string)$body['location']),
        'purchase_date' => ($body['purchase_date'] ?? null) ?: null,
        'status' => $status,
        'next_service_date' => ($body['next_service_date'] ?? null) ?: null
    ]);
    $id = (string)$pdo->query('SELECT id FROM equipment ORDER BY created_at DESC LIMIT 1')->fetchColumn();
    audit_log($pdo, (string)$auth['id'], 'CREATE', 'Equipment', $id);
    json_response(201, ['id' => $id]);
}

if ($segments[0] === 'equipment' && count($segments) === 2 && $method === 'GET') {
    $id = $segments[1];

    $s1 = $pdo->prepare('SELECT * FROM equipment WHERE id = :id AND archived = 0 LIMIT 1');
    $s1->execute(['id' => $id]);
    $equipment = $s1->fetch();
    if (!$equipment) {
        json_response(404, ['message' => 'Equipment not found']);
    }

    $faults = $pdo->prepare('SELECT * FROM fault_reports WHERE equipment_id = :id ORDER BY created_at DESC');
    $faults->execute(['id' => $id]);

    $reqs = $pdo->prepare('SELECT * FROM maintenance_requests WHERE equipment_id = :id ORDER BY created_at DESC');
    $reqs->execute(['id' => $id]);

    $history = $pdo->prepare('SELECT * FROM service_history WHERE equipment_id = :id ORDER BY service_date DESC');
    $history->execute(['id' => $id]);

    json_response(200, [
        'equipment' => $equipment,
        'fault_reports' => $faults->fetchAll(),
        'maintenance_requests' => $reqs->fetchAll(),
        'service_history' => $history->fetchAll()
    ]);
}

if ($segments[0] === 'equipment' && count($segments) === 2 && $method === 'PATCH') {
    require_roles($auth, ['LAB_MANAGER', 'ADMIN']);
    $id = $segments[1];
    $body = parse_json_body();

    if (isset($body['archived']) && (int)$body['archived'] === 1) {
        $open = $pdo->prepare("
            SELECT COUNT(*) FROM maintenance_requests
            WHERE equipment_id = :id AND status IN ('OPEN','IN_PROGRESS','PENDING_PARTS')
        ");
        $open->execute(['id' => $id]);
        if ((int)$open->fetchColumn() > 0) {
            json_response(409, ['message' => 'Cannot archive equipment with open maintenance requests']);
        }
    }

    $updates = [];
    $params = ['id' => $id];
    $allowed = ['name', 'model', 'category', 'location', 'status', 'next_service_date', 'archived'];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $updates[] = "{$field} = :{$field}";
            $params[$field] = $body[$field];
        }
    }
    if (!$updates) {
        json_response(422, ['message' => 'No fields to update']);
    }
    $updates[] = 'updated_at = NOW()';
    $sql = 'UPDATE equipment SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    audit_log($pdo, (string)$auth['id'], 'UPDATE', 'Equipment', $id, $body);
    json_response(200, ['updated' => true]);
}

if ($segments[0] === 'faults' && count($segments) === 1 && $method === 'POST') {
    $equipmentId = trim((string)($_POST['equipment_id'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $severity = strtoupper(trim((string)($_POST['severity'] ?? 'MEDIUM')));
    if ($equipmentId === '' || $description === '') {
        json_response(422, ['message' => 'equipment_id and description are required']);
    }
    $allowed = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    if (!in_array($severity, $allowed, true)) {
        json_response(422, ['message' => 'Invalid severity']);
    }

    $stmt = $pdo->prepare("
        INSERT INTO fault_reports (id, equipment_id, reported_by, description, severity, status, created_at)
        VALUES (UUID(), :equipment_id, :reported_by, :description, :severity, 'OPEN', NOW())
    ");
    $stmt->execute([
        'equipment_id' => $equipmentId,
        'reported_by' => $auth['id'],
        'description' => $description,
        'severity' => $severity
    ]);

    $faultId = (string)$pdo->query('SELECT id FROM fault_reports ORDER BY created_at DESC LIMIT 1')->fetchColumn();
    if (!empty($_FILES)) {
        $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . trim($env['UPLOAD_DIR'], '/\\');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $files = $_FILES['attachments'] ?? null;
        if (is_array($files) && isset($files['name']) && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $safe = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', (string)$files['name'][$i]);
                $newName = time() . '_' . $i . '_' . $safe;
                $target = $uploadDir . DIRECTORY_SEPARATOR . $newName;
                if (move_uploaded_file($files['tmp_name'][$i], $target)) {
                    $ins = $pdo->prepare("
                        INSERT INTO attachments (id, fault_report_id, file_name, file_path, mime_type, uploaded_at)
                        VALUES (UUID(), :fault_report_id, :file_name, :file_path, :mime_type, NOW())
                    ");
                    $ins->execute([
                        'fault_report_id' => $faultId,
                        'file_name' => $safe,
                        'file_path' => trim($env['UPLOAD_DIR'], '/\\') . '/' . $newName,
                        'mime_type' => (string)$files['type'][$i]
                    ]);
                }
            }
        }
    }
    audit_log($pdo, (string)$auth['id'], 'CREATE', 'FaultReport', $faultId, ['severity' => $severity]);
    json_response(201, ['id' => $faultId, 'notify' => 'Maintenance team notified']);
}

if ($segments[0] === 'faults' && count($segments) === 1 && $method === 'GET') {
    $where = ['1=1'];
    $params = [];
    foreach (['equipment_id', 'severity', 'status'] as $field) {
        $value = trim((string)($_GET[$field] ?? ''));
        if ($value !== '') {
            $where[] = "f.{$field} = :{$field}";
            $params[$field] = $value;
        }
    }
    $sql = "
        SELECT f.*, e.name AS equipment_name, u.name AS reported_by_name
        FROM fault_reports f
        INNER JOIN equipment e ON e.id = f.equipment_id
        INNER JOIN users u ON u.id = f.reported_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY f.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(200, $stmt->fetchAll());
}

if ($segments[0] === 'maintenance' && count($segments) === 1 && $method === 'POST') {
    $body = parse_json_body();
    $required = ['equipment_id', 'request_type', 'priority', 'description', 'due_date'];
    foreach ($required as $field) {
        if (trim((string)($body[$field] ?? '')) === '') {
            json_response(422, ['message' => "Missing field: {$field}"]);
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO maintenance_requests (
            id, equipment_id, fault_report_id, request_type, priority, description, requested_by, due_date,
            status, sla_deadline, created_at, updated_at
        )
        VALUES (
            UUID(), :equipment_id, :fault_report_id, :request_type, :priority, :description, :requested_by, :due_date,
            'OPEN', :due_date, NOW(), NOW()
        )
    ");
    $stmt->execute([
        'equipment_id' => $body['equipment_id'],
        'fault_report_id' => $body['fault_report_id'] ?? null,
        'request_type' => $body['request_type'],
        'priority' => $body['priority'],
        'description' => $body['description'],
        'requested_by' => $auth['id'],
        'due_date' => $body['due_date']
    ]);
    $id = (string)$pdo->query('SELECT id FROM maintenance_requests ORDER BY created_at DESC LIMIT 1')->fetchColumn();
    audit_log($pdo, (string)$auth['id'], 'CREATE', 'MaintenanceRequest', $id, ['priority' => $body['priority']]);
    json_response(201, ['id' => $id]);
}

if ($segments[0] === 'maintenance' && count($segments) === 1 && $method === 'GET') {
    $where = ['1=1'];
    $params = [];
    foreach (['status', 'priority'] as $field) {
        $value = trim((string)($_GET[$field] ?? ''));
        if ($value !== '') {
            $where[] = "m.{$field} = :{$field}";
            $params[$field] = $value;
        }
    }
    $sql = "
        SELECT
            m.*,
            e.name AS equipment_name,
            e.model AS equipment_model,
            e.serial_number AS equipment_serial
        FROM maintenance_requests m
        INNER JOIN equipment e ON e.id = m.equipment_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['escalated'] = ((string)$row['status'] !== 'CLOSED' && strtotime((string)$row['sla_deadline']) < time());
    }
    json_response(200, $rows);
}

if ($segments[0] === 'maintenance' && count($segments) === 3 && $segments[2] === 'assign' && $method === 'PATCH') {
    require_roles($auth, ['LAB_MANAGER', 'ADMIN']);
    $requestId = $segments[1];
    $body = parse_json_body();
    $technicianId = trim((string)($body['technician_id'] ?? ''));
    if ($technicianId === '') {
        json_response(422, ['message' => 'technician_id is required']);
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM technician_assignments ta
        INNER JOIN maintenance_requests m ON m.id = ta.maintenance_request_id
        WHERE ta.technician_id = :technician_id AND m.status IN ('OPEN','IN_PROGRESS','PENDING_PARTS')
    ");
    $countStmt->execute(['technician_id' => $technicianId]);
    $active = (int)$countStmt->fetchColumn();
    if ($active >= 8) {
        json_response(409, ['message' => 'Technician workload too high', 'active_task_count' => $active]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO technician_assignments (id, maintenance_request_id, technician_id, assigned_at, notes)
        VALUES (UUID(), :maintenance_request_id, :technician_id, NOW(), :notes)
    ");
    $stmt->execute([
        'maintenance_request_id' => $requestId,
        'technician_id' => $technicianId,
        'notes' => $body['notes'] ?? null
    ]);
    audit_log($pdo, (string)$auth['id'], 'ASSIGN', 'MaintenanceRequest', $requestId, ['technician_id' => $technicianId]);
    json_response(200, ['assigned' => true, 'active_task_count' => $active + 1, 'notify' => 'Technician notified']);
}

if ($segments[0] === 'maintenance' && count($segments) === 3 && $segments[2] === 'status' && $method === 'PATCH') {
    $requestId = $segments[1];
    $body = parse_json_body();
    $status = trim((string)($body['status'] ?? ''));
    $allowed = ['OPEN', 'IN_PROGRESS', 'PENDING_PARTS', 'RESOLVED', 'CLOSED'];
    if (!in_array($status, $allowed, true)) {
        json_response(422, ['message' => 'Invalid status']);
    }

    $up = $pdo->prepare("
        INSERT INTO repair_status_updates (id, maintenance_request_id, updated_by, status, notes, estimated_completion, created_at)
        VALUES (UUID(), :maintenance_request_id, :updated_by, :status, :notes, :estimated_completion, NOW())
    ");
    $up->execute([
        'maintenance_request_id' => $requestId,
        'updated_by' => $auth['id'],
        'status' => $status,
        'notes' => $body['notes'] ?? null,
        'estimated_completion' => $body['estimated_completion'] ?? null
    ]);

    $m = $pdo->prepare('UPDATE maintenance_requests SET status = :status, updated_at = NOW() WHERE id = :id');
    $m->execute(['status' => $status, 'id' => $requestId]);
    audit_log($pdo, (string)$auth['id'], 'STATUS_UPDATE', 'MaintenanceRequest', $requestId, ['status' => $status]);
    json_response(200, ['updated' => true]);
}

if ($segments[0] === 'service-history' && count($segments) === 2 && $method === 'GET') {
    $equipmentId = $segments[1];
    $stmt = $pdo->prepare("
        SELECT sh.*, u.name AS technician_name
        FROM service_history sh
        INNER JOIN users u ON u.id = sh.technician_id
        WHERE sh.equipment_id = :equipment_id
        ORDER BY sh.service_date DESC
    ");
    $stmt->execute(['equipment_id' => $equipmentId]);
    json_response(200, $stmt->fetchAll());
}

if ($segments[0] === 'service-history' && count($segments) === 3 && $segments[2] === 'export' && $method === 'GET') {
    $equipmentId = $segments[1];
    $stmt = $pdo->prepare("
        SELECT sh.service_date, u.name AS technician, sh.work_performed, sh.parts_replaced, sh.cost, sh.outcome, sh.next_service_date
        FROM service_history sh
        INNER JOIN users u ON u.id = sh.technician_id
        WHERE sh.equipment_id = :equipment_id
        ORDER BY sh.service_date DESC
    ");
    $stmt->execute(['equipment_id' => $equipmentId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="service-history-' . $equipmentId . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['service_date', 'technician', 'work_performed', 'parts_replaced', 'cost', 'outcome', 'next_service_date']);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

if ($segments[0] === 'service-history' && count($segments) === 1 && $method === 'POST') {
    require_roles($auth, ['TECHNICIAN', 'LAB_MANAGER', 'ADMIN']);
    $body = parse_json_body();
    $required = ['equipment_id', 'technician_id', 'work_performed', 'cost', 'outcome', 'service_date'];
    foreach ($required as $field) {
        if (trim((string)($body[$field] ?? '')) === '') {
            json_response(422, ['message' => "Missing field: {$field}"]);
        }
    }
    $stmt = $pdo->prepare("
        INSERT INTO service_history (
            id, equipment_id, maintenance_request_id, technician_id, work_performed, parts_replaced,
            cost, outcome, service_date, next_service_date, created_at
        )
        VALUES (
            UUID(), :equipment_id, :maintenance_request_id, :technician_id, :work_performed, :parts_replaced,
            :cost, :outcome, :service_date, :next_service_date, NOW()
        )
    ");
    $stmt->execute([
        'equipment_id' => $body['equipment_id'],
        'maintenance_request_id' => $body['maintenance_request_id'] ?? null,
        'technician_id' => $body['technician_id'],
        'work_performed' => $body['work_performed'],
        'parts_replaced' => $body['parts_replaced'] ?? null,
        'cost' => $body['cost'],
        'outcome' => $body['outcome'],
        'service_date' => $body['service_date'],
        'next_service_date' => $body['next_service_date'] ?? null
    ]);

    if (!empty($body['next_service_date'])) {
        $u = $pdo->prepare('UPDATE equipment SET next_service_date = :next_service_date, updated_at = NOW() WHERE id = :id');
        $u->execute(['next_service_date' => $body['next_service_date'], 'id' => $body['equipment_id']]);
    }
    $id = (string)$pdo->query('SELECT id FROM service_history ORDER BY created_at DESC LIMIT 1')->fetchColumn();
    audit_log($pdo, (string)$auth['id'], 'CREATE', 'ServiceHistory', $id);
    json_response(201, ['id' => $id]);
}

if ($segments[0] === 'technicians' && count($segments) === 1 && $method === 'GET') {
    $sql = "
        SELECT
            u.id, u.name, u.email, u.specialization, u.is_available,
            COALESCE(SUM(CASE WHEN m.status IN ('OPEN','IN_PROGRESS','PENDING_PARTS') THEN 1 ELSE 0 END), 0) AS active_task_count
        FROM users u
        LEFT JOIN technician_assignments ta ON ta.technician_id = u.id
        LEFT JOIN maintenance_requests m ON m.id = ta.maintenance_request_id
        WHERE u.role = 'TECHNICIAN'
        GROUP BY u.id, u.name, u.email, u.specialization, u.is_available
        ORDER BY u.name
    ";
    $rows = $pdo->query($sql)->fetchAll();
    json_response(200, $rows);
}

if ($segments[0] === 'dashboard' && ($segments[1] ?? '') === 'stats' && $method === 'GET') {
    $equipmentCount = (int)$pdo->query('SELECT COUNT(*) FROM equipment WHERE archived = 0')->fetchColumn();
    $activeFaults = (int)$pdo->query("SELECT COUNT(*) FROM fault_reports WHERE status IN ('OPEN','ACKNOWLEDGED')")->fetchColumn();
    $openRequests = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('OPEN','IN_PROGRESS','PENDING_PARTS')")->fetchColumn();
    $overdue = (int)$pdo->query("
        SELECT COUNT(*) FROM maintenance_requests
        WHERE due_date < NOW() AND status IN ('OPEN','IN_PROGRESS','PENDING_PARTS')
    ")->fetchColumn();

    $trendStmt = $pdo->query("
        SELECT severity, COUNT(*) AS count
        FROM fault_reports
        GROUP BY severity
        ORDER BY FIELD(severity,'LOW','MEDIUM','HIGH','CRITICAL')
    ");
    json_response(200, [
        'equipmentCount' => $equipmentCount,
        'activeFaults' => $activeFaults,
        'openRequests' => $openRequests,
        'overdueMaintenance' => $overdue,
        'faultTrend' => $trendStmt->fetchAll()
    ]);
}

route_fail();
