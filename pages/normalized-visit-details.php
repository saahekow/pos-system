<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_promo_plug_schema();
ensure_places_management_schema();
$requestedReturnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
$requestedReturnPath = (string)(parse_url($requestedReturnTo, PHP_URL_PATH) ?: '');
$marketingReportPaths = array_map(
    static fn(string $page): string => (string)(parse_url(app_url($page), PHP_URL_PATH) ?: app_url($page)),
    ['marketing-notes-report.php', 'marketing-promo-report.php']
);
$openedFromMarketingReport = in_array($requestedReturnPath, $marketingReportPaths, true);
if ($openedFromMarketingReport) {
    require_auth();
    $requiredMenuItem = $requestedReturnPath === $marketingReportPaths[0]
        ? 'marketing_report_notes'
        : 'marketing_report_promo';
    if (!can_access_menu_item($requiredMenuItem)) {
        http_response_code(403);
        exit('Access denied.');
    }
} else {
    $detailModule = (string)($_GET['view'] ?? '') === 'followup'
        ? 'reports'
        : (current_user_role() === 'vendor' ? 'vendor_customers' : 'sales_trip');
    require_module_access($detailModule);
}
ensure_job_type_schema();

$visitId = max(0, (int)($_GET['id'] ?? 0));
$statement = db()->prepare(
    "SELECT v.*,c.customer_ref,c.customer_name,c.phone,c.other_phone,c.job_type,c.job_type_id,c.customer_picture,
            c.vehicle_registration_no,c.vin_no,c.supervisor_name,c.supervisor_phone,
            p.bus_loc_ref,p.business_name,p.area,p.google_location,p.shop_picture,p.shop_picture_2,p.shop_video,p.is_legacy_placeholder,
            l.town_name,l.region_name,l.mmda_name AS district_name,d.destination_name,d.destination_key,sht.shop_type_name,
            ps.session_ref,cs.sale_record_ref,cs.sales_ref,cs.promo_plug,cs.sale_confirmed,cs.car_picture,
            staff.full_name AS staff_name,
            (SELECT n.feedback FROM visit_notes n WHERE n.visit_id=v.id ORDER BY n.id DESC LIMIT 1) feedback,
            (SELECT n.note FROM visit_notes n WHERE n.visit_id=v.id ORDER BY n.id DESC LIMIT 1) note
     FROM visits v
     INNER JOIN customers c ON c.id=v.customer_id
     INNER JOIN business_locations p ON p.id=v.bus_loc_id
     LEFT JOIN locations l ON l.id=p.location_id
     LEFT JOIN destinations d ON d.id=p.destination_id
     LEFT JOIN shop_types sht ON sht.id=p.shop_type_id
     LEFT JOIN place_visit_sessions ps ON ps.id=v.place_session_id
     LEFT JOIN customer_sales cs ON cs.visit_id=v.id
     LEFT JOIN staff ON staff.id=v.staff_id
     WHERE v.id=?"
);
$statement->execute([$visitId]);
$visit = $statement->fetch();
if (!$visit) {
    http_response_code(404);
    exit('Customer visit not found.');
}
$vendorProfileId = current_user_role() === 'vendor'
    ? (int)(current_vendor_profile()['id'] ?? 0)
    : 0;
$vendorCanView = $vendorProfileId > 0 && (
    (int)($visit['vendor_id'] ?? 0) === $vendorProfileId
    || ((int)($visit['sales_trip_id'] ?? 0) > 0 && can_access_registration_trip((int)$visit['sales_trip_id']))
);
if (current_user_role() === 'vendor' && !$vendorCanView) {
    http_response_code(403);
    exit('Access denied.');
}
$followupOnly = (string)($_GET['view'] ?? '') === 'followup' && (string)($visit['visit_type'] ?? '') === 'follow_up';
$isTaxi = destination_is_taxi_rank($visit);

