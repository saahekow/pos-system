<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_promo_plug_schema();
$requestedReportSection = (string) ($_GET['report'] ?? '');
require_module_access($requestedReportSection === 'followup' ? 'customer_followup' : 'reports');
ensure_destination_visit_schema();
ensure_places_management_schema();

$pageTitle = 'Reports';
$breadcrumbs = [['label' => 'Home', 'url' => app_url('index.php')], ['label' => 'Reports']];
$destinationId = max(0, (int) ($_GET['destination_id'] ?? 0));
$reportSection = (string) ($_GET['report'] ?? '');
$reportSection = in_array($reportSection, ['visits', 'followup', 'visit-summary'], true) ? $reportSection : '';
if($reportSection==='visit-summary'&&!can_access_menu_item('marketing_report_trip')){header('Location: '.app_url('marketing.php?view=reports'));exit;}
$allDestinations = $reportSection === 'visits' && (string)($_GET['scope'] ?? '') === 'all';
$mode = (string) ($_GET['mode'] ?? '');
$mode = in_array($mode, ['type', 'lookup'], true) ? $mode : '';
$reportReturnParams = [];
if ($reportSection !== '') $reportReturnParams['report'] = $reportSection;
if ($destinationId > 0) $reportReturnParams['destination_id'] = $destinationId;
if ($allDestinations) $reportReturnParams['scope'] = 'all';
if ($mode !== '') $reportReturnParams['mode'] = $mode;
$reportReturnUrl = app_url('reports.php'.($reportReturnParams ? '?'.http_build_query($reportReturnParams) : ''));
$destinations = db()->query("SELECT id,destination_name,destination_key FROM destinations WHERE is_active=1 ORDER BY (destination_key='taxi_rank') ASC,destination_name")->fetchAll();
$locations=active_locations();
$towns=$locations;
$locationRegions=[];foreach($towns as $town){$key=(string)($town['region_code']?:$town['region_name']);$locationRegions[$key]=(string)$town['region_name'];}asort($locationRegions);
$selectedDestination = null;
foreach ($destinations as $destination) {
    if ((int) $destination['id'] === $destinationId) {
        $selectedDestination = $destination;
        break;
    }
}
$rows = [];
$visitSummaries = [];
$summaryPlacesByTrip = [];
if ($reportSection === 'visit-summary') {
    $visitSummaries = db()->query(
        "SELECT st.id,st.trip_code,st.trip_date,st.created_at,st.status,
                st.journey_start_time,st.journey_end_time,st.journey_start_kilometers,
                st.journey_end_kilometers,st.journey_distance_kilometers,
                st.journey_start_kilometer_photo,st.journey_end_kilometer_photo,
                vehicle.plate_number,staff.full_name AS staff_name,
                (SELECT COUNT(*) FROM place_visit_sessions ps WHERE ps.sales_trip_id=st.id) AS visit_count
         FROM sales_trips st
         LEFT JOIN vehicles vehicle ON vehicle.id=st.vehicle_id
         LEFT JOIN staff ON staff.id=st.staff_id
         ORDER BY st.trip_date DESC,st.id DESC"
    )->fetchAll();
    if ($visitSummaries) {
        $tripIds = array_map(static fn(array $trip): int => (int)$trip['id'], $visitSummaries);
        $placeholders = implode(',', array_fill(0, count($tripIds), '?'));
        $placeStatement = db()->prepare(
            "SELECT ps.id AS session_id,ps.sales_trip_id,ps.session_ref,ps.arrival_time,ps.departure_time,ps.status AS session_status,
                    p.id AS bus_loc_id,p.bus_loc_ref,p.business_name,p.area,l.town_name,l.region_name,
                    v.id AS visit_id,v.visit_ref,v.visit_type,v.record_status,
                    c.id AS customer_id,c.customer_ref,c.customer_name,c.phone,c.other_phone,c.job_type,c.master_customer_id
             FROM place_visit_sessions ps
             INNER JOIN business_locations p ON p.id=ps.bus_loc_id
             LEFT JOIN locations l ON l.id=p.location_id
             LEFT JOIN visits v ON v.place_session_id=ps.id
             LEFT JOIN customers c ON c.id=v.customer_id
             WHERE ps.sales_trip_id IN ($placeholders)
             ORDER BY ps.sales_trip_id,ps.id,v.id"
        );
        $placeStatement->execute($tripIds);
        foreach ($placeStatement->fetchAll() as $placeRow) {
            $tripId = (int)$placeRow['sales_trip_id'];
            $sessionId = (int)$placeRow['session_id'];
            if (!isset($summaryPlacesByTrip[$tripId][$sessionId])) {
                $summaryPlacesByTrip[$tripId][$sessionId] = [
                    'session_id'=>$sessionId,'session_ref'=>$placeRow['session_ref'],'arrival_time'=>$placeRow['arrival_time'],
                    'departure_time'=>$placeRow['departure_time'],'session_status'=>$placeRow['session_status'],
                    'bus_loc_ref'=>$placeRow['bus_loc_ref'],'business_name'=>$placeRow['business_name'],'area'=>$placeRow['area'],
                    'town_name'=>$placeRow['town_name'],'region_name'=>$placeRow['region_name'],'customers'=>[],'apprentices'=>[],
                ];
            }
            if ((int)($placeRow['customer_id'] ?? 0) > 0) {
                $isApprentice=(int)($placeRow['master_customer_id']??0)>0||strcasecmp(trim((string)($placeRow['job_type']??'')),'Apprentice')===0;
                $group = $isApprentice ? 'apprentices' : 'customers';
                $summaryPlacesByTrip[$tripId][$sessionId][$group][(int)$placeRow['customer_id']] = $placeRow;
            }
        }
    }
}
if (($selectedDestination || $allDestinations) && $mode !== '') {
    $reportUserId = (int)(current_user_id() ?? 0);
    $reportStaffId = (int)(current_staff_id() ?? 0);
    $reportVendor = current_vendor_profile();
    $reportVendorId = (int)($reportVendor['id'] ?? 0);
    $reportSharedTripAccess = false;
    if ($reportStaffId) {
        $sharedTripStatement=db()->prepare("SELECT COUNT(*) FROM sales_trips st INNER JOIN sales_trip_staff_assignments stsa ON stsa.sales_trip_id=st.id WHERE st.status='in_progress' AND stsa.staff_id=?");
        $sharedTripStatement->execute([$reportStaffId]);
        $reportSharedTripAccess=(int)$sharedTripStatement->fetchColumn()>0;
    }
    $sql = "SELECT v.id,v.visit_ref,v.customer_id,p.destination_id,p.business_name AS company_name,c.customer_name AS owner_name,
                   c.phone,c.other_phone,p.area,p.location_id,v.visit_type,v.follow_up_method,v.follow_up_at,
                   cs.sales_ref,cs.sale_confirmed,
                   (SELECT COUNT(*) FROM customer_pos_sale_vins csv WHERE csv.customer_source='visit' AND csv.record_id=v.id AND csv.amount>0) AS purchase_count,
                   v.created_at,l.region_name,l.town_name,d.destination_name,st.trip_code,
                   'normalized' AS report_source
            FROM visits v
            INNER JOIN business_locations p ON p.id=v.bus_loc_id
            INNER JOIN customers c ON c.id=v.customer_id
            INNER JOIN destinations d ON d.id=p.destination_id
            LEFT JOIN customer_sales cs ON cs.visit_id=v.id
            LEFT JOIN locations l ON l.id=p.location_id
            LEFT JOIN sales_trips st ON st.id=v.sales_trip_id
            WHERE v.record_status='completed'";
    $params = [];
    if ($reportSection === 'followup') $sql .= " AND v.visit_type='follow_up'"; else $sql .= " AND v.visit_type='registration'";
    if (!$allDestinations) {$sql .= ' AND p.destination_id=?'; $params[]=$destinationId;}
    if ($reportSection === 'followup' && $reportVendorId) {
        $sql .= ' AND v.vendor_id=?';
        $params[]=$reportVendorId;
    } elseif ($reportSection === 'followup' && !in_array(current_user_role(), ['super_admin','admin'], true) && !$reportSharedTripAccess) {
        $sql .= ' AND (v.recorded_by_user_id=? OR v.staff_id=?)';
        array_push($params,$reportUserId,$reportStaffId);
    }
    $sql .= ' ORDER BY COALESCE(v.follow_up_at,v.created_at) DESC,v.id DESC';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    $legacySql="SELECT dv.id,0 customer_id,dv.destination_id,dv.company_name,dv.owner_name,dv.phone,dv.other_phone,dv.area,dv.location_id,dv.visit_type,dv.follow_up_method,dv.follow_up_at,dv.sales_ref,0 sale_confirmed,dv.created_at,l.region_name,l.town_name,d.destination_name,st.trip_code,CONCAT('VS-',dv.id) visit_ref,'legacy' report_source FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id LEFT JOIN locations l ON l.id=dv.location_id LEFT JOIN sales_trips st ON st.id=dv.sales_trip_id WHERE dv.record_status='completed' AND dv.normalized_customer_id IS NULL AND NOT EXISTS(SELECT 1 FROM customers nc WHERE COALESCE(NULLIF(nc.phone,''),NULLIF(nc.other_phone,''),'#') IN (COALESCE(NULLIF(dv.phone,''),'!'),COALESCE(NULLIF(dv.other_phone,''),'?')))";
    $legacyParams=[];
    if($reportSection==='followup')$legacySql.=" AND dv.visit_type='follow_up'";else $legacySql.=" AND dv.visit_type='registration'";
    if(!$allDestinations){$legacySql.=' AND dv.destination_id=?';$legacyParams[]=$destinationId;}
    if($reportSection==='followup'&&$reportVendorId){$legacySql.=' AND dv.vendor_id=?';$legacyParams[]=$reportVendorId;
    }elseif($reportSection==='followup'&&!in_array(current_user_role(),['super_admin','admin'],true)&&!$reportSharedTripAccess){$legacySql.=' AND (dv.recorded_by_user_id=? OR dv.staff_id=?)';array_push($legacyParams,$reportUserId,$reportStaffId);}
    $legacyStatement=db()->prepare($legacySql.' ORDER BY COALESCE(dv.follow_up_at,dv.created_at) DESC,dv.id DESC');$legacyStatement->execute($legacyParams);
    $rows=array_merge($rows,$legacyStatement->fetchAll());
    usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['follow_up_at']?:$b['created_at']),(string)($a['follow_up_at']?:$a['created_at'])));
}
$reportsMenuReturn = requested_return_url(app_url('marketing.php?view=reports'));
$reportsHubUrl = app_url('reports.php?return_to='.rawurlencode($reportsMenuReturn));
$reportsContext = '&return_to='.rawurlencode($reportsMenuReturn);
$internalBackUrl = $reportsMenuReturn;
if ($reportSection !== '') {
    $internalBackUrl = $reportsMenuReturn;
    if (in_array($reportSection, ['visits', 'followup'], true) && ($selectedDestination || $allDestinations)) {
        if ($mode !== '') {
            $internalBackUrl = app_url($allDestinations
                ? 'reports.php?report=visits&scope=all'.$reportsContext
                : 'reports.php?report='.$reportSection.'&destination_id='.$destinationId.$reportsContext);
        } else {
            $internalBackUrl = app_url('reports.php?report='.$reportSection.$reportsContext);
        }
    }
}
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($reportSection === ''): ?>
<section class="management-panel reports-menu-panel">
    <div class="management-heading"><div><span class="section-kicker">Admin Reports</span><h1>Reports</h1><p>Review staff, field, fleet, registration, and operational records.</p></div><div class="management-icon"><i class="fa-solid fa-chart-line"></i></div></div>
    <div class="report-destination-grid">
        <a class="report-destination-card" href="<?= e(app_url('staff-report.php?return_to='.rawurlencode($reportsHubUrl))) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-users"></i></span><strong>Staff</strong><span>View all staff records <i class="fa-solid fa-arrow-right"></i></span></a>
        <a class="report-destination-card" href="<?= e(app_url('attendance-report.php?return_to='.rawurlencode($reportsHubUrl))) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-calendar-check"></i></span><strong>Attendance</strong><span>View staff attendance <i class="fa-solid fa-arrow-right"></i></span></a>
        <?php if(can_access_module('activity_log')): ?><a class="report-destination-card" href="<?= e(app_url('activity-log.php?return_to='.rawurlencode($reportsHubUrl))) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-clock-rotate-left"></i></span><strong>Activity Log</strong><span>Review trip and field history <i class="fa-solid fa-arrow-right"></i></span></a><?php endif; ?>
        <?php if(can_access_module('vehicle_log')): ?><a class="report-destination-card" href="<?= e(app_url('vehicles.php?return_to='.rawurlencode($reportsHubUrl))) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-car-side"></i></span><strong>Vehicle Records</strong><span>Review fuel and log books <i class="fa-solid fa-arrow-right"></i></span></a><?php endif; ?>
        <?php if(can_access_module('feedback')): ?><a class="report-destination-card" href="<?= e(app_url('feedback.php?return_to='.rawurlencode($reportsHubUrl))) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-comments"></i></span><strong>Feedback</strong><span>Review field feedback <i class="fa-solid fa-arrow-right"></i></span></a><?php endif; ?>
        <?php if(can_access_module('registration_records')): ?><a class="report-destination-card" href="<?= e(app_url('registration-records.php?return_to='.rawurlencode($reportsHubUrl))) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-address-card"></i></span><strong>Registration Records</strong><span>Review saved registrations <i class="fa-solid fa-arrow-right"></i></span></a><?php endif; ?>
        <a class="report-destination-card" href="<?= e(app_url('reports.php?report=visits&scope=all'.$reportsContext)) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-location-dot"></i></span><strong>Visits</strong><span>View all records <i class="fa-solid fa-arrow-right"></i></span></a>
        <a class="report-destination-card" href="<?= e(app_url('reports.php?report=followup'.$reportsContext)) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-clipboard-check"></i></span><strong>Follow-up Reports</strong><span>Open report <i class="fa-solid fa-arrow-right"></i></span></a>
        <a class="report-destination-card" href="<?= e(app_url('reports.php?report=visit-summary'.$reportsContext)) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-list-check"></i></span><strong>Visit Summary</strong><span>View trip visit totals <i class="fa-solid fa-arrow-right"></i></span></a>
    </div>
