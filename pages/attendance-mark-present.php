<?php
require_once __DIR__ . '/../config/app.php';

require_auth();
header('Content-Type: application/json; charset=utf-8');

if (!can_access_module('attendance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not allowed to mark attendance.']);
    exit;
}

ensure_attendance_schema();

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$token = (string) ($payload['csrf_token'] ?? '');
$serviceId = max(0, (int) ($payload['service_id'] ?? 0));
$requestedStaffId = max(0, (int) ($payload['staff_id'] ?? 0));
$latitude = isset($payload['latitude']) ? (float) $payload['latitude'] : null;
$longitude = isset($payload['longitude']) ? (float) $payload['longitude'] : null;
$accuracy = isset($payload['accuracy']) ? (float) $payload['accuracy'] : null;

if (!verify_csrf_token($token)) {
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
    exit;
}

$staffId = is_admin_user() && $requestedStaffId > 0 ? $requestedStaffId : (current_staff_id() ?: 0);

if ($serviceId <= 0 || $staffId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Select a valid attendance session and staff member.']);
    exit;
}

$serviceStatement = db()->prepare("SELECT * FROM attendance_services WHERE id = ? AND status = 'active' LIMIT 1");
$serviceStatement->execute([$serviceId]);
$service = $serviceStatement->fetch();

if (!$service) {
    echo json_encode(['success' => false, 'message' => 'Select a valid active attendance session.']);
    exit;
}

$locationSettings = attendance_location_settings();
$locationVerified = 0;
$distance = null;

if (!is_admin_user()) {
    if (!attendance_location_is_configured($locationSettings)) {
        echo json_encode(['success' => false, 'message' => 'Attendance location has not been configured.']);
        exit;
    }

    if ($latitude === null || $longitude === null) {
        echo json_encode(['success' => false, 'message' => 'Please allow location access to mark attendance.']);
        exit;
    }

    $distance = attendance_distance_meters(
        $latitude,
        $longitude,
        (float) $locationSettings['latitude'],
        (float) $locationSettings['longitude']
    );
    $radius = (int) ($locationSettings['attendance_radius'] ?? 100);

    if ($distance > $radius) {
        echo json_encode([
            'success' => false,
            'message' => 'You are ' . round($distance) . ' metres from the saved attendance location and outside the ' . $radius . '-metre radius.',
            'distance' => round($distance, 2),
        ]);
        exit;
    }

    $locationVerified = 1;
}

$statement = db()->prepare(
    "INSERT INTO attendance_records
        (service_id, staff_id, status, marked_by_user_id, latitude, longitude, location_accuracy, distance_meters, location_verified)
     VALUES (?, ?, 'present', ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        marked_by_user_id = VALUES(marked_by_user_id),
        latitude = VALUES(latitude),
        longitude = VALUES(longitude),
        location_accuracy = VALUES(location_accuracy),
        distance_meters = VALUES(distance_meters),
        location_verified = VALUES(location_verified),
        marked_at = CURRENT_TIMESTAMP"
);
$statement->execute([
    $serviceId,
    $staffId,
    current_user_id(),
    $latitude,
    $longitude,
    $accuracy,
    $distance,
    $locationVerified,
]);

echo json_encode([
    'success' => true,
    'message' => 'Attendance marked successfully.',
    'distance' => $distance !== null ? round($distance, 2) : null,
]);
