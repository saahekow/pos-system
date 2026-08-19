<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
unset($_SESSION['normalized_customer_workflow']);
unset($_SESSION['normalized_customer_menu_return']);
require_module_access('customer_visit');

$userId = (int)current_user_id();
$staffId = current_staff_id() ?: 0;
$vendorId = (int)(current_vendor_profile()['id'] ?? 0);
ensure_sales_trip_assignment_schema();
$activeTripStatement = db()->prepare(
    'SELECT st.id,st.trip_code,st.recorded_by_user_id
     FROM sales_trips st
     WHERE st.status=? AND (
        st.recorded_by_user_id=?
        OR EXISTS (
            SELECT 1 FROM sales_trip_staff_assignments sta
            WHERE sta.sales_trip_id=st.id AND sta.staff_id=?
        )
        OR EXISTS (
            SELECT 1 FROM sales_trip_vendor_assignments vta
            WHERE vta.sales_trip_id=st.id AND vta.vendor_id=?
        )
        OR (st.staff_id=? AND st.recorded_by_user_id IS NULL)
     )
     ORDER BY st.id DESC
     LIMIT 1'
);
$activeTripStatement->execute(['in_progress', $userId, $staffId, $vendorId, $staffId]);
$activeTrip = $activeTripStatement->fetch() ?: null;

if (!$activeTrip) {
    header('Location: ' . app_url('normalized-customer.php'));
    exit;
}

$activeLocationStatement = db()->prepare(
    'SELECT id
     FROM place_visit_sessions
     WHERE sales_trip_id=? AND status=?
     ORDER BY id DESC
     LIMIT 1'
);
$activeLocationStatement->execute([(int)$activeTrip['id'], 'active']);
$workspaceStage = $activeLocationStatement->fetchColumn() ? 'activity' : 'new-place';
header('Location: ' . app_url('normalized-customer.php?stage=' . $workspaceStage));
exit;

$pageTitle = 'Marketing Trip';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Marketing Trip'],
];
$internalBackUrl=app_url('marketing.php?view=trip');
require __DIR__ . '/../includes/header.php';
?>
<section class="management-panel marketing-trip-menu" aria-labelledby="marketing-trip-menu-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker"><?=e((string)$activeTrip['trip_code'])?></span>
            <h1 id="marketing-trip-menu-title">Marketing Trip</h1>
            <p>Choose whether to register new activity or update an existing record.</p>
        </div>
        <div class="management-icon"><i class="fa-solid fa-map-location-dot"></i></div>
    </div>

    <div class="marketing-trip-choice-grid">
        <a class="marketing-trip-choice marketing-trip-choice--new" href="<?=e(app_url('normalized-customer.php?stage=new-place'))?>">
            <span class="marketing-trip-choice__icon"><i class="fa-solid fa-plus"></i></span>
            <span class="marketing-trip-choice__copy">
                <span class="section-kicker">Create</span>
                <strong>New</strong>
                <small>Register a new location or customer activity in the active trip.</small>
            </span>
            <i class="fa-solid fa-arrow-right marketing-trip-choice__arrow"></i>
        </a>

        <a class="marketing-trip-choice" href="<?=e(app_url('registration-records.php'))?>">
            <span class="marketing-trip-choice__icon"><i class="fa-solid fa-pen-to-square"></i></span>
            <span class="marketing-trip-choice__copy">
                <span class="section-kicker">Manage</span>
                <strong>Edit</strong>
                <small>Update drafts, completed registrations, and saved locations.</small>
            </span>
            <i class="fa-solid fa-arrow-right marketing-trip-choice__arrow"></i>
        </a>
    </div>

    <div class="form-actions">
        <a class="secondary-button" href="<?=e($internalBackUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php';