</section>

<?php elseif ($reportSection === 'visit-summary'): ?>
<section class="management-panel management-panel--table">
    <div class="management-heading"><div><span class="section-kicker">Report Center</span><h1>Visit Summary</h1><p>Visit totals recorded under each marketing trip.</p></div><div class="management-icon"><i class="fa-solid fa-list-check"></i></div></div>
    <div class="report-subnav"><a class="secondary-button" href="<?=e($reportsHubUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Reports</span></a></div>
    <?php if(!$visitSummaries):?><p class="empty-state">No marketing trip summaries are available.</p><?php else:?><div class="visit-summary-stack"><?php foreach($visitSummaries as $summary):$tripPlaces=$summaryPlacesByTrip[(int)$summary['id']]??[];$tripCustomerCount=array_sum(array_map(static fn(array $place):int=>count($place['customers']??[]),$tripPlaces));$tripApprenticeCount=array_sum(array_map(static fn(array $place):int=>count($place['apprentices']??[]),$tripPlaces));?>
        <details class="visit-summary-trip">
            <summary>
                <span><span class="section-kicker"><?=e(date('d M Y',strtotime((string)$summary['trip_date'])))?></span><strong><?=e((string)$summary['trip_code'])?></strong></span>
                <span class="visit-summary-trip__count"><span class="visit-summary-count-pill is-location"><i class="fa-solid fa-location-dot"></i><strong><?=number_format(count($tripPlaces))?></strong><span>location<?=count($tripPlaces)===1?'':'s'?></span></span><span class="visit-summary-count-pill is-customer"><i class="fa-solid fa-user"></i><strong><?=number_format($tripCustomerCount)?></strong><span>customer<?=$tripCustomerCount===1?'':'s'?></span></span><span class="visit-summary-count-pill is-apprentice"><i class="fa-solid fa-user-group"></i><strong><?=number_format($tripApprenticeCount)?></strong><span>apprentice<?=$tripApprenticeCount===1?'':'s'?></span></span></span>
                <span class="status-badge <?=$summary['status']==='completed'?'is-active':'is-warning'?>"><?=e(ucwords(str_replace('_',' ',(string)$summary['status'])))?></span>
                <i class="fa-solid fa-chevron-down"></i>
            </summary>
            <div class="visit-summary-trip__body">
                <dl class="visit-summary-trip__overview">
                    <div><dt>Vehicle</dt><dd><?=e((string)($summary['plate_number']?:'Not set'))?></dd></div>
                    <div><dt>Staff</dt><dd><?=e((string)($summary['staff_name']?:'Not set'))?></dd></div>
                    <div><dt>Start</dt><dd><?=e(substr((string)$summary['journey_start_time'],0,5))?><?= $summary['journey_start_kilometers']!==null?' · '.e(number_format((float)$summary['journey_start_kilometers'],2)).' km':''?></dd></div>
                    <div><dt>End</dt><dd><?=e(substr((string)$summary['journey_end_time'],0,5))?><?= $summary['journey_end_kilometers']!==null?' · '.e(number_format((float)$summary['journey_end_kilometers'],2)).' km':''?></dd></div>
                    <div><dt>Distance</dt><dd><?=$summary['journey_distance_kilometers']!==null?e(number_format((float)$summary['journey_distance_kilometers'],2)).' km':'Pending'?></dd></div>
                    <div><dt>Pictures</dt><dd class="trip-photo-links"><?php if($summary['journey_start_kilometer_photo']):?><button type="button" data-media-viewer="image" data-media-src="<?=e(app_url((string)$summary['journey_start_kilometer_photo']))?>" data-media-title="<?=e((string)$summary['trip_code'].' — Starting Kilometer Picture')?>">Start</button><?php endif;?><?php if($summary['journey_end_kilometer_photo']):?><button type="button" data-media-viewer="image" data-media-src="<?=e(app_url((string)$summary['journey_end_kilometer_photo']))?>" data-media-title="<?=e((string)$summary['trip_code'].' — Ending Kilometer Picture')?>">End</button><?php endif;?><?php if(!$summary['journey_start_kilometer_photo']&&!$summary['journey_end_kilometer_photo']):?>None<?php endif;?></dd></div>
                </dl>
                <div class="visit-summary-place-stack">
                    <?php foreach($tripPlaces as $place):?>
                    <details class="visit-summary-place">
                        <summary><span class="visit-summary-place__icon"><i class="fa-solid fa-location-dot"></i></span><span><strong><?=e((string)($place['business_name']?:'Unnamed Location'))?></strong><small><?=e(implode(' / ',array_filter([(string)$place['bus_loc_ref'],(string)$place['town_name'],(string)$place['area']])))?></small></span><span class="visit-summary-place__count"><?=number_format(count($place['customers']))?> customer<?=count($place['customers'])===1?'':'s'?></span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="visit-summary-place__body">
                            <div class="visit-summary-place__meta"><span>Arrival <strong><?=e(substr((string)$place['arrival_time'],0,5)?:'Pending')?></strong></span><span>Departure <strong><?=e(substr((string)$place['departure_time'],0,5)?:'Pending')?></strong></span></div>
                            <?php if(!$place['customers']):?><p class="empty-state">No customers were recorded at this location.</p><?php else:?><div class="visit-summary-customer-list"><?php foreach($place['customers'] as $customer):?>
                                <a class="visit-summary-customer" href="<?=e(app_url('normalized-visit-details.php?id='.(int)$customer['visit_id'].'&return_to='.rawurlencode($reportReturnUrl)))?>"><span class="visit-summary-customer__avatar"><i class="fa-solid fa-user"></i></span><span><strong><?=e((string)$customer['customer_name'])?></strong><small><?=e(implode(' · ',array_filter([(string)$customer['customer_ref'],(string)$customer['visit_ref'],(string)$customer['phone'],(string)$customer['job_type']])))?></small></span><i class="fa-solid fa-arrow-right"></i></a>
                            <?php endforeach;?></div><?php endif;?>
                        </div>
                    </details>
                    <?php endforeach;?>
                    <?php if(!$tripPlaces):?><p class="empty-state">No locations were recorded on this trip.</p><?php endif;?>
                </div>
            </div>
        </details>
    <?php endforeach;?></div><?php endif;?>
