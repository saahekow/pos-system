<?php
require_once __DIR__ . '/../config/app.php';
$adminCustomerMode = isset($adminCustomerMode) && $adminCustomerMode === true;
require_module_access($adminCustomerMode ? 'admin' : 'vendor_customers');
ensure_destination_visit_schema();

function save_vendor_customer_media(string $field, array $types, int $maxBytes): ?string
{
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file=$_FILES[$field];if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||(int)($file['size']??0)>$maxBytes)throw new RuntimeException('A selected media file could not be uploaded or is too large.');
    $mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);$extension=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));if(!isset($types[$mime]))$mime=['heic'=>'image/heic','heif'=>'image/heif'][$extension]??$mime;if(!isset($types[$mime]))throw new RuntimeException('Choose a supported image or video file.');
    $dir=__DIR__.'/../assets/uploads/visits';if(!is_dir($dir))mkdir($dir,0775,true);$compressible=in_array($mime,['image/jpeg','image/png','image/webp'],true);$name=$field.'-'.bin2hex(random_bytes(10)).'.'.($compressible?'jpg':$types[$mime]);$target=$dir.'/'.$name;
    if($compressible&&compress_uploaded_image((string)$file['tmp_name'],$mime,$target))return 'assets/uploads/visits/'.$name;
    if($compressible){$name=$field.'-'.bin2hex(random_bytes(10)).'.'.$types[$mime];$target=$dir.'/'.$name;}
    if(!move_uploaded_file((string)$file['tmp_name'],$target))throw new RuntimeException('The selected media file could not be saved.');return 'assets/uploads/visits/'.$name;
}

$vendors = [];
$adminTownOptions = [];
if ($adminCustomerMode) {
$vendors = db()->query('SELECT id, vendor_name, phone FROM vendors WHERE is_active=1 ORDER BY vendor_name')->fetchAll();
    foreach ($vendors as $vendorOption) {
        foreach (assigned_towns_for_vendor((int)$vendorOption['id']) as $townOption) {
            $townOption['vendor_id'] = (int)$vendorOption['id'];
            $adminTownOptions[] = $townOption;
        }
    }
    $requestedVendorId = max(0, (int)($_POST['vendor_id'] ?? 0));
    $vendor = null;
    foreach ($vendors as $vendorOption) {
        if ((int)$vendorOption['id'] === $requestedVendorId) { $vendor = $vendorOption; break; }
    }
} else {
    $vendor = current_vendor_profile();
}
if (!$adminCustomerMode && (!$vendor || !(int)($vendor['location_id'] ?? 0))) {
    http_response_code(403);
    exit('Your vendor account needs an active town before customers can be created.');
}
$managedTowns=$vendor ? assigned_towns_for_vendor((int)$vendor['id']) : [];
if(!$adminCustomerMode && !$managedTowns){http_response_code(403);exit('Your vendor account has no assigned towns.');}

