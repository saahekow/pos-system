<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
ensure_places_management_schema();
header('Content-Type: application/json; charset=utf-8');

$phone = normalize_phone_number((string)($_GET['phone'] ?? ''));
$excludeId = max(0, (int)($_GET['exclude_id'] ?? 0));
$excludeDraftId = max(0, (int)($_GET['exclude_draft_id'] ?? 0));
if (!is_valid_phone_number($phone)) {
    echo json_encode(['success'=>true,'valid'=>false,'exists'=>false]); exit;
}
$customer = registered_customer_for_phone($phone, $excludeId);
$name = $customer ? trim((string)($customer['company_name'] ?: $customer['owner_name'])) : '';
if ($customer) {
    echo json_encode(['success'=>true,'valid'=>true,'exists'=>true,'customer_name'=>$name,'visit_id'=>(int)$customer['id']]);
    exit;
}

$lastNine = substr(preg_replace('/\D/', '', $phone), -9);
$normalizedCustomers = db()->query("SELECT id,customer_name,phone,other_phone FROM customers WHERE is_active=1")->fetchAll();
foreach ($normalizedCustomers as $normalizedCustomer) {
    foreach (['phone','other_phone'] as $field) {
        if (substr(preg_replace('/\D/', '', (string)($normalizedCustomer[$field] ?? '')), -9) === $lastNine) {
            echo json_encode(['success'=>true,'valid'=>true,'exists'=>true,'customer_name'=>$normalizedCustomer['customer_name'],'customer_id'=>(int)$normalizedCustomer['id']]);
            exit;
        }
    }
}

$drafts = db()->query('SELECT id,draft_ref,draft_payload FROM customer_visit_drafts')->fetchAll();
foreach ($drafts as $draft) {
    if ((int)$draft['id'] === $excludeDraftId) continue;
    $payload = json_decode((string)$draft['draft_payload'], true) ?: [];
    foreach (['phone','other_phone'] as $field) {
        $candidate = substr(preg_replace('/\D/', '', (string)($payload[$field] ?? '')), -9);
        if ($candidate !== '' && $candidate === $lastNine) {
            echo json_encode(['success'=>true,'valid'=>true,'exists'=>true,'draft_ref'=>$draft['draft_ref'],'customer_name'=>'draft '.$draft['draft_ref']]);
            exit;
        }
    }
}
echo json_encode(['success'=>true,'valid'=>true,'exists'=>false]);