</section>

<?php elseif (in_array($reportSection, ['visits', 'followup'], true) && !$selectedDestination && !$allDestinations): ?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker"><?= $reportSection === 'followup' ? 'Follow-up Reports' : 'Visit Reports' ?></span><h1><?= $reportSection === 'followup' ? 'Follow-up' : 'Visits' ?></h1><p><?= $reportSection === 'followup' ? 'Choose the destination whose customer follow-ups you want to review.' : 'Show all visits or choose one destination.' ?></p></div><div class="management-icon"><i class="fa-solid <?= $reportSection === 'followup' ? 'fa-clipboard-check' : 'fa-location-dot' ?>"></i></div></div>
    <div class="report-subnav"><a class="secondary-button" href="<?=e($reportsHubUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Reports</span></a></div>
    <div class="report-destination-grid">
        <?php if($reportSection === 'visits'): ?><a class="report-destination-card" href="<?=e(app_url('reports.php?report=visits&scope=all'.$reportsContext))?>"><span class="report-destination-card__icon"><i class="fa-solid fa-layer-group"></i></span><strong>All</strong><span>All destination visits <i class="fa-solid fa-arrow-right"></i></span></a><?php endif; ?>
        <?php foreach($destinations as $destination):?><a class="report-destination-card" href="<?=e(app_url('reports.php?report='.$reportSection.'&destination_id='.(int)$destination['id'].$reportsContext))?>"><span class="report-destination-card__icon"><i class="fa-solid <?=destination_is_taxi_rank($destination)?'fa-taxi':'fa-store'?>"></i></span><strong><?=e((string)$destination['destination_name'])?></strong><span><?= $reportSection === 'followup' ? 'Customer follow-ups' : 'Destination visits' ?> <i class="fa-solid fa-arrow-right"></i></span></a><?php endforeach;?>
    </div>