$pageTitle = $adminCustomerMode ? 'Create Customer' : 'Create Customers';
$breadcrumbs = $adminCustomerMode
    ? [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Create Customer']]
    : [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Create Customers']];
$message = $error = '';
$customerFormSaved = false;
$placeChoiceVisitId = 0;
$destinations=db()->query('SELECT id,destination_name,destination_key FROM destinations WHERE is_active=1 ORDER BY is_default DESC,destination_name')->fetchAll();
$shopTypes=db()->query('SELECT id,shop_type_name FROM shop_types WHERE is_active=1 ORDER BY shop_type_name')->fetchAll();
$feedbackOptions=db()->query('SELECT id,feedback_label FROM visit_feedback_options WHERE is_active=1 ORDER BY feedback_label')->fetchAll();
$blankForm=['vendor_id'=>$vendor?(string)$vendor['id']:'','sales_customer_id'=>'','place_group_key'=>'','visit_date'=>date('Y-m-d'),'destination_id'=>'','shop_arrival_time'=>'','sales_ref'=>'','promo_plug'=>'','location_id'=>count($managedTowns)===1?(string)$managedTowns[0]['id']:'','area'=>'','company_name'=>'','owner_name'=>'','phone'=>'','other_phone'=>'','google_location'=>'','shop_type_id'=>'','driver_name'=>'','car_registration_no'=>'','supervisor_name'=>'','supervisor_phone'=>'','vin_no'=>'','feedback_option_id'=>'','note'=>'','shop_departure_time'=>''];
$form=$blankForm;
$samePlaceId=max(0,(int)($_GET['same_place']??0));
if($_SERVER['REQUEST_METHOD']!=='POST'&&$samePlaceId>0){$sameSql='SELECT vendor_id,destination_id,location_id,area,google_location,shop_type_id,place_group_key FROM destination_visits WHERE id=? AND '.($adminCustomerMode?'recorded_by_user_id=?':'vendor_id=?').' LIMIT 1';$sameStatement=db()->prepare($sameSql);$sameStatement->execute([$samePlaceId,$adminCustomerMode?current_user_id():(int)$vendor['id']]);$same=$sameStatement->fetch();if($same){$form['vendor_id']=(string)($same['vendor_id']??'');$form['destination_id']=(string)($same['destination_id']??'');$form['location_id']=(string)($same['location_id']??'');$form['area']=(string)($same['area']??'');$form['google_location']=(string)($same['google_location']??'');$form['shop_type_id']=(string)($same['shop_type_id']??'');$form['place_group_key']=(string)($same['place_group_key']?:bin2hex(random_bytes(16)));}}
$draftSql="SELECT dv.id,dv.company_name,dv.owner_name,dv.updated_at,dv.created_at,d.destination_name,v.vendor_name FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id LEFT JOIN vendors v ON v.id=dv.vendor_id WHERE dv.record_status='draft' AND ".($adminCustomerMode?'dv.recorded_by_user_id=?':'dv.vendor_id=?')." ORDER BY COALESCE(dv.updated_at,dv.created_at) DESC";
$draftStatement=db()->prepare($draftSql);$draftStatement->execute([$adminCustomerMode?current_user_id():(int)$vendor['id']]);$draftVisits=$draftStatement->fetchAll();
$salesCustomerSql="SELECT dv.id,dv.vendor_id,dv.company_name,dv.owner_name,dv.phone,dv.other_phone,dv.area,dv.created_at,v.vendor_name FROM destination_visits dv LEFT JOIN vendors v ON v.id=dv.vendor_id WHERE dv.visit_type='registration' AND dv.record_status='completed'";
$salesCustomerParams=[];
if(!$adminCustomerMode){$salesCustomerSql.=' AND dv.vendor_id=?';$salesCustomerParams[]=(int)$vendor['id'];}
$salesCustomerSql.=' ORDER BY dv.created_at DESC,dv.id DESC LIMIT 150';
$salesCustomerStatement=db()->prepare($salesCustomerSql);$salesCustomerStatement->execute($salesCustomerParams);$salesCustomers=$salesCustomerStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $key) $form[$key] = trim((string)($_POST[$key] ?? ''));
    $saleVins=[];$saleAmounts=[];
    foreach((array)($_POST['sale_vin']??[]) as $saleIndex=>$saleVin){
        $saleVin=strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/','',trim((string)$saleVin)));
        $saleAmount=trim((string)((array)($_POST['sale_amount']??[])[$saleIndex]??''));
        if($saleVin===''&&$saleAmount==='')continue;
        $saleVins[]=$saleVin;$saleAmounts[]=$saleAmount;
    }
    if ($adminCustomerMode) {
        $vendor = null;
        foreach ($vendors as $vendorOption) if ((int)$vendorOption['id']===(int)$form['vendor_id']) { $vendor=$vendorOption; break; }
        $managedTowns=$vendor ? assigned_towns_for_vendor((int)$vendor['id']) : [];
    }
    $destination=null;foreach($destinations as $option)if((int)$option['id']===(int)$form['destination_id']){$destination=$option;break;}
    $salesCustomer=null;
    if((int)$form['sales_customer_id']>0&&$vendor){$salesCustomerStatement=db()->prepare("SELECT id,vendor_id,company_name,owner_name,phone,other_phone,location_id,area,google_location,shop_type_id,vehicle_registration_no,supervisor_name,supervisor_phone,vin_no FROM destination_visits WHERE id=? AND vendor_id=? AND visit_type='registration' AND record_status='completed' LIMIT 1");$salesCustomerStatement->execute([(int)$form['sales_customer_id'],(int)$vendor['id']]);$salesCustomer=$salesCustomerStatement->fetch()?:null;}
    $isTaxi=$destination&&destination_is_taxi_rank($destination);
    $selectedTown=null;foreach($managedTowns as $town)if((int)$town['id']===(int)$form['location_id']){$selectedTown=$town;break;}
    $feedback=null;if((int)$form['feedback_option_id']){$feedbackStatement=db()->prepare('SELECT feedback_label FROM visit_feedback_options WHERE id=? AND is_active=1');$feedbackStatement->execute([(int)$form['feedback_option_id']]);$feedback=$feedbackStatement->fetchColumn()?:null;}
    $form['phone']=normalize_phone_number($form['phone']);$form['other_phone']=normalize_phone_number($form['other_phone']);$form['supervisor_phone']=normalize_phone_number($form['supervisor_phone']);
    $duplicatePhone=$form['phone']!==''?registered_customer_for_phone($form['phone']):null;$duplicateOtherPhone=$form['other_phone']!==''?registered_customer_for_phone($form['other_phone']):null;
    $saveAsDraft=(bool)$vendor&&(bool)$destination&&(!$selectedTown||$form['area']===''||$form['google_location']===''||(!$salesCustomer&&($form['phone']===''||$form['note']===''||(!$isTaxi&&($form['company_name']===''||$form['owner_name']===''))||($isTaxi&&($form['driver_name']===''||$form['car_registration_no']==='')))));
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) $error = 'Your session expired. Please try again.';
    elseif(!$vendor)$error='Select a valid active vendor.';
    elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$form['visit_date']) || !checkdate((int)substr($form['visit_date'],5,2),(int)substr($form['visit_date'],8,2),(int)substr($form['visit_date'],0,4)))$error='Select a valid visit date.';
    elseif(!$destination)$error='Select a valid destination.';
    elseif((int)$form['sales_customer_id']>0&&!$salesCustomer)$error='Select a valid customer for this vendor.';
    elseif($form['google_location']==='')$error='Google Location is required. Use GPS or paste a Google Maps location before saving.';
    elseif(count($saleVins)!==count(array_filter($saleVins,static fn($vin)=>strlen($vin)===17)))$error='Each purchased VIN must contain exactly 17 valid characters.';
    elseif(count($saleAmounts)!==count(array_filter($saleAmounts,static fn($amount)=>is_numeric($amount)&&(float)$amount>0)))$error='Enter a valid amount for every purchased VIN.';
    elseif ($form['phone']!==''&&!is_valid_phone_number($form['phone'])) $error = 'Enter a valid customer phone number.';
    elseif ($form['other_phone'] !== '' && !is_valid_phone_number($form['other_phone'])) $error = 'Enter a valid other phone number.';
    elseif($duplicatePhone||$duplicateOtherPhone){$duplicate=$duplicatePhone?:$duplicateOtherPhone;$duplicateName=trim((string)($duplicate['company_name']?:$duplicate['owner_name']));$error='This phone number has already been registered'.($duplicateName!==''?' to '.$duplicateName:'').'.';}
    elseif($form['supervisor_phone']!==''&&!is_valid_phone_number($form['supervisor_phone']))$error='Enter a valid supervisor phone number.';
    elseif((int)$form['feedback_option_id']&&!$feedback)$error='Select a valid feedback option.';
    else {
        try {
            $imageTypes=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'];$videoTypes=['video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov'];
            $ownerPic=save_vendor_customer_media($isTaxi?'driver_pic':'owner_pic',$imageTypes,APP_IMAGE_UPLOAD_MAX_BYTES);$shopPic=save_vendor_customer_media($isTaxi?'station_pic':'shop_pic',$imageTypes,APP_IMAGE_UPLOAD_MAX_BYTES);$carPic=$isTaxi?save_vendor_customer_media('car_pic',$imageTypes,APP_IMAGE_UPLOAD_MAX_BYTES):null;$shopVideo=!$isTaxi?save_vendor_customer_media('shop_video',$videoTypes,30*1024*1024):null;
            $placeGroupKey=preg_match('/^[a-f0-9]{32}$/',$form['place_group_key'])?$form['place_group_key']:bin2hex(random_bytes(16));
            $recordCompanyName=$salesCustomer?((string)($salesCustomer['company_name']??'')?:null):($isTaxi?(string)$destination['destination_name']:($form['company_name']?:null));$recordOwnerName=$salesCustomer?((string)($salesCustomer['owner_name']??'')?:null):($isTaxi?($form['driver_name']?:null):($form['owner_name']?:null));$recordPhone=$salesCustomer?((string)($salesCustomer['phone']??'')?:null):($form['phone']?:null);$recordOtherPhone=$salesCustomer?((string)($salesCustomer['other_phone']??'')?:null):($form['other_phone']?:null);
            $statement=db()->prepare('INSERT INTO destination_visits (sales_trip_id,destination_id,vendor_id,parent_visit_id,place_group_key,recorded_by_user_id,visit_type,sales_ref,sale_vins_json,sale_amounts_json,promo_plug,shop_arrival_time,shop_departure_time,company_name,owner_name,phone,other_phone,location_id,area,google_location,shop_type_id,vehicle_registration_no,supervisor_name,supervisor_phone,vin_no,owner_pic,shop_pic,car_pic,shop_video,feedback,note,created_at,record_status) VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([(int)$destination['id'],(int)$vendor['id'],$salesCustomer?(int)$salesCustomer['id']:null,$placeGroupKey,(int)current_user_id(),'registration',$form['sales_ref']?:null,$saleVins?json_encode($saleVins):null,$saleAmounts?json_encode(array_map('floatval',$saleAmounts)):null,$form['promo_plug']?:null,null,null,$recordCompanyName,$recordOwnerName,$recordPhone,$recordOtherPhone,$selectedTown?(int)$selectedTown['id']:null,$form['area']?:null,$form['google_location']?:null,$isTaxi?null:((int)$form['shop_type_id']?:null),$isTaxi?($form['car_registration_no']?:null):null,$isTaxi?($form['supervisor_name']?:null):null,$isTaxi?($form['supervisor_phone']?:null):null,$isTaxi?($form['vin_no']?:null):null,$ownerPic,$shopPic,$carPic,$shopVideo,$feedback,$form['note']?:null,$form['visit_date'].' '.date('H:i:s'),$saveAsDraft?'draft':'completed']);
            $savedVisitId=(int)db()->lastInsertId();
            $savedCustomerName=$salesCustomer?(trim((string)($salesCustomer['owner_name']?:$salesCustomer['company_name']))?:'Customer'):(trim((string)($isTaxi?$form['driver_name']:($form['owner_name']?:$form['company_name'])))?:'Customer');
            $message=$saveAsDraft?$savedCustomerName.' saved as draft.':$savedCustomerName.' saved successfully.';
            $form=$blankForm;
            $customerFormSaved=true;
            $placeChoiceVisitId=0;
        } catch (Throwable $exception) {
            $error='The customer visit could not be saved.';
        }
    }
}

