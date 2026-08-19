<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');
ensure_destination_visit_schema();

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) $payload = $_POST;

if (!verify_csrf_token((string)($payload['csrf_token'] ?? ''))) {
    echo json_encode(['success'=>false,'message'=>'Your session expired. Please refresh and try again.']); exit;
}

$recordId=max(0,(int)($payload['record_id']??0));
$source=(string)($payload['source']??'visit');
$confirmed=!empty($payload['confirmed'])?1:0;
$rawVins=is_array($payload['vins']??null)?$payload['vins']:preg_split('/[,\r\n]+/',(string)($payload['vins']??''));
$vins=[];foreach($rawVins as $rawVin){$vin=strtoupper(trim((string)$rawVin));if($vin!=='')$vins[]=$vin;}$vins=array_values(array_unique($vins));
if($vins)$confirmed=1;
if($recordId<=0||!in_array($source,['visit','vendor_customer'],true)){
    echo json_encode(['success'=>false,'message'=>'Invalid customer record.']); exit;
}
foreach($vins as $vin){if(strlen($vin)>80){echo json_encode(['success'=>false,'message'=>'Each VIN or chassis number must be 80 characters or fewer.']);exit;}}

$allowed=false;
if($source==='visit'){
    $statement=db()->prepare('SELECT id,vendor_id,staff_id,recorded_by_user_id,sales_ref FROM destination_visits WHERE id=? AND visit_type=\'registration\' LIMIT 1');
    $statement->execute([$recordId]);$record=$statement->fetch();
    if($record){
        $vendor=current_vendor_profile();$staffId=current_staff_id();
        $allowed=is_admin_user()
            || (current_user_role()==='vendor'&&$vendor&&(int)$record['vendor_id']===(int)$vendor['id'])
            || (current_user_role()==='staff'&&((int)$record['recorded_by_user_id']===(int)current_user_id()||($staffId&&(int)$record['staff_id']===$staffId)));
        if($allowed){
            $existingVins=customer_pos_sale_vins($source,$recordId);
            if($confirmed&&!$vins&&!$existingVins){echo json_encode(['success'=>false,'message'=>'Enter at least one VIN before marking this customer as purchased.']);exit;}
            $duplicate=db()->prepare("SELECT vin_no FROM customer_pos_sale_vins WHERE vin_no IN (".implode(',',array_fill(0,max(1,count($vins)),'?')).") AND NOT (customer_source=? AND record_id=?) LIMIT 1");
            if($vins){$duplicate->execute([...$vins,$source,$recordId]);$duplicateVin=$duplicate->fetchColumn();if($duplicateVin){echo json_encode(['success'=>false,'message'=>'VIN '.$duplicateVin.' is already registered to another customer.']);exit;}}
            db()->beginTransaction();
            try{$insert=db()->prepare('INSERT IGNORE INTO customer_pos_sale_vins (customer_source,record_id,vin_no,created_by_user_id) VALUES (?,?,?,?)');foreach($vins as $vin)$insert->execute([$source,$recordId,$vin,current_user_id()]);$allVins=array_values(array_unique(array_merge($existingVins,$vins)));$confirmed=($confirmed||count($allVins)>0)?1:0;db()->prepare('UPDATE destination_visits SET sale_confirmed=? WHERE id=?')->execute([$confirmed,$recordId]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();echo json_encode(['success'=>false,'message'=>'The VIN details could not be saved.']);exit;}
            $vins=$allVins;$sold=trim((string)($record['sales_ref']??''))!==''||$confirmed===1;
        }
    }
}else{
    $statement=db()->prepare('SELECT id,vendor_id FROM vendor_customers WHERE id=? LIMIT 1');$statement->execute([$recordId]);$record=$statement->fetch();
    $vendor=current_vendor_profile();
    $allowed=$record&&(is_admin_user()||(current_user_role()==='vendor'&&$vendor&&(int)$record['vendor_id']===(int)$vendor['id']));
    if($allowed){db()->prepare('UPDATE vendor_customers SET sale_confirmed=? WHERE id=?')->execute([$confirmed,$recordId]);$sold=$confirmed===1;}
}

if(!$allowed){http_response_code(403);echo json_encode(['success'=>false,'message'=>'You are not allowed to update this customer.']);exit;}
echo json_encode(['success'=>true,'sold'=>$sold,'label'=>$sold?'Yes':'No','vins'=>$vins,'purchase_count'=>count($vins)]);
