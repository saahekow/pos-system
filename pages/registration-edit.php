<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_promo_plug_schema();
require_module_access('registration_records');
ensure_job_type_schema();

function registration_edit_upload(string $field): ?string
{
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > APP_IMAGE_UPLOAD_MAX_BYTES) {
        throw new RuntimeException('A selected picture could not be uploaded or is too large.');
    }
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'];
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($allowed[$mime])) $mime = ['heic'=>'image/heic','heif'=>'image/heif'][$extension] ?? $mime;
    if (!isset($allowed[$mime])) throw new RuntimeException('Choose a supported picture file.');
    $directory = __DIR__ . '/../assets/uploads/normalized-visits';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('The media folder could not be created.');
    $name = $field . '-' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $directory . '/' . $name)) throw new RuntimeException('The selected picture could not be saved.');
    return 'assets/uploads/normalized-visits/' . $name;
}
function registration_edit_phone_conflict(string $phone, string $type, int $recordId, int $customerId = 0): ?string
{
    $digits = substr(preg_replace('/\D/', '', normalize_phone_number($phone)), -9);
    if ($digits === '') return null;

    foreach (db()->query('SELECT id,customer_name,phone,other_phone FROM customers WHERE is_active=1')->fetchAll() as $customer) {
        if ($customerId > 0 && (int)$customer['id'] === $customerId) continue;
        foreach (['phone','other_phone'] as $field) {
            if (substr(preg_replace('/\D/', '', (string)($customer[$field] ?? '')), -9) === $digits) {
                return 'This phone number is already registered to '.((string)$customer['customer_name'] ?: 'another customer').'.';
            }
        }
    }
    foreach (db()->query('SELECT id,draft_ref,draft_payload FROM customer_visit_drafts')->fetchAll() as $draft) {
        if ($type === 'draft' && (int)$draft['id'] === $recordId) continue;
        $draftPayload = json_decode((string)$draft['draft_payload'], true) ?: [];
        foreach (['phone','other_phone'] as $field) {
            if (substr(preg_replace('/\D/', '', (string)($draftPayload[$field] ?? '')), -9) === $digits) {
                return 'This phone number is already saved in draft '.$draft['draft_ref'].'. Continue that draft instead.';
            }
        }
    }
    return null;
}

$type = (string)($_GET['type'] ?? $_POST['record_type'] ?? '');
$id = max(0, (int)($_GET['id'] ?? $_POST['record_id'] ?? 0));
$requestedReturnTo=trim((string)($_GET['return_to']??$_POST['return_to']??''));$recordsBaseUrl=app_url('registration-records.php');$defaultReturnTo=app_url('registration-records.php?tab='.($type==='draft'?'drafts':'completed'));$returnTo=($requestedReturnTo===$recordsBaseUrl||str_starts_with($requestedReturnTo,$recordsBaseUrl.'?'))?$requestedReturnTo:$defaultReturnTo;$returnWithStatus=static function(string $status,string $customerName)use($returnTo):string{return $returnTo.(str_contains($returnTo,'?')?'&':'?').'status='.rawurlencode($status).'&customer_name='.rawurlencode($customerName);};
$error = '';
$record = null;
$payload = [];

