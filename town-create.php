<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

header('Content-Type: application/json; charset=utf-8');
require_auth();

function town_create_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    town_create_response(405, ['ok' => false, 'message' => 'Use the town form to add a town.']);
}

if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    town_create_response(419, ['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
}

$regionKey = trim((string)($_POST['region_key'] ?? ''));
$townName = preg_replace('/\s+/u', ' ', trim((string)($_POST['town_name'] ?? ''))) ?? '';

if ($regionKey === '' || $townName === '') {
    town_create_response(422, ['ok' => false, 'message' => 'Select a region, then enter the new town name.']);
}
if ((function_exists('mb_strlen') ? mb_strlen($townName) : strlen($townName)) > 160) {
    town_create_response(422, ['ok' => false, 'message' => 'The town name must be 160 characters or fewer.']);
}

ensure_location_schema();
$regionStatement = db()->prepare(
    "SELECT region_code,region_name
     FROM locations
     WHERE is_active=1
       AND region_name IS NOT NULL AND TRIM(region_name)<>''
       AND (CAST(region_code AS CHAR)=? OR region_name=?)
     ORDER BY CASE WHEN entry_type='region' THEN 0 ELSE 1 END,id
     LIMIT 1"
);
$regionStatement->execute([$regionKey, $regionKey]);
$region = $regionStatement->fetch();
if (!$region) {
    town_create_response(422, ['ok' => false, 'message' => 'Select a valid region.']);
}

$existingStatement = db()->prepare(
    "SELECT id,town_name FROM locations
     WHERE entry_type='town' AND region_code=?
       AND LOWER(TRIM(town_name))=LOWER(TRIM(?))
     LIMIT 1"
);
$existingStatement->execute([(string)$region['region_code'], $townName]);
$existing = $existingStatement->fetch();

if ($existing) {
    $locationId = (int)$existing['id'];
    $townName = (string)$existing['town_name'];
    db()->prepare("UPDATE locations SET is_active=1 WHERE id=?")->execute([$locationId]);
} else {
    $insert = db()->prepare(
        "INSERT INTO locations(entry_type,region_code,region_name,mmda_code,mmda_name,town_name,is_capital,is_active)
         VALUES('town',?,?,NULL,NULL,?,0,1)"
    );
    $insert->execute([(string)$region['region_code'], (string)$region['region_name'], $townName]);
    $locationId = (int)db()->lastInsertId();
}

$requestedVendorId = max(0, (int)($_POST['vendor_id'] ?? 0));
$currentVendor = current_vendor_profile();
if ($currentVendor && current_user_role() === 'vendor') {
    $requestedVendorId = (int)$currentVendor['id'];
}
if ($requestedVendorId > 0 && (is_admin_user() || (int)($currentVendor['id'] ?? 0) === $requestedVendorId)) {
    ensure_vendor_town_assignments_schema();
    db()->prepare(
        'INSERT INTO vendor_town_assignments(vendor_id,town_id,location_id,assigned_by_user_id,is_active)
         VALUES(?,NULL,?,?,1)
         ON DUPLICATE KEY UPDATE assigned_by_user_id=VALUES(assigned_by_user_id),is_active=1'
    )->execute([$requestedVendorId, $locationId, (int)current_user_id()]);
}

town_create_response(200, [
    'ok' => true,
    'town' => [
        'id' => $locationId,
        'name' => $townName,
        'region_key' => (string)$region['region_code'],
        'mmda_name' => '',
    ],
]);