</section>

<?php elseif ($mode === ''): ?>
<section class="management-panel">
    <?php $menuTitle=$reportSection==='followup'?(string)$selectedDestination['destination_name'].' Follow-up':($allDestinations?'All Visits':(string)$selectedDestination['destination_name']); $menuBase=$allDestinations?'report=visits&scope=all':'report='.$reportSection.'&destination_id='.$destinationId; ?>
    <div class="management-heading"><div><span class="section-kicker">Report Center</span><h1><?= e($menuTitle) ?> Reports</h1><p>Choose how you want to open this report.</p></div><div class="management-icon"><i class="fa-solid <?= $reportSection==='followup'?'fa-clipboard-check':'fa-chart-line' ?>"></i></div></div>
    <div class="report-subnav"><a class="secondary-button" href="<?= e(app_url('reports.php?report='.$reportSection.$reportsContext)) ?>"><i class="fa-solid fa-arrow-left"></i><span><?= $reportSection==='followup'?'Follow-up':'Visits' ?></span></a></div>
    <div class="report-mode-actions report-mode-actions--menu">
        <a class="report-mode-button" href="<?= e(app_url('reports.php?'.$menuBase.'&mode=lookup'.$reportsContext)) ?>"><i class="fa-solid fa-list-check"></i><span>Lookup</span></a>
        <a class="report-mode-button" href="<?= e(app_url('reports.php?'.$menuBase.'&mode=type'.$reportsContext)) ?>"><i class="fa-solid fa-keyboard"></i><span>Quick Search</span></a>
    </div>
