<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_promo_plug_schema();
require_module_access('customer_followup');
ensure_destination_visit_schema();
ensure_sales_trip_assignment_schema();

$pageTitle = 'Marketing Trip Follow-up';
$breadcrumbs = [['label' => 'Home', 'url' => app_url('index.php')], ['label' => 'Customer Follow-up']];
$internalBackUrl=requested_return_url(app_url('index.php'));
$message = $error = '';
$destinationId = max(0, (int) ($_GET['destination_id'] ?? $_POST['destination_id'] ?? 0));
$sourceVisitId = max(0, (int) ($_GET['visit_id'] ?? $_POST['source_visit_id'] ?? 0));
$followupMethod = (string) ($_GET['method'] ?? $_POST['follow_up_method'] ?? '');
$followupMethod = in_array($followupMethod, ['phone_call', 'physical_visit'], true) ? $followupMethod : '';
$mode = (string) ($_GET['mode'] ?? $_POST['mode'] ?? '');
$mode = in_array($mode, ['type', 'lookup'], true) ? $mode : '';
$userId = current_user_id();
$staffId = current_staff_id();
$currentVendor=current_vendor_profile();
$currentVendorId=(int)($currentVendor['id']??0);
$managedTownIds=$currentVendorId?array_map(static function (array $town): int { return (int)$town['id']; },assigned_towns_for_vendor($currentVendorId)):[];
$sharedTripAccess=false;
if($staffId){$sharedTripStatement=db()->prepare("SELECT COUNT(*) FROM sales_trips st INNER JOIN sales_trip_staff_assignments stsa ON stsa.sales_trip_id=st.id WHERE st.status='in_progress' AND stsa.staff_id=?");$sharedTripStatement->execute([$staffId]);$sharedTripAccess=(int)$sharedTripStatement->fetchColumn()>0;}
$activeTripStatement = db()->prepare("SELECT st.id FROM sales_trips st WHERE st.status='in_progress' AND (st.recorded_by_user_id=? OR EXISTS (SELECT 1 FROM sales_trip_staff_assignments stsa WHERE stsa.sales_trip_id=st.id AND stsa.staff_id=?) OR EXISTS (SELECT 1 FROM sales_trip_vendor_assignments stva WHERE stva.sales_trip_id=st.id AND stva.vendor_id=?)) ORDER BY st.id DESC LIMIT 1");
$activeTripStatement->execute([$userId, $staffId ?: 0, $currentVendorId]);
$activeTripId = (int) ($activeTripStatement->fetchColumn() ?: 0);
$destination = $source = null;