$locationTownOptions=$adminCustomerMode?$adminTownOptions:$managedTowns;
$locationRegions=[];foreach($locationTownOptions as $town){$key=(string)($town['region_code']?:$town['region_name']);$locationRegions[$key]=(string)$town['region_name'];}asort($locationRegions);
$selectedLocationRegion='';foreach($locationTownOptions as $town){if($form['location_id']===(string)$town['id']){$selectedLocationRegion=(string)($town['region_code']?:$town['region_name']);break;}}


require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker"><?= $adminCustomerMode?'Admin':'Vendor Workspace' ?></span><h1><?= $adminCustomerMode?'Create Customer':'Create Customers' ?></h1><p><?= $adminCustomerMode?'Register a customer for an active vendor without an active marketing trip.':'Register customers around your assigned town. The system applies your location automatically.' ?></p></div><div class="management-icon"><i class="fa-solid fa-user-plus"></i></div></div>
    <?php if($message):?><div class="profile-message is-success" role="status"><?=e($message)?></div><?php endif;?>
    <?php if($error):?><div class="profile-message is-error" role="alert"><?=e($error)?></div><?php endif;?>
    <?php if(!$adminCustomerMode): ?><div class="report-subnav"><a class="secondary-button" href="<?=e(app_url('vendor-reports.php'))?>"><i class="fa-solid fa-chart-column"></i><span>Customer Reports</span></a></div><?php endif; ?>
</section>


<section class="management-panel">
    <div class="management-heading management-heading--compact"><div><span class="section-kicker">Customer Details</span><h2><?= $adminCustomerMode?'Vendor customer registration':number_format(count($managedTowns)).' assigned town'.(count($managedTowns)===1?'':'s') ?></h2><p><?= $adminCustomerMode?'Choose a vendor and one of that vendor’s assigned towns.':'You can register customers only within these assigned towns.' ?></p></div><span class="status-badge is-active">Access secured</span></div>
    <form class="record-form mobile-line-form" method="post" enctype="multipart/form-data" data-standalone-customer-form data-form-recovery-key="<?=$adminCustomerMode?'admin':'vendor'?>-customer-<?=current_user_id()?>" data-form-recovery-clear="<?=$customerFormSaved?'true':'false'?>" data-location-edit-url="<?=e(app_url('registration-records.php?tab=places&return_to='.rawurlencode(app_url($adminCustomerMode?'admin-customers.php':'vendor-customers.php'))))?>" data-customer-edit-url="<?=e(app_url('registration-records.php?tab=completed&return_to='.rawurlencode(app_url($adminCustomerMode?'admin-customers.php':'vendor-customers.php'))))?>">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="place_group_key" value="<?=e($form['place_group_key'])?>">
        <div class="form-field form-field--wide sales-customer-picker" id="sales_customer_field" data-sales-customer-picker><input id="sales_customer_id" type="hidden" name="sales_customer_id" value="<?=e($form['sales_customer_id'])?>" data-sales-customer-id><span class="sales-customer-picker__label">Customer</span><button class="sales-customer-picker__button" type="button" data-open-sales-customer><span><i class="fa-solid fa-user-check"></i><strong data-sales-customer-name>Select customer</strong></span><i class="fa-solid fa-chevron-right"></i></button><button class="sales-customer-picker__clear" type="button" data-clear-sales-customer hidden>Clear selection</button></div>
        <div class="form-grid">