$historyStatement = db()->prepare(
    "SELECT v.*,st.trip_code,st.trip_date,cs.sales_ref,cs.promo_plug,cs.sale_confirmed,
            staff.full_name AS staff_name,
            (SELECT n.feedback FROM visit_notes n WHERE n.visit_id=v.id ORDER BY n.id DESC LIMIT 1) feedback,
            (SELECT n.note FROM visit_notes n WHERE n.visit_id=v.id ORDER BY n.id DESC LIMIT 1) note
     FROM visits v
     LEFT JOIN sales_trips st ON st.id=v.sales_trip_id
     LEFT JOIN customer_sales cs ON cs.visit_id=v.id
     LEFT JOIN staff ON staff.id=v.staff_id
     WHERE v.customer_id=? ORDER BY COALESCE(v.follow_up_at,v.created_at),v.id"
);
$historyStatement->execute([(int)$visit['customer_id']]);
$history = $historyStatement->fetchAll();
foreach ($history as $historyIndex => &$historyItem) $historyItem['_history_number'] = $historyIndex + 1;
unset($historyItem);
if ($followupOnly) {
    $history = array_values(array_filter($history,static fn(array $item): bool => (int)$item['id'] === $visitId));
}
$displayName = (string)($visit['business_name'] ?: $visit['customer_name']);
$salesReference = trim((string)($visit['sales_ref'] ?? ''));
$purchaseCountStatement = db()->prepare("SELECT COUNT(*) FROM customer_pos_sale_vins WHERE customer_source='visit' AND record_id=? AND amount>0");
$purchaseCountStatement->execute([$visitId]);
$purchaseCount = (int)$purchaseCountStatement->fetchColumn();
$isSold = customer_has_completed_pos_sale((int)($visit['customer_id']??0));
$returnTo = $requestedReturnTo;
$returnParts = $returnTo !== '' ? parse_url($returnTo) : false;
$allowedReturnPaths = array_map(static function (string $url): string { return (string)(parse_url($url, PHP_URL_PATH) ?: $url); }, [app_url('reports.php'), app_url('registration-records.php'), app_url('customers.php'), app_url('normalized-customer.php'), app_url('marketing-notes-report.php'), app_url('marketing-promo-report.php')]);
if (!is_array($returnParts) || isset($returnParts['scheme']) || isset($returnParts['host']) || !in_array((string)($returnParts['path'] ?? ''), $allowedReturnPaths, true)) {
    $returnTo = '';
}
$openedFromReports = (string)($_GET['from'] ?? '') === 'reports';
$isAdmin = is_admin_user();
$staffOwnsVisit = current_user_role() === 'staff' && (
    (int)($visit['recorded_by_user_id'] ?? 0) === current_user_id()
    || (current_staff_id() !== null && (int)($visit['staff_id'] ?? 0) === (int)current_staff_id())
);
$vendorOwnsVisit = current_user_role() === 'vendor'
    && $vendorCanView;
$tripCanManage = (int)($visit['sales_trip_id'] ?? 0) > 0 && can_access_registration_trip((int)$visit['sales_trip_id']);
$canEdit = $isAdmin || $staffOwnsVisit || $vendorOwnsVisit || $tripCanManage;
$editMode = (string)($_GET['edit'] ?? '') === '1' && $canEdit;
$actionError = '';
$backUrl = $returnTo !== ''
    ? $returnTo
    : ($openedFromReports ? app_url('reports.php?report=visits&scope=all&mode=lookup') : app_url('normalized-customer.php?stage=activity'));