if ($destinationId) {
    $statement = db()->prepare('SELECT * FROM destinations WHERE id=? AND is_active=1');
    $statement->execute([$destinationId]);
    $destination = $statement->fetch() ?: null;
}
if ($sourceVisitId && $destination) {
    $statement = db()->prepare(
        "SELECT v.*,p.destination_id,p.business_name AS company_name,p.area,p.location_id,p.google_location,p.shop_type_id,
                c.customer_name AS owner_name,c.phone,c.other_phone,c.vehicle_registration_no,c.supervisor_name,
                c.supervisor_phone,c.vin_no,cs.sales_ref,cs.promo_plug,cs.sale_confirmed
         FROM visits v
         INNER JOIN customers c ON c.id=v.customer_id
         INNER JOIN business_locations p ON p.id=v.bus_loc_id
         LEFT JOIN customer_sales cs ON cs.visit_id=v.id
         WHERE v.id=? AND p.destination_id=? AND v.visit_type='registration' AND v.record_status='completed'"
    );
    $statement->execute([$sourceVisitId, $destinationId]);
    $source = $statement->fetch() ?: null;
    if($source&&$currentVendorId&&(int)($source['vendor_id']??0)!==$currentVendorId)$source=null;
    if($source&&!$currentVendorId&&!in_array(current_user_role(),['super_admin','admin'],true)&&!$sharedTripAccess&&(int)($source['recorded_by_user_id']??0)!==(int)$userId&&(int)($source['staff_id']??0)!==(int)$staffId)$source=null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $followupMethod === 'physical_visit' && !$activeTripId) {
    $error = 'Start a marketing trip before recording a physical visit.';
    $followupMethod = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $arrival = trim((string) ($_POST['shop_arrival_time'] ?? ''));
    $departure = trim((string) ($_POST['shop_departure_time'] ?? ''));
    $followupAtInput = trim((string) ($_POST['follow_up_at'] ?? ''));
    $followupTimestamp = $followupAtInput !== '' ? strtotime($followupAtInput) : false;
    $feedbackId = max(0, (int) ($_POST['feedback_option_id'] ?? 0));
    $feedback = null;

    if ($feedbackId) {
        $statement = db()->prepare('SELECT feedback_label FROM visit_feedback_options WHERE id=? AND is_active=1');
        $statement->execute([$feedbackId]);
        $feedback = $statement->fetchColumn() ?: null;
    }

    $tripId = $activeTripId;

    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$source) {
        $error = 'Select a valid destination visit.';
    } elseif ($followupMethod === '') {
        $error = 'Select how this follow-up was completed.';
    } elseif ($followupMethod === 'physical_visit' && !$tripId) {
        $error = 'Start a marketing trip before recording a physical visit.';
    } elseif ($followupMethod === 'phone_call' && $followupTimestamp === false) {
        $error = 'Enter a valid call date and time.';
    } elseif ($followupMethod === 'physical_visit' && (!preg_match('/^\d{2}:\d{2}$/', $arrival) || !preg_match('/^\d{2}:\d{2}$/', $departure))) {
        $error = 'Enter valid arrival and departure times.';
    } elseif ($followupMethod === 'physical_visit' && $departure < $arrival) {
        $error = 'Departure time cannot be earlier than arrival time.';
    } elseif ($feedbackId && !$feedback) {
        $error = 'Select a valid feedback option.';
    } else {
        $followupAt = $followupMethod === 'phone_call'
            ? date('Y-m-d H:i:s', (int) $followupTimestamp)
            : date('Y-m-d') . ' ' . $arrival . ':00';
        $salesRef = trim((string) ($_POST['sales_ref'] ?? ''));
        $promoPlug = trim((string) ($_POST['promo_plug'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        try {
            db()->beginTransaction();
            $statement = db()->prepare(
                'INSERT INTO visits (visit_ref,sales_trip_id,place_session_id,bus_loc_id,customer_id,vendor_id,staff_id,recorded_by_user_id,visit_type,follow_up_method,follow_up_at,visit_date,arrival_time,departure_time,sales_ref,promo_plug,sale_confirmed,record_status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $statement->execute([
                next_project_reference('visit'),$tripId ?: null,null,(int)$source['bus_loc_id'],(int)$source['customer_id'],
                (int)($source['vendor_id'] ?? 0) ?: null,$staffId ?: null,$userId,'follow_up',$followupMethod,$followupAt,
                substr($followupAt,0,10),$followupMethod === 'physical_visit' ? $arrival : null,
                $followupMethod === 'physical_visit' ? $departure : null,$salesRef ?: null,$promoPlug ?: null,0,'completed',
            ]);
            $followupVisitId = (int)db()->lastInsertId();
            if ($feedback !== null || $note !== '') {
                db()->prepare('INSERT INTO visit_notes (note_ref,visit_id,customer_id,feedback,note,staff_id,vendor_id,recorded_by_user_id) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([next_project_reference('visit_note'),$followupVisitId,(int)$source['customer_id'],$feedback,$note ?: null,$staffId ?: null,(int)($source['vendor_id'] ?? 0) ?: null,$userId]);
            }
            if ($promoPlug !== '') {
                db()->prepare('INSERT INTO customer_promo_plugs (visit_id,customer_id,bus_loc_id,promo_plug,recorded_by_user_id) VALUES (?,?,?,?,?)')
                    ->execute([$followupVisitId,(int)$source['customer_id'],(int)$source['bus_loc_id'],$promoPlug,$userId]);
            }
            db()->commit();
            $message = ($followupMethod === 'phone_call' ? 'Phone call' : 'Physical visit') . ' follow-up saved under ' . (string) $destination['destination_name'] . '.';
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $error = 'The follow-up could not be saved. Please try again.';
        }
    }
}

$destinations = db()->query('SELECT id,destination_name FROM destinations WHERE is_active=1 ORDER BY destination_name')->fetchAll();
$visits = [];
if ($destination) {
    $sql = "SELECT v.id,v.customer_id,p.business_name AS company_name,c.customer_name AS owner_name,c.phone,p.area,p.location_id,
                   cs.sales_ref,COALESCE(cs.sale_confirmed,v.sale_confirmed) AS sale_confirmed,v.created_at,st.trip_code
            FROM visits v
            INNER JOIN customers c ON c.id=v.customer_id
            INNER JOIN business_locations p ON p.id=v.bus_loc_id
            LEFT JOIN customer_sales cs ON cs.visit_id=v.id
            LEFT JOIN sales_trips st ON st.id=v.sales_trip_id
            WHERE p.destination_id=? AND v.visit_type='registration' AND v.record_status='completed'
              AND c.is_active=1
              AND v.id=(SELECT MAX(v2.id) FROM visits v2 WHERE v2.customer_id=v.customer_id AND v2.visit_type='registration' AND v2.record_status='completed')";
    $params = [$destinationId];
    if($currentVendorId){$sql.=" AND v.vendor_id=?";array_push($params,$currentVendorId);
    } elseif (!in_array(current_user_role(), ['super_admin', 'admin'], true) && !$sharedTripAccess) {
        $sql .= ' AND (v.recorded_by_user_id=? OR v.staff_id=?)';
        array_push($params, $userId, $staffId);
    }
    $sql .= ' ORDER BY c.customer_name,v.created_at DESC';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $visits = $statement->fetchAll();
}
$feedbackOptions = db()->query('SELECT id,feedback_label FROM visit_feedback_options WHERE is_active=1 ORDER BY feedback_label')->fetchAll();
$locations=active_locations();
$locationRegions=[];foreach($locations as $location){$key=(string)($location['region_code']?:$location['region_name']);$locationRegions[$key]=(string)$location['region_name'];}asort($locationRegions);
require_once __DIR__ . '/../includes/header.php';
?>
<?php if (!$destination): ?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">Marketing Trip Follow-up</span><h1>Choose Destination</h1><p>Select the destination whose previous registrations you want to follow up.</p></div><div class="management-icon"><i class="fa-solid fa-clipboard-check"></i></div></div><div class="report-subnav"><a class="secondary-button" href="<?= e(app_url('index.php')) ?>"><i class="fa-solid fa-arrow-left"></i><span>Dashboard</span></a></div><div class="report-destination-grid"><?php foreach ($destinations as $option): ?><a class="report-destination-card" href="<?= e(app_url('followup.php?destination_id=' . (int) $option['id'])) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-location-dot"></i></span><strong><?= e((string) $option['destination_name']) ?></strong><span>Find registration <i class="fa-solid fa-arrow-right"></i></span></a><?php endforeach; ?></div></section>
<?php endif; ?>

<?php if ($destination): ?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">Marketing Trip Follow-up</span><h1><?= e((string) $destination['destination_name']) ?> Follow-up</h1><p>Open an earlier registration and select the follow-up method.</p></div><div class="management-icon"><i class="fa-solid fa-clipboard-check"></i></div></div><?php if ($message): ?><div class="profile-message is-success"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="profile-message is-error"><?= e($error) ?></div><?php endif; ?><div class="report-subnav"><a class="secondary-button" href="<?= e(app_url('followup.php')) ?>"><i class="fa-solid fa-arrow-left"></i><span>All destinations</span></a></div></section>
<?php endif; ?>

<?php if ($source && $followupMethod): ?>
<section class="management-panel"><div class="management-heading management-heading--compact"><div><span class="section-kicker"><?= $followupMethod === 'phone_call' ? 'Phone Call' : 'Physical Visit' ?></span><h2><?= e((string) ($source['company_name'] ?: $source['owner_name'])) ?></h2><p><?= e((string) $destination['destination_name']) ?> · <?= e((string) ($source['area'] ?? '')) ?></p></div><div class="management-icon"><i class="fa-solid <?= $followupMethod === 'phone_call' ? 'fa-phone' : 'fa-location-dot' ?>"></i></div></div>
<form class="record-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="destination_id" value="<?= $destinationId ?>"><input type="hidden" name="source_visit_id" value="<?= $sourceVisitId ?>"><input type="hidden" name="follow_up_method" value="<?= e($followupMethod) ?>"><input type="hidden" name="mode" value="<?= e($mode) ?>"><div class="form-grid">
<?php $sourceSold=customer_has_completed_pos_sale((int)($source['customer_id']??0)); ?><div class="form-field form-field--wide"><div class="sales-status-editor <?=$sourceSold?'is-sold':'is-unsold'?>" data-customer-sales-row><div><span class="section-kicker">POS Sales Status</span><strong><?=$sourceSold?'Yes — Purchased':'No — Not purchased'?></strong><small><?=$sourceSold?'A completed POS sale is linked to this customer.':'No completed POS sale is linked to this customer.'?></small></div></div></div>
<?php if ($followupMethod === 'phone_call'): ?><div class="form-field"><label for="follow_up_at">Call date and time</label><input id="follow_up_at" name="follow_up_at" type="datetime-local" value="<?= e(date('Y-m-d\TH:i')) ?>" required></div><div class="form-field"><label>Phone number</label><div class="readonly-value"><?= e((string) ($source['phone'] ?: 'No phone recorded')) ?></div></div><?php else: ?><div class="form-field"><label for="shop_arrival_time">Arrival time</label><input id="shop_arrival_time" name="shop_arrival_time" type="time" required></div><?php endif; ?>
<div class="form-field"><label for="feedback_option_id">Feedback</label><select id="feedback_option_id" name="feedback_option_id"><option value="">Select feedback</option><?php foreach ($feedbackOptions as $option): ?><option value="<?= (int) $option['id'] ?>"><?= e((string) $option['feedback_label']) ?></option><?php endforeach; ?></select></div><div class="form-field form-field--wide"><label for="note">Note</label><textarea id="note" name="note"></textarea></div><?php if ($followupMethod === 'physical_visit'): ?><div class="form-field form-field--wide"><label for="shop_departure_time">Departure time</label><input id="shop_departure_time" name="shop_departure_time" type="time" required></div><?php endif; ?></div><div class="form-actions"><a class="secondary-button" href="<?= e(app_url('followup.php?destination_id=' . $destinationId . '&mode=' . $mode)) ?>">Cancel</a><button class="login-button">Save <?= $followupMethod === 'phone_call' ? 'call' : 'visit' ?> follow-up</button></div></form></section>
<?php elseif ($destination && $mode === ''): ?>
<section class="management-panel">
    <div class="management-heading management-heading--compact"><div><span class="section-kicker"><?= e((string) $destination['destination_name']) ?></span><h2>Choose how to find a registration</h2><p>Type a search or look up customers using location and date filters.</p></div></div>
    <div class="report-mode-actions report-mode-actions--menu">
        <a class="report-mode-button" href="<?= e(app_url('followup.php?destination_id=' . $destinationId . '&mode=lookup')) ?>"><i class="fa-solid fa-list-check"></i><span>Lookup</span></a>
        <a class="report-mode-button" href="<?= e(app_url('followup.php?destination_id=' . $destinationId . '&mode=type')) ?>"><i class="fa-solid fa-keyboard"></i><span>Quick Search</span></a>
    </div>
</section>
<?php elseif ($destination): ?>
<section class="management-panel management-panel--table" data-report-listing data-followup-registration-listing data-has-active-trip="<?= $activeTripId ? 'true' : 'false' ?>">
    <div class="management-heading management-heading--compact"><div><h2><?= e((string) $destination['destination_name']) ?> Registrations</h2><p>Search for a customer before opening their follow-up.</p></div></div>
    <?php if (!$visits): ?><p class="empty-state">No registration visits are available for this destination.</p><?php else: ?>
    <div class="report-subnav"><a class="secondary-button" href="<?= e(app_url('followup.php?destination_id=' . $destinationId)) ?>"><i class="fa-solid fa-arrow-left"></i><span>Lookup or Quick Search</span></a></div>
    <form class="report-filter-form" data-report-filter-form <?= $mode === 'type' ? 'data-report-require-filter' : '' ?>>
        <?php if ($mode === 'type'): ?><label class="report-search-field" for="followup_registration_search"><i class="fa-solid fa-magnifying-glass"></i><input id="followup_registration_search" type="search" placeholder="Search trip code, customer, phone, or area..." autocomplete="off" data-report-search></label>
        <?php else: ?><div class="report-filter-panel"><div class="form-field"><label for="followup_region">Region</label><select id="followup_region" data-location-region-select><option value="">All regions</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e($regionKey)?>"><?=e($regionName)?></option><?php endforeach;?></select></div><div class="form-field"><label for="location_id">Town</label><select id="location_id" name="location_id" data-location-town-select data-report-lookup><option value="">All towns</option><?php foreach($locations as $location):?><option value="<?=(int)$location['id']?>" data-region-key="<?=e((string)($location['region_code']?:$location['region_name']))?>" data-mmda-name="<?=e((string)$location['mmda_name'])?>"><?=e((string)$location['town_name'])?><?= (int)$location['is_capital']===1?' *':'' ?></option><?php endforeach;?></select><small data-location-mmda-output></small></div><div class="form-field report-date-field"><label for="date_from">Date From</label><input id="date_from" name="date_from" type="date" data-report-date-filter></div><div class="form-field report-date-field"><label for="date_to">Date To</label><input id="date_to" name="date_to" type="date" data-report-date-filter></div></div><?php endif; ?>
        <button class="report-filter-clear is-hidden" type="button" data-report-filter-clear>Clear</button>
    </form>
    <p class="report-result-count" data-report-results-count>0 results found</p>
    <p class="empty-state" data-report-empty-state data-initial-message="Search or select a filter to view registrations." data-no-match-message="No registrations match your filters.">Search or select a filter to view registrations.</p>
    <div class="table-wrap is-hidden" data-report-results data-followup-registration-results><table class="data-table"><thead><tr><th>Name</th><th>Trip</th><th>Contact</th><th>Location</th><th>POS Sales</th><th>Date</th></tr></thead><tbody><?php foreach ($visits as $visit): ?><?php $baseUrl = app_url('followup.php?destination_id=' . $destinationId . '&mode=' . $mode . '&visit_id=' . (int) $visit['id']); $search=implode(' ',[$visit['trip_code']??'',$visit['company_name']??'',$visit['owner_name']??'',$visit['phone']??'',$visit['area']??'']);$sold=customer_has_completed_pos_sale((int)($visit['customer_id']??0)); ?><tr class="customer-sales-row <?=$sold?'is-sold':'is-unsold'?>" data-customer-sales-row data-clickable-listing data-followup-method-open data-record-name="<?= e((string) ($visit['company_name'] ?: $visit['owner_name'])) ?>" data-phone-url="<?= e($baseUrl . '&method=phone_call') ?>" data-visit-url="<?= e($baseUrl . '&method=physical_visit') ?>" data-followup-registration-item data-report-filter-item data-report-search="<?= e(strtolower($search)) ?>" data-town-id="<?= e((string) ($visit['location_id'] ?? '')) ?>" data-report-date="<?= e(substr((string) $visit['created_at'], 0, 10)) ?>"><td><strong><?= e((string) ($visit['company_name'] ?: $visit['owner_name'])) ?></strong></td><td><?=e((string)($visit['trip_code']??''))?></td><td><?= e((string) ($visit['owner_name'] ?? '')) ?><span class="muted-text"><?= e((string) ($visit['phone'] ?? '')) ?></span></td><td><?= e((string) ($visit['area'] ?? '')) ?></td><td><span class="status-badge <?=$sold?'is-success':'is-warning'?>"><?=$sold?'Yes':'No'?></span></td><td><?= e(date('d M Y', strtotime((string) $visit['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