<?php if($adminCustomerMode): ?><div class="form-field form-field--wide"><label for="vendor_id">Vendor</label><select id="vendor_id" name="vendor_id" data-admin-customer-vendor data-vendor-selector data-popup-select data-popup-search data-popup-hide-empty required><option value="">Search or select vendor</option><?php foreach($vendors as $vendorOption):?><option value="<?=(int)$vendorOption['id']?>" <?=$form['vendor_id']===(string)$vendorOption['id']?'selected':''?>><?=e(implode(' · ',array_filter([(string)$vendorOption['vendor_name'],(string)($vendorOption['phone']??'')])))?></option><?php endforeach;?></select></div><?php endif; ?>
            <div class="form-field"><label for="visit_date">Visit Date</label><input id="visit_date" name="visit_date" type="date" value="<?=e($form['visit_date'])?>" required></div>
            <div class="form-field form-field--wide"><label for="destination_id">Destination</label><select id="destination_id" name="destination_id" data-destination-select required><option value="">Select destination</option><?php foreach($destinations as $destination):$destinationMode=destination_is_taxi_rank($destination)?'taxi_rank':'registration';?><option value="<?=(int)$destination['id']?>" data-destination-mode="<?=e($destinationMode)?>" <?=$form['destination_id']===(string)$destination['id']?'selected':''?>><?=e((string)$destination['destination_name'])?></option><?php endforeach;?></select></div>
            <div class="form-field"><label for="sales_ref">Sales Ref</label><textarea id="sales_ref" name="sales_ref" rows="2"><?=e($form['sales_ref'])?></textarea></div>
            <div class="form-field" data-visit-mode="registration"><label for="promo_plug">Promo Plug</label><input id="promo_plug" name="promo_plug" value="<?=e($form['promo_plug'])?>"></div>
            <div class="form-field"><label for="sale_vin_0">VIN Purchased</label><input id="sale_vin_0" name="sale_vin[]" maxlength="17" autocomplete="off"></div>
            <div class="form-field"><label for="sale_amount_0">Amount</label><input id="sale_amount_0" name="sale_amount[]" type="number" min="0.01" step="0.01" inputmode="decimal"></div>
            <div class="form-field"><label for="customer_region">Region</label><select id="customer_region" data-location-region-select required><option value="">Select region</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e((string)$regionKey)?>" <?=$selectedLocationRegion===(string)$regionKey?'selected':''?>><?=e($regionName)?></option><?php endforeach;?></select></div>
            <div class="form-field"><label for="location_id">Town</label><select id="location_id" name="location_id" data-location-town-select <?=$adminCustomerMode?'data-admin-customer-town':''?> required><option value="">Select assigned town</option><option value="__other__" data-add-town-option="true">Other — add a new town</option><?php foreach($locationTownOptions as $town):?><option value="<?=(int)$town['id']?>" data-region-key="<?=e((string)($town['region_code']?:$town['region_name']))?>" data-mmda-name="<?=e((string)$town['mmda_name'])?>" <?=$adminCustomerMode?'data-vendor-id="'.(int)$town['vendor_id'].'"':''?> <?=$form['location_id']===(string)$town['id']?'selected':''?>><?=e((string)$town['town_name'])?></option><?php endforeach;?></select><small data-location-mmda-output></small></div>
            <div class="form-field"><label for="area">Area</label><input id="area" name="area" value="<?=e($form['area'])?>" required></div>
            <div class="form-field" data-visit-mode="registration"><label for="company_name">Comp Name</label><input id="company_name" name="company_name" value="<?=e($form['company_name'])?>" required></div>
            <div class="form-field" data-visit-mode="registration"><label for="owner_name">Owner's Name</label><input id="owner_name" name="owner_name" value="<?=e($form['owner_name'])?>" required></div>
            <div class="form-field" data-visit-mode="registration taxi_rank"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?=e($form['phone'])?>" data-phone-input data-customer-phone-check="<?=e(app_url('customer-phone-check.php'))?>"></div>
            <div class="form-field" data-visit-mode="registration taxi_rank"><label for="other_phone">Other Phone</label><input id="other_phone" name="other_phone" type="tel" value="<?=e($form['other_phone'])?>" data-phone-input data-customer-phone-check="<?=e(app_url('customer-phone-check.php'))?>"></div>
            <div class="form-field" data-visit-mode="registration taxi_rank"><label for="google_location">Google Location</label><div class="field-control-row"><input id="google_location" name="google_location" type="url" value="<?=e($form['google_location'])?>" placeholder="Use GPS or paste a Google Maps link" required><button class="secondary-button secondary-button--small" type="button" data-current-location-target="google_location">Use GPS</button></div></div>
            <div class="form-field" data-visit-mode="registration"><label for="shop_type_id">Shop Type</label><select id="shop_type_id" name="shop_type_id"><option value="">Select shop type</option><?php foreach($shopTypes as $type):?><option value="<?=(int)$type['id']?>" <?=$form['shop_type_id']===(string)$type['id']?'selected':''?>><?=e((string)$type['shop_type_name'])?></option><?php endforeach;?></select></div>
            <div class="form-field" data-visit-mode="taxi_rank"><label for="driver_name">Driver's Name</label><input id="driver_name" name="driver_name" value="<?=e($form['driver_name'])?>" required></div>
            <div class="form-field" data-visit-mode="taxi_rank"><label for="car_registration_no">Car Registration No</label><input id="car_registration_no" name="car_registration_no" value="<?=e($form['car_registration_no'])?>" required></div>
            <div class="form-field" data-visit-mode="taxi_rank"><label for="supervisor_name">Supervisor Name</label><input id="supervisor_name" name="supervisor_name" value="<?=e($form['supervisor_name'])?>"></div>
            <div class="form-field" data-visit-mode="taxi_rank"><label for="supervisor_phone">Supervisor Phone</label><input id="supervisor_phone" name="supervisor_phone" type="tel" value="<?=e($form['supervisor_phone'])?>" data-phone-input></div>
            <div class="form-field" data-visit-mode="taxi_rank"><label for="vin_no">VIN No</label><input id="vin_no" name="vin_no" value="<?=e($form['vin_no'])?>"></div>
            <div class="form-field" data-visit-mode="registration"><label for="owner_pic">Owner's Pic</label><input id="owner_pic" name="owner_pic" type="file" accept="image/*" data-photo-source-choice></div>
            <div class="form-field" data-visit-mode="registration"><label for="shop_pic">Shop Pic</label><input id="shop_pic" name="shop_pic" type="file" accept="image/*" data-photo-source-choice></div>
            <div class="form-field" data-visit-mode="registration"><label for="shop_video">Shop Video</label><input id="shop_video" name="shop_video" type="file" accept="video/mp4,video/webm,video/quicktime"></div>
            <div class="form-field" data-visit-mode="taxi_rank"><label for="driver_pic">Driver's Pic</label><input id="driver_pic" name="driver_pic" type="file" accept="image/*" data-photo-source-choice></div>
            <div class="form-field" data-visit-mode="taxi_rank"><label for="station_pic">Station Pic</label><input id="station_pic" name="station_pic" type="file" accept="image/*" data-photo-source-choice></div>
            <div class="form-field" data-visit-mode="taxi_rank"><label for="car_pic">Car Pic</label><input id="car_pic" name="car_pic" type="file" accept="image/*" data-photo-source-choice></div>
            <div class="form-field" data-visit-mode="registration"><label for="feedback_option_id">Feedback</label><select id="feedback_option_id" name="feedback_option_id"><option value="">Select feedback</option><?php foreach($feedbackOptions as $option):?><option value="<?=(int)$option['id']?>" <?=$form['feedback_option_id']===(string)$option['id']?'selected':''?>><?=e((string)$option['feedback_label'])?></option><?php endforeach;?></select></div>
            <div class="form-field form-field--wide"><label for="note">Note</label><textarea id="note" name="note" rows="3"><?=e($form['note'])?></textarea></div>
        </div>
        <div class="form-actions"><button class="login-button" type="submit" formnovalidate><span>Save / Next</span><i class="fa-solid fa-arrow-right"></i></button></div>
    </form>
