<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('registration_records');
ensure_sales_trip_assignment_schema();
ensure_places_management_schema();

$locationsOnly=(string)($_GET['view']??'')==='locations';
$customersOnly=(string)($_GET['view']??'')==='customers';
$customersPage=$customersOnly&&basename((string)($_SERVER['SCRIPT_NAME']??''))==='customers.php';
$standaloneReport=$locationsOnly||$customersOnly;
$standalonePermission=$locationsOnly?'marketing_report_location':($customersOnly?'marketing_report_customer':'');
if($standalonePermission!==''&&!can_access_menu_item($standalonePermission)&&!($customersPage&&can_access_menu_item('marketing_customer'))){header('Location: '.app_url($customersPage?'marketing.php?view=trip':'marketing.php?view=reports'));exit;}
$tab = $locationsOnly?'places':($customersOnly?'completed':(string)($_GET['tab'] ?? 'all'));
$tab = in_array($tab, ['all','drafts', 'completed', 'places'], true) ? $tab : 'all';
$search = trim((string)($_GET['q'] ?? ''));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : '';
$requestedReturnTo=trim((string)($_GET['return_to']??''));$allowedReturnBases=[app_url('admin-customers.php'),app_url('vendor-customers.php')];$returnTo='';foreach($allowedReturnBases as $allowedReturnBase){if($requestedReturnTo===$allowedReturnBase||str_starts_with($requestedReturnTo,$allowedReturnBase.'?')){$returnTo=$requestedReturnTo;break;}}$defaultBackUrl=app_url('marketing-trip.php');$backUrl=$returnTo!==''?$returnTo:$defaultBackUrl;$backLabel=$returnTo!==''?'Create Customers':'Marketing Trip';
$userId = (int)current_user_id();
$staffId = current_staff_id() ?: 0;
$vendorId = (int)(current_vendor_profile()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'delete_draft') {
    $deleteId = max(0, (int)($_POST['draft_id'] ?? 0));
    if (verify_csrf_token((string)($_POST['csrf_token'] ?? '')) && $deleteId > 0) {
        $statement = db()->prepare(
            'SELECT ps.sales_trip_id,d.recorded_by_user_id,d.customer_id FROM customer_visit_drafts d
             INNER JOIN place_visit_sessions ps ON ps.id=d.place_session_id WHERE d.id=?'
        );
        $statement->execute([$deleteId]);
        $deleteDraft = $statement->fetch();
        $deleteTripId = (int)($deleteDraft['sales_trip_id'] ?? 0);
        if ((int)($deleteDraft['recorded_by_user_id'] ?? 0) === $userId || can_access_registration_trip($deleteTripId)) {
            $draftCustomerId=(int)($deleteDraft['customer_id']??0);
            db()->beginTransaction();
            db()->prepare('DELETE FROM customer_visit_drafts WHERE id=?')->execute([$deleteId]);
            if($draftCustomerId>0){db()->prepare("DELETE FROM customers WHERE id=? AND record_status='draft' AND NOT EXISTS(SELECT 1 FROM visits WHERE customer_id=?)")->execute([$draftCustomerId,$draftCustomerId]);}
            db()->commit();
            $deleteRedirectParams=['tab'=>'drafts','deleted'=>1];if($search!=='')$deleteRedirectParams['q']=$search;if($dateFrom!=='')$deleteRedirectParams['date_from']=$dateFrom;if($dateTo!=='')$deleteRedirectParams['date_to']=$dateTo;if($returnTo!=='')$deleteRedirectParams['return_to']=$returnTo;header('Location: '.app_url('registration-records.php?'.http_build_query($deleteRedirectParams)));
            exit;
        }
    }
    http_response_code(403);
    exit('The draft could not be deleted.');
}

$tripAccess = is_admin_user()
    ? '1=1'
    : '(st.recorded_by_user_id=? OR st.vendor_id=? OR EXISTS (SELECT 1 FROM sales_trip_staff_assignments sta WHERE sta.sales_trip_id=st.id AND sta.staff_id=?) OR EXISTS (SELECT 1 FROM sales_trip_vendor_assignments vta WHERE vta.sales_trip_id=st.id AND vta.vendor_id=?))';
$accessParams = is_admin_user() ? [] : [$userId, $vendorId, $staffId, $vendorId];
$like = '%' . $search . '%';

$draftSql =
    "SELECT d.id,d.draft_ref,d.draft_payload,d.created_at,ps.sales_trip_id,
            p.bus_loc_ref,p.business_name,COALESCE(st.trip_code,'ADDENDUM') AS trip_code,u.full_name AS created_by
     FROM customer_visit_drafts d
     INNER JOIN place_visit_sessions ps ON ps.id=d.place_session_id
     INNER JOIN business_locations p ON p.id=ps.bus_loc_id
     LEFT JOIN sales_trips st ON st.id=ps.sales_trip_id
     LEFT JOIN users u ON u.id=d.recorded_by_user_id
     WHERE ".(is_admin_user() ? '1=1' : "(d.recorded_by_user_id=? OR $tripAccess)");
