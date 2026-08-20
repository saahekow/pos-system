<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
ensure_destination_visit_schema();

function save_visit_edit_upload(string $field, array $types, int $maxBytes): ?string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > $maxBytes) throw new RuntimeException('The selected media file could not be uploaded or is too large.');
    $info = new finfo(FILEINFO_MIME_TYPE); $mime = (string)$info->file((string)$file['tmp_name']);
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($types[$mime])) $mime = ['heic'=>'image/heic','heif'=>'image/heif'][$extension] ?? $mime;
    if (!isset($types[$mime])) throw new RuntimeException('Choose a supported image or video file.');
    $dir = __DIR__ . '/../assets/uploads/visits'; if (!is_dir($dir)) mkdir($dir, 0775, true);
    $compressible = in_array($mime, ['image/jpeg','image/png','image/webp'], true);
    $video = str_starts_with($mime, 'video/');
    $name = $field . '-' . bin2hex(random_bytes(10)) . '.' . ($compressible || $video ? ($video ? 'mp4' : 'jpg') : $types[$mime]);
    $target = $dir . '/' . $name;
    if ($compressible && compress_uploaded_image((string)$file['tmp_name'], $mime, $target)) return 'assets/uploads/visits/' . $name;
    if ($video && compress_uploaded_video((string)$file['tmp_name'], $target)) return 'assets/uploads/visits/' . $name;
    if ($compressible || $video) { $name = $field . '-' . bin2hex(random_bytes(10)) . '.' . $types[$mime]; $target = $dir . '/' . $name; }
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) throw new RuntimeException('The selected media file could not be saved.');
    return 'assets/uploads/visits/' . $name;
}