if ($type === 'draft') {
    $statement = db()->prepare(
        'SELECT d.*,ps.sales_trip_id,ps.arrival_time,ps.activity_date,ps.session_type,p.id AS bus_loc_id,p.business_name,p.bus_loc_ref,p.google_location,p.area,l.town_name,COALESCE(st.trip_code,\'ADDENDUM\') AS trip_code,dest.destination_key
         FROM customer_visit_drafts d
         INNER JOIN place_visit_sessions ps ON ps.id=d.place_session_id
         INNER JOIN business_locations p ON p.id=ps.bus_loc_id
         LEFT JOIN destinations dest ON dest.id=p.destination_id
         LEFT JOIN locations l ON l.id=p.location_id
         LEFT JOIN sales_trips st ON st.id=ps.sales_trip_id
         WHERE d.id=?'
    );
    $statement->execute([$id]);
    $record = $statement->fetch();
    $payload = $record ? (json_decode((string)$record['draft_payload'], true) ?: []) : [];
} elseif ($type === 'completed') {
    $statement = db()->prepare(
        "SELECT v.id AS visit_id,v.sales_trip_id,v.visit_ref,v.recorded_by_user_id,v.arrival_time,v.departure_time,
                c.id AS customer_id,c.*,p.business_name,p.bus_loc_ref,p.google_location,p.area,l.town_name,COALESCE(st.trip_code,'ADDENDUM') AS trip_code,dest.destination_key,
                cs.sales_ref,cs.promo_plug,cs.sale_confirmed,cs.car_picture,
                (SELECT n.feedback FROM visit_notes n WHERE n.visit_id=v.id ORDER BY n.id DESC LIMIT 1) feedback,
                (SELECT n.note FROM visit_notes n WHERE n.visit_id=v.id ORDER BY n.id DESC LIMIT 1) notes
         FROM visits v
         INNER JOIN customers c ON c.id=v.customer_id
         INNER JOIN business_locations p ON p.id=v.bus_loc_id
         LEFT JOIN destinations dest ON dest.id=p.destination_id
         LEFT JOIN locations l ON l.id=p.location_id
         LEFT JOIN customer_sales cs ON cs.visit_id=v.id
         LEFT JOIN sales_trips st ON st.id=v.sales_trip_id
         WHERE v.id=? AND v.record_status='completed'"
    );
    $statement->execute([$id]);
    $record = $statement->fetch();
    if ($record) {
        $payload = $record;
        $payload['job_type_id'] = (string)($record['job_type_id'] ?? '');
        $purchaseStatement = db()->prepare("SELECT vin_no,amount FROM customer_pos_sale_vins WHERE customer_source='visit' AND record_id=? ORDER BY id");
        $purchaseStatement->execute([$id]);
        $savedPurchases = $purchaseStatement->fetchAll();
        $payload['sale_vin'] = array_column($savedPurchases, 'vin_no');
        $payload['sale_amount'] = array_map(static fn(array $purchase): string => $purchase['amount'] === null ? '' : (string)$purchase['amount'], $savedPurchases);
    }
}