$draftParams = is_admin_user() ? [] : [$userId, ...$accessParams];
if ($search !== '') {
    $draftSql .= ' AND (d.draft_ref LIKE ? OR d.draft_payload LIKE ? OR p.business_name LIKE ? OR st.trip_code LIKE ?)';
    array_push($draftParams, $like, $like, $like, $like);
}
if ($dateFrom !== '') {$draftSql .= ' AND DATE(d.created_at)>=?'; $draftParams[] = $dateFrom;}
if ($dateTo !== '') {$draftSql .= ' AND DATE(d.created_at)<=?'; $draftParams[] = $dateTo;}
$draftSql .= ' ORDER BY d.id DESC';
$statement = db()->prepare($draftSql);
$statement->execute($draftParams);
$drafts = $statement->fetchAll();

$completedSql =
    "SELECT v.id,v.visit_ref,v.visit_date,v.created_at,v.sales_trip_id,c.id AS customer_id,c.customer_ref,c.customer_name,p.id AS bus_loc_id,
            c.phone,c.other_phone,p.bus_loc_ref,p.business_name,p.is_legacy_placeholder,
            COALESCE(st.trip_code,'ADDENDUM') AS trip_code,u.full_name AS created_by
     FROM visits v
     INNER JOIN customers c ON c.id=v.customer_id
     LEFT JOIN job_types jt ON jt.id=c.job_type_id
     INNER JOIN business_locations p ON p.id=v.bus_loc_id
     LEFT JOIN sales_trips st ON st.id=v.sales_trip_id
     LEFT JOIN users u ON u.id=v.recorded_by_user_id
     WHERE v.record_status='completed'
       AND c.master_customer_id IS NULL
       AND COALESCE(LOWER(NULLIF(jt.job_type_name,'')),LOWER(NULLIF(c.job_type,'')),'')<>'apprentice'
       AND ".(is_admin_user() ? '1=1' : "(v.vendor_id=? OR v.recorded_by_user_id=? OR $tripAccess)");
$completedParams = is_admin_user() ? [] : [$vendorId, $userId, ...$accessParams];
if ($search !== '') {
    $completedSql .= ' AND (v.visit_ref LIKE ? OR c.customer_ref LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ? OR c.other_phone LIKE ? OR p.business_name LIKE ? OR st.trip_code LIKE ?)';
    array_push($completedParams, $like, $like, $like, $like, $like, $like, $like);
}
if ($dateFrom !== '') {$completedSql .= ' AND DATE(v.created_at)>=?'; $completedParams[] = $dateFrom;}
if ($dateTo !== '') {$completedSql .= ' AND DATE(v.created_at)<=?'; $completedParams[] = $dateTo;}
$completedSql .= ' ORDER BY v.id DESC';
$statement = db()->prepare($completedSql);
$statement->execute($completedParams);
$completed = $statement->fetchAll();
foreach ($completed as &$completedRow) { $completedRow['record_source']='normalized_visit'; $completedRow['source_id']=(int)$completedRow['id']; $completedRow['can_edit']=1; }
unset($completedRow);

