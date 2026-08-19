<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_promo_plug_schema();
require_module_access('registration_records');
ensure_places_management_schema();

function place_details_upload(string $field, array $allowed, int $maxBytes): ?string
{
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('A selected media file could not be uploaded or is too large.');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($allowed[$mime])) $mime = ['heic'=>'image/heic','heif'=>'image/heif'][$extension] ?? $mime;
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Choose a supported image or video file.');
    }
    $directory = __DIR__ . '/../assets/uploads/normalized-visits';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('The media folder could not be created.');
    }
    $compressible = in_array($mime, ['image/jpeg','image/png','image/webp'], true);
    $video = str_starts_with($mime, 'video/');
    $name = $field . '-' . bin2hex(random_bytes(10)) . '.' . ($compressible || $video ? ($video ? 'mp4' : 'jpg') : $allowed[$mime]);
    $targetPath = $directory . '/' . $name;
    if ($compressible && compress_uploaded_image((string)$file['tmp_name'], $mime, $targetPath)) {
        return 'assets/uploads/normalized-visits/' . $name;
    }
    if ($video && compress_uploaded_video((string)$file['tmp_name'], $targetPath)) {
        return 'assets/uploads/normalized-visits/' . $name;
    }
    if ($compressible || $video) {
        $name = $field . '-' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
        $targetPath = $directory . '/' . $name;
    }
    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
        throw new RuntimeException('The selected media file could not be saved.');
    }
    return 'assets/uploads/normalized-visits/' . $name;
}
$placeId = max(0, (int)($_GET['id'] ?? 0));
$requestedReturnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
$recordsBaseUrl = app_url('registration-records.php');
$customerVisitBaseUrl = app_url('normalized-customer.php');
$customerDetailsBaseUrl = app_url('normalized-visit-details.php');
$returnTo = $requestedReturnTo !== ''
    && (str_starts_with($requestedReturnTo, $recordsBaseUrl) || str_starts_with($requestedReturnTo, $customerVisitBaseUrl) || str_starts_with($requestedReturnTo, $customerDetailsBaseUrl))
    ? $requestedReturnTo
    : app_url('normalized-customer.php?stage=new-place');
$statement = db()->prepare(
    "SELECT p.*,l.town_name,COALESCE(l.region_name,legacy_region.region_name) AS region_name,l.mmda_name,d.destination_name,d.destination_key,st.shop_type_name
     FROM business_locations p
     LEFT JOIN locations l ON l.id=p.location_id
     LEFT JOIN regions legacy_region ON legacy_region.id=p.region_id
     LEFT JOIN destinations d ON d.id=p.destination_id
     LEFT JOIN shop_types st ON st.id=p.shop_type_id
     WHERE p.id=? AND p.is_active=1"
);
$statement->execute([$placeId]);
$place = $statement->fetch();
if (!$place) {
    http_response_code(404);
    exit('Location not found.');
}

$canAccessPlace = is_admin_user() || (int)($place['created_by_user_id'] ?? 0) === (int)current_user_id();
if (!$canAccessPlace) {
    $accessStatement = db()->prepare('SELECT sales_trip_id FROM place_visit_sessions WHERE bus_loc_id=?');
    $accessStatement->execute([$placeId]);
    foreach ($accessStatement->fetchAll(PDO::FETCH_COLUMN) as $tripId) {
        if (can_access_registration_trip((int)$tripId)) {
            $canAccessPlace = true;
            break;
        }
    }
}
if (!$canAccessPlace) {
    http_response_code(403);
    exit('You do not have access to this location.');
}
$canManageLocationRecords = is_admin_user() || (current_user_role() === 'vendor' && $canAccessPlace);
$canManageTargetPlace = static function (array $targetPlace): bool {
    if (is_admin_user()) return true;
    if (current_user_role() !== 'vendor') return false;
    if ((int)($targetPlace['created_by_user_id'] ?? 0) === (int)current_user_id()) return true;
    $statement = db()->prepare('SELECT sales_trip_id FROM place_visit_sessions WHERE bus_loc_id=?');
    $statement->execute([(int)$targetPlace['id']]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $tripId) if (can_access_registration_trip((int)$tripId)) return true;
    return false;
};

$activeLocationStatement = db()->prepare(
    "SELECT ps.sales_trip_id
     FROM place_visit_sessions ps
     INNER JOIN sales_trips st ON st.id=ps.sales_trip_id
     WHERE ps.bus_loc_id=? AND ps.status='active' AND st.status='in_progress'
     ORDER BY ps.id DESC"
);
$activeLocationStatement->execute([$placeId]);
$isCurrentActiveLocation = false;
foreach ($activeLocationStatement->fetchAll(PDO::FETCH_COLUMN) as $activeLocationTripId) {
    if (can_access_registration_trip((int)$activeLocationTripId)) {
        $isCurrentActiveLocation = true;
        break;
    }
}