if (!$record) {
    http_response_code(404);
    exit('Registration record not found.');
}
$payload['sale_vin'] = isset($payload['sale_vin']) && is_array($payload['sale_vin']) ? $payload['sale_vin'] : [];
$payload['sale_amount'] = isset($payload['sale_amount']) && is_array($payload['sale_amount']) ? $payload['sale_amount'] : [];
if ((int)($record['recorded_by_user_id'] ?? 0) !== (int)current_user_id()
    && !can_access_registration_trip((int)$record['sales_trip_id'])) {
    http_response_code(403);
    exit('You do not have access to this registration.');
}
$isTaxiRegistration = (string)($record['destination_key'] ?? '') === 'taxi_rank';
$originalPhone = normalize_phone_number((string)($payload['phone'] ?? ''));
$originalOtherPhone = normalize_phone_number((string)($payload['other_phone'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $type === 'completed' && (string)($_POST['form_action']??'save') === 'delete') {
    if(!verify_csrf_token((string)($_POST['csrf_token']??''))) $error='Your session expired. Please try again.';
    else {
        try {
            $customerId=(int)($record['customer_id']??0);
            db()->beginTransaction();
            db()->prepare("DELETE FROM customer_pos_sale_vins WHERE customer_source='visit' AND record_id=?")->execute([$id]);
            db()->prepare('DELETE FROM visit_notes WHERE visit_id=?')->execute([$id]);
            db()->prepare('DELETE FROM customer_promo_plugs WHERE visit_id=?')->execute([$id]);
            db()->prepare('DELETE FROM visits WHERE id=?')->execute([$id]);
            if($customerId>0){db()->prepare("DELETE FROM customers WHERE id=? AND NOT EXISTS(SELECT 1 FROM visits WHERE customer_id=?) AND NOT EXISTS(SELECT 1 FROM customer_visit_drafts WHERE customer_id=?)")->execute([$customerId,$customerId,$customerId]);}
            db()->commit();
            header('Location: '.$returnTo.(str_contains($returnTo,'?')?'&':'?').'deleted=1');exit;
        } catch(Throwable $exception) { if(db()->inTransaction())db()->rollBack();$error='The customer registration could not be deleted.'; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action']??'save') !== 'delete') {
    foreach (['customer_name','phone','other_phone','vehicle_registration_no','vin_no','supervisor_name','supervisor_phone'] as $field) {
        $payload[$field] = trim((string)($_POST[$field] ?? ''));
    }
    $payload['phone'] = normalize_phone_number((string)$payload['phone']);
    $payload['other_phone'] = normalize_phone_number((string)$payload['other_phone']);
    $payload['supervisor_phone'] = normalize_phone_number((string)$payload['supervisor_phone']);
    if (!$isTaxiRegistration) {
        $payload['vehicle_registration_no'] = '';
        $payload['vin_no'] = '';
        $payload['supervisor_name'] = '';
        $payload['supervisor_phone'] = '';
    }
    $payload['job_type_id'] = max(0, (int)($_POST['job_type_id'] ?? 0));
    $payload['feedback'] = trim((string)($_POST['feedback'] ?? ($payload['feedback'] ?? '')));
    $payload['notes'] = trim((string)($_POST['notes'] ?? ($payload['notes'] ?? '')));
    $payload['promo_plug'] = trim((string)($_POST['promo_plug'] ?? ($payload['promo_plug'] ?? '')));
    $arrivalTime = trim((string)($_POST['arrival_time'] ?? substr((string)($record['arrival_time'] ?? ''), 0, 5)));
    $departureTime = trim((string)($_POST['departure_time'] ?? substr((string)($record['departure_time'] ?? ''), 0, 5)));
    $purchases = [];
    $purchaseError = '';
    $seenVins = [];
    $purchaseCount = max(count($payload['sale_vin']), count($payload['sale_amount']));
    for ($purchaseIndex=0;$purchaseIndex<$purchaseCount;$purchaseIndex++) {
        $purchaseVin = strtoupper(preg_replace('/\s+/', '', trim((string)($payload['sale_vin'][$purchaseIndex] ?? ''))) ?? '');
        $purchaseAmount = str_replace([',',' '], '', trim((string)($payload['sale_amount'][$purchaseIndex] ?? '')));
        if ($purchaseVin === '' && $purchaseAmount === '') continue;
        if ($purchaseVin === '' || $purchaseAmount === '') {$purchaseError='Enter both the VIN and amount for every purchased vehicle.';break;}
        if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/',$purchaseVin)) {$purchaseError=$purchaseVin.' is not a valid 17-character VIN.';break;}
        if (!is_numeric($purchaseAmount) || (float)$purchaseAmount<=0) {$purchaseError='Enter a valid amount for VIN '.$purchaseVin.'.';break;}
        if (isset($seenVins[$purchaseVin])) {$purchaseError='VIN '.$purchaseVin.' was entered more than once.';break;}
        $seenVins[$purchaseVin]=true;
        $purchases[]=['vin'=>$purchaseVin,'amount'=>number_format((float)$purchaseAmount,2,'.','')];
    }
    $jobType = null;
    if ($payload['job_type_id']) {
        $statement = db()->prepare('SELECT job_type_name FROM job_types WHERE id=? AND is_active=1');
        $statement->execute([$payload['job_type_id']]);
        $jobType = $statement->fetchColumn() ?: null;
    }
    $phoneConflict = $payload['phone'] !== '' && $payload['phone'] !== $originalPhone
        ? registration_edit_phone_conflict((string)$payload['phone'], $type, $id, (int)($record['customer_id'] ?? 0))
        : null;
    $otherPhoneConflict = $payload['other_phone'] !== '' && $payload['other_phone'] !== $originalOtherPhone
        ? registration_edit_phone_conflict((string)$payload['other_phone'], $type, $id, (int)($record['customer_id'] ?? 0))
        : null;
    $uploadError = '';
    if (verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        try {
            $payload['customer_picture'] = registration_edit_upload('customer_picture') ?: ($payload['customer_picture'] ?? null);
            if ($isTaxiRegistration) $payload['car_picture'] = registration_edit_upload('car_picture') ?: ($payload['car_picture'] ?? null);
        } catch (RuntimeException $exception) {
            $uploadError = $exception->getMessage();
        }
    }

    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($uploadError !== '') {
        $error = $uploadError;
    } elseif ($payload['customer_name'] === '') {
        $error = 'Enter the customer, owner, or driver name.';
    } elseif ($payload['phone'] !== '' && !is_valid_phone_number((string)$payload['phone'])) {
        $error = 'Enter a valid phone number.';
    } elseif ($payload['other_phone'] !== '' && !is_valid_phone_number((string)$payload['other_phone'])) {
        $error = 'Enter a valid other phone number.';
    } elseif ($phoneConflict || $otherPhoneConflict) {
        $error = $phoneConflict ?: $otherPhoneConflict;
    } elseif ($payload['job_type_id'] && !$jobType) {
        $error = 'Select a valid job type.';
    } elseif ($purchaseError !== '') {
        $error = $purchaseError;
    } elseif ($arrivalTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $arrivalTime)) {
        $error = 'Select a valid location arrival time.';
    } elseif ($departureTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $departureTime)) {
        $error = 'Select a valid location departure time.';
    } else {
        if ($type === 'draft') {
            if ($arrivalTime !== '' && trim((string)($record['arrival_time'] ?? '')) === '') {
                db()->prepare('UPDATE place_visit_sessions SET arrival_time=? WHERE id=?')->execute([$arrivalTime,(int)$record['place_session_id']]);
                $record['arrival_time'] = $arrivalTime;
            }
            $payload['job_type'] = $jobType;
            $canCompleteDraft = $payload['customer_name'] !== ''
                && $payload['phone'] !== ''
                && is_valid_phone_number((string)$payload['phone'])
                && $payload['feedback'] !== ''
                && $payload['notes'] !== ''
                && ((string)($record['session_type'] ?? 'trip') === 'addendum'
                    || (trim((string)($record['google_location'] ?? '')) !== '' && trim((string)($record['arrival_time'] ?? '')) !== ''));
            if ((int)($record['customer_id'] ?? 0) > 0) {
                db()->prepare("UPDATE customers SET vendor_id=?,customer_name=?,job_type=?,job_type_id=?,phone=?,other_phone=?,vehicle_registration_no=?,vin_no=?,supervisor_name=?,supervisor_phone=?,record_status=? WHERE id=?")->execute([max(0,(int)($payload['vendor_id']??0)) ?: null,$payload['customer_name']?:'Incomplete customer',$jobType,$payload['job_type_id']?:null,$payload['phone']?:null,$payload['other_phone']?:null,$payload['vehicle_registration_no']?:null,$payload['vin_no']?:null,$payload['supervisor_name']?:null,$payload['supervisor_phone']?:null,$canCompleteDraft?'completed':'draft',(int)$record['customer_id']]);
            }
            if (!$canCompleteDraft) {
                db()->prepare('UPDATE customer_visit_drafts SET draft_payload=? WHERE id=?')
                    ->execute([json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
            } else {
                try {
                    db()->beginTransaction();
                    $customerId = max(0, (int)($record['customer_id'] ?? 0));
                    if ($customerId <= 0) {
                        $customerStatement = db()->prepare(
                            'INSERT INTO customers
                             (customer_ref,bus_loc_id,vendor_id,customer_name,job_type,job_type_id,phone,other_phone,customer_picture,
                              vehicle_registration_no,vin_no,supervisor_name,supervisor_phone,created_by_user_id)
                             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                        );
                        $customerStatement->execute([
                            next_project_reference('customer'),(int)$record['bus_loc_id'],max(0,(int)($payload['vendor_id']??0)) ?: null,$payload['customer_name'],
                            $jobType,$payload['job_type_id'] ?: null,$payload['phone'],$payload['other_phone'] ?: null,
                            $payload['customer_picture'] ?: null,$payload['vehicle_registration_no'] ?: null,$payload['vin_no'] ?: null,
                            $payload['supervisor_name'] ?: null,$payload['supervisor_phone'] ?: null,current_user_id(),
                        ]);
                        $customerId = (int)db()->lastInsertId();
                    }
                    $visitStatement = db()->prepare(
                        "INSERT INTO visits
                         (visit_ref,sales_trip_id,place_session_id,bus_loc_id,customer_id,vendor_id,staff_id,
                          recorded_by_user_id,visit_type,visit_date,arrival_time,record_status)
                         VALUES(?,?,?,?,?,?,?,?, 'registration',?,?,'completed')"
                    );
                    $visitStatement->execute([
                        next_project_reference('visit'),$record['sales_trip_id'] !== null ? (int)$record['sales_trip_id'] : null,(int)$record['place_session_id'],
                        (int)$record['bus_loc_id'],$customerId,max(0,(int)($payload['vendor_id']??0)) ?: ((int)(current_vendor_profile()['id'] ?? 0) ?: null),
                        current_staff_id(),(int)($record['recorded_by_user_id'] ?? current_user_id()),
                        (string)($record['activity_date'] ?: date('Y-m-d')),substr((string)$record['arrival_time'],0,5) ?: null,
                    ]);
                    $visitId = (int)db()->lastInsertId();
                    if ($purchases) {
                        $placeholders=implode(',',array_fill(0,count($purchases),'?'));
                        $duplicate=db()->prepare("SELECT vin_no FROM customer_pos_sale_vins WHERE vin_no IN ($placeholders) LIMIT 1");
                        $duplicate->execute(array_column($purchases,'vin'));
                        $duplicateVin=(string)($duplicate->fetchColumn()?:'');
                        if($duplicateVin!=='') throw new RuntimeException('VIN '.$duplicateVin.' has already been recorded as purchased.');
                    }
                    $draftPromoPlug=trim((string)($payload['promo_plug']??''));
                    if($draftPromoPlug!==''){
                        db()->prepare('INSERT INTO customer_promo_plugs (visit_id,customer_id,bus_loc_id,promo_plug,recorded_by_user_id) VALUES(?,?,?,?,?)')
                            ->execute([$visitId,$customerId,(int)$record['bus_loc_id'],$draftPromoPlug,current_user_id()]);
                    }
                    if($purchases){
                        $insertPurchase=db()->prepare("INSERT INTO customer_pos_sale_vins(customer_source,record_id,vin_no,amount,created_by_user_id) VALUES('visit',?,?,?,?)");
                        foreach($purchases as $purchase)$insertPurchase->execute([$visitId,$purchase['vin'],$purchase['amount'],current_user_id()]);
                    }
                    db()->prepare(
                        'INSERT INTO visit_notes
                         (note_ref,visit_id,customer_id,feedback,note,staff_id,vendor_id,recorded_by_user_id)
                         VALUES(?,?,?,?,?,?,?,?)'
                    )->execute([
                        next_project_reference('visit_note'),$visitId,$customerId,$payload['feedback'],$payload['notes'],
                        current_staff_id(),(int)(current_vendor_profile()['id'] ?? 0) ?: null,current_user_id(),
                    ]);
                    db()->prepare('DELETE FROM customer_visit_drafts WHERE id=?')->execute([$id]);
                    db()->commit();
                    header('Location: '.$returnWithStatus('completed',(string)$payload['customer_name']));
                    exit;
                } catch (Throwable $exception) {
                    if (db()->inTransaction()) db()->rollBack();
                    $error = $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'The draft could not be completed.';
                }
            }
        } else {
            try {
                db()->beginTransaction();
                if ($purchases) {
                    $placeholders=implode(',',array_fill(0,count($purchases),'?'));
                    $duplicate=db()->prepare("SELECT vin_no FROM customer_pos_sale_vins WHERE vin_no IN ($placeholders) AND NOT (customer_source='visit' AND record_id=?) LIMIT 1");
                    $duplicate->execute([...array_column($purchases,'vin'),$id]);
                    $duplicateVin=(string)($duplicate->fetchColumn()?:'');
                    if($duplicateVin!=='') throw new RuntimeException('VIN '.$duplicateVin.' has already been recorded as purchased.');
                }
                db()->prepare(
                    'UPDATE customers SET customer_name=?,job_type=?,job_type_id=?,phone=?,other_phone=?,customer_picture=?,
                        vehicle_registration_no=?,vin_no=?,supervisor_name=?,supervisor_phone=? WHERE id=?'
                )->execute([
                    $payload['customer_name'],$jobType,$payload['job_type_id'] ?: null,
                    $payload['phone'] ?: null,$payload['other_phone'] ?: null,$payload['customer_picture'] ?: null,
                    $payload['vehicle_registration_no'] ?: null,$payload['vin_no'] ?: null,
                    $payload['supervisor_name'] ?: null,$payload['supervisor_phone'] ?: null,
                    (int)$record['customer_id'],
                ]);
                $saleStatement=db()->prepare('SELECT id FROM customer_promo_plugs WHERE visit_id=? ORDER BY id DESC LIMIT 1');
                $saleStatement->execute([$id]);
                $saleId=(int)($saleStatement->fetchColumn()?:0);
                if($saleId){
                    if($payload['promo_plug']==='') db()->prepare('DELETE FROM customer_promo_plugs WHERE id=?')->execute([$saleId]);
                    else db()->prepare('UPDATE customer_promo_plugs SET promo_plug=? WHERE id=?')->execute([$payload['promo_plug'],$saleId]);
                } elseif($payload['promo_plug']!=='') {
                    db()->prepare('INSERT INTO customer_promo_plugs(visit_id,customer_id,bus_loc_id,promo_plug,recorded_by_user_id) VALUES(?,?,?,?,?)')->execute([$id,(int)$record['customer_id'],(int)$record['bus_loc_id'],$payload['promo_plug'],current_user_id()]);
                }
                $noteStatement=db()->prepare('SELECT id FROM visit_notes WHERE visit_id=? ORDER BY id DESC LIMIT 1');
                $noteStatement->execute([$id]);
                $noteId=(int)($noteStatement->fetchColumn()?:0);
                if($noteId){
                    db()->prepare('UPDATE visit_notes SET feedback=?,note=? WHERE id=?')->execute([$payload['feedback']?:null,$payload['notes']?:null,$noteId]);
                } elseif($payload['feedback']!==''||$payload['notes']!=='') {
                    db()->prepare('INSERT INTO visit_notes(note_ref,visit_id,customer_id,feedback,note,staff_id,vendor_id,recorded_by_user_id) VALUES(?,?,?,?,?,?,?,?)')->execute([next_project_reference('visit_note'),$id,(int)$record['customer_id'],$payload['feedback']?:null,$payload['notes']?:null,current_staff_id(),(int)(current_vendor_profile()['id']??0)?:null,current_user_id()]);
                }
                db()->prepare("DELETE FROM customer_pos_sale_vins WHERE customer_source='visit' AND record_id=?")->execute([$id]);
                $insertPurchase=db()->prepare("INSERT INTO customer_pos_sale_vins(customer_source,record_id,vin_no,amount,created_by_user_id) VALUES('visit',?,?,?,?)");
                foreach($purchases as $purchase){$insertPurchase->execute([$id,$purchase['vin'],$purchase['amount'],current_user_id()]);}
                db()->commit();
            } catch(Throwable $exception) {
                if(db()->inTransaction())db()->rollBack();
                $error=$exception instanceof RuntimeException?$exception->getMessage():'The purchased VIN details could not be saved.';
            }
        }
        if($error===''){header('Location: '.$returnWithStatus($type==='draft'?'draft':'completed',(string)$payload['customer_name']));exit;}
    }
}

$jobTypes = db()->query('SELECT id,job_type_name FROM job_types WHERE is_active=1 ORDER BY job_type_name')->fetchAll();
$feedbackOptions = db()->query('SELECT feedback_label FROM visit_feedback_options WHERE is_active=1 ORDER BY feedback_label')->fetchAll();
$pageTitle = $type === 'draft' ? 'Continue Draft' : 'Edit Registration';
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Registration Records','url'=>$returnTo],['label'=>$pageTitle]];
$internalBackUrl = $returnTo;
require __DIR__ . '/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker"><?=e((string)($record['draft_ref']??$record['visit_ref']))?> / <?=e($record['trip_code'])?></span><h1><?=e($pageTitle)?></h1><p><?=e(trim((string)$record['business_name'])?:$record['bus_loc_ref'])?></p></div><div class="management-icon"><i class="fa-solid fa-pen-to-square"></i></div></div>
    <?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
    <div class="current-place-strip current-place-strip--compact registration-edit-location-strip">
        <div><span class="section-kicker">Business Location</span><h2><?=e(trim((string)($record['business_name']??''))?:('Location '.(string)($record['bus_loc_ref']??'')))?></h2><p><?=e(trim(implode(' / ',array_filter([(string)($record['town_name']??''),(string)($record['area']??'')]))))?></p></div>
        <div class="current-place-strip__actions"><span class="status-badge is-active"><?=e((string)($record['bus_loc_ref']??''))?></span><?php if((int)($record['bus_loc_id']??0)>0):?><a class="secondary-button secondary-button--small" href="<?=e(app_url('place-details.php?id='.(int)$record['bus_loc_id'].'&edit=1&customer_id='.(int)($record['customer_id']??0).'&return_to='.rawurlencode($returnTo)))?>"><i class="fa-solid fa-arrow-right-arrow-left"></i><span>Move / Merge</span></a><?php endif;?><?php if(trim((string)($record['google_location']??''))!==''):?><a class="secondary-button secondary-button--small" href="<?=e((string)$record['google_location'])?>" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-map-location-dot"></i><span>Open Location</span></a><?php endif;?></div>
    </div>
    <form class="record-form mobile-line-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=e($type)?>"><input type="hidden" name="record_id" value="<?=$id?>"><input type="hidden" name="return_to" value="<?=e($returnTo)?>">
        <details class="registration-accordion" name="registration-edit-sections" open>
            <summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-user"></i></span><span class="registration-accordion__title"><strong>Customer / Visit Details</strong><small>Edit the customer, timing, feedback, notes, and registration picture.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary>
            <div class="registration-accordion__body">
        <div class="form-grid">
            <div class="form-field form-field--wide"><label>Customer/Owner/Driver Name</label><input name="customer_name" value="<?=e((string)($payload['customer_name']??''))?>" required></div>
            <div class="form-field"><label>Phone</label><input type="tel" name="phone" value="<?=e((string)($payload['phone']??''))?>" inputmode="tel"></div>
            <div class="form-field"><label>Other Phone</label><input type="tel" name="other_phone" value="<?=e((string)($payload['other_phone']??''))?>" inputmode="tel"></div>
            <div class="form-field"><label>Job Type</label><select name="job_type_id"><option value="">Select job type</option><?php foreach($jobTypes as $job):?><option value="<?=(int)$job['id']?>" <?=(int)($payload['job_type_id']??0)===(int)$job['id']?'selected':''?>><?=e($job['job_type_name'])?></option><?php endforeach;?></select></div>
            <?php if($isTaxiRegistration):?><div class="form-field"><label>Car Registration Number</label><input name="vehicle_registration_no" value="<?=e((string)($payload['vehicle_registration_no']??''))?>"></div>
            <div class="form-field"><label>VIN</label><input name="vin_no" value="<?=e((string)($payload['vin_no']??''))?>"></div>
            <div class="form-field"><label>Supervisor Name</label><input name="supervisor_name" value="<?=e((string)($payload['supervisor_name']??''))?>"></div>
            <div class="form-field"><label>Supervisor Phone</label><input type="tel" name="supervisor_phone" value="<?=e((string)($payload['supervisor_phone']??''))?>" inputmode="tel"></div><?php endif;?>
        </div>

        <?php if($type==='draft'): ?>
        <div class="form-grid">


            <div class="form-field"><label>Customer Picture <span class="muted-text">(optional)</span></label><input name="customer_picture" type="file" accept="image/*" data-photo-source-choice><?php if(!empty($payload['customer_picture'])):?><small>Picture attached. A new picture will replace it.</small><?php endif;?></div>
            <div class="form-field"><label>Feedback</label><select name="feedback"><option value="">Select feedback</option><?php foreach($feedbackOptions as $feedbackOption):?><option value="<?=e((string)$feedbackOption['feedback_label'])?>" <?=($payload['feedback']??'')===$feedbackOption['feedback_label']?'selected':''?>><?=e((string)$feedbackOption['feedback_label'])?></option><?php endforeach;?></select></div>
            <div class="form-field form-field--wide"><label>Note</label><textarea name="notes" rows="4"><?=e((string)($payload['notes']??''))?></textarea></div>
        </div>
        <?php else: ?>
        <div class="form-grid">



            <div class="form-field"><label>Customer Picture <span class="muted-text">(optional)</span></label><input name="customer_picture" type="file" accept="image/*" data-photo-source-choice><?php if(!empty($payload['customer_picture'])):?><small>Picture attached. A new picture will replace it.</small><?php endif;?></div>
            <div class="form-field"><label>Feedback</label><select name="feedback"><option value="">Select feedback</option><?php foreach($feedbackOptions as $feedbackOption):?><option value="<?=e((string)$feedbackOption['feedback_label'])?>" <?=($payload['feedback']??'')===$feedbackOption['feedback_label']?'selected':''?>><?=e((string)$feedbackOption['feedback_label'])?></option><?php endforeach;?></select></div>
            <div class="form-field form-field--wide"><label>Note</label><textarea name="notes" rows="4"><?=e((string)($payload['notes']??''))?></textarea></div>
        </div>
        <?php endif; ?>
            </div>
        </details>
        <details class="registration-accordion" name="registration-edit-sections">
            <summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-bullhorn"></i></span><span class="registration-accordion__title"><strong>Promo Plug</strong><small>Edit the business promotional plug.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary>
            <div class="registration-accordion__body">
        <div class="form-grid"><div class="form-field"><label>Promotional Plug</label><input name="promo_plug" value="<?=e((string)($payload['promo_plug']??''))?>"></div><?php if($isTaxiRegistration):?><div class="form-field"><label>Car Picture <span class="muted-text">(optional)</span></label><input name="car_picture" type="file" accept="image/*" data-photo-source-choice><?php if(!empty($payload['car_picture'])):?><small>Picture attached. A new picture will replace it.</small><?php endif;?></div><?php endif;?></div>
            </div>
        </details>
        <div class="form-actions"><a class="secondary-button" href="<?=e($returnTo)?>">Cancel</a><?php if($type==='completed'):?><button class="danger-button" type="submit" name="form_action" value="delete" formnovalidate data-confirm-title="Delete customer registration?" data-confirm-message="This permanently deletes this visit, its notes, sales, and VIN details. The customer profile is also removed when it has no other records."><i class="fa-solid fa-trash"></i><span>Delete</span></button><?php endif;?><button class="login-button" type="submit" name="form_action" value="save"><i class="fa-solid fa-floppy-disk"></i><span><?=$type==='draft'?'Save / Complete':'Save Changes'?></span></button></div>
    </form>
</section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-sale-purchase-editor]').forEach(editor => {
        const rows=editor.querySelector('[data-sale-purchase-rows]');
        const template=editor.querySelector('[data-sale-purchase-template]');
        const update=()=>{const items=editor.querySelectorAll('[data-sale-purchase-row]');items.forEach(item=>{const button=item.querySelector('[data-remove-sale-purchase]');if(button)button.hidden=false;});};
        editor.addEventListener('input',event=>{if(event.target.matches('[data-sale-vin-input]'))event.target.value=event.target.value.toUpperCase().replace(/[^A-HJ-NPR-Z0-9]/g,'').slice(0,17);});
        editor.addEventListener('click',event=>{if(event.target.closest('[data-add-sale-purchase]')&&rows&&template){rows.append(template.content.cloneNode(true));update();rows.lastElementChild?.querySelector('[data-sale-vin-input]')?.focus();return;}const remove=event.target.closest('[data-remove-sale-purchase]');if(remove){const row=remove.closest('[data-sale-purchase-row]');if(editor.querySelectorAll('[data-sale-purchase-row]').length===1){row?.querySelectorAll('input').forEach(input=>input.value='');}else{row?.remove();}update();}});
        update();
    });
});
</script>
<?php require __DIR__ . '/../includes/footer.php';
