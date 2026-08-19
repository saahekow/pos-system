<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_status_schema();
ensure_places_management_schema();
ensure_customer_promo_plug_schema();

$source = (string)($_GET['source'] ?? $_POST['source'] ?? '');
$sourceId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$requestedReturn=trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
$appBase=rtrim(app_url(''),'/');
$returnTo=($requestedReturn!==''&&($requestedReturn===$appBase||str_starts_with($requestedReturn,$appBase.'/')))?$requestedReturn:app_url('registration-records.php?tab=completed');
if (!in_array($source, ['destination_visit','vendor_customer'], true) || $sourceId < 1) {
    http_response_code(404); exit('Legacy customer not found.');
}

$vendorProfile = current_vendor_profile();
$vendorId = (int)($vendorProfile['id'] ?? 0);
if ($source === 'destination_visit') {
    $statement=db()->prepare("SELECT dv.id,dv.vendor_id,dv.sales_trip_id,dv.staff_id,dv.recorded_by_user_id,dv.owner_name AS customer_name,dv.company_name,dv.phone,dv.other_phone,dv.owner_pic AS customer_picture,dv.vehicle_registration_no,dv.vin_no,dv.supervisor_name,dv.supervisor_phone,dv.feedback,dv.note,dv.promo_plug,dv.created_at,dv.normalized_customer_id,dv.normalized_visit_id FROM destination_visits dv LEFT JOIN sales_trips st ON st.id=dv.sales_trip_id WHERE dv.id=? AND dv.visit_type='registration' AND (".(is_admin_user()?'1=1':'dv.vendor_id=? OR dv.recorded_by_user_id=? OR st.vendor_id=?').") LIMIT 1");
    $statement->execute(is_admin_user()?[$sourceId]:[$sourceId,$vendorId,current_user_id(),$vendorId]);
} else {
    $statement=db()->prepare("SELECT vc.id,vc.vendor_id,NULL AS sales_trip_id,NULL AS staff_id,vc.created_by_user_id AS recorded_by_user_id,vc.customer_name,vc.customer_name AS company_name,vc.phone,vc.other_phone,NULL AS customer_picture,NULL AS vehicle_registration_no,NULL AS vin_no,NULL AS supervisor_name,NULL AS supervisor_phone,NULL AS feedback,vc.notes AS note,NULL AS promo_plug,vc.created_at,vc.normalized_customer_id,vc.normalized_visit_id FROM vendor_customers vc WHERE vc.id=? AND ".(is_admin_user()?'1=1':'vc.vendor_id=?')." LIMIT 1");
    $statement->execute(is_admin_user()?[$sourceId]:[$sourceId,$vendorId]);
}
$legacy=$statement->fetch();
if(!$legacy){http_response_code(404);exit('Legacy customer not found or access denied.');}
if((int)($legacy['normalized_customer_id']??0)>0){header('Location: '.$returnTo);exit;}

