<?php
require_once __DIR__ . '/config/app.php';
require_module_access('vendor_customers');
ensure_places_management_schema();
ensure_addendum_schema();
$_SESSION['normalized_customer_workflow'] = 'create_customer';

$activeAddendum = db()->prepare("SELECT id FROM place_visit_sessions WHERE sales_trip_id IS NULL AND session_type='addendum' AND status='active' AND recorded_by_user_id=? ORDER BY id DESC LIMIT 1");
$activeAddendum->execute([current_user_id()]);
$target = $activeAddendum->fetchColumn()
    ? 'normalized-customer.php?stage=activity'
    : 'normalized-customer.php?stage=new-place';
header('Location: ' . app_url($target));
exit;