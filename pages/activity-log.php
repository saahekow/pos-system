<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('activity_log');
ensure_destination_visit_schema();
ensure_sales_trip_assignment_schema();
ensure_places_management_schema();

$pageTitle = 'Activity Log';
$breadcrumbs = [['label' => 'Home', 'url' => app_url('index.php')], ['label' => 'Activity Log']];
$internalBackUrl = requested_return_url(app_url('reports.php'));
$isAdmin = in_array(current_user_role(), ['super_admin', 'admin'], true);
$userId = current_user_id();
$staffId = current_staff_id();

$sql = "SELECT st.id,st.trip_code,st.trip_date,st.journey_start_time,st.journey_start_kilometers,st.recorded_by_user_id,
               st.journey_start_kilometer_photo,st.journey_end_time,st.journey_end_kilometers,st.journey_end_kilometer_photo,st.journey_distance_kilometers,st.status,
               s.full_name AS staff_name,companion.full_name AS companion_staff_name,v.plate_number,
               ((SELECT COUNT(*) FROM destination_visits dv WHERE dv.sales_trip_id=st.id)
                 +(SELECT COUNT(*) FROM visits nv WHERE nv.sales_trip_id=st.id AND nv.record_status='completed')) AS visit_count,
               CONCAT_WS(', ',
                 NULLIF((SELECT GROUP_CONCAT(DISTINCT d.destination_name ORDER BY d.destination_name SEPARATOR ', ') FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id WHERE dv.sales_trip_id=st.id),''),
                 NULLIF((SELECT GROUP_CONCAT(DISTINCT d.destination_name ORDER BY d.destination_name SEPARATOR ', ') FROM visits nv INNER JOIN business_locations p ON p.id=nv.bus_loc_id LEFT JOIN destinations d ON d.id=p.destination_id WHERE nv.sales_trip_id=st.id AND nv.record_status='completed'),'')
               ) AS destinations
        FROM sales_trips st
        LEFT JOIN staff s ON s.id=st.staff_id
        LEFT JOIN staff companion ON companion.id=st.companion_staff_id
        LEFT JOIN vehicles v ON v.id=st.vehicle_id";
$params = [];
if (!$isAdmin) {
    $sql .= ' WHERE st.recorded_by_user_id=? OR st.staff_id=?
        OR EXISTS(SELECT 1 FROM sales_trip_staff_assignments a WHERE a.sales_trip_id=st.id AND a.staff_id=?)
        OR EXISTS(SELECT 1 FROM destination_visits dv WHERE dv.sales_trip_id=st.id AND (dv.recorded_by_user_id=? OR dv.staff_id=?))
        OR EXISTS(SELECT 1 FROM visits nv WHERE nv.sales_trip_id=st.id AND (nv.recorded_by_user_id=? OR nv.staff_id=?))';
    $params = [$userId, $staffId, $staffId, $userId, $staffId, $userId, $staffId];
}
$sql .= ' ORDER BY st.trip_date DESC,st.id DESC LIMIT 300';
$statement = db()->prepare($sql);
$statement->execute($params);
$trips = $statement->fetchAll();

$visitsByTrip = [];
if ($trips) {
    $tripIds = array_map(static function (array $trip): int { return (int) $trip['id']; }, $trips);
    $placeholders = implode(',', array_fill(0, count($tripIds), '?'));
    $visitStatement = db()->prepare(
        "SELECT dv.id,dv.sales_trip_id,dv.visit_type,dv.follow_up_method,dv.follow_up_at,dv.company_name,dv.owner_name,dv.phone,dv.area,
                dv.shop_arrival_time,dv.shop_departure_time,dv.feedback,dv.created_at,d.destination_name
         FROM destination_visits dv
         INNER JOIN destinations d ON d.id=dv.destination_id
         WHERE dv.sales_trip_id IN ({$placeholders})
         ORDER BY dv.created_at,dv.id"
    );
    $visitStatement->execute($tripIds);
    foreach ($visitStatement->fetchAll() as $visit) {
        $visit['record_source']='legacy';
        $visitsByTrip[(int) $visit['sales_trip_id']][] = $visit;
    }
    $normalizedVisitStatement=db()->prepare(
        "SELECT nv.id,nv.sales_trip_id,nv.visit_type,nv.follow_up_method,nv.follow_up_at,
                p.business_name AS company_name,c.customer_name AS owner_name,c.phone,p.area,
                nv.arrival_time AS shop_arrival_time,nv.departure_time AS shop_departure_time,
                (SELECT n.feedback FROM visit_notes n WHERE n.visit_id=nv.id ORDER BY n.id DESC LIMIT 1) AS feedback,
                nv.created_at,d.destination_name
         FROM visits nv
         INNER JOIN customers c ON c.id=nv.customer_id
         INNER JOIN business_locations p ON p.id=nv.bus_loc_id
         LEFT JOIN destinations d ON d.id=p.destination_id
         WHERE nv.sales_trip_id IN ({$placeholders}) AND nv.record_status='completed'
         ORDER BY nv.created_at,nv.id"
    );
    $normalizedVisitStatement->execute($tripIds);
    foreach($normalizedVisitStatement->fetchAll() as $visit){$visit['record_source']='normalized';$visitsByTrip[(int)$visit['sales_trip_id']][]=$visit;}
    foreach($visitsByTrip as &$tripVisitRows)usort($tripVisitRows,static fn(array $a,array $b):int=>strcmp((string)$a['created_at'],(string)$b['created_at']));unset($tripVisitRows);
}

$staffFilters = [];
$destinationFilters = [];
$filterToken = static function (string $value): string { return preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))) ?: ''; };
foreach ($trips as $trip) {
    foreach ([(string) ($trip['staff_name'] ?? ''), (string) ($trip['companion_staff_name'] ?? '')] as $name) {
        if ($name !== '') $staffFilters[$name] = $name;
    }
    foreach (array_filter(array_map('trim', explode(',', (string) ($trip['destinations'] ?? '')))) as $destination) {
        $destinationFilters[$destination] = $destination;
    }
}
ksort($staffFilters, SORT_NATURAL | SORT_FLAG_CASE);
ksort($destinationFilters, SORT_NATURAL | SORT_FLAG_CASE);

require_once __DIR__ . '/../includes/header.php';
?>
<?php if(isset($_GET['trip_deleted'])):?><div class="profile-message is-success" role="status">Trip deleted successfully.</div><?php endif;?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker">History</span><h1>Activity Log</h1><p>Review marketing trips and the destination visits recorded during each journey.</p></div><div class="management-icon"><i class="fa-solid fa-clock-rotate-left"></i></div></div>
</section>

<section class="management-panel activity-log-panel" data-live-filter-scope data-live-filter-require-filter>
    <div class="management-heading management-heading--compact"><div><span class="section-kicker">Filters</span><h2>Find Activity</h2></div></div>

    <form class="activity-filter-form" data-live-filter-form>
        <div class="filter-search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <label for="activity_search">Search activity</label>
            <input id="activity_search" name="search" type="search" placeholder="Search trip, staff, destination..." autocomplete="off">
        </div>
        <button class="filter-toggle" type="button" data-filter-toggle aria-controls="activity-filter-panel" aria-expanded="false"><i class="fa-solid fa-sliders"></i><span>Filters</span></button>
        <div class="filter-panel is-hidden" id="activity-filter-panel" data-filter-panel>
            <div class="form-field"><label for="activity_status">Status</label><select id="activity_status" name="status"><option value="">All statuses</option><option value="in_progress">In Progress</option><option value="completed">Completed</option><option value="planned">Planned</option><option value="cancelled">Cancelled</option></select></div>
            <div class="form-field"><label for="activity_staff">Staff</label><select id="activity_staff" name="staff"><option value="">All staff</option><?php foreach ($staffFilters as $staffName): ?><option value="<?= e($filterToken($staffName)) ?>"><?= e($staffName) ?></option><?php endforeach; ?></select></div>
            <div class="form-field"><label for="activity_destination">Destination</label><select id="activity_destination" name="destination"><option value="">All destinations</option><?php foreach ($destinationFilters as $destinationName): ?><option value="<?= e($filterToken($destinationName)) ?>"><?= e($destinationName) ?></option><?php endforeach; ?></select></div>
            <div class="form-field"><label for="activity_date_from">Date from</label><input id="activity_date_from" name="date_from" type="date"></div>
            <div class="form-field"><label for="activity_date_to">Date to</label><input id="activity_date_to" name="date_to" type="date"></div>
            <div class="form-actions activity-filter-actions"><button class="secondary-button" type="button" data-live-filter-reset><i class="fa-solid fa-rotate-left"></i><span>Reset</span></button></div>
        </div>
    </form>

    <p class="activity-result-count" data-live-filter-count>Showing current trips</p>
    <p class="empty-state" data-live-filter-empty data-live-filter-initial-message="No trips are currently in progress. Search or select a filter to view activity history." data-live-filter-no-match-message="No trip activity matches these filters.">No trips are currently in progress. Search or select a filter to view activity history.</p>

    <div class="activity-log-list">
        <?php foreach ($trips as $trip):
            $tripVisits = $visitsByTrip[(int) $trip['id']] ?? [];
            $staffText = trim((string) ($trip['staff_name'] ?? '') . ' ' . (string) ($trip['companion_staff_name'] ?? ''));
            $destinations = (string) ($trip['destinations'] ?? '');
            $searchText = strtolower(implode(' ', [$trip['trip_code'], $staffText, $destinations, $trip['plate_number'] ?? '', $trip['status']]));
            $start = trim(substr((string) ($trip['journey_start_time'] ?? ''), 0, 5));
            $end = trim(substr((string) ($trip['journey_end_time'] ?? ''), 0, 5));
        ?>
        <article class="activity-card <?=$trip['status']==='in_progress'?'':'is-hidden'?>" data-live-filter-item data-live-filter-default-visible="<?=$trip['status']==='in_progress'?'true':'false'?>" data-filter-text="<?= e($searchText) ?>" data-filter-date="<?= e((string) $trip['trip_date']) ?>" data-filter-status="<?= e(strtolower((string) $trip['status'])) ?>" data-filter-staff="<?= e(trim($filterToken((string) ($trip['staff_name'] ?? '')) . ' ' . $filterToken((string) ($trip['companion_staff_name'] ?? '')))) ?>" data-filter-destination="<?= e(implode(' ', array_map($filterToken, array_filter(array_map('trim', explode(',', $destinations)))))) ?>">
            <div class="activity-card__header"><div><span class="section-kicker"><?= e(date('d M Y', strtotime((string) $trip['trip_date']))) ?></span><h3><?= e((string) $trip['trip_code']) ?></h3></div><span class="status-badge <?= $trip['status'] === 'completed' ? 'is-active' : 'is-warning' ?>"><?= e(ucwords(str_replace('_', ' ', (string) $trip['status']))) ?></span></div>
            <div class="activity-card__summary">
                <div><span>Staff</span><strong><?= e((string) ($trip['staff_name'] ?: 'None')) ?></strong></div>
                <div><span>Other Staff</span><strong><?= e((string) ($trip['companion_staff_name'] ?: 'None')) ?></strong></div>
                <div><span>Start</span><strong><?= e(($start ?: '/') . ($trip['journey_start_kilometers'] !== null ? ' / ' . number_format((float) $trip['journey_start_kilometers'], 2) . ' km' : '')) ?></strong></div>
                <div><span>End</span><strong><?= e(($end ?: '/') . ($trip['journey_end_kilometers'] !== null ? ' / ' . number_format((float) $trip['journey_end_kilometers'], 2) . ' km' : '')) ?></strong></div>
                <div><span>Distance</span><strong><?= $trip['journey_distance_kilometers'] !== null ? e(number_format((float) $trip['journey_distance_kilometers'], 2) . ' km') : '' ?></strong></div>
                <div><span>Visits</span><strong><?= number_format((int) $trip['visit_count']) ?></strong></div>
                <div><span>Kilometer pictures</span><strong class="trip-photo-links"><?php if(!empty($trip['journey_start_kilometer_photo'])):?><button type="button" data-media-viewer="image" data-media-src="<?=e(app_url((string)$trip['journey_start_kilometer_photo']))?>" data-media-title="<?=e((string)$trip['trip_code'].' — Starting Kilometer Picture')?>"><i class="fa-solid fa-image"></i><span>Start</span></button><?php endif;?><?php if(!empty($trip['journey_end_kilometer_photo'])):?><button type="button" data-media-viewer="image" data-media-src="<?=e(app_url((string)$trip['journey_end_kilometer_photo']))?>" data-media-title="<?=e((string)$trip['trip_code'].' — Ending Kilometer Picture')?>"><i class="fa-solid fa-image"></i><span>End</span></button><?php endif;?><?php if(empty($trip['journey_start_kilometer_photo'])&&empty($trip['journey_end_kilometer_photo'])):?><span class="muted-text">None</span><?php endif;?></strong></div>
            </div>
            <?php if($isAdmin || (int)$trip['recorded_by_user_id']===$userId): ?><div class="form-actions"><a class="secondary-button" href="<?=e(app_url('trip-edit.php?id='.(int)$trip['id']))?>"><i class="fa-solid fa-pen-to-square"></i><span>Edit Trip</span></a></div><?php endif; ?>
            <details class="activity-card__details"><summary><span>Visit details</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
                <?php if (!$tripVisits): ?><p class="empty-state">No destination visits were recorded on this trip.</p><?php else: ?>
                <div class="activity-visit-list"><?php foreach ($tripVisits as $visit): ?>
                    <?php $visitLabel=$visit['visit_type']==='registration'?'Registration':ucwords(str_replace('_',' ',(string)($visit['follow_up_method']?:'physical_visit'))); ?>
                    <article class="activity-visit-card" data-clickable-listing data-listing-url="<?=e(app_url(($visit['record_source']??'legacy')==='normalized'?'normalized-visit-details.php?id='.(int)$visit['id']:'visit-details.php?id='.(int)$visit['id']))?>"><div><span class="status-badge <?= $visit['visit_type'] === 'registration' ? 'is-active' : 'is-warning' ?>"><?= e($visitLabel) ?></span><h4><?= e((string) ($visit['company_name'] ?: $visit['owner_name'] ?: $visit['destination_name'])) ?></h4><p><?= e((string) $visit['destination_name']) ?><?= $visit['area'] ? ' · ' . e((string) $visit['area']) : '' ?></p></div><dl><div><dt>Owner</dt><dd><?= e((string) ($visit['owner_name'] ?? '')) ?></dd></div><div><dt>Phone</dt><dd><?= e((string) ($visit['phone'] ?? '')) ?></dd></div><div><dt>Time</dt><dd><?= $visit['follow_up_method']==='phone_call'&&$visit['follow_up_at']?e(date('d M Y H:i',strtotime((string)$visit['follow_up_at']))):e(substr((string)$visit['shop_arrival_time'],0,5).' → '.substr((string)$visit['shop_departure_time'],0,5)) ?></dd></div></dl></article>
                <?php endforeach; ?></div><?php endif; ?>
            </details>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
