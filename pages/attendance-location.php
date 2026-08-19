<?php
require_once __DIR__ . '/../config/app.php';

require_auth();
header('Content-Type: application/json; charset=utf-8');

if (!is_admin_user()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only administrators can manage attendance location.']);
    exit;
}

ensure_attendance_schema();

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$token = (string) ($payload['csrf_token'] ?? '');
$latitude = isset($payload['latitude']) ? (float) $payload['latitude'] : null;
$longitude = isset($payload['longitude']) ? (float) $payload['longitude'] : null;
$accuracy = isset($payload['accuracy']) ? (float) $payload['accuracy'] : null;
$radius = max(20, min(1000, (int) ($payload['attendance_radius'] ?? 100)));

if (!verify_csrf_token($token)) {
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
    exit;
}

if ($latitude === null || $longitude === null) {
    echo json_encode(['success' => false, 'message' => 'Please allow location access before saving attendance location.']);
    exit;
}

db()->prepare(
    'UPDATE attendance_location_settings
     SET latitude = ?, longitude = ?, attendance_radius = ?, location_accuracy = ?,
         location_updated_by_user_id = ?, location_updated_at = CURRENT_TIMESTAMP
     ORDER BY id ASC
     LIMIT 1'
)->execute([$latitude, $longitude, $radius, $accuracy, current_user_id()]);

echo json_encode([
    'success' => true,
    'message' => 'Attendance location saved successfully.',
    'settings' => [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'attendance_radius' => $radius,
        'location_accuracy' => $accuracy,
    ],
]);