// Standalone vendor registrations and legacy vendor customers live outside the
// normalized visits tables. Include them here so the vendor's record list and
// customer report describe the same customer population.
if (is_admin_user() || (current_user_role()==='vendor' && $vendorId>0)) {
    $vendorVisitSql = "SELECT dv.id,dv.created_at,dv.sales_trip_id,dv.company_name,dv.owner_name,dv.phone,dv.other_phone,
            dv.location_id,dv.area,dv.vendor_id,l.town_name,u.full_name AS created_by,COALESCE(st.trip_code,'STANDALONE') AS trip_code
        FROM destination_visits dv
        LEFT JOIN locations l ON l.id=dv.location_id
        LEFT JOIN users u ON u.id=dv.recorded_by_user_id
        LEFT JOIN sales_trips st ON st.id=dv.sales_trip_id
        WHERE dv.visit_type='registration' AND dv.record_status='completed' AND dv.normalized_customer_id IS NULL
          AND NOT EXISTS (SELECT 1 FROM customers nc WHERE COALESCE(NULLIF(nc.phone,''),NULLIF(nc.other_phone,''),'#') IN (COALESCE(NULLIF(dv.phone,''),'!'),COALESCE(NULLIF(dv.other_phone,''),'?')))
          AND ".(is_admin_user()?'1=1':'(dv.vendor_id=? OR st.vendor_id=? OR EXISTS (SELECT 1 FROM sales_trip_vendor_assignments vta WHERE vta.sales_trip_id=dv.sales_trip_id AND vta.vendor_id=?))');
    $vendorVisitParams=is_admin_user()?[]:[$vendorId,$vendorId,$vendorId];
    if($search!==''){$vendorVisitSql.=' AND (dv.company_name LIKE ? OR dv.owner_name LIKE ? OR dv.phone LIKE ? OR dv.other_phone LIKE ? OR dv.area LIKE ? OR l.town_name LIKE ? OR st.trip_code LIKE ?)';array_push($vendorVisitParams,$like,$like,$like,$like,$like,$like,$like);}
    if($dateFrom!==''){$vendorVisitSql.=' AND DATE(dv.created_at)>=?';$vendorVisitParams[]=$dateFrom;}
    if($dateTo!==''){$vendorVisitSql.=' AND DATE(dv.created_at)<=?';$vendorVisitParams[]=$dateTo;}
    $vendorVisitStatement=db()->prepare($vendorVisitSql);$vendorVisitStatement->execute($vendorVisitParams);
    foreach($vendorVisitStatement->fetchAll() as $row){$name=trim((string)($row['company_name']?:$row['owner_name']))?:'Customer';$completed[]=['id'=>(int)$row['id'],'source_id'=>(int)$row['id'],'record_source'=>'destination_visit','can_edit'=>1,'visit_ref'=>'VS-'.(int)$row['id'],'visit_date'=>substr((string)$row['created_at'],0,10),'created_at'=>(string)$row['created_at'],'sales_trip_id'=>(int)($row['sales_trip_id']??0),'customer_id'=>0,'customer_ref'=>'','customer_name'=>$name,'phone'=>(string)($row['phone']??''),'other_phone'=>(string)($row['other_phone']??''),'bus_loc_id'=>0,'bus_loc_ref'=>'','business_name'=>trim((string)($row['area']??'').' / '.(string)($row['town_name']??''),' /'),'is_legacy_placeholder'=>0,'trip_code'=>(string)$row['trip_code'],'created_by'=>(string)($row['created_by']??'')];}

    $legacySql="SELECT vc.*,l.town_name,u.full_name AS created_by FROM vendor_customers vc LEFT JOIN locations l ON l.id=vc.location_id LEFT JOIN users u ON u.id=vc.created_by_user_id WHERE ".(is_admin_user()?'1=1':'vc.vendor_id=?')." AND vc.normalized_customer_id IS NULL AND NOT EXISTS(SELECT 1 FROM customers nc WHERE COALESCE(NULLIF(nc.phone,''),NULLIF(nc.other_phone,''),'#') IN (COALESCE(NULLIF(vc.phone,''),'!'),COALESCE(NULLIF(vc.other_phone,''),'?')))";$legacyParams=is_admin_user()?[]:[$vendorId];
    if($search!==''){$legacySql.=' AND (vc.customer_name LIKE ? OR vc.contact_name LIKE ? OR vc.phone LIKE ? OR vc.other_phone LIKE ? OR vc.area LIKE ? OR l.town_name LIKE ?)';array_push($legacyParams,$like,$like,$like,$like,$like,$like);}
    if($dateFrom!==''){$legacySql.=' AND DATE(vc.created_at)>=?';$legacyParams[]=$dateFrom;}
    if($dateTo!==''){$legacySql.=' AND DATE(vc.created_at)<=?';$legacyParams[]=$dateTo;}
    $legacyStatement=db()->prepare($legacySql);$legacyStatement->execute($legacyParams);
    foreach($legacyStatement->fetchAll() as $row){$completed[]=['id'=>(int)$row['id'],'source_id'=>(int)$row['id'],'record_source'=>'vendor_customer','can_edit'=>1,'visit_ref'=>'CUSTOMER-'.(int)$row['id'],'visit_date'=>substr((string)$row['created_at'],0,10),'created_at'=>(string)$row['created_at'],'sales_trip_id'=>0,'customer_id'=>(int)$row['id'],'customer_ref'=>'','customer_name'=>(string)$row['customer_name'],'phone'=>(string)$row['phone'],'other_phone'=>(string)($row['other_phone']??''),'bus_loc_id'=>0,'bus_loc_ref'=>'','business_name'=>trim((string)($row['area']??'').' / '.(string)($row['town_name']??''),' /'),'is_legacy_placeholder'=>0,'trip_code'=>'STANDALONE','created_by'=>(string)($row['created_by']??'')];}
    usort($completed,static fn(array $a,array $b):int=>strcmp((string)$b['created_at'],(string)$a['created_at']));
}
$allRegistrations=[];
foreach($drafts as $draftRow){$draftPayload=json_decode((string)$draftRow['draft_payload'],true)?:[];$allRegistrations[]=['record_type'=>'draft','sort_at'=>(string)$draftRow['created_at'],'id'=>(int)$draftRow['id'],'registration_ref'=>(string)$draftRow['draft_ref'],'customer_ref'=>'','customer_name'=>trim((string)($draftPayload['customer_name']??''))?:'Incomplete customer','phone'=>(string)($draftPayload['phone']??$draftPayload['other_phone']??''),'business_name'=>(string)$draftRow['business_name'],'bus_loc_ref'=>(string)$draftRow['bus_loc_ref'],'trip_code'=>(string)$draftRow['trip_code'],'created_by'=>(string)$draftRow['created_by'],'is_legacy_placeholder'=>0];}
foreach($completed as $completedRow){$allRegistrations[]=['record_type'=>'completed','record_source'=>(string)($completedRow['record_source']??'normalized_visit'),'can_edit'=>(int)($completedRow['can_edit']??1),'sort_at'=>(string)$completedRow['created_at'],'id'=>(int)$completedRow['id'],'source_id'=>(int)($completedRow['source_id']??$completedRow['id']),'registration_ref'=>(string)$completedRow['visit_ref'],'customer_ref'=>(string)$completedRow['customer_ref'],'customer_name'=>(string)$completedRow['customer_name'],'phone'=>(string)($completedRow['phone']?:$completedRow['other_phone']),'business_name'=>(string)$completedRow['business_name'],'bus_loc_ref'=>(string)$completedRow['bus_loc_ref'],'trip_code'=>(string)$completedRow['trip_code'],'created_by'=>(string)$completedRow['created_by'],'is_legacy_placeholder'=>(int)$completedRow['is_legacy_placeholder'],'bus_loc_id'=>(int)$completedRow['bus_loc_id']];}
usort($allRegistrations,static fn(array $a,array $b):int=>strcmp($b['sort_at'],$a['sort_at']));

