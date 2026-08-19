<?php
require_once __DIR__ . '/../config/app.php';

require_auth();
header('Content-Type: application/json; charset=utf-8');

if (!can_access_module('attendance') || !is_admin_user()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not allowed to search staff for attendance.']);
    exit;
}

ensure_attendance_schema();

$query = trim((string) ($_GET['q'] ?? ''));
$serviceId = max(0, (int) ($_GET['service_id'] ?? 0));

if ($query === '' || strlen($query) < 2) {
    echo json_encode(['success' => true, 'staff' => []]);
    exit;
}

$statement = db()->prepare(
    "SELECT staff.id, staff.staff_code, staff.full_name, staff.phone, staff.email,
            attendance_records.status AS attendance_status
     FROM staff
     LEFT JOIN attendance_records ON attendance_records.staff_id = staff.id AND attendance_records.service_id = ?
     WHERE staff.is_active = 1
       AND (staff.staff_code LIKE ? OR staff.full_name LIKE ? OR staff.phone LIKE ? OR staff.email LIKE ?)
     ORDER BY staff.full_name
     LIMIT 20"
);
$search = '%' . $query . '%';
$statement->execute([$serviceId, $search, $search, $search, $search]);

echo json_encode([
    'success' => true,
    'staff' => $statement->fetchAll(),
]);