</section>
<dialog class="sales-customer-dialog" data-sales-customer-dialog>
    <div class="sales-customer-dialog__header"><div><span>Customer records</span><h2>Select Customer</h2></div><button type="button" data-close-sales-customer aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <label class="sales-customer-dialog__search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search customer, phone, area, or vendor" data-sales-customer-search></label>
    <div class="sales-customer-dialog__list" data-sales-customer-list><?php foreach($salesCustomers as $salesCustomer):$salesCustomerName=trim((string)($salesCustomer['owner_name']?:$salesCustomer['company_name']))?:'Unnamed customer';?><button type="button" class="sales-customer-option" data-sales-customer-option data-customer-id="<?=(int)$salesCustomer['id']?>" data-vendor-id="<?=(int)$salesCustomer['vendor_id']?>" data-customer-name="<?=e($salesCustomerName)?>" data-customer-search="<?=e(strtolower(implode(' ',[$salesCustomerName,(string)$salesCustomer['company_name'],(string)$salesCustomer['phone'],(string)$salesCustomer['other_phone'],(string)$salesCustomer['area'],(string)$salesCustomer['vendor_name']])))?>"><span><strong><?=e($salesCustomerName)?></strong><small><?=e((string)$salesCustomer['phone'])?><?=trim((string)$salesCustomer['area'])!==''?' · '.e((string)$salesCustomer['area']):''?></small></span><span class="sales-customer-option__meta"><b>#<?=(int)$salesCustomer['id']?></b><small><?=e((string)$salesCustomer['vendor_name'])?></small></span></button><?php endforeach;?></div>
    <p class="empty-state" data-sales-customer-empty hidden>No customer matches your search.</p>