$placesSql =
    "SELECT DISTINCT p.id,p.bus_loc_ref,p.business_name,p.area,p.google_location,p.created_at,
            l.town_name,d.destination_name,u.full_name AS created_by,
            CASE WHEN NULLIF(TRIM(p.business_name),'') IS NULL OR p.destination_id IS NULL
                       OR p.location_id IS NULL OR NULLIF(TRIM(p.area),'') IS NULL
                       OR NULLIF(TRIM(p.google_location),'') IS NULL
                 THEN 1 ELSE 0 END AS is_incomplete
     FROM business_locations p
     LEFT JOIN locations l ON l.id=p.location_id
     LEFT JOIN destinations d ON d.id=p.destination_id
     LEFT JOIN users u ON u.id=p.created_by_user_id
     WHERE p.is_active=1 AND p.is_legacy_placeholder=0 AND ".(is_admin_user() ? '1=1' : "(p.created_by_user_id=? OR EXISTS (
        SELECT 1 FROM place_visit_sessions ps
        LEFT JOIN sales_trips st ON st.id=ps.sales_trip_id
        WHERE ps.bus_loc_id=p.id AND $tripAccess
     ))");
$placesParams = is_admin_user() ? [] : [$userId, ...$accessParams];
if ($search !== '') {
    $placesSql .= ' AND (p.bus_loc_ref LIKE ? OR p.business_name LIKE ? OR p.area LIKE ? OR l.town_name LIKE ?)';
    array_push($placesParams, $like, $like, $like, $like);
}
if ($dateFrom !== '') {$placesSql .= ' AND DATE(p.created_at)>=?'; $placesParams[] = $dateFrom;}
if ($dateTo !== '') {$placesSql .= ' AND DATE(p.created_at)<=?'; $placesParams[] = $dateTo;}
$placesSql .= ' ORDER BY is_incomplete DESC,p.id DESC';
$statement = db()->prepare($placesSql);
$statement->execute($placesParams);
$places = $statement->fetchAll();

$pageTitle = $locationsOnly?'Locations':($customersOnly?'Customers':'Registration Records');
$savedStatus = (string)($_GET['status'] ?? '');
$savedName = trim((string)($_GET['customer_name'] ?? '')) ?: 'Customer';
$reportReturnUrl=$standaloneReport?requested_return_url(app_url($customersPage?'normalized-customer.php?stage=new-place&menu=customer':'marketing.php?view=reports')):'';
$recordsRoute=$customersPage?'customers.php':'registration-records.php';$recordsParams=['tab'=>$tab];if($standaloneReport){unset($recordsParams['tab']);if(!$customersPage)$recordsParams['view']=$locationsOnly?'locations':'customers';$recordsParams['return_to']=$reportReturnUrl;}if($search!=='')$recordsParams['q']=$search;if($dateFrom!=='')$recordsParams['date_from']=$dateFrom;if($dateTo!=='')$recordsParams['date_to']=$dateTo;if(!$standaloneReport&&$returnTo!=='')$recordsParams['return_to']=$returnTo;$recordsCurrentUrl=app_url($recordsRoute.'?'.http_build_query($recordsParams));$tabUrl=static function(string $targetTab)use($search,$dateFrom,$dateTo,$returnTo):string{$params=['tab'=>$targetTab];if($search!=='')$params['q']=$search;if($dateFrom!=='')$params['date_from']=$dateFrom;if($dateTo!=='')$params['date_to']=$dateTo;if($returnTo!=='')$params['return_to']=$returnTo;return app_url('registration-records.php?'.http_build_query($params));};
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>$customersPage?'Customers':'Registration Records']];
$internalBackUrl=$standaloneReport?$reportReturnUrl:requested_return_url(app_url('index.php'));
require __DIR__ . '/../includes/header.php';
?>
<section class="management-panel registration-records-panel">
    <div class="management-heading"><div><span class="section-kicker"><?=$customersPage?'Marketing Customer':($standaloneReport?'Marketing Reports':'Registration workspace')?></span><h1><?=$locationsOnly?'Locations':($customersOnly?'Customers':'Registration Records')?></h1></div><div class="management-icon"><i class="fa-solid <?=$locationsOnly?'fa-location-dot':($customersOnly?'fa-users':'fa-address-card')?>"></i></div></div>
    <?php if($savedStatus==='completed'):?><div class="profile-message is-success"><?=e($savedName)?> saved successfully.</div><?php elseif($savedStatus==='draft'):?><div class="profile-message is-success"><?=e($savedName)?> saved as draft.</div><?php endif;?>
    <?php if(!$standaloneReport):?><div class="registration-records-back"><a href="<?=e($backUrl)?>"><i class="fa-solid fa-arrow-left"></i><span><?=e($backLabel)?></span></a></div><?php endif;?>
    <form class="filter-bar" method="get" data-live-record-filter>
        <?php if($standaloneReport):?><input type="hidden" name="view" value="<?=$locationsOnly?'locations':'customers'?>"><input type="hidden" name="return_to" value="<?=e($reportReturnUrl)?>"><?php else:?><input type="hidden" name="tab" value="<?=e($tab)?>"><?php if($returnTo!==''):?><input type="hidden" name="return_to" value="<?=e($returnTo)?>"><?php endif;?><?php endif;?>
        <label class="form-field"><span><?=$locationsOnly?'Search locations':($customersOnly?'Search customers':'Search records')?></span><input type="search" name="q" value="<?=e($search)?>" placeholder="<?=$locationsOnly?'Location, town, area or reference':($customersOnly?'Customer, phone, reference or location':'Name, phone, reference, trip or location')?>"></label>
        <div class="date-range-row"><label class="form-field"><span>Date from</span><input type="date" name="date_from" value="<?=e($dateFrom)?>"></label><label class="form-field"><span>Date to</span><input type="date" name="date_to" value="<?=e($dateTo)?>"></label></div>
    </form>
    <?php if($standaloneReport):?><?php $standaloneCount=$locationsOnly?count($places):count($completed);?><div class="marketing-notes-result-bar"><span><i class="fa-solid <?=$locationsOnly?'fa-location-dot':'fa-users'?>"></i></span><p data-separate-listing-count><strong><?=number_format($standaloneCount)?></strong> <?=$locationsOnly?($standaloneCount===1?'location':'locations'):($standaloneCount===1?'customer':'customers')?> found</p></div><?php endif;?>
    <?php if(!$standaloneReport):?><nav class="record-tabs" aria-label="Registration record sections">
        <a class="<?=$tab==='all'?'is-active':''?>" href="<?=e($tabUrl('all'))?>">All <span><?=count($allRegistrations)?></span></a>
        <a class="<?=$tab==='completed'?'is-active':''?>" href="<?=e($tabUrl('completed'))?>">Completed <span><?=count($completed)?></span></a>
        <a class="<?=$tab==='drafts'?'is-active':''?>" href="<?=e($tabUrl('drafts'))?>">Drafts <span><?=count($drafts)?></span></a>
        <a class="<?=$tab==='places'?'is-active':''?>" href="<?=e($tabUrl('places'))?>">Locations <span><?=count($places)?></span></a>
    </nav><?php endif;?>

    <div class="table-wrap registration-records-table-wrap"><table class="data-table registration-records-table<?=$customersOnly?' customer-clickable-list':''?>" data-record-tab="<?=e($tab)?>"><thead>
    <?php if($tab==='all'): ?><tr><th>Registration</th><th>Customer</th><th>Phone</th><th>Business Location</th><th>Location Ref</th><th>Trip</th><th>Status</th><th>Created by</th><th>Action</th></tr>
    <?php elseif($tab==='drafts'): ?><tr><th>Draft</th><th>Customer</th><th>Business Location</th><th>Location Ref</th><th>Trip</th><th>Created by</th><th>Action</th></tr>
    <?php elseif($tab==='completed'): ?><tr><th>Registration</th><th>Customer</th><th>Phone</th><th>Business Location</th><th>Location Ref</th><th>Trip</th><th>Created by</th><th>Action</th></tr>
    <?php else: ?><tr><th>Location</th><th>Town / Area</th><th>Destination</th><th>Created by</th><th>Status</th><th>Action</th></tr><?php endif; ?>
    </thead><tbody>
    <?php if($tab==='all'): foreach($allRegistrations as $row): $isDraft=$row['record_type']==='draft';$legacyAssignUrl=!$isDraft&&in_array((string)($row['record_source']??''),['destination_visit','vendor_customer'],true)?app_url('legacy-customer-location.php?source='.(string)$row['record_source'].'&id='.(int)$row['source_id'].'&return_to='.rawurlencode($recordsCurrentUrl)):''; ?>
        <tr><td data-label="Registration"><strong><?=e($row['registration_ref'])?></strong><?php if($row['customer_ref']!==''):?><span class="muted-text"><?=e($row['customer_ref'])?></span><?php endif;?></td><td data-label="Customer"><?=e($row['customer_name'])?></td><td data-label="Phone"><?=e($row['phone'])?></td><td data-label="Business Location"><?=(int)$row['is_legacy_placeholder']===1?'No location assigned':e(trim((string)$row['business_name'])?:'Unnamed location')?></td><td data-label="Location Ref"><?=(int)$row['is_legacy_placeholder']===1?'—':e((string)$row['bus_loc_ref'])?></td><td data-label="Trip"><?=e($row['trip_code'])?></td><td data-label="Status"><span class="status-pill <?=$isDraft?'status-pill--warning':'status-pill--success'?>"><?=$isDraft?'Draft':'Completed'?></span></td><td data-label="Created by"><?=e($row['created_by']?:'Unknown')?></td><td data-label="Action"><div class="table-actions"><?php if($isDraft):?><a class="secondary-button secondary-button--small" href="<?=e(app_url('registration-edit.php?type=draft&id='.(int)$row['id'].'&return_to='.rawurlencode($recordsCurrentUrl)))?>">Continue</a><?php elseif(($row['record_source']??'normalized_visit')==='vendor_customer'):?><a class="secondary-button secondary-button--small" href="<?=e(app_url('vendor-customer-edit.php?id='.(int)$row['source_id']))?>">Edit</a><?php elseif(($row['record_source']??'normalized_visit')==='destination_visit'&&(int)($row['can_edit']??0)===1):?><a class="secondary-button secondary-button--small" href="<?=e(app_url('visit-edit.php?id='.(int)$row['source_id'].'&return_to='.rawurlencode($recordsCurrentUrl)))?>">Edit</a><?php elseif(($row['record_source']??'normalized_visit')==='normalized_visit'):?><a class="secondary-button secondary-button--small" href="<?=e(app_url('registration-edit.php?type=completed&id='.(int)$row['id'].'&return_to='.rawurlencode($recordsCurrentUrl)))?>">Edit</a><?php else:?><span class="muted-text">View only</span><?php endif;?></div></td></tr>
    <?php endforeach; elseif($tab==='drafts'): foreach($drafts as $row): $payload=json_decode((string)$row['draft_payload'],true)?:[]; ?>
        <tr><td data-label="Draft"><strong><?=e($row['draft_ref'])?></strong><span class="muted-text"><?=e(date('d M Y',strtotime((string)$row['created_at'])))?></span></td><td data-label="Customer"><?=e(trim((string)($payload['customer_name']??''))?:'Incomplete customer')?><span class="muted-text"><?=e((string)($payload['phone']??''))?></span></td><td data-label="Business Location"><?=e(trim((string)$row['business_name'])?:'Unnamed location')?></td><td data-label="Location Ref"><?=e((string)$row['bus_loc_ref'])?></td><td data-label="Trip"><?=e($row['trip_code'])?></td><td data-label="Created by"><?=e((string)($row['created_by']?:'Unknown'))?></td><td data-label="Action"><div class="table-actions"><a class="secondary-button secondary-button--small" href="<?=e(app_url('registration-edit.php?type=draft&id='.(int)$row['id'].'&return_to='.rawurlencode($recordsCurrentUrl)))?>">Continue</a><form method="post" data-confirm-title="Delete draft" data-confirm-message="Delete <?=e($row['draft_ref'])?> permanently?"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="delete_draft"><input type="hidden" name="draft_id" value="<?=(int)$row['id']?>"><button class="icon-button icon-button--danger" type="submit" title="Delete draft" aria-label="Delete <?=e($row['draft_ref'])?>"><i class="fa-solid fa-trash"></i></button></form></div></td></tr>
    <?php endforeach; elseif($tab==='completed'): foreach($completed as $row): $legacyAssignUrl=in_array((string)($row['record_source']??''),['destination_visit','vendor_customer'],true)?app_url('legacy-customer-location.php?source='.(string)$row['record_source'].'&id='.(int)$row['source_id'].'&return_to='.rawurlencode($recordsCurrentUrl)):''; ?>
        <tr><td data-label="Registration"><strong><?=e($row['visit_ref'])?></strong><span class="muted-text"><?=e($row['customer_ref'])?></span></td><td data-label="Customer"><?=e($row['customer_name'])?><?php if((int)$row['is_legacy_placeholder']===1):?><span class="muted-text">Legacy customer</span><?php endif;?></td><td data-label="Phone"><?=e((string)($row['phone']?:$row['other_phone']))?></td><td data-label="Business Location"><?=(int)$row['is_legacy_placeholder']===1?'No location assigned':e(trim((string)$row['business_name'])?:'Unnamed location')?></td><td data-label="Location Ref"><?=(int)$row['is_legacy_placeholder']===1?'—':e((string)$row['bus_loc_ref'])?></td><td data-label="Trip"><?=e($row['trip_code'])?></td><td data-label="Created by"><?=e((string)($row['created_by']?:'Unknown'))?></td><td data-label="Action"><div class="table-actions"><?php if(($row['record_source']??'normalized_visit')==='vendor_customer'):?><a class="secondary-button secondary-button--small" href="<?=e(app_url('vendor-customer-edit.php?id='.(int)$row['source_id']))?>">Edit</a><?php elseif(($row['record_source']??'normalized_visit')==='destination_visit'&&(int)($row['can_edit']??0)===1):?><a class="secondary-button secondary-button--small" href="<?=e(app_url('visit-edit.php?id='.(int)$row['source_id'].'&return_to='.rawurlencode($recordsCurrentUrl)))?>">Edit</a><?php elseif(($row['record_source']??'normalized_visit')==='normalized_visit'):?><?php if((int)$row['is_legacy_placeholder']===1):?><a class="secondary-button secondary-button--small" href="<?=e(app_url('place-details.php?id='.(int)$row['bus_loc_id'].'&edit=1&customer_id='.(int)$row['customer_id'].'&return_to='.rawurlencode($recordsCurrentUrl)))?>">Assign Location</a><?php endif;?><a class="secondary-button secondary-button--small" href="<?=e(app_url('registration-edit.php?type=completed&id='.(int)$row['id'].'&return_to='.rawurlencode($recordsCurrentUrl)))?>">Edit</a><?php else:?><span class="muted-text">View only</span><?php endif;?></div></td></tr>
    <?php endforeach; else: foreach($places as $row): ?>
        <tr><td data-label="Location"><strong><?=e(trim((string)$row['business_name'])?:'Incomplete Location')?></strong><span class="muted-text"><?=e($row['bus_loc_ref'])?></span></td><td data-label="Town / Area"><?=e(trim((string)($row['town_name']??'').' / '.(string)($row['area']??''),' /')?:'Not completed')?></td><td data-label="Destination"><?=e((string)($row['destination_name']?:'Not completed'))?></td><td data-label="Created by"><?=e((string)($row['created_by']?:'Unknown'))?></td><td data-label="Status"><span class="status-pill <?=$row['is_incomplete']?'status-pill--warning':'status-pill--success'?>"><?=$row['is_incomplete']?'Incomplete':'Completed'?></span></td><td data-label="Action"><a class="secondary-button secondary-button--small" href="<?=e(app_url('place-details.php?id='.(int)$row['id'].'&edit=1&return_to='.rawurlencode($recordsCurrentUrl)))?>">Edit</a></td></tr>
    <?php endforeach; endif; ?>
    <?php $empty=($tab==='all'&&!$allRegistrations)||($tab==='drafts'&&!$drafts)||($tab==='completed'&&!$completed)||($tab==='places'&&!$places); if($empty): ?><tr><td colspan="<?=$tab==='places'?6:($tab==='drafts'?7:($tab==='all'?9:8))?>" class="empty-state">No accessible records match this view.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<script>
function enhanceCustomerRecordLinks() {
    const isCustomersPage = <?=json_encode($customersOnly)?>;
    document.querySelectorAll('.registration-records-table tbody tr').forEach(function (row) {
        const actions = row.querySelector('[data-label="Action"] .table-actions');
        const customerCell = row.querySelector('[data-label="Customer"]');
        if (!actions || !customerCell || actions.querySelector('[data-customer-view]')) return;
        const editLink = Array.from(actions.querySelectorAll('a')).find(function (link) {
            return /(?:registration-edit|visit-edit|vendor-customer-edit)\.php/.test(link.href);
        });
        if (!editLink || editLink.textContent.trim() === 'Continue') return;
        const editUrl = new URL(editLink.href, window.location.href);
        let source = 'normalized_visit';
        if (editUrl.pathname.endsWith('/visit-edit.php')) source = 'destination_visit';
        if (editUrl.pathname.endsWith('/vendor-customer-edit.php')) source = 'vendor_customer';
        const id = editUrl.searchParams.get('id');
        if (!id) return;
        const detailsPage = source === 'normalized_visit'
            ? <?=json_encode(app_url('normalized-visit-details.php'))?>
            : (source === 'destination_visit'
                ? <?=json_encode(app_url('visit-details.php'))?>
                : <?=json_encode(app_url('vendor-customer-edit.php'))?>);
        const viewUrl = new URL(detailsPage, window.location.href);
        viewUrl.searchParams.set('id', id);
        const currentListUrl = new URL(window.location.href);
        const customerReturn = currentListUrl.pathname.endsWith('/customers.php') || currentListUrl.searchParams.get('view') === 'customers'
            ? currentListUrl.pathname + currentListUrl.search
            : <?=json_encode(app_url('customers.php?return_to='.rawurlencode(app_url('normalized-customer.php?stage=new-place&menu=customer'))))?>;
        viewUrl.searchParams.set('return_to', customerReturn);
        if (source === 'vendor_customer') {
            editUrl.searchParams.set('return_to', customerReturn);
            editLink.href = editUrl.toString();
        }
        const viewLink = document.createElement('a');
        viewLink.className = 'secondary-button secondary-button--small';
        viewLink.href = viewUrl.toString();
        viewLink.dataset.customerView = '';
        viewLink.innerHTML = '<i class="fa-solid fa-eye"></i><span>View</span>';
        actions.prepend(viewLink);
        if (isCustomersPage) {
            row.dataset.clickableListing = '';
            row.dataset.listingUrl = viewUrl.toString();
        }
        const name = customerCell.childNodes[0];
        if (name && name.nodeType === Node.TEXT_NODE && name.textContent.trim() !== '') {
            const nameLink = document.createElement('a');
            nameLink.href = viewUrl.toString();
            nameLink.textContent = name.textContent.trim();
            nameLink.style.fontWeight = '700';
            customerCell.replaceChild(nameLink, name);
        }
    });
    if (isCustomersPage && typeof setupClickableListings === 'function') setupClickableListings();
}
document.addEventListener('DOMContentLoaded', function () {
    enhanceCustomerRecordLinks();
    const filterForm = document.querySelector('[data-live-record-filter]');
    if (!filterForm) return;

    let activeRequest;
    const applyFilters = async function () {
        if (activeRequest) activeRequest.abort();
        activeRequest = new AbortController();

        const url = new URL(filterForm.action || window.location.href, window.location.href);
        url.search = new URLSearchParams(new FormData(filterForm)).toString();

        try {
            const response = await fetch(url.toString(), {
                signal: activeRequest.signal,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            if (!response.ok) throw new Error('Unable to filter records.');

            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextBody = page.querySelector('.registration-records-table tbody');
            const currentBody = document.querySelector('.registration-records-table tbody');
            const nextTabs = page.querySelector('.record-tabs');
            const currentTabs = document.querySelector('.record-tabs');
            const nextCount = page.querySelector('[data-separate-listing-count]');
            const currentCount = document.querySelector('[data-separate-listing-count]');

            if (nextBody && currentBody) {
                currentBody.innerHTML = nextBody.innerHTML;
                if (typeof setupEditOnlyTables === 'function') setupEditOnlyTables();
                enhanceCustomerRecordLinks();
            }
            if (nextTabs && currentTabs) currentTabs.innerHTML = nextTabs.innerHTML;
            if (nextCount && currentCount) currentCount.innerHTML = nextCount.innerHTML;
            window.history.replaceState({}, '', url.toString());
        } catch (error) {
            if (error.name !== 'AbortError') filterForm.submit();
        }
    };

    const searchInput = filterForm.querySelector('input[type="search"]');
    if (searchInput) searchInput.addEventListener('input', applyFilters);
    filterForm.querySelectorAll('input[type="date"]').forEach(function (input) {
        input.addEventListener('change', applyFilters);
    });
});
</script>
<?php require __DIR__ . '/../includes/footer.php';