$message = '';
$error = '';
$editMode = (string)($_GET['edit'] ?? '') === '1';
$destinations = db()->query("SELECT id,destination_name,destination_key FROM destinations WHERE is_active=1 ORDER BY destination_name")->fetchAll();
$locations = active_locations();
$savedLocationId = (int)($place['location_id'] ?? 0);
if ($savedLocationId <= 0 && (int)($place['town_id'] ?? 0) > 0
    && db_table_exists('towns') && db_table_exists('regions') && db_table_exists('districts')) {
    $legacyLocationStatement = db()->prepare(
        "SELECT l.*
         FROM towns t
         LEFT JOIN regions r ON r.id=t.region_id
         LEFT JOIN districts d ON d.id=t.district_id
         INNER JOIN locations l
            ON UPPER(TRIM(l.region_name))=UPPER(TRIM(r.region_name))
           AND UPPER(TRIM(l.mmda_name))=UPPER(TRIM(d.district_name))
           AND LOWER(REPLACE(TRIM(l.town_name),' ',''))=LOWER(REPLACE(TRIM(CASE
                WHEN LOWER(REPLACE(t.town_name,' ','')) IN ('bawulashie','bawaleshie') THEN 'Bawulashi'
                ELSE t.town_name END),' ',''))
         WHERE t.id=?
         ORDER BY l.is_active DESC,l.id DESC
         LIMIT 1"
    );
    $legacyLocationStatement->execute([(int)$place['town_id']]);
    $recoveredLocation = $legacyLocationStatement->fetch();
    if ($recoveredLocation) {
        $savedLocationId = (int)$recoveredLocation['id'];
        $place['location_id'] = $savedLocationId;
        $place['region_name'] = (string)$recoveredLocation['region_name'];
        $place['mmda_name'] = (string)$recoveredLocation['mmda_name'];
        $place['town_name'] = (string)$recoveredLocation['town_name'];
    }
}
if ($savedLocationId > 0 && !in_array($savedLocationId,array_map('intval',array_column($locations,'id')),true)) {
    $savedLocation = location_by_id($savedLocationId,false);
    if ($savedLocation) {
        $locations[] = $savedLocation;
        usort($locations,static fn(array $a,array $b):int=>[
            (string)$a['region_name'],(string)$a['mmda_name'],(string)$a['town_name']
        ] <=> [
            (string)$b['region_name'],(string)$b['mmda_name'],(string)$b['town_name']
        ]);
    }
}
$locationRegions = [];
foreach ($locations as $location) {
    $regionKey = (string)($location['region_code'] ?: $location['region_name']);
    $locationRegions[$regionKey] = (string)$location['region_name'];
}
asort($locationRegions);
$shopTypes = db()->query("SELECT id,shop_type_name FROM shop_types WHERE is_active=1 ORDER BY shop_type_name")->fetchAll();
$existingBusinessLocations = db()->prepare(
    "SELECT p.id,p.bus_loc_ref,p.business_name,p.area,p.created_by_user_id,l.town_name,l.region_name
     FROM business_locations p
     LEFT JOIN locations l ON l.id=p.location_id
     WHERE p.is_active=1 AND p.is_legacy_placeholder=0 AND p.id<>?
     ORDER BY p.business_name,p.bus_loc_ref"
);
$existingBusinessLocations->execute([$placeId]);
$existingBusinessLocations = $existingBusinessLocations->fetchAll();
if (!is_admin_user()) $existingBusinessLocations = array_values(array_filter($existingBusinessLocations, $canManageTargetPlace));
$legacyCustomerId = max(0,(int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0));
$legacyCustomersStatement = db()->prepare('SELECT id,customer_ref,customer_name,phone FROM customers WHERE bus_loc_id=? AND is_active=1 AND master_customer_id IS NULL ORDER BY customer_name,id');
$legacyCustomersStatement->execute([$placeId]);
$legacyCustomers = $legacyCustomersStatement->fetchAll();
if ($legacyCustomerId <= 0 && count($legacyCustomers) === 1) $legacyCustomerId = (int)$legacyCustomers[0]['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
        $editMode = true;
    } elseif ((string)($_POST['form_action'] ?? '') === 'move_customer') {
        $targetPlaceId=max(0,(int)($_POST['target_bus_loc_id']??0));
        $customerId=max(0,(int)($_POST['customer_id']??0));
        $targetStatement=db()->prepare('SELECT id,bus_loc_ref,business_name,created_by_user_id FROM business_locations WHERE id=? AND is_active=1 AND is_legacy_placeholder=0 AND id<>?');
        $targetStatement->execute([$targetPlaceId,$placeId]);
        $targetPlace=$targetStatement->fetch();if($targetPlace&&!$canManageTargetPlace($targetPlace))$targetPlace=false;
        $customerStatement=db()->prepare('SELECT id,customer_name FROM customers WHERE id=? AND bus_loc_id=? AND is_active=1');
        $customerStatement->execute([$customerId,$placeId]);
        $movingCustomer=$customerStatement->fetch();
        if(!$canManageLocationRecords){$error='You do not have permission to move customers at this business location.';}
        elseif((int)($place['is_legacy_placeholder']??0)===1){$error='Use the legacy customer assignment tool for this location.';}
        elseif(!$movingCustomer){$error='Select a valid customer at this business location.';}
        elseif(!$targetPlace){$error='Select a valid destination business location.';}
        else{try{
            db()->beginTransaction();
            $lock=db()->prepare('SELECT id FROM business_locations WHERE id IN (?,?) FOR UPDATE');
            $lock->execute([$placeId,$targetPlaceId]);
            $sessionStatement=db()->prepare("SELECT DISTINCT ps.* FROM place_visit_sessions ps
                WHERE ps.bus_loc_id=? AND (
                    EXISTS(SELECT 1 FROM visits v WHERE v.place_session_id=ps.id AND v.customer_id=?)
                    OR EXISTS(SELECT 1 FROM customer_visit_drafts d WHERE d.place_session_id=ps.id AND d.customer_id=?))");
            $sessionStatement->execute([$placeId,$customerId,$customerId]);
            foreach($sessionStatement->fetchAll() as $customerSession){
                $otherRecords=db()->prepare("SELECT
                    (SELECT COUNT(*) FROM visits WHERE place_session_id=? AND customer_id<>?)+
                    (SELECT COUNT(*) FROM customer_visit_drafts WHERE place_session_id=? AND (customer_id IS NULL OR customer_id<>?))");
                $otherRecords->execute([(int)$customerSession['id'],$customerId,(int)$customerSession['id'],$customerId]);
                if((int)$otherRecords->fetchColumn()===0){
                    db()->prepare('UPDATE place_visit_sessions SET bus_loc_id=? WHERE id=?')->execute([$targetPlaceId,(int)$customerSession['id']]);
                }else{
                    $clonedStatus=(string)$customerSession['status']==='active'?'completed':(string)$customerSession['status'];
                    db()->prepare("INSERT INTO place_visit_sessions(session_ref,sales_trip_id,bus_loc_id,session_type,activity_date,arrival_time,departure_time,status,recorded_by_user_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
                        ->execute([next_project_reference('place_visit'),$customerSession['sales_trip_id']?:null,$targetPlaceId,(string)$customerSession['session_type'],$customerSession['activity_date']?:null,$customerSession['arrival_time']?:null,$customerSession['departure_time']?:null,$clonedStatus,$customerSession['recorded_by_user_id']?:null,(string)$customerSession['created_at']]);
                    $newSessionId=(int)db()->lastInsertId();
                    db()->prepare('UPDATE visits SET place_session_id=? WHERE place_session_id=? AND customer_id=?')->execute([$newSessionId,(int)$customerSession['id'],$customerId]);
                    db()->prepare('UPDATE customer_visit_drafts SET place_session_id=? WHERE place_session_id=? AND customer_id=?')->execute([$newSessionId,(int)$customerSession['id'],$customerId]);
                }
            }
            db()->prepare('UPDATE customers SET bus_loc_id=? WHERE id=? AND bus_loc_id=?')->execute([$targetPlaceId,$customerId,$placeId]);
            db()->prepare('UPDATE visits SET bus_loc_id=? WHERE customer_id=? AND bus_loc_id=?')->execute([$targetPlaceId,$customerId,$placeId]);
            db()->prepare('UPDATE customer_promo_plugs SET bus_loc_id=? WHERE customer_id=? AND bus_loc_id=?')->execute([$targetPlaceId,$customerId,$placeId]);
            db()->commit();
            header('Location: '.app_url('place-details.php?id='.$targetPlaceId.'&customer_moved=1&return_to='.rawurlencode($returnTo)));exit;
        }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error='The customer could not be moved to the selected location.';}}
        $editMode=true;
    } elseif ((string)($_POST['form_action'] ?? '') === 'merge_location') {
        $targetPlaceId=max(0,(int)($_POST['target_bus_loc_id']??0));
        $targetStatement=db()->prepare('SELECT id,bus_loc_ref,business_name,created_by_user_id FROM business_locations WHERE id=? AND is_active=1 AND is_legacy_placeholder=0 AND id<>?');
        $targetStatement->execute([$targetPlaceId,$placeId]);
        $targetPlace=$targetStatement->fetch();if($targetPlace&&!$canManageTargetPlace($targetPlace))$targetPlace=false;
        if(!$canManageLocationRecords){$error='You do not have permission to merge this business location.';}
        elseif((int)($place['is_legacy_placeholder']??0)===1){$error='Use the legacy customer assignment tool for this location.';}
        elseif(!$targetPlace){$error='Select a valid business location to keep.';}
        else{try{
            db()->beginTransaction();
            $lock=db()->prepare('SELECT id FROM business_locations WHERE id IN (?,?) FOR UPDATE');
            $lock->execute([$placeId,$targetPlaceId]);
            db()->prepare('UPDATE customers SET bus_loc_id=? WHERE bus_loc_id=?')->execute([$targetPlaceId,$placeId]);
            db()->prepare('UPDATE visits SET bus_loc_id=? WHERE bus_loc_id=?')->execute([$targetPlaceId,$placeId]);
            db()->prepare('UPDATE customer_promo_plugs SET bus_loc_id=? WHERE bus_loc_id=?')->execute([$targetPlaceId,$placeId]);
            db()->prepare('UPDATE place_visit_sessions SET bus_loc_id=? WHERE bus_loc_id=?')->execute([$targetPlaceId,$placeId]);
            db()->prepare('UPDATE business_locations SET is_active=0 WHERE id=?')->execute([$placeId]);
            db()->commit();
            header('Location: '.app_url('place-details.php?id='.$targetPlaceId.'&merged=1&return_to='.rawurlencode($returnTo)));exit;
        }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error='The duplicate location could not be merged.';}}
        $editMode=true;
    } elseif ((string)($_POST['form_action'] ?? '') === 'assign_existing_location') {
        $targetPlaceId=max(0,(int)($_POST['target_bus_loc_id']??0));
        $customerId=max(0,(int)($_POST['customer_id']??0));
        $targetStatement=db()->prepare('SELECT id,bus_loc_ref,business_name FROM business_locations WHERE id=? AND is_active=1 AND is_legacy_placeholder=0 AND id<>?');
        $targetStatement->execute([$targetPlaceId,$placeId]);
        $targetPlace=$targetStatement->fetch();
        $customerStatement=db()->prepare('SELECT id,customer_name FROM customers WHERE id=? AND bus_loc_id=? AND is_active=1');
        $customerStatement->execute([$customerId,$placeId]);
        $legacyCustomer=$customerStatement->fetch();
        if((int)($place['is_legacy_placeholder']??0)!==1){$error='This customer is already assigned to a completed business location.';}
        elseif(!$legacyCustomer){$error='Select a valid legacy customer.';}
        elseif(!$targetPlace){$error='Select a valid existing business location.';}
        else{try{
            db()->beginTransaction();
            db()->prepare('UPDATE customers SET bus_loc_id=? WHERE id=? AND bus_loc_id=?')->execute([$targetPlaceId,$customerId,$placeId]);
            db()->prepare('UPDATE visits SET bus_loc_id=? WHERE customer_id=? AND bus_loc_id=?')->execute([$targetPlaceId,$customerId,$placeId]);
            db()->prepare('UPDATE customer_promo_plugs SET bus_loc_id=? WHERE customer_id=? AND bus_loc_id=?')->execute([$targetPlaceId,$customerId,$placeId]);
            $movableSessions=db()->prepare("SELECT ps.id FROM place_visit_sessions ps
                WHERE ps.bus_loc_id=?
                  AND (EXISTS(SELECT 1 FROM visits v WHERE v.place_session_id=ps.id AND v.customer_id=?)
                       OR EXISTS(SELECT 1 FROM customer_visit_drafts d WHERE d.place_session_id=ps.id AND d.customer_id=?))
                  AND NOT EXISTS(SELECT 1 FROM visits v WHERE v.place_session_id=ps.id AND v.customer_id<>?)
                  AND NOT EXISTS(SELECT 1 FROM customer_visit_drafts d WHERE d.place_session_id=ps.id AND (d.customer_id IS NULL OR d.customer_id<>?))");
            $movableSessions->execute([$placeId,$customerId,$customerId,$customerId,$customerId]);
            $sessionIds=array_map('intval',$movableSessions->fetchAll(PDO::FETCH_COLUMN));
            if($sessionIds){$sessionUpdate=db()->prepare('UPDATE place_visit_sessions SET bus_loc_id=? WHERE id=? AND bus_loc_id=?');foreach($sessionIds as $sessionId)$sessionUpdate->execute([$targetPlaceId,$sessionId,$placeId]);}
            $remainingCustomers=db()->prepare('SELECT COUNT(*) FROM customers WHERE bus_loc_id=?');
            $remainingCustomers->execute([$placeId]);
            if((int)$remainingCustomers->fetchColumn()===0){
                db()->prepare('UPDATE visits SET bus_loc_id=? WHERE bus_loc_id=?')->execute([$targetPlaceId,$placeId]);
                db()->prepare('UPDATE customer_promo_plugs SET bus_loc_id=? WHERE bus_loc_id=?')->execute([$targetPlaceId,$placeId]);
                db()->prepare('UPDATE place_visit_sessions SET bus_loc_id=? WHERE bus_loc_id=?')->execute([$targetPlaceId,$placeId]);
            }
            $remaining=db()->prepare("SELECT
                (SELECT COUNT(*) FROM customers WHERE bus_loc_id=?)+
                (SELECT COUNT(*) FROM visits WHERE bus_loc_id=?)+
                (SELECT COUNT(*) FROM place_visit_sessions WHERE bus_loc_id=?)+
                (SELECT COUNT(*) FROM customer_promo_plugs WHERE bus_loc_id=?)");
            $remaining->execute([$placeId,$placeId,$placeId,$placeId]);
            if((int)$remaining->fetchColumn()===0)db()->prepare('UPDATE business_locations SET is_active=0 WHERE id=? AND is_legacy_placeholder=1')->execute([$placeId]);
            db()->commit();
            header('Location: '.app_url('place-details.php?id='.$targetPlaceId.'&assigned=1&return_to='.rawurlencode($returnTo)));exit;
        }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error='The legacy customer could not be assigned to the selected location.';}}
        $editMode=true;    } elseif ((string)($_POST['form_action'] ?? '') === 'update_place') {
        $businessName = trim((string)($_POST['business_name'] ?? ''));
        $destinationId = max(0, (int)($_POST['destination_id'] ?? 0));
        $locationRegionKey = trim((string)($_POST['location_region_key'] ?? ''));
        $locationId = max(0, (int)($_POST['location_id'] ?? 0));
        $currentLocationRegionKey = '';
        foreach ($locations as $locationOption) {
            if ((int)$locationOption['id'] === (int)($place['location_id'] ?? 0)) {
                $currentLocationRegionKey = (string)($locationOption['region_code'] ?: $locationOption['region_name']);
                break;
            }
        }
        if ($locationId <= 0 && (int)($place['location_id'] ?? 0) > 0
            && ($locationRegionKey === '' || $locationRegionKey === $currentLocationRegionKey)) {
            $locationId = (int)$place['location_id'];
        }
        $area = trim((string)($_POST['area'] ?? ''));
        $googleLocation = trim((string)($_POST['google_location'] ?? ''));
        $shopTypeId = max(0, (int)($_POST['shop_type_id'] ?? 0));
        $locationRegionValid = $locationRegionKey === '' || array_key_exists($locationRegionKey,$locationRegions);
        $legacyRegionId = (int)($place['region_id'] ?? 0);
        if ($locationRegionKey !== '' && $locationRegionValid && db_table_exists('regions')) {
            $legacyRegionStatement = db()->prepare(
                "SELECT id FROM regions
                 WHERE REPLACE(UPPER(TRIM(region_name)),' REGION','')=
                       REPLACE(UPPER(TRIM(?)),' REGION','')
                 LIMIT 1"
            );
            $legacyRegionStatement->execute([(string)$locationRegions[$locationRegionKey]]);
            $legacyRegionId = (int)($legacyRegionStatement->fetchColumn() ?: 0);
        }
        foreach ($destinations as $destination) {
            if ((int)$destination['id'] === $destinationId && destination_is_taxi_rank($destination)) {
                $shopTypeId = 0;
                break;
            }
        }
        $destinationValid = !$destinationId || in_array($destinationId, array_map('intval', array_column($destinations, 'id')), true);
        $locationValid = !$locationId || in_array($locationId, array_map('intval', array_column($locations, 'id')), true);
        $shopTypeValid = !$shopTypeId || in_array($shopTypeId, array_map('intval', array_column($shopTypes, 'id')), true);

        $promotingLegacyLocation = (int)($place['is_legacy_placeholder'] ?? 0) === 1;
        if ($businessName === '') {
            $error = 'Enter the location or business name.';
        } elseif (!$destinationValid || !$locationRegionValid || !$locationValid || !$shopTypeValid) {
            $error = 'Select valid location details.';
        } elseif ($promotingLegacyLocation && (!$destinationId || !$locationId || $area === '' || $googleLocation === '')) {
            $error = 'Complete the destination, town, area and Google location before assigning this legacy location.';
        } elseif ($googleLocation !== '' && !filter_var($googleLocation, FILTER_VALIDATE_URL)) {
            $error = 'Enter a valid Google location link.';
        } else {
            try {
                $newShopPicture = place_details_upload('shop_picture', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES);
                $newShopPicture2 = place_details_upload('shop_picture_2', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES);
                $newShopVideo = place_details_upload('shop_video', ['video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov'], 30 * 1024 * 1024);
                $shopPicture = $newShopPicture ?: ((string)($place['shop_picture'] ?? '') ?: null);
                $shopPicture2 = $newShopPicture2 ?: ((string)($place['shop_picture_2'] ?? '') ?: null);
                $shopVideo = $newShopVideo ?: ((string)($place['shop_video'] ?? '') ?: null);
                db()->prepare('UPDATE business_locations SET business_name=?,destination_id=?,region_id=?,location_id=?,area=?,google_location=?,shop_type_id=?,shop_picture=?,shop_picture_2=?,shop_video=?,is_legacy_placeholder=0 WHERE id=?')
                    ->execute([$businessName,$destinationId ?: null,$legacyRegionId ?: null,$locationId ?: null,$area ?: null,$googleLocation ?: null,$shopTypeId ?: null,$shopPicture,$shopPicture2,$shopVideo,$placeId]);
                header('Location: '.app_url('place-details.php?id='.$placeId.'&updated=1&return_to='.rawurlencode($returnTo)));
                exit;
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
        $place = array_merge($place, [
            'business_name'=>$businessName,'destination_id'=>$destinationId,'region_id'=>$legacyRegionId,'location_id'=>$locationId,
            'area'=>$area,'google_location'=>$googleLocation,'shop_type_id'=>$shopTypeId,
        ]);
        $editMode = true;
    }
}
$message = (string)($_GET['assigned'] ?? '') === '1' ? 'Legacy customer assigned to this business location successfully.' : ((string)($_GET['customer_moved'] ?? '') === '1' ? 'Customer moved to this business location successfully.' : ((string)($_GET['merged'] ?? '') === '1' ? 'Duplicate business location merged successfully.' : ((string)($_GET['updated'] ?? '') === '1' ? 'Location updated successfully.' : $message)));

$statement = db()->prepare(
    "SELECT c.*,(SELECT COUNT(*) FROM visits a WHERE a.customer_id=c.id) AS activity_count
     FROM customers c WHERE c.bus_loc_id=? AND c.is_active=1 AND c.master_customer_id IS NULL ORDER BY c.customer_name"
);
$statement->execute([$placeId]);
$customers = $statement->fetchAll();

$statement = db()->prepare(
    "SELECT ps.*,st.trip_code,COUNT(a.id) AS activity_count
     FROM place_visit_sessions ps
     LEFT JOIN sales_trips st ON st.id=ps.sales_trip_id
     LEFT JOIN visits a ON a.place_session_id=ps.id
     WHERE ps.bus_loc_id=?
     GROUP BY ps.id ORDER BY ps.id DESC"
);
$statement->execute([$placeId]);
$placeVisits = $statement->fetchAll();

$statement = db()->prepare(
    "SELECT a.*,ps.session_ref,st.trip_code,c.customer_ref,c.customer_name,
            cs.sale_record_ref,cs.sales_ref,cs.sale_confirmed,
            (SELECT n.feedback FROM visit_notes n WHERE n.visit_id=a.id ORDER BY n.id DESC LIMIT 1) AS feedback,
            (SELECT n.note FROM visit_notes n WHERE n.visit_id=a.id ORDER BY n.id DESC LIMIT 1) AS note
     FROM visits a
     INNER JOIN customers c ON c.id=a.customer_id
     LEFT JOIN place_visit_sessions ps ON ps.id=a.place_session_id
     LEFT JOIN sales_trips st ON st.id=a.sales_trip_id
     LEFT JOIN customer_sales cs ON cs.visit_id=a.id
     WHERE a.bus_loc_id=? ORDER BY a.id DESC"
);
$statement->execute([$placeId]);
$activities = $statement->fetchAll();

$placeDisplayName = trim((string)$place['business_name']) ?: 'Incomplete Location';
$pageTitle = $placeDisplayName;
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Marketing Trip','url'=>app_url('normalized-customer.php?stage=new-place')],['label'=>'Location Details']];
$internalBackUrl = $returnTo;
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel place-detail-page">
    <?php if ($message): ?><div class="profile-message is-success"><?=e($message)?></div><?php endif; ?>
    <?php if ($error): ?><div class="profile-message is-error"><?=e($error)?></div><?php endif; ?>
    <div class="management-heading"><div><span class="section-kicker"><?= e($place['bus_loc_ref']) ?></span><h1><?= e($placeDisplayName) ?></h1><p><i class="fa-solid fa-location-dot"></i> <?= e(trim(($place['town_name'] ?? '').' / '.($place['area'] ?? ''),' /') ?: 'Location details pending') ?></p></div><div class="management-icon"><i class="fa-solid fa-shop"></i></div></div>
    <div class="place-detail-actions"><a class="secondary-button" href="<?=e($returnTo)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><a class="secondary-button" href="<?=e(app_url('place-details.php?id='.$placeId.($editMode?'':'&edit=1').'&return_to='.rawurlencode($returnTo)))?>"><i class="fa-solid fa-pen"></i><span><?=$editMode?'Cancel Edit':'Edit Location'?></span></a><?php if($isCurrentActiveLocation):?><a class="login-button" href="<?=e(app_url('normalized-customer.php?stage=activity'))?>"><span>Continue at This Location</span><i class="fa-solid fa-arrow-right"></i></a><?php else:?><form method="post" action="<?= e(app_url('normalized-customer.php?stage=existing-place')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="select_place"><input type="hidden" name="bus_loc_id" value="<?= (int)$place['id'] ?>"><button class="login-button" type="submit"><span>Continue at This Location</span><i class="fa-solid fa-arrow-right"></i></button></form><?php endif;?></div>
    <?php if($editMode && (int)($place['is_legacy_placeholder']??0)===1): ?>
    <form class="record-form mobile-line-form" method="post" action="<?=e(app_url('place-details.php?id='.$placeId.'&edit=1&customer_id='.$legacyCustomerId.'&return_to='.rawurlencode($returnTo)))?>">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="assign_existing_location"><input type="hidden" name="return_to" value="<?=e($returnTo)?>">
        <details class="registration-accordion place-registration-toggle legacy-existing-assignment" open><summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-link"></i></span><span class="registration-accordion__title"><strong>Assign to existing business location</strong></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary><div class="registration-accordion__body legacy-existing-assignment__body"><div class="form-grid legacy-existing-assignment__fields">
            <div class="form-field"><label>Legacy Customer</label><select name="customer_id" required><option value="">Select customer</option><?php foreach($legacyCustomers as $legacyCustomer):?><option value="<?=(int)$legacyCustomer['id']?>" <?=$legacyCustomerId===(int)$legacyCustomer['id']?'selected':''?>><?=e((string)$legacyCustomer['customer_ref'].' - '.(string)$legacyCustomer['customer_name'].' - '.(string)$legacyCustomer['phone'])?></option><?php endforeach;?></select></div>
            <div class="form-field"><label>Existing Business Location</label><select name="target_bus_loc_id" data-popup-select required><option value="">Search or select location</option><?php foreach($existingBusinessLocations as $existingPlace):?><option value="<?=(int)$existingPlace['id']?>"><?=e(implode(' - ',array_filter([(string)$existingPlace['bus_loc_ref'],(string)$existingPlace['business_name'],trim((string)$existingPlace['town_name'].' / '.(string)$existingPlace['area'],' /')])) )?></option><?php endforeach;?></select></div>
        </div><div class="form-actions"><button class="login-button" type="submit"><i class="fa-solid fa-link"></i><span>Assign to Existing Location</span></button></div></div></details>
    </form>
    <?php endif; ?>
    <?php if($canManageLocationRecords && (int)($place['is_legacy_placeholder']??0)!==1): ?>
    <form class="record-form mobile-line-form" method="post" action="<?=e(app_url('place-details.php?id='.$placeId.'&edit=1&return_to='.rawurlencode($returnTo)))?>" data-confirm-title="Move customer" data-confirm-message="Move the selected customer and their related records to the selected business location?">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="move_customer"><input type="hidden" name="return_to" value="<?=e($returnTo)?>">
        <details class="place-admin-action"><summary class="secondary-button secondary-button--small"><i class="fa-solid fa-user-tag"></i><span>Move Customer</span></summary><div class="place-admin-action__body"><p class="muted-text">Move one customer and their records to another business location.</p><div class="form-grid"><div class="form-field"><label>Customer to Move</label><select name="customer_id" required><option value="">Select customer</option><?php foreach($legacyCustomers as $locationCustomer):?><option value="<?=(int)$locationCustomer['id']?>"><?=e((string)$locationCustomer['customer_ref'].' - '.(string)$locationCustomer['customer_name'].' - '.(string)$locationCustomer['phone'])?></option><?php endforeach;?></select></div><div class="form-field"><label>Destination Business Location</label><select name="target_bus_loc_id" data-popup-select required><option value="">Search or select location</option><?php foreach($existingBusinessLocations as $existingPlace):?><option value="<?=(int)$existingPlace['id']?>"><?=e(implode(' - ',array_filter([(string)$existingPlace['bus_loc_ref'],(string)$existingPlace['business_name'],trim((string)$existingPlace['town_name'].' / '.(string)$existingPlace['area'],' /')])) )?></option><?php endforeach;?></select></div></div><div class="form-actions"><button class="login-button" type="submit"><i class="fa-solid fa-arrow-right-arrow-left"></i><span>Move Customer</span></button></div></div></details>
    </form>
    <form class="record-form mobile-line-form" method="post" action="<?=e(app_url('place-details.php?id='.$placeId.'&edit=1&return_to='.rawurlencode($returnTo)))?>" data-confirm-title="Merge duplicate location" data-confirm-message="Move every customer, visit, session, and sale from <?=e((string)$place['bus_loc_ref'])?> to the selected location? The current location will be deactivated.">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="merge_location"><input type="hidden" name="return_to" value="<?=e($returnTo)?>">
        <details class="place-admin-action"><summary class="secondary-button secondary-button--small"><i class="fa-solid fa-code-merge"></i><span>Merge Duplicate</span></summary><div class="place-admin-action__body"><p class="muted-text">Keep one location reference for all related customers and records.</p><div class="form-grid"><div class="form-field form-field--wide"><label>Business Location to Keep</label><select name="target_bus_loc_id" data-popup-select required><option value="">Search or select location</option><?php foreach($existingBusinessLocations as $existingPlace):?><option value="<?=(int)$existingPlace['id']?>"><?=e(implode(' - ',array_filter([(string)$existingPlace['bus_loc_ref'],(string)$existingPlace['business_name'],trim((string)$existingPlace['town_name'].' / '.(string)$existingPlace['area'],' /')])) )?></option><?php endforeach;?></select></div></div><div class="form-actions"><button class="login-button" type="submit"><i class="fa-solid fa-code-merge"></i><span>Merge into Selected Location</span></button></div></div></details>
    </form>
    <?php endif; ?>
    <?php if($editMode): ?>
    <form class="record-form mobile-line-form place-edit-registration-form" method="post" enctype="multipart/form-data" action="<?=e(app_url('place-details.php?id='.$placeId.'&edit=1&return_to='.rawurlencode($returnTo)))?>">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="update_place"><input type="hidden" name="return_to" value="<?=e($returnTo)?>">
        <details class="registration-accordion place-registration-toggle" open>
        <summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-shop"></i></span><span class="registration-accordion__title"><strong>Location Registration</strong></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary>
        <div class="registration-accordion__body">
        <div class="form-grid">
            <div class="form-field"><label>Workshop/Business Name</label><input name="business_name" value="<?=e((string)$place['business_name'])?>" required></div>
            <div class="form-field"><label>Destination Type</label><select name="destination_id" data-destination-shop-type-toggle><option value="">Select destination</option><?php foreach($destinations as $item):?><option value="<?=(int)$item['id']?>" data-destination-key="<?=e((string)$item['destination_key'])?>" <?=(int)$place['destination_id']===(int)$item['id']?'selected':''?>><?=e($item['destination_name'])?></option><?php endforeach;?></select></div>
            <div class="form-field" data-shop-type-field><label>Shop Type</label><select name="shop_type_id"><option value="">Select shop type</option><?php foreach($shopTypes as $item):?><option value="<?=(int)$item['id']?>" <?=(int)$place['shop_type_id']===(int)$item['id']?'selected':''?>><?=e($item['shop_type_name'])?></option><?php endforeach;?></select></div>
            <?php $selectedLocationRegion='';foreach($locations as $item){if((int)$item['id']===(int)$place['location_id']){$selectedLocationRegion=(string)($item['region_code']?:$item['region_name']);break;}}if($selectedLocationRegion===''&&trim((string)($place['region_name']??''))!==''){$savedRegionName=preg_replace('/\s+REGION$/i','',trim((string)$place['region_name']));foreach($locationRegions as $regionKey=>$regionName){$optionRegionName=preg_replace('/\s+REGION$/i','',trim($regionName));if(strcasecmp($optionRegionName,$savedRegionName)===0){$selectedLocationRegion=(string)$regionKey;break;}}} ?>
            <div class="form-field"><label>Region</label><select name="location_region_key" data-location-region-select><option value="">Select region</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e((string)$regionKey)?>" <?=$selectedLocationRegion===(string)$regionKey?'selected':''?>><?=e($regionName)?></option><?php endforeach;?></select></div>
            <div class="form-field"><label>Town</label><select name="location_id" data-location-town-select><option value="">Select town</option><?php foreach($locations as $item):?><option value="<?=(int)$item['id']?>" data-region-key="<?=e((string)($item['region_code']?:$item['region_name']))?>" data-mmda-name="<?=e((string)$item['mmda_name'])?>" <?=(int)$place['location_id']===(int)$item['id']?'selected':''?>><?=e($item['town_name'])?><?= (int)$item['is_capital']===1?' *':'' ?></option><?php endforeach;?></select><small data-location-mmda-output></small></div>
            <div class="form-field"><label>Area</label><input name="area" value="<?=e((string)$place['area'])?>"></div>
            <div class="form-field form-field--wide"><label>Google Location</label><div class="field-control-row"><input id="edit_google_location" type="url" name="google_location" value="<?=e((string)$place['google_location'])?>" placeholder="https://maps.google.com/..."><button class="secondary-button secondary-button--small" type="button" data-current-location-target="edit_google_location">Use GPS</button></div></div>
            <div class="form-field"><label>Shop/Station Picture <span class="muted-text"><?= $place['shop_picture'] ? '(upload to replace existing)' : '(optional)' ?></span></label><input name="shop_picture" type="file" accept="image/*" data-photo-source-choice></div>
            <div class="form-field"><label>Additional Location Picture <span class="muted-text"><?= $place['shop_picture_2'] ? '(upload to replace existing)' : '(optional)' ?></span></label><input name="shop_picture_2" type="file" accept="image/*" data-photo-source-choice></div>
            <div class="form-field"><label>Shop Video <span class="muted-text"><?= $place['shop_video'] ? '(upload to replace existing)' : '(optional)' ?></span></label><input name="shop_video" type="file" accept="video/*"></div>
        </div>
        <div class="form-actions"><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>Save Location Changes</span></button></div>
        </div>
        </details>
    </form>
    <?php endif; ?>
    <div class="place-detail-overview">
        <?php if ($place['shop_picture']): ?><div class="place-detail-media"><img src="<?= e(app_url($place['shop_picture'])) ?>" alt="<?= e($placeDisplayName) ?>"></div><?php endif; ?>
        <?php if ($place['shop_picture_2']): ?><div class="place-detail-media"><img src="<?= e(app_url($place['shop_picture_2'])) ?>" alt="<?= e($placeDisplayName) ?> additional location picture"></div><?php endif; ?>
        <div class="trip-summary-grid">
            <div><span>Destination</span><strong><?= e((string)($place['destination_name'] ?: 'Not completed')) ?></strong></div>
            <?php if (!destination_is_taxi_rank($place)): ?><div><span>Shop Type</span><strong><?= e((string)($place['shop_type_name'] ?: 'Not completed')) ?></strong></div><?php endif; ?>
            <div><span>Region / Town</span><strong><?= e(trim(($place['region_name'] ?? '').' / '.($place['town_name'] ?? ''),' /') ?: 'Not completed') ?></strong></div>
            <div><span>Area</span><strong><?= e((string)($place['area'] ?: 'Not completed')) ?></strong></div>
            <div><span>Google Location</span><strong><?php if(trim((string)($place['google_location']??''))!==''):?><a href="<?=e((string)$place['google_location'])?>" target="_blank" rel="noopener">Open map</a><?php else:?>Not available<?php endif;?></strong></div>
            <div><span>Shop Video</span><strong><?= $place['shop_video'] ? '<a href="'.e(app_url($place['shop_video'])).'" target="_blank">View video</a>' : 'Not available' ?></strong></div>
            <div><span>Customers</span><strong><?= count($customers) ?></strong></div>
            <div><span>Location Visits</span><strong><?= count($placeVisits) ?></strong></div>
            <div><span>Customer Activities</span><strong><?= count($activities) ?></strong></div>
        </div>
    </div>

    <details class="registration-accordion"><summary class="registration-accordion__summary"><span class="registration-accordion__number">01</span><span class="registration-accordion__title"><strong>Customers at this location</strong><small><?= count($customers) ?> permanently registered customer(s)</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary><div class="registration-accordion__body">
        <div class="place-detail-customer-grid"><?php foreach ($customers as $customer): ?><article class="place-detail-customer-card"><?php if ($customer['customer_picture']): ?><img src="<?= e(app_url($customer['customer_picture'])) ?>" alt=""><?php else: ?><span class="place-customer-item__avatar"><i class="fa-solid fa-user"></i></span><?php endif; ?><div><span class="section-kicker"><?= e($customer['customer_ref']) ?></span><h3><?= e($customer['customer_name']) ?></h3><p><?= e((string)($customer['phone'] ?: 'No phone')) ?><?= $customer['other_phone'] ? ' / '.e($customer['other_phone']) : '' ?></p><p><?= e((string)($customer['job_type'] ?: 'Customer')) ?><?= $customer['vehicle_registration_no'] ? ' · '.e($customer['vehicle_registration_no']) : '' ?></p></div><strong><?= (int)$customer['activity_count'] ?> activities</strong></article><?php endforeach; ?><?php if (!$customers): ?><p class="empty-state">No customers are registered at this location.</p><?php endif; ?></div>
    </div></details>

    <details class="registration-accordion"><summary class="registration-accordion__summary"><span class="registration-accordion__number">02</span><span class="registration-accordion__title"><strong>Location Visits</strong><small>Arrival-to-departure history</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary><div class="registration-accordion__body"><div class="table-wrap"><table class="data-table data-table--compact"><thead><tr><th>Location Visit</th><th>Trip</th><th>Arrival</th><th>Departure</th><th>Activities</th><th>Status</th></tr></thead><tbody><?php foreach ($placeVisits as $visit): ?><tr><td><?= e($visit['session_ref']) ?></td><td><?= e((string)$visit['trip_code']) ?></td><td><?= e(substr((string)$visit['arrival_time'],0,5) ?: '—') ?></td><td><?= e(substr((string)$visit['departure_time'],0,5) ?: '—') ?></td><td><?= (int)$visit['activity_count'] ?></td><td><?= e(ucfirst($visit['status'])) ?></td></tr><?php endforeach; ?><?php if (!$placeVisits): ?><tr><td colspan="6" class="empty-state">No location visits recorded.</td></tr><?php endif; ?></tbody></table></div></div></details>

    <details class="registration-accordion"><summary class="registration-accordion__summary"><span class="registration-accordion__number">03</span><span class="registration-accordion__title"><strong>Customer Activities</strong><small>Registrations, sales, feedback and notes</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary><div class="registration-accordion__body"><div class="table-wrap"><table class="data-table data-table--compact"><thead><tr><th>Activity</th><th>Location Visit</th><th>Customer</th><th>Type</th><th>Sales</th><th>Feedback</th><th>Notes</th></tr></thead><tbody><?php foreach ($activities as $activity): ?><tr><td><?= e($activity['visit_ref']) ?></td><td><?= e((string)$activity['session_ref']) ?></td><td><?= e($activity['customer_ref'].' / '.$activity['customer_name']) ?></td><td><?= e(ucwords(str_replace('_',' ',$activity['visit_type']))) ?></td><td><?= e((string)($activity['sale_record_ref'] ?: '—')) ?><span class="muted-text"><?= e((string)$activity['sales_ref']) ?></span></td><td><?= e((string)$activity['feedback']) ?></td><td><?= e((string)$activity['note']) ?></td></tr><?php endforeach; ?><?php if (!$activities): ?><tr><td colspan="7" class="empty-state">No customer activities recorded.</td></tr><?php endif; ?></tbody></table></div></div></details>
</section>
<?php require_once __DIR__ . '/../includes/footer.php';