$id = max(0, (int)($_GET['id'] ?? $_POST['visit_id'] ?? 0));
$returnTo = trim((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
$returnParts = $returnTo !== '' ? parse_url($returnTo) : false;
$allowedReturnPaths = array_map(static function (string $url): string { return (string) (parse_url($url, PHP_URL_PATH) ?: $url); }, [app_url('reports.php'), app_url('sales-trip.php'),app_url('admin-customers.php'),app_url('vendor-customers.php'),app_url('vendor-reports.php'),app_url('registration-records.php')]);
if (!is_array($returnParts) || isset($returnParts['scheme']) || isset($returnParts['host']) || !in_array((string) ($returnParts['path'] ?? ''), $allowedReturnPaths, true)) {
    $returnTo = '';
}
$statement = db()->prepare("SELECT dv.*,d.destination_name,d.destination_key FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id WHERE dv.id=? AND dv.visit_type='registration'");
$statement->execute([$id]); $visit = $statement->fetch();
if (!$visit) { http_response_code(404); $pageTitle='Visit Not Found'; $breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Visit Not Found']]; require __DIR__.'/../includes/header.php'; echo '<section class="management-panel"><p class="empty-state">Select a valid registration visit.</p></section>'; require __DIR__.'/../includes/footer.php'; exit; }
$staffOwnsVisit=current_user_role()==='staff'&&(
    (int)($visit['recorded_by_user_id']??0)===(int)current_user_id()
    || (current_staff_id()!==null&&(int)($visit['staff_id']??0)===(int)current_staff_id())
);
$vendorId=(int)(current_vendor_profile()['id']??0);
$vendorCanEdit=current_user_role()==='vendor'&&$vendorId>0&&((int)($visit['vendor_id']??0)===$vendorId||can_access_registration_trip((int)($visit['sales_trip_id']??0)));
$canEdit=in_array(current_user_role(),['super_admin','admin'],true)||$staffOwnsVisit||$vendorCanEdit;
if(!$canEdit){http_response_code(403);exit('You can edit only customers assigned to your account.');}
$isTaxi = (string)($visit['destination_key']??'') === taxi_rank_destination_key();
$error = '';
$originalPhone=normalize_phone_number((string)($visit['phone']??''));
$originalOtherPhone=normalize_phone_number((string)($visit['other_phone']??''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action']??'save') === 'delete') {
    if(!verify_csrf_token((string)($_POST['csrf_token']??''))) $error='Your session expired. Please try again.';
    else { try { db()->beginTransaction();$visitIdsStatement=db()->prepare('SELECT id FROM destination_visits WHERE id=? OR parent_visit_id=?');$visitIdsStatement->execute([$id,$id]);$visitIds=array_map('intval',$visitIdsStatement->fetchAll(PDO::FETCH_COLUMN));if($visitIds){$marks=implode(',',array_fill(0,count($visitIds),'?'));db()->prepare("DELETE FROM customer_pos_sale_vins WHERE customer_source='visit' AND record_id IN ($marks)")->execute($visitIds);}db()->prepare('DELETE FROM destination_visits WHERE parent_visit_id=?')->execute([$id]);db()->prepare('DELETE FROM destination_visits WHERE id=?')->execute([$id]);db()->commit();header('Location: '.($returnTo!==''?$returnTo:app_url('vendor-reports.php?report=customers&mode=lookup&deleted=1')));exit;}catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error='The customer visit could not be deleted.';} }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action']??'save') !== 'delete') {
    foreach (['company_name','owner_name','phone','other_phone','area','google_location','sales_ref','promo_plug','note','shop_arrival_time','shop_departure_time','vehicle_registration_no','supervisor_name','supervisor_phone','vin_no'] as $key) $visit[$key]=trim((string)($_POST[$key]??''));
    $visit['phone']=normalize_phone_number($visit['phone']);$visit['other_phone']=normalize_phone_number($visit['other_phone']);
    $duplicatePhone=$visit['phone']!==''&&$visit['phone']!==$originalPhone?registered_customer_for_phone($visit['phone'],$id):null;$duplicateOtherPhone=$visit['other_phone']!==''&&$visit['other_phone']!==$originalOtherPhone?registered_customer_for_phone($visit['other_phone'],$id):null;
    $visit['location_id']=max(0,(int)($_POST['location_id']??0)); $visit['shop_type_id']=max(0,(int)($_POST['shop_type_id']??0));
    $feedbackId=max(0,(int)($_POST['feedback_option_id']??0)); $feedback=null;
    if($feedbackId){$s=db()->prepare('SELECT feedback_label FROM visit_feedback_options WHERE id=? AND is_active=1');$s->execute([$feedbackId]);$feedback=$s->fetchColumn()?:null;}
    $townValid=location_by_id((int)$visit['location_id'])!==null;
    if(!verify_csrf_token((string)($_POST['csrf_token']??''))) $error='Your session expired. Please try again.';
    elseif(!$townValid) $error='Select a valid region and town.';
    elseif($visit['google_location']==='') $error='Google Location is required. Use GPS or paste a Google Maps location before saving.';
    elseif($visit['company_name']===''&&$visit['owner_name']==='') $error='Enter a business/location name or contact name.';
    elseif($isTaxi&&$visit['owner_name']==='') $error="Driver's name is required.";
    elseif($isTaxi&&$visit['vehicle_registration_no']==='') $error='Car registration number is required.';
    elseif($visit['phone']!==''&&!is_valid_phone_number($visit['phone'])) $error='Enter a valid phone number.';
    elseif($visit['other_phone']!==''&&!is_valid_phone_number($visit['other_phone'])) $error='Enter a valid other phone number.';
    elseif($duplicatePhone||$duplicateOtherPhone){$duplicate=$duplicatePhone?:$duplicateOtherPhone;$duplicateName=trim((string)($duplicate['company_name']?:$duplicate['owner_name']));$error='This phone number has already been registered'.($duplicateName!==''?' to '.$duplicateName:'').'.';}
    elseif(!preg_match('/^\d{2}:\d{2}$/',$visit['shop_arrival_time'])||!preg_match('/^\d{2}:\d{2}$/',$visit['shop_departure_time'])) $error='Enter valid arrival and departure times.';
    elseif($visit['shop_departure_time']<$visit['shop_arrival_time']) $error='Departure time cannot be earlier than arrival time.';
    elseif($feedbackId&&!$feedback) $error='Select a valid feedback option.';
    else {
        try {
            $imageTypes=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'];
            $videoTypes=['video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov'];
            $contactPic=save_visit_edit_upload('owner_pic',$imageTypes,APP_IMAGE_UPLOAD_MAX_BYTES) ?: $visit['owner_pic'];
            $locationPic=save_visit_edit_upload('shop_pic',$imageTypes,APP_IMAGE_UPLOAD_MAX_BYTES) ?: $visit['shop_pic'];
            $locationVideo=save_visit_edit_upload('shop_video',$videoTypes,30*1024*1024) ?: $visit['shop_video'];
            $s=db()->prepare("UPDATE destination_visits SET company_name=?,owner_name=?,phone=?,other_phone=?,location_id=?,area=?,google_location=?,shop_type_id=?,sales_ref=?,promo_plug=?,shop_arrival_time=?,shop_departure_time=?,feedback=?,note=?,owner_pic=?,shop_pic=?,shop_video=?,vehicle_registration_no=?,supervisor_name=?,supervisor_phone=?,vin_no=?,record_status='completed' WHERE id=?");
            $s->execute([$visit['company_name']?:null,$visit['owner_name']?:null,$visit['phone']?:null,$visit['other_phone']?:null,$visit['location_id']?:null,$visit['area']?:null,$visit['google_location']?:null,$visit['shop_type_id']?:null,$visit['sales_ref']?:null,$visit['promo_plug']?:null,$visit['shop_arrival_time'],$visit['shop_departure_time'],$feedback,$visit['note']?:null,$contactPic,$locationPic,$locationVideo,$visit['vehicle_registration_no']?:null,$visit['supervisor_name']?:null,$visit['supervisor_phone']?:null,$visit['vin_no']?:null,$id]);
            header('Location: '.($returnTo!==''?$returnTo:app_url('visit-details.php?id='.$id))); exit;
        } catch(RuntimeException $exception) { $error=$exception->getMessage(); }
    }
}

$locations=$vendorCanEdit?assigned_towns_for_vendor($vendorId):active_locations();
$locationRegions=[];foreach($locations as $location){$key=(string)($location['region_code']?:$location['region_name']);$locationRegions[$key]=(string)$location['region_name'];}asort($locationRegions);
$shopTypes=db()->query('SELECT id,shop_type_name FROM shop_types WHERE is_active=1 ORDER BY shop_type_name')->fetchAll();
$feedbackOptions=db()->query('SELECT id,feedback_label FROM visit_feedback_options WHERE is_active=1 ORDER BY feedback_label')->fetchAll();
$pageTitle='Edit '.(string)$visit['destination_name'].' Visit'; $breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Reports','url'=>app_url('reports.php')],['label'=>'Visits','url'=>app_url('reports.php?report=visits')],['label'=>'Visit Details','url'=>app_url('visit-details.php?id='.$id)],['label'=>'Edit Visit']];
$internalBackUrl = $returnTo!==''?$returnTo:app_url('visit-details.php?id='.$id);
require __DIR__.'/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker"><?=e((string)$visit['destination_name'])?></span><h1>Edit <?=e((string)$visit['destination_name'])?> Visit</h1><p>Update the destination profile, location, feedback, and registration media.</p></div><div class="management-icon"><i class="fa-solid fa-pen-to-square"></i></div></div>
    <?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
    <form class="record-form mobile-line-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="visit_id" value="<?=$id?>"><input type="hidden" name="return_to" value="<?=e($returnTo)?>">
    <div class="form-grid">
        <?php $selectedLocationRegion='';foreach($locations as $location){if((int)$visit['location_id']===(int)$location['id']){$selectedLocationRegion=(string)($location['region_code']?:$location['region_name']);break;}} ?>
        <div class="form-field"><label for="visit_edit_region">Region</label><select id="visit_edit_region" data-location-region-select required><option value="">Select region</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e((string)$regionKey)?>" <?=$selectedLocationRegion===(string)$regionKey?'selected':''?>><?=e($regionName)?></option><?php endforeach;?></select></div>
        <div class="form-field"><label for="location_id">Town</label><select id="location_id" name="location_id" data-location-town-select required><option value="">Select town</option><option value="__other__" data-add-town-option="true">Other — add a new town</option><?php foreach($locations as $location):?><option value="<?=(int)$location['id']?>" data-region-key="<?=e((string)($location['region_code']?:$location['region_name']))?>" data-mmda-name="<?=e((string)$location['mmda_name'])?>" <?=(int)$visit['location_id']===(int)$location['id']?'selected':''?>><?=e((string)$location['town_name'])?><?= (int)$location['is_capital']===1?' *':'' ?></option><?php endforeach;?></select><small data-location-mmda-output></small></div>
        <div class="form-field"><label for="area">Area</label><input id="area" name="area" value="<?=e((string)($visit['area']??''))?>"></div>
        <div class="form-field"><label for="sales_ref">Sales Ref</label><textarea id="sales_ref" name="sales_ref"><?=e((string)($visit['sales_ref']??''))?></textarea></div>
        <div class="form-field"><label for="promo_plug">Promo Plug</label><input id="promo_plug" name="promo_plug" value="<?=e((string)($visit['promo_plug']??''))?>"></div>
        <div class="form-field"><label for="shop_arrival_time"><?= $isTaxi?'Arrival Time':'Shop Arrival Time' ?></label><div class="field-control-row"><input id="shop_arrival_time" class="time-picker-input" name="shop_arrival_time" value="<?=e(substr((string)$visit['shop_arrival_time'],0,5))?>" data-time-picker readonly required><button class="secondary-button secondary-button--small" type="button" data-current-time-target="shop_arrival_time"><i class="fa-regular fa-clock"></i><span>Now</span></button></div></div>
        <div class="form-field"><label for="shop_departure_time"><?= $isTaxi?'Departure Time':'Shop Departure Time' ?></label><div class="field-control-row"><input id="shop_departure_time" class="time-picker-input" name="shop_departure_time" value="<?=e(substr((string)$visit['shop_departure_time'],0,5))?>" data-time-picker readonly required><button class="secondary-button secondary-button--small" type="button" data-current-time-target="shop_departure_time"><i class="fa-regular fa-clock"></i><span>Now</span></button></div></div>
        <div class="form-field"><label for="shop_type_id">Shop Type</label><select id="shop_type_id" name="shop_type_id"><option value="">Select Shop Type</option><?php foreach($shopTypes as $type):?><option value="<?=(int)$type['id']?>" <?=(int)$visit['shop_type_id']===(int)$type['id']?'selected':''?>><?=e((string)$type['shop_type_name'])?></option><?php endforeach;?></select></div>
        <div class="form-field"><label for="company_name">Comp Name</label><input id="company_name" name="company_name" value="<?=e((string)($visit['company_name']??''))?>"></div>
        <div class="form-field"><label for="owner_name"><?= $isTaxi?"Driver's Name":"Owner's Name" ?></label><input id="owner_name" name="owner_name" value="<?=e((string)($visit['owner_name']??''))?>"></div>
        <?php if($isTaxi):?><div class="form-field"><label for="vehicle_registration_no">Car Registration Number</label><input id="vehicle_registration_no" name="vehicle_registration_no" value="<?=e((string)($visit['vehicle_registration_no']??''))?>"></div><div class="form-field"><label for="vin_no">VIN</label><input id="vin_no" name="vin_no" value="<?=e((string)($visit['vin_no']??''))?>"></div><?php endif;?>
        <div class="form-field"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?=e((string)($visit['phone']??''))?>" data-phone-input data-customer-phone-check="<?=e(app_url('customer-phone-check.php'))?>" data-exclude-visit-id="<?=$id?>"></div>
        <div class="form-field"><label for="other_phone">Other Phone</label><input id="other_phone" name="other_phone" type="tel" value="<?=e((string)($visit['other_phone']??''))?>" data-phone-input data-customer-phone-check="<?=e(app_url('customer-phone-check.php'))?>" data-exclude-visit-id="<?=$id?>"></div>
        <div class="form-field"><label for="google_location">Google Location</label><div class="field-control-row"><input id="google_location" name="google_location" type="url" value="<?=e((string)($visit['google_location']??''))?>" placeholder="Use GPS or paste a Google Maps link" required><button class="secondary-button secondary-button--small" type="button" data-current-location-target="google_location"><i class="fa-solid fa-location-crosshairs"></i><span>Use GPS</span></button></div></div>
        <div class="form-field"><label for="feedback_option_id">Feedback</label><select id="feedback_option_id" name="feedback_option_id"><option value="">Select feedback</option><?php foreach($feedbackOptions as $option):?><option value="<?=(int)$option['id']?>" <?=strtolower((string)$visit['feedback'])===strtolower((string)$option['feedback_label'])?'selected':''?>><?=e((string)$option['feedback_label'])?></option><?php endforeach;?></select></div>
        <div class="form-field"><label for="owner_pic"><?= $isTaxi?'Driver Pic':"Owner's Pic" ?></label><input id="owner_pic" name="owner_pic" type="file" accept="image/*" data-photo-source-choice></div>
        <div class="form-field"><label for="shop_pic"><?= $isTaxi?'Station Pic':'Shop Pic' ?></label><input id="shop_pic" name="shop_pic" type="file" accept="image/*" data-photo-source-choice></div>
        <div class="form-field"><label for="shop_video">Shop Vid</label><input id="shop_video" name="shop_video" type="file" accept="video/mp4,video/webm,video/quicktime"></div>
        <div class="form-field form-field--wide"><label for="note">Note</label><textarea id="note" name="note" rows="4"><?=e((string)($visit['note']??''))?></textarea></div>
    </div>
    <div class="form-actions"><a class="secondary-button" href="<?=e($internalBackUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Cancel</span></a><button class="danger-button" type="submit" name="form_action" value="delete" formnovalidate data-confirm-title="Delete customer visit?" data-confirm-message="This permanently deletes this registration and all of its follow-ups."><i class="fa-solid fa-trash"></i><span>Delete</span></button><button class="login-button" type="submit" name="form_action" value="save"><span>Update visit</span><i class="fa-solid fa-floppy-disk"></i></button></div>
    </form>
</section>
<?php require __DIR__.'/../includes/footer.php'; ?>