</section>

<?php else: ?>
<section class="management-panel management-panel--table" data-report-listing>
    <?php $reportTitle=$reportSection==='followup'?(string)$selectedDestination['destination_name'].' Follow-up':($allDestinations?'All Visits':(string)$selectedDestination['destination_name']); $reportBack=$allDestinations?'reports.php?report=visits':'reports.php?report='.$reportSection.'&destination_id='.$destinationId; $reportBackLabel=$reportSection==='followup'?'Follow-up':'Visits'; ?>
    <div class="management-heading"><div><span class="section-kicker">Report Center</span><h1><?= e($reportTitle) ?> Reports</h1><p><?= $allDestinations?'Review visits across every destination.':'Review records for this selection only.' ?></p></div><div class="management-icon"><i class="fa-solid <?= $reportSection==='followup'?'fa-clipboard-check':'fa-chart-line' ?>"></i></div></div>
    <div class="report-subnav"><a class="secondary-button" href="<?= e(app_url($reportBack)) ?>"><i class="fa-solid fa-arrow-left"></i><span><?= e($reportBackLabel) ?></span></a></div>

    <form class="report-filter-form" data-report-filter-form <?= $mode === 'type' ? 'data-report-require-filter' : '' ?>>
        <?php if ($mode === 'type'): ?>
        <label class="report-search-field" for="report_search"><i class="fa-solid fa-magnifying-glass"></i><input id="report_search" type="search" placeholder="Type trip code or sales reference..." autocomplete="off" data-report-search></label>
        <?php else: ?>
        <div class="report-filter-panel">
            <div class="form-field"><label for="report_region">Region</label><select id="report_region" data-location-region-select data-popup-select data-popup-search><option value="">All regions</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e($regionKey)?>"><?=e($regionName)?></option><?php endforeach;?></select></div>
            <div class="form-field"><label for="location_id">Town</label><select id="location_id" name="location_id" data-location-town-select data-report-lookup><option value="">All towns</option><?php foreach($towns as $town):?><option value="<?=(int)$town['id']?>" data-region-key="<?=e((string)($town['region_code']?:$town['region_name']))?>" data-mmda-name="<?=e((string)$town['mmda_name'])?>"><?=e((string)$town['town_name'])?><?= (int)$town['is_capital']===1?' *':'' ?></option><?php endforeach;?></select><small data-location-mmda-output></small></div>
            <div class="form-field report-date-field"><label for="date_from">Date From</label><input id="date_from" name="date_from" type="date" data-report-date-filter></div>
            <div class="form-field report-date-field"><label for="date_to">Date To</label><input id="date_to" name="date_to" type="date" data-report-date-filter></div>
        </div>
        <?php endif; ?>
        <button class="report-filter-clear is-hidden" type="button" data-report-filter-clear>Clear</button>
    </form>

    <p class="report-result-count" data-report-results-count>0 results found</p>
    <p class="empty-state" data-report-empty-state data-initial-message="Search or select a filter to view records." data-no-match-message="No records match these filters.">Search or select a filter to view records.</p>
    <?php if ($rows): ?>
    <div class="table-wrap is-hidden" data-report-results><table class="data-table data-table--report-retailers"><thead><tr><th>Date</th><th>Name</th><th>Visit ID</th><th>Area</th><th>Number</th><th>Sales Status</th></tr></thead><tbody>
        <?php foreach ($rows as $visit):
            $displayName=(string)($visit['owner_name']?:$visit['company_name']?:'Visit');
            $number=(string)($visit['phone']?:$visit['other_phone']?:'');
            $area=trim((string)($visit['area']??'').' '.(string)($visit['town_name']??'').' '.(string)($visit['region_name']??''));
            $methodLabel=$visit['visit_type']==='follow_up'?ucwords(str_replace('_',' ',(string)($visit['follow_up_method']?:'physical_visit'))):'';
            $followupCount=(int)($visit['followup_count']??0);
            $search=implode(' ',[$visit['trip_code']??'',$visit['visit_ref']??'',$displayName,$visit['owner_name']??'',$number,$visit['sales_ref']??'',$area,$visit['destination_name']??'',$methodLabel]);
            $reportDate=$reportSection==='followup'&&$visit['follow_up_at']?substr((string)$visit['follow_up_at'],0,10):substr((string)$visit['created_at'],0,10);
            $sold=customer_has_completed_pos_sale((int)($visit['customer_id']??0));
            $listingUrl=($visit['report_source']??'normalized')==='legacy'
                ? ((string)$visit['visit_type']==='registration'
                    ? app_url('legacy-customer-location.php?source=destination_visit&id='.(int)$visit['id'].'&return_to='.rawurlencode($reportReturnUrl))
                    : app_url('visit-details.php?id='.(int)$visit['id'].'&return_to='.rawurlencode($reportReturnUrl)))
                : app_url('normalized-visit-details.php?id='.(int)$visit['id'].($reportSection==='followup'?'&view=followup':'').'&return_to='.rawurlencode($reportReturnUrl));
        ?>
        <tr class="customer-sales-row <?=$sold?'is-sold':'is-unsold'?>" data-customer-sales-row data-clickable-listing data-listing-url="<?=e($listingUrl)?>" data-report-filter-item data-report-search="<?=e($search)?>" data-destination-id="<?=e((string)($visit['destination_id']??$destinationId))?>" data-location-id="<?=e((string)($visit['location_id']??''))?>" data-report-date="<?=e($reportDate)?>">
            <td><?=e(date('d M Y',strtotime($reportDate)))?></td>
            <td><strong><?=e($displayName)?></strong></td>
            <td><strong><?=e((string)$visit['visit_ref'])?></strong></td>
            <td><?=e((string)($visit['area']??''))?> <span class="muted-text"><?=e(trim((string)($visit['town_name']??'').', '.(string)($visit['region_name']??''),', '))?></span></td><td><?=e($number)?></td><td><span class="status-badge <?=$sold?'is-success':'is-warning'?>"><?=$sold?'Yes':'No'?></span></td>
        </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