</dialog>
<?php if($placeChoiceVisitId>0): ?>
<div class="place-choice-backdrop" data-place-choice-modal>
    <section class="place-choice-dialog" role="dialog" aria-modal="true" aria-labelledby="place-choice-title">
        <span class="place-choice-dialog__icon"><i class="fa-solid fa-location-dot"></i></span>
        <div><span class="section-kicker">Customer saved</span><h2 id="place-choice-title">Where is the next customer?</h2><p>Choose Same Location to reuse and group this location, or Other Location to start with a clean location.</p></div>
        <div class="place-choice-dialog__actions">
            <a class="login-button" href="<?=e(app_url(($adminCustomerMode?'admin-customers.php':'vendor-customers.php').'?same_place='.$placeChoiceVisitId))?>"><i class="fa-solid fa-location-dot"></i><span>Same Location</span></a>
            <a class="secondary-button" href="<?=e(app_url($adminCustomerMode?'admin-customers.php':'vendor-customers.php'))?>"><i class="fa-solid fa-map-location-dot"></i><span>Other Location</span></a>
            <button class="place-choice-finish" type="button" data-place-choice-close>Finish</button>
        </div>
    </section>
</div>
<?php endif; ?>
<?php if($adminCustomerMode): ?><script>
document.addEventListener('DOMContentLoaded',()=>{const vendor=document.querySelector('[data-admin-customer-vendor]');const town=document.querySelector('[data-admin-customer-town]');if(!vendor||!town)return;const sync=()=>{const selected=town.value;Array.from(town.options).forEach((option,index)=>{if(index===0)return;const show=vendor.value!==''&&option.dataset.vendorId===vendor.value;option.hidden=!show;option.disabled=!show;});if(town.selectedOptions[0]?.disabled)town.value='';town.dispatchEvent(new Event('change',{bubbles:true}));};vendor.addEventListener('change',sync);sync();});
</script><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