$places=db()->query("SELECT p.id,p.bus_loc_ref,COALESCE(NULLIF(p.business_name,''),p.bus_loc_ref) business_name,p.area,l.town_name,l.region_name FROM business_locations p LEFT JOIN locations l ON l.id=p.location_id WHERE p.is_active=1 AND p.is_legacy_placeholder=0 ORDER BY p.business_name,l.town_name")->fetchAll();
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf_token((string)($_POST['csrf_token']??''))){http_response_code(419);exit('Your session expired. Refresh the page and try again.');}
    $placeId=max(0,(int)($_POST['bus_loc_id']??0));
    $valid=db()->prepare('SELECT id FROM business_locations WHERE id=? AND is_active=1 AND is_legacy_placeholder=0');$valid->execute([$placeId]);
    if(!$valid->fetchColumn()){$error='Select a valid business location.';}
    else try{
        db()->beginTransaction();
        $customerId=0;$digits=substr(preg_replace('/\D/','',(string)($legacy['phone']??'')),-9);
        if($digits!==''){
            foreach(db()->query("SELECT id,phone,other_phone FROM customers WHERE is_active=1")->fetchAll() as $candidate){
                foreach(['phone','other_phone'] as $field){if(substr(preg_replace('/\D/','',(string)($candidate[$field]??'')),-9)===$digits){$customerId=(int)$candidate['id'];break 2;}}
            }
        }
        $name=trim((string)($legacy['customer_name']??''))?:trim((string)($legacy['company_name']??''))?:'Customer';
        if($customerId>0){db()->prepare("UPDATE customers SET bus_loc_id=?,vendor_id=COALESCE(vendor_id,?),customer_status='registered',updated_at=NOW() WHERE id=?")->execute([$placeId,(int)($legacy['vendor_id']??0)?:null,$customerId]);}
        else{
            db()->prepare('INSERT INTO customers(customer_ref,bus_loc_id,vendor_id,customer_name,phone,other_phone,customer_picture,vehicle_registration_no,vin_no,supervisor_name,supervisor_phone,created_by_user_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([next_project_reference('customer'),$placeId,(int)($legacy['vendor_id']??0)?:null,$name,$legacy['phone']?:null,$legacy['other_phone']?:null,$legacy['customer_picture']?:null,$legacy['vehicle_registration_no']?:null,$legacy['vin_no']?:null,$legacy['supervisor_name']?:null,$legacy['supervisor_phone']?:null,(int)($legacy['recorded_by_user_id']??0)?:current_user_id(),$legacy['created_at']]);
            $customerId=(int)db()->lastInsertId();
        }
        db()->prepare("INSERT INTO visits(visit_ref,sales_trip_id,place_session_id,bus_loc_id,customer_id,vendor_id,staff_id,recorded_by_user_id,visit_type,visit_date,record_status,created_at) VALUES(?,?,NULL,?,?,?,?,?,'registration',?,'completed',?)")->execute([next_project_reference('visit'),$legacy['sales_trip_id']?:null,$placeId,$customerId,$legacy['vendor_id']?:null,$legacy['staff_id']?:null,$legacy['recorded_by_user_id']?:current_user_id(),substr((string)$legacy['created_at'],0,10),$legacy['created_at']]);
        $visitId=(int)db()->lastInsertId();
        if(trim((string)($legacy['feedback']??''))!==''||trim((string)($legacy['note']??''))!=='')db()->prepare('INSERT INTO visit_notes(note_ref,visit_id,customer_id,feedback,note,staff_id,vendor_id,recorded_by_user_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([next_project_reference('visit_note'),$visitId,$customerId,$legacy['feedback']?:null,$legacy['note']?:null,$legacy['staff_id']?:null,$legacy['vendor_id']?:null,$legacy['recorded_by_user_id']?:current_user_id(),$legacy['created_at']]);
        if(trim((string)($legacy['promo_plug']??''))!=='')db()->prepare('INSERT IGNORE INTO customer_promo_plugs(visit_id,customer_id,bus_loc_id,promo_plug,recorded_by_user_id,created_at) VALUES(?,?,?,?,?,?)')->execute([$visitId,$customerId,$placeId,$legacy['promo_plug'],$legacy['recorded_by_user_id']?:current_user_id(),$legacy['created_at']]);
        $table=$source==='destination_visit'?'destination_visits':'vendor_customers';db()->prepare("UPDATE `$table` SET normalized_customer_id=?,normalized_visit_id=?,migrated_at=NOW() WHERE id=?")->execute([$customerId,$visitId,$sourceId]);
        db()->commit();header('Location: '.$returnTo);exit;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error='The legacy customer could not be assigned to the location.';}
}
$pageTitle='Assign Customer Location';$breadcrumbs=[['label'=>'Registration Records','url'=>$returnTo],['label'=>'Assign Location']];require_once __DIR__.'/../includes/header.php';
?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">Legacy customer</span><h1>Assign Location</h1><p>Link <?=e((string)($legacy['customer_name']?:$legacy['company_name']?:'customer'))?> to a normalized business location.</p></div></div><?php if($error!==''):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?><form method="post" class="stacked-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="source" value="<?=e($source)?>"><input type="hidden" name="id" value="<?=$sourceId?>"><input type="hidden" name="return_to" value="<?=e($returnTo)?>"><div class="form-field"><label for="bus_loc_id">Business location</label><select id="bus_loc_id" name="bus_loc_id" required><option value="">Select location</option><?php foreach($places as $place):?><option value="<?=(int)$place['id']?>"><?=e(implode(' · ',array_filter([$place['business_name'],$place['town_name'],$place['region_name'],$place['area']])))?></option><?php endforeach;?></select></div><div class="form-actions"><a class="secondary-button" href="<?=e($returnTo)?>">Back</a><button class="primary-button" type="submit"><i class="fa-solid fa-location-dot"></i> Assign Location</button></div></form></section>
<?php require_once __DIR__.'/../includes/footer.php';