$detailsUrl = app_url('normalized-visit-details.php?id='.$visitId.($followupOnly?'&view=followup':'').($returnTo !== '' ? '&return_to='.rawurlencode($returnTo) : ($openedFromReports ? '&from=reports' : '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string)($_POST['form_action'] ?? '');
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $actionError = 'Your session expired. Please try again.';
    } elseif ($formAction === 'delete_visit' && !$canEdit) {
        $actionError = 'You are not allowed to delete this customer visit.';
    } elseif ($formAction === 'delete_visit') {
        try {
            db()->beginTransaction();
            db()->prepare('DELETE FROM visit_notes WHERE visit_id=?')->execute([$visitId]);
            db()->prepare('DELETE FROM customer_promo_plugs WHERE visit_id=?')->execute([$visitId]);
            db()->prepare('DELETE FROM visits WHERE id=?')->execute([$visitId]);
            db()->commit();
            header('Location: '.$backUrl.(str_contains($backUrl, '?') ? '&' : '?').'deleted=1');
            exit;
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $actionError = 'The customer visit could not be deleted.';
        }
    } elseif ($formAction === 'update_visit' && !$canEdit) {
        $actionError = 'You are not allowed to edit this customer visit.';
    } elseif ($formAction === 'update_visit') {
        $customerName = trim((string)($_POST['customer_name'] ?? ''));
        $phone = normalize_phone_number((string)($_POST['phone'] ?? ''));
        $jobTypeId = max(0, (int)($_POST['job_type_id'] ?? 0));
        $jobTypeName = null;
        if ($jobTypeId) {
            $jobTypeStatement = db()->prepare('SELECT job_type_name FROM job_types WHERE id=? AND is_active=1');
            $jobTypeStatement->execute([$jobTypeId]);
            $jobTypeName = $jobTypeStatement->fetchColumn() ?: null;
        }
        if ($customerName === '' || $phone === '') {
            $actionError = 'Customer name and phone are required.';
            $editMode = true;
        } else {
            try {
                db()->beginTransaction();
                db()->prepare(
                    'UPDATE customers SET customer_name=?,phone=?,other_phone=?,job_type=?,job_type_id=?,
                     vehicle_registration_no=?,vin_no=?,supervisor_name=?,supervisor_phone=? WHERE id=?'
                )->execute([
                    $customerName,$phone,normalize_phone_number((string)($_POST['other_phone'] ?? '')) ?: null,
                    $jobTypeName,$jobTypeId ?: null,$isTaxi?(trim((string)($_POST['vehicle_registration_no'] ?? '')) ?: null):($visit['vehicle_registration_no']??null),
                    $isTaxi?(trim((string)($_POST['vin_no'] ?? '')) ?: null):($visit['vin_no']??null),$isTaxi?(trim((string)($_POST['supervisor_name'] ?? '')) ?: null):($visit['supervisor_name']??null),
                    $isTaxi?(normalize_phone_number((string)($_POST['supervisor_phone'] ?? '')) ?: null):($visit['supervisor_phone']??null),(int)$visit['customer_id'],
                ]);
                db()->prepare('UPDATE visits SET arrival_time=?,departure_time=? WHERE id=?')->execute([
                    trim((string)($_POST['arrival_time'] ?? '')) ?: null,
                    trim((string)($_POST['departure_time'] ?? '')) ?: null,
                    $visitId,
                ]);
                $saleStatement = db()->prepare('SELECT id FROM customer_promo_plugs WHERE visit_id=? ORDER BY id DESC LIMIT 1');
                $saleStatement->execute([$visitId]);
                $saleId = (int)($saleStatement->fetchColumn() ?: 0);
                $promoPlug = trim((string)($_POST['promo_plug'] ?? ''));
                if ($saleId) {
                    if ($promoPlug === '') db()->prepare('DELETE FROM customer_promo_plugs WHERE id=?')->execute([$saleId]);
                    else db()->prepare('UPDATE customer_promo_plugs SET promo_plug=? WHERE id=?')->execute([$promoPlug,$saleId]);
                } elseif ($promoPlug !== '') {
                    db()->prepare('INSERT INTO customer_promo_plugs (visit_id,customer_id,bus_loc_id,promo_plug,recorded_by_user_id) VALUES(?,?,?,?,?)')
                        ->execute([$visitId,(int)$visit['customer_id'],(int)$visit['bus_loc_id'],$promoPlug,current_user_id()]);
                }
                $noteStatement = db()->prepare('SELECT id FROM visit_notes WHERE visit_id=? ORDER BY id DESC LIMIT 1');
                $noteStatement->execute([$visitId]);
                $noteId = (int)($noteStatement->fetchColumn() ?: 0);
                $feedback = trim((string)($_POST['feedback'] ?? ''));
                $note = trim((string)($_POST['note'] ?? ''));
                if ($noteId) {
                    db()->prepare('UPDATE visit_notes SET feedback=?,note=? WHERE id=?')->execute([$feedback ?: null,$note ?: null,$noteId]);
                } elseif ($feedback !== '' || $note !== '') {
                    db()->prepare('INSERT INTO visit_notes (note_ref,visit_id,customer_id,feedback,note,staff_id,vendor_id,recorded_by_user_id) VALUES(?,?,?,?,?,?,?,?)')
                        ->execute([next_project_reference('visit_note'),$visitId,(int)$visit['customer_id'],$feedback ?: null,$note ?: null,current_staff_id(),(int)(current_vendor_profile()['id'] ?? 0) ?: null,current_user_id()]);
                }
                db()->commit();
                header('Location: '.$detailsUrl.'&updated=1');
                exit;
            } catch (Throwable $exception) {
                if (db()->inTransaction()) db()->rollBack();
                $actionError = 'The customer visit could not be updated.';
                $editMode = true;
            }
        }
    }
}

$jobTypes = db()->query('SELECT id,job_type_name FROM job_types WHERE is_active=1 ORDER BY job_type_name')->fetchAll();
$apprenticeStatement=db()->prepare("SELECT id,customer_ref,customer_name,phone,other_phone FROM customers WHERE master_customer_id=? AND is_active=1 ORDER BY customer_name,id");$apprenticeStatement->execute([(int)$visit['customer_id']]);$apprentices=$apprenticeStatement->fetchAll();

$pageTitle = $followupOnly ? 'Follow-up Details' : (string)$visit['customer_name'];
$breadcrumbs = $openedFromReports
    ? [
        ['label'=>'Home','url'=>app_url('index.php')],
        ['label'=>'Reports','url'=>app_url('reports.php')],
        ['label'=>$followupOnly?'Follow-up':'Visits','url'=>app_url('reports.php?report='.($followupOnly?'followup':'visits'))],
        ['label'=>$followupOnly?'Follow-up Details':'Customer Details'],
    ]
    : [
        ['label'=>'Home','url'=>app_url('index.php')],
        ['label'=>'Marketing Trip','url'=>app_url('normalized-customer.php?stage=activity')],
        ['label'=>'Customer Details'],
    ];
$internalBackUrl = $backUrl;
require_once __DIR__ . '/../includes/header.php';
?>
<?php if(!$followupOnly): ?>
<section class="management-panel retailer-profile-panel">
    <div class="management-heading">
        <div class="retailer-profile-title"><span class="section-kicker"><?=e((string)($visit['destination_name'] ?: $visit['visit_ref']))?></span><h1><?=e($displayName)?></h1><p><?=e(implode(', ',array_filter([(string)($visit['town_name']??''),(string)($visit['district_name']??''),(string)($visit['region_name']??'')])))?></p></div>
        <span class="management-icon"><i class="fa-solid fa-store"></i></span>
    </div>
    <?php if($actionError):?><div class="profile-message is-error"><?=e($actionError)?></div><?php endif;?>
    <?php if((string)($_GET['updated']??'')==='1'):?><div class="profile-message is-success">Customer visit updated successfully.</div><?php endif;?>
    <?php if($editMode):?>
    <form class="record-form normalized-visit-edit-form" method="post">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="form_action" value="update_visit">
        <div class="form-grid">
            <div class="form-field"><label>Customer Name</label><input name="customer_name" value="<?=e((string)($_POST['customer_name']??$visit['customer_name']))?>" required></div>
            <div class="form-field"><label>Phone</label><input name="phone" type="tel" value="<?=e((string)($_POST['phone']??$visit['phone']))?>" required></div>
            <div class="form-field"><label>Other Phone</label><input name="other_phone" type="tel" value="<?=e((string)($_POST['other_phone']??$visit['other_phone']))?>"></div>
            <div class="form-field"><label>Job Type</label><select name="job_type_id" data-popup-select><option value="">Select job type</option><?php foreach($jobTypes as $jobType):?><option value="<?=(int)$jobType['id']?>" <?=((int)($_POST['job_type_id']??$visit['job_type_id']??0)===(int)$jobType['id'])?'selected':''?>><?=e((string)$jobType['job_type_name'])?></option><?php endforeach;?></select></div>
            <div class="form-field"><label>Arrival Time</label><input name="arrival_time" type="time" value="<?=e(substr((string)($_POST['arrival_time']??$visit['arrival_time']),0,5))?>"></div>
            <div class="form-field"><label>Departure Time</label><input name="departure_time" type="time" value="<?=e(substr((string)($_POST['departure_time']??$visit['departure_time']),0,5))?>"></div>
            <?php if($isTaxi):?>
            <div class="form-field"><label>Vehicle Registration</label><input name="vehicle_registration_no" value="<?=e((string)($_POST['vehicle_registration_no']??$visit['vehicle_registration_no']))?>"></div>
            <div class="form-field"><label>VIN</label><input name="vin_no" value="<?=e((string)($_POST['vin_no']??$visit['vin_no']))?>"></div>
            <div class="form-field"><label>Supervisor Name</label><input name="supervisor_name" value="<?=e((string)($_POST['supervisor_name']??$visit['supervisor_name']))?>"></div>
            <div class="form-field"><label>Supervisor Phone</label><input name="supervisor_phone" value="<?=e((string)($_POST['supervisor_phone']??$visit['supervisor_phone']))?>"></div>
            <?php endif;?>
            <div class="form-field"><label>Promotional Plug</label><input name="promo_plug" value="<?=e((string)($_POST['promo_plug']??$visit['promo_plug']))?>"></div>
            <div class="form-field form-field--wide"><label>Feedback</label><textarea name="feedback"><?=e((string)($_POST['feedback']??$visit['feedback']))?></textarea></div>
            <div class="form-field form-field--wide"><label>Note</label><textarea name="note"><?=e((string)($_POST['note']??$visit['note']))?></textarea></div>
        </div>
        <div class="form-actions"><a class="secondary-button" href="<?=e($detailsUrl)?>">Cancel</a><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>Save Changes</span></button></div>
    </form>
    <?php endif;?>
    <?php if(!$editMode):?><div class="form-actions customer-detail-action-bar"><a class="secondary-button" href="<?=e($backUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><?php if($canEdit):?><?php if((int)($visit['bus_loc_id']??0)>0):?><a class="secondary-button" href="<?=e(app_url('place-details.php?id='.(int)$visit['bus_loc_id'].'&edit=1&customer_id='.(int)$visit['customer_id'].'&return_to='.rawurlencode($detailsUrl)))?>"><i class="fa-solid <?=(int)($visit['is_legacy_placeholder']??0)===1?'fa-location-dot':'fa-arrow-right-arrow-left'?>"></i><span><?=(int)($visit['is_legacy_placeholder']??0)===1?'Assign Location':'Move / Merge'?></span></a><?php endif;?><a class="action-button" href="<?=e($detailsUrl.'&edit=1')?>"><i class="fa-solid fa-pen"></i><span>Edit</span></a><form method="post" data-confirm-title="Delete customer visit?" data-confirm-message="This permanently deletes this visit, its sales information, and its notes."><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="delete_visit"><button class="danger-button" type="submit"><i class="fa-solid fa-trash"></i><span>Delete</span></button></form><?php endif;?></div><?php endif;?>
    <div class="detail-grid detail-grid--plain retailer-profile-grid"><dl>
        <?php $detailFields=[
            'customer_ref'=>'Customer ID','visit_ref'=>'Visit ID','session_ref'=>'Location Visit ID',
            'customer_name'=>"Owner's Name",'phone'=>'Phone','other_phone'=>'Other Phone',
            'region_name'=>'Region','district_name'=>'District','town_name'=>'Town','area'=>'Area',
            'shop_type_name'=>'Shop Type','job_type'=>'Job Type'
        ];if($isTaxi)$detailFields+=['vehicle_registration_no'=>'Vehicle Registration','vin_no'=>'VIN','supervisor_name'=>'Supervisor','supervisor_phone'=>'Supervisor Phone'];foreach($detailFields as $key=>$label): ?><div><dt><?=e($label)?></dt><dd><?=e((string)($visit[$key]??''))?></dd></div><?php endforeach; ?>
        <div><dt>Date Added</dt><dd><?=e(date('d M Y',strtotime((string)$visit['created_at'])))?></dd></div>
        <div><dt>Recorded By</dt><dd><?=e((string)($visit['staff_name']??''))?></dd></div>
        <div><dt>Google Location</dt><dd><?php if($visit['google_location']):?><a href="<?=e((string)$visit['google_location'])?>" target="_blank" rel="noopener">Open location</a><?php else: ?>Not provided<?php endif; ?></dd></div>
        <div><dt>Apprentices</dt><dd><button class="apprentice-count-button" type="button" data-open-apprentices><strong><?=number_format(count($apprentices))?></strong><span>View apprentices</span></button></dd></div>
    </dl></div>
</section>

<section class="management-panel management-panel--table retailer-visit-panel">
    <div class="management-heading management-heading--compact"><div><span class="section-kicker">Media</span><h2><?=e((string)($visit['destination_name']?:'Customer'))?> Media</h2></div></div>
    <div class="retailer-media-grid normalized-media-grid">
        <?php $media=[['customer_picture',"Owner's Pic",'No owner pic'],['shop_picture','Location Pic 1','No location pic 1'],['shop_picture_2','Location Pic 2','No location pic 2'],['car_picture','Car Pic','No car pic'],['shop_video','Shop Vid','No shop video']]; foreach($media as [$key,$label,$empty]): $mediaPath=(string)($visit[$key]??'');$mediaExists=$mediaPath!==''&&is_file(__DIR__.'/../'.ltrim($mediaPath,'/'));$mediaUrl=$mediaExists?app_url($mediaPath):'';$isVideo=$key==='shop_video'; ?>
        <div class="retailer-media-item <?=$isVideo?'retailer-media-item--video':''?>"><span><?=e($label)?></span>
            <?php if($mediaUrl!==''): ?><button class="media-video-trigger" type="button" data-media-viewer="<?=$isVideo?'video':'image'?>" data-media-src="<?=e($mediaUrl)?>" data-media-title="<?=e($label)?>"><?php if($isVideo):?><video preload="metadata" muted playsinline><source src="<?=e($mediaUrl)?>"></video><span class="media-play-badge"><i class="fa-solid fa-play"></i></span><?php else:?><img src="<?=e($mediaUrl)?>" alt="<?=e($label)?>"><?php endif;?></button><?php else:?><div class="media-empty-state"><?=e($empty)?></div><?php endif;?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if(!$followupOnly):?><dialog class="sales-customer-dialog apprentice-list-dialog" data-apprentice-dialog><div class="sales-customer-dialog__header"><div><span>Under <?=e($displayName)?></span><h2>Apprentices (<?=number_format(count($apprentices))?>)</h2></div><button type="button" data-close-apprentices aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><div class="sales-customer-dialog__list"><?php foreach($apprentices as $apprentice):?><div class="sales-customer-option apprentice-list-option"><span><strong><?=e((string)$apprentice['customer_name'])?></strong><small><?=e(implode(' / ',array_filter([(string)$apprentice['phone'],(string)$apprentice['other_phone']])))?></small></span><span class="sales-customer-option__meta"><b><?=e((string)$apprentice['customer_ref'])?></b></span></div><?php endforeach;?><?php if(!$apprentices):?><p class="empty-state">No apprentices are registered under this master.</p><?php endif;?></div></dialog><?php endif;?>

<section class="management-panel management-panel--table retailer-visit-panel <?=$followupOnly?'followup-detail-only':''?>">
    <?php if(!$followupOnly): ?><div class="management-heading management-heading--compact"><div><span class="section-kicker">Visit History</span><h2>Visits</h2></div></div><?php endif; ?>
    <div class="retailer-visit-stack">
        <?php foreach($history as $index=>$item): $historyLabel=$item['visit_type']==='registration'?'Registration':ucwords(str_replace('_',' ',(string)($item['follow_up_method']?:'physical_visit')));$noteText=trim((string)($item['note']??''));$notePreview=strlen($noteText)>90?substr($noteText,0,87).'...':$noteText; ?>
        <article class="retailer-visit-card"><div class="retailer-visit-card__header"><span class="retailer-visit-card__marker"><?=(int)($item['_history_number']??($index+1))?></span><div><span class="status-badge <?=$item['visit_type']==='registration'?'is-active':'is-warning'?>"><?=e($historyLabel)?></span><h3><?=e((string)($item['trip_code']??''))?></h3><p><?=e((string)($item['staff_name']??''))?></p></div><span class="muted-text"><?=e(date('d M Y',strtotime((string)$item['created_at'])) )?></span></div>
        <dl class="retailer-visit-summary"><div><dt>Trip Date</dt><dd><?=e($item['trip_date']?date('d M Y',strtotime((string)$item['trip_date'])):'')?></dd></div><div><dt>Arrival</dt><dd><?=e(substr((string)$item['arrival_time'],0,5))?></dd></div><div><dt>Departure</dt><dd><?=e(substr((string)$item['departure_time'],0,5))?></dd></div><div><dt>Promo Plug</dt><dd><?=e((string)($item['promo_plug']??''))?></dd></div><div><dt>Recorded</dt><dd><?=e(date('d M Y',strtotime((string)$item['created_at'])))?></dd></div></dl>
        <div class="retailer-visit-notes"><div><span>Feedback</span><p><?=e((string)($item['feedback']??''))?></p></div><div><span>Note</span><?php if($noteText!==''):?><button class="visit-note-preview" type="button" data-note-view data-note-text="<?=e($noteText)?>"><?=e($notePreview)?></button><?php else:?><p class="muted-text">No note</p><?php endif;?></div></div></article>
        <?php endforeach; ?>
    </div>
</section>
<?php if(!$followupOnly):?><script>document.addEventListener('DOMContentLoaded',function(){const dialog=document.querySelector('[data-apprentice-dialog]');document.querySelector('[data-open-apprentices]')?.addEventListener('click',function(){dialog?.showModal();});document.querySelector('[data-close-apprentices]')?.addEventListener('click',function(){dialog?.close();});dialog?.addEventListener('click',function(event){if(event.target===dialog)dialog.close();});});</script><?php endif;?>
<?php require_once __DIR__ . '/../includes/footer.php';
