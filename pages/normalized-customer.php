<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_status_schema();
ensure_customer_promo_plug_schema();
$requestedCreateCustomerWorkflow = (string)($_SESSION['normalized_customer_workflow'] ?? '') === 'create_customer';
require_module_access($requestedCreateCustomerWorkflow
    ? (current_user_role() === 'vendor' ? 'vendor_customers' : 'create_customer')
    : 'customer_visit');
ensure_sales_trip_assignment_schema();
ensure_places_management_schema();
ensure_addendum_schema();
ensure_job_type_schema();

function flow_active_trip(): ?array
{
    $statement = db()->prepare(
        "SELECT st.* FROM sales_trips st
         WHERE st.status='in_progress' AND (
            st.recorded_by_user_id=?
            OR EXISTS (SELECT 1 FROM sales_trip_staff_assignments a WHERE a.sales_trip_id=st.id AND a.staff_id=?)
            OR EXISTS (SELECT 1 FROM sales_trip_vendor_assignments a WHERE a.sales_trip_id=st.id AND a.vendor_id=?)
            OR st.vendor_id=?
         ) ORDER BY st.id DESC LIMIT 1"
    );
    $vendorId = (int)(current_vendor_profile()['id'] ?? 0);
    $statement->execute([current_user_id(), current_staff_id() ?: 0, $vendorId, $vendorId]);
    return $statement->fetch() ?: null;
}

function flow_upload(string $field, array $allowed, int $maxBytes): ?string
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

function flow_session(int $tripId): ?array
{
    $statement = db()->prepare(
        "SELECT ps.*,p.bus_loc_ref,COALESCE(NULLIF(p.business_name,''),CONCAT('Incomplete Location ',p.bus_loc_ref)) business_name,
                COALESCE(p.area,'') area,p.google_location,p.shop_picture,p.shop_picture_2,p.shop_video,
                l.town_name,d.destination_name,d.destination_key,st.shop_type_name
         FROM place_visit_sessions ps
         INNER JOIN business_locations p ON p.id=ps.bus_loc_id
         LEFT JOIN locations l ON l.id=p.location_id
         LEFT JOIN destinations d ON d.id=p.destination_id
         LEFT JOIN shop_types st ON st.id=p.shop_type_id
         WHERE ps.sales_trip_id=? AND ps.status='active'
         ORDER BY ps.id DESC LIMIT 1"
    );
    $statement->execute([$tripId]);
    return $statement->fetch() ?: null;
}

function flow_addendum_session(): ?array
{
    $statement = db()->prepare(
        "SELECT ps.*,p.bus_loc_ref,COALESCE(NULLIF(p.business_name,''),CONCAT('Incomplete Location ',p.bus_loc_ref)) business_name,
                COALESCE(p.area,'') area,p.google_location,p.shop_picture,p.shop_picture_2,p.shop_video,
                l.town_name,d.destination_name,d.destination_key,st.shop_type_name
         FROM place_visit_sessions ps
         INNER JOIN business_locations p ON p.id=ps.bus_loc_id
         LEFT JOIN locations l ON l.id=p.location_id
         LEFT JOIN destinations d ON d.id=p.destination_id
         LEFT JOIN shop_types st ON st.id=p.shop_type_id
         WHERE ps.sales_trip_id IS NULL AND ps.session_type='addendum' AND ps.status='active' AND ps.recorded_by_user_id=?
         ORDER BY ps.id DESC LIMIT 1"
    );
    $statement->execute([current_user_id()]);
    return $statement->fetch() ?: null;
}

function flow_session_has_records(int $sessionId): bool
{
    $statement = db()->prepare(
        'SELECT
            (SELECT COUNT(*) FROM visits WHERE place_session_id=?) +
            (SELECT COUNT(*) FROM customer_visit_drafts WHERE place_session_id=?)'
    );
    $statement->execute([$sessionId,$sessionId]);
    return (int)$statement->fetchColumn() > 0;
}

function flow_time(string $value): bool
{
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
}

function flow_phone_conflict(string $value, int $excludeDraftId = 0): ?string
{
    $phone = normalize_phone_number($value);
    $excludeCustomerId = 0;
    if ($excludeDraftId > 0) {
        $excludeStatement = db()->prepare('SELECT customer_id FROM customer_visit_drafts WHERE id=?');
        $excludeStatement->execute([$excludeDraftId]);
        $excludeCustomerId = (int)($excludeStatement->fetchColumn() ?: 0);
    }
    if (!is_valid_phone_number($phone)) return null;
    $lastNine = substr(preg_replace('/\D/', '', $phone), -9);

    $customers = db()->query("SELECT id,customer_name,phone,other_phone FROM customers WHERE is_active=1")->fetchAll();
    foreach ($customers as $customer) {
        if ($excludeCustomerId > 0 && (int)$customer['id'] === $excludeCustomerId) continue;
        foreach (['phone','other_phone'] as $field) {
            $candidate = substr(preg_replace('/\D/', '', (string)($customer[$field] ?? '')), -9);
            if ($candidate !== '' && $candidate === $lastNine) {
                return 'This phone number is already registered to '.$customer['customer_name'].'.';
            }
        }
    }

    $drafts = db()->query('SELECT id,draft_ref,draft_payload FROM customer_visit_drafts')->fetchAll();
    foreach ($drafts as $draft) {
        if ((int)$draft['id'] === $excludeDraftId) continue;
        $payload = json_decode((string)$draft['draft_payload'], true) ?: [];
        foreach (['phone','other_phone'] as $field) {
            $candidate = substr(preg_replace('/\D/', '', (string)($payload[$field] ?? '')), -9);
            if ($candidate !== '' && $candidate === $lastNine) {
                return 'This phone number is already saved in draft '.$draft['draft_ref'].'. Continue that draft instead.';
            }
        }
    }
    return null;
}

function flow_customer_for_phone(string $value): ?array
{
    $phone = normalize_phone_number($value);
    if (!is_valid_phone_number($phone)) return null;
    $lastNine = substr(preg_replace('/\D/', '', $phone), -9);
    $customers = db()->query(
        "SELECT c.*,COALESCE(NULLIF(p.business_name,''),p.bus_loc_ref) business_name
         FROM customers c
         INNER JOIN business_locations p ON p.id=c.bus_loc_id
         WHERE c.is_active=1"
    )->fetchAll();
    foreach ($customers as $customer) {
        foreach (['phone','other_phone'] as $field) {
            $candidate = substr(preg_replace('/\D/', '', (string)($customer[$field] ?? '')), -9);
            if ($candidate !== '' && $candidate === $lastNine) return $customer;
        }
    }
    return null;
}

function flow_ini_bytes(string $value): int
{
    $value = trim($value);
    $number = (float)$value;
    return match (strtolower(substr($value, -1))) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
}

function flow_save_sale(int $visitId, int $customerId, int $placeId, ?string $carPicture): void
{
    $promoPlug = trim((string)($_POST['promo_plug'] ?? ''));
    if ($promoPlug === '') return;
    $existing = db()->prepare('SELECT id FROM customer_promo_plugs WHERE visit_id=? LIMIT 1');
    $existing->execute([$visitId]);
    $promoId = (int)($existing->fetchColumn() ?: 0);
    if ($promoId > 0) {
        db()->prepare('UPDATE customer_promo_plugs SET promo_plug=? WHERE id=?')->execute([$promoPlug,$promoId]);
    } else {
        db()->prepare('INSERT INTO customer_promo_plugs (visit_id,customer_id,bus_loc_id,promo_plug,recorded_by_user_id) VALUES(?,?,?,?,?)')
            ->execute([$visitId,$customerId,$placeId,$promoPlug,current_user_id()]);
    }
}

function flow_save_note(int $visitId, int $customerId, string $feedback, string $notes): void
{
    if ($feedback === '' && $notes === '') {
        return;
    }

    db()->prepare(
        'INSERT INTO visit_notes
         (note_ref,visit_id,customer_id,feedback,note,staff_id,vendor_id,recorded_by_user_id)
         VALUES(?,?,?,?,?,?,?,?)'
    )->execute([
        next_project_reference('visit_note'),
        $visitId,
        $customerId,
        $feedback ?: null,
        $notes ?: null,
        current_staff_id(),
        (int)(current_vendor_profile()['id'] ?? 0) ?: null,
        current_user_id(),
    ]);
}

function flow_customer_name(int $customerId, string $fallback = ''): string
{
    if ($customerId > 0) {
        $statement = db()->prepare('SELECT customer_name FROM customers WHERE id=? LIMIT 1');
        $statement->execute([$customerId]);
        $name = trim((string)($statement->fetchColumn() ?: ''));
        if ($name !== '') return $name;
    }
    return trim($fallback) ?: 'Customer';
}

$createCustomerWorkflow = (string)($_SESSION['normalized_customer_workflow'] ?? '') === 'create_customer';
$activeTrip = $createCustomerWorkflow ? null : flow_active_trip();
$activeSession = $activeTrip ? flow_session((int)$activeTrip['id']) : flow_addendum_session();
$isAddendumSession = $activeSession && (string)($activeSession['session_type'] ?? 'trip') === 'addendum';
$stage = (string)($_GET['stage'] ?? '');
$stage = in_array($stage, ['new-place','existing-place','activity','leave','drafts','draft-view'], true) ? $stage : '';
if ($stage === '' && !$activeSession) {
    $stage = 'new-place';
}
$placeMode = (string)($_GET['place_mode'] ?? '');
$placeMode = $stage === 'new-place' && in_array($placeMode,['new','addendum'],true) ? $placeMode : '';
$entryMenu = (string)($_GET['menu'] ?? '');
$locationEntryMenu = $entryMenu === 'location';
$customerEntryMenu = $entryMenu === 'customer';
$promoEntryMenu = $entryMenu === 'promo';
$locationOnlyMenu = $locationEntryMenu && $stage === 'new-place' && $placeMode === '';
$isAddendumMode = !$activeTrip && $placeMode === 'addendum';
$isExistingAddendumMode = !$activeTrip && $stage === 'existing-place' && (string)($_GET['existing_mode'] ?? $_POST['existing_mode'] ?? '') === 'addendum';
if($_SERVER['REQUEST_METHOD']==='GET'&&!$requestedCreateCustomerWorkflow&&$stage==='new-place'&&!$activeSession&&$placeMode===''&&$entryMenu===''){
    header('Location: '.app_url('marketing.php?view=trip'));
    exit;
}
$error = '';
$outcome = (string)($_GET['outcome'] ?? '');
$customerSaved = (string)($_GET['customer_saved'] ?? '') === '1';
$locationReused = (string)($_GET['location_reused'] ?? '') === '1';
$draftSaved = (string)($_GET['draft_saved'] ?? '') === '1';
$draftDeleted = (string)($_GET['draft_deleted'] ?? '') === '1';
$savedCustomerName = trim((string)($_GET['customer_name'] ?? '')) ?: 'Customer';
$draftId = max(0, (int)($_GET['draft_id'] ?? $_POST['draft_id'] ?? 0));
$prefill = [];
$draftRow = null;

if ($draftId > 0 && $activeSession) {
    $draftStatement = db()->prepare('SELECT * FROM customer_visit_drafts WHERE id=? AND place_session_id=?');
    $draftStatement->execute([$draftId, (int)$activeSession['id']]);
    $draftRow = $draftStatement->fetch();
    if ($draftRow) {
        $prefill = json_decode((string)$draftRow['draft_payload'], true) ?: [];
    }
}

$places = db()->query(
    "SELECT p.*,COALESCE(NULLIF(p.business_name,''),CONCAT('Incomplete Location ',p.bus_loc_ref)) business_display_name,
            l.town_name,l.region_name,l.mmda_name,d.destination_name,st.shop_type_name,
            (SELECT COUNT(*) FROM customers c WHERE c.bus_loc_id=p.id AND c.is_active=1 AND c.master_customer_id IS NULL) customer_count
     FROM business_locations p
     LEFT JOIN locations l ON l.id=p.location_id
     LEFT JOIN destinations d ON d.id=p.destination_id
     LEFT JOIN shop_types st ON st.id=p.shop_type_id
     WHERE p.is_active=1 AND p.is_legacy_placeholder=0 ORDER BY p.business_name"
)->fetchAll();
$destinations = db()->query("SELECT id,destination_name,destination_key FROM destinations WHERE is_active=1 ORDER BY destination_name")->fetchAll();
$locations = active_locations();
$locationRegions = [];
foreach ($locations as $location) {
    $regionKey = (string)($location['region_code'] ?: $location['region_name']);
    $locationRegions[$regionKey] = (string)$location['region_name'];
}
asort($locationRegions);
$shopTypes = db()->query("SELECT id,shop_type_name FROM shop_types WHERE is_active=1 ORDER BY shop_type_name")->fetchAll();
$jobTypes = db()->query("SELECT id,job_type_name FROM job_types WHERE is_active=1 ORDER BY job_type_name")->fetchAll();
$masterCustomers = db()->query("SELECT c.id,c.customer_ref,c.customer_name,c.phone FROM customers c LEFT JOIN job_types jt ON jt.id=c.job_type_id WHERE c.is_active=1 AND c.record_status='completed' AND c.master_customer_id IS NULL AND COALESCE(LOWER(jt.job_type_name),'')<>'apprentice' ORDER BY c.customer_name,c.id")->fetchAll();
$jobTypeNamesById = [];
foreach ($jobTypes as $jobType) {
    $jobTypeNamesById[(int)$jobType['id']] = (string)$jobType['job_type_name'];
}
$feedbackOptions = db()->query("SELECT feedback_label FROM visit_feedback_options WHERE is_active=1 ORDER BY feedback_label")->fetchAll();
$addendumVendors=db()->query("SELECT id,vendor_name,phone,email FROM vendors WHERE is_active=1 ORDER BY vendor_name")->fetchAll();
$currentVendorId=(int)(current_vendor_profile()['id']??0);
$registeredPhones = db()->query(
    "SELECT c.id,c.customer_ref,c.customer_name,c.phone,c.other_phone,c.bus_loc_id,p.business_name
     FROM customers c
     INNER JOIN business_locations p ON p.id=c.bus_loc_id
     WHERE c.is_active=1 AND c.master_customer_id IS NULL
     ORDER BY c.customer_name"
)->fetchAll();
$standaloneSalesSql="SELECT c.id,c.customer_ref,c.customer_name,c.phone,c.other_phone,c.bus_loc_id,p.business_name,
    (SELECT v.id FROM visits v WHERE v.customer_id=c.id AND v.record_status='completed' ORDER BY v.visit_date DESC,v.id DESC LIMIT 1) latest_visit_id
    FROM customers c INNER JOIN business_locations p ON p.id=c.bus_loc_id
    WHERE c.is_active=1 AND c.record_status='completed' AND c.master_customer_id IS NULL";
$standaloneSalesParams=[];
if(current_user_role()==='vendor'){
    $standaloneSalesSql.=' AND c.vendor_id=?';
    $standaloneSalesParams=[$currentVendorId];
}elseif(!is_admin_user()){
    $standaloneSalesSql.=' AND (c.created_by_user_id=? OR EXISTS(SELECT 1 FROM visits av WHERE av.customer_id=c.id AND av.recorded_by_user_id=?))';
    $standaloneSalesParams=[current_user_id(),current_user_id()];
}
$standaloneSalesSql.=' ORDER BY c.customer_name,c.id';
$standaloneSalesStatement=db()->prepare($standaloneSalesSql);$standaloneSalesStatement->execute($standaloneSalesParams);$standaloneSalesCustomers=array_values(array_filter($standaloneSalesStatement->fetchAll(),static fn(array $row):bool=>(int)$row['latest_visit_id']>0));

$draftPhoneRows = db()->query(
    "SELECT d.id,d.draft_ref,d.draft_payload,ps.bus_loc_id,p.business_name
     FROM customer_visit_drafts d
     INNER JOIN place_visit_sessions ps ON ps.id=d.place_session_id
     INNER JOIN business_locations p ON p.id=ps.bus_loc_id
     ORDER BY d.id"
)->fetchAll();
foreach ($draftPhoneRows as $draftPhoneRow) {
    if ((int)$draftPhoneRow['id'] === $draftId) continue;
    $draftPhonePayload = json_decode((string)$draftPhoneRow['draft_payload'], true) ?: [];
    $draftPhone = normalize_phone_number((string)($draftPhonePayload['phone'] ?? ''));
    $draftOtherPhone = normalize_phone_number((string)($draftPhonePayload['other_phone'] ?? ''));
    if ($draftPhone === '' && $draftOtherPhone === '') continue;
    $registeredPhones[] = [
        'id' => (int)$draftPhoneRow['id'],
        'customer_ref' => (string)$draftPhoneRow['draft_ref'],
        'customer_name' => trim((string)($draftPhonePayload['customer_name'] ?? '')) ?: 'Draft customer',
        'phone' => $draftPhone,
        'other_phone' => $draftOtherPhone,
        'bus_loc_id' => (int)$draftPhoneRow['bus_loc_id'],
        'business_name' => (string)$draftPhoneRow['business_name'],
        'is_draft' => true,
    ];
}
$placeCustomers = [];
$recentPlaceCustomers = [];
$sessionDrafts = [];
$sessionCustomerChain = [];
if ($activeSession) {
    $customerStatement = db()->prepare('SELECT * FROM customers WHERE bus_loc_id=? AND is_active=1 AND master_customer_id IS NULL ORDER BY customer_name');
    $customerStatement->execute([(int)$activeSession['bus_loc_id']]);
    $placeCustomers = $customerStatement->fetchAll();
    $recentCustomerStatement = db()->prepare(
        "SELECT c.*,
                (SELECT MAX(v.created_at) FROM visits v WHERE v.customer_id=c.id AND v.record_status='completed') latest_registration_at
         FROM customers c
         WHERE c.bus_loc_id=? AND c.is_active=1 AND c.master_customer_id IS NULL
           AND EXISTS (SELECT 1 FROM visits v WHERE v.customer_id=c.id AND v.record_status='completed')
         ORDER BY latest_registration_at DESC,c.id DESC"
    );
    $recentCustomerStatement->execute([(int)$activeSession['bus_loc_id']]);
    $recentPlaceCustomers = $recentCustomerStatement->fetchAll();
    foreach ($recentPlaceCustomers as &$recentPlaceCustomer) {
        $recentPlaceCustomer['is_draft'] = false;
    }
    unset($recentPlaceCustomer);
    $recentDraftStatement = db()->prepare(
        'SELECT d.id,d.draft_ref,d.draft_payload,d.created_at
         FROM customer_visit_drafts d
         INNER JOIN place_visit_sessions ps ON ps.id=d.place_session_id
         WHERE ps.bus_loc_id=? AND ps.id=?
         ORDER BY d.created_at DESC,d.id DESC'
    );
    $recentDraftStatement->execute([(int)$activeSession['bus_loc_id'],(int)$activeSession['id']]);
    foreach ($recentDraftStatement->fetchAll() as $recentDraft) {
        $recentDraftPayload = json_decode((string)$recentDraft['draft_payload'],true) ?: [];
        $recentPlaceCustomers[] = [
            'id' => (int)$recentDraft['id'],
            'customer_ref' => (string)$recentDraft['draft_ref'],
            'customer_name' => trim((string)($recentDraftPayload['customer_name'] ?? '')) ?: 'Draft customer',
            'phone' => (string)($recentDraftPayload['phone'] ?? ''),
            'other_phone' => (string)($recentDraftPayload['other_phone'] ?? ''),
            'latest_registration_at' => (string)$recentDraft['created_at'],
            'is_draft' => true,
        ];
    }
    usort($recentPlaceCustomers,static fn(array $a,array $b): int => strcmp((string)$b['latest_registration_at'],(string)$a['latest_registration_at']));
    $draftStatement = db()->prepare('SELECT * FROM customer_visit_drafts WHERE place_session_id=? ORDER BY id DESC');
    $draftStatement->execute([(int)$activeSession['id']]);
    $sessionDrafts = $draftStatement->fetchAll();
    $completedStatement = db()->prepare(
        "SELECT v.id,v.visit_ref,v.created_at,c.customer_name
         FROM visits v INNER JOIN customers c ON c.id=v.customer_id
         WHERE v.place_session_id=? ORDER BY v.id"
    );
    $completedStatement->execute([(int)$activeSession['id']]);
    foreach ($completedStatement->fetchAll() as $completedEntry) {
        $sessionCustomerChain[] = [
            'type' => 'completed',
            'id' => (int)$completedEntry['id'],
            'sort_at' => (string)$completedEntry['created_at'],
            'title' => (string)$completedEntry['customer_name'],
        ];
    }
    foreach ($sessionDrafts as $draftEntry) {
        $draftPayload = json_decode((string)$draftEntry['draft_payload'], true) ?: [];
        $sessionCustomerChain[] = [
            'type' => 'draft',
            'id' => (int)$draftEntry['id'],
            'sort_at' => (string)$draftEntry['created_at'],
            'title' => trim((string)($draftPayload['customer_name'] ?? '')) ?: (string)$draftEntry['draft_ref'],
        ];
    }
    usort($sessionCustomerChain, static fn(array $a, array $b): int => [$a['sort_at'],$a['id']] <=> [$b['sort_at'],$b['id']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['form_action'] ?? $_POST['form_intent'] ?? '');
    $requestBytes = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postLimitBytes = flow_ini_bytes((string)ini_get('post_max_size'));
    if ($requestBytes > 0 && $postLimitBytes > 0 && $requestBytes > $postLimitBytes) {
        $error = 'The selected files are too large for one submission. Reduce the video or picture size and try again.';
    } elseif (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'save_standalone_sales') {
        if($promoEntryMenu){$promoEnabled=isset($_POST['promo_enabled']);$promoNumber=trim((string)($_POST['promo_plug_number']??''));$_POST['promo_plug']=$promoEnabled?$promoNumber:'No';}
        $selectedCustomerId=max(0,(int)($_POST['sales_customer_id']??0));
        $selectedCustomer=null;
        foreach($standaloneSalesCustomers as $salesCustomer){if((int)$salesCustomer['id']===$selectedCustomerId){$selectedCustomer=$salesCustomer;break;}}
        $hasStandaloneSales=trim((string)($_POST['sales_ref']??''))!==''||trim((string)($_POST['promo_plug']??''))!==''||isset($_POST['sale_confirmed']);
        foreach((array)($_POST['sale_vin']??[]) as $vinValue){if(trim((string)$vinValue)!==''){$hasStandaloneSales=true;break;}}
        if(!$selectedCustomer){$error='Select a customer before saving sales details.';}
        elseif($promoEntryMenu&&isset($_POST['promo_enabled'])&&trim((string)($_POST['promo_plug_number']??''))===''){$error='Enter the plug number.';}
        elseif(!$hasStandaloneSales){$error='Enter sales details before saving.';}
        else{try{db()->beginTransaction();flow_save_sale((int)$selectedCustomer['latest_visit_id'],(int)$selectedCustomer['id'],(int)$selectedCustomer['bus_loc_id'],null);db()->commit();$savedTarget=$promoEntryMenu?'normalized-customer.php?stage=new-place&menu=promo&standalone_sale_saved=1':'normalized-customer.php?standalone_sale_saved=1';header('Location: '.app_url($savedTarget));exit;}catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error=$exception instanceof RuntimeException?$exception->getMessage():'The sales details could not be saved.';}}
    } elseif (!$activeTrip && !$activeSession && !(in_array($action,['create_place','save_place_only'],true) && (string)($_POST['session_mode'] ?? '') === 'addendum') && !($action==='select_place' && (string)($_POST['session_mode']??'')==='addendum')) {
        $error = 'Start a trip or use Addendum before registering a location or customer.';
    } elseif ($action === 'delete_draft' && $activeSession) {
        $deleteDraftId = max(0, (int)($_POST['draft_id'] ?? 0));
        if ($deleteDraftId <= 0) {
            $error = 'Select a valid draft to delete.';
        } else {
            $statement = db()->prepare('DELETE FROM customer_visit_drafts WHERE id=? AND place_session_id=?');
            $statement->execute([$deleteDraftId, (int)$activeSession['id']]);
            if ($statement->rowCount() < 1) {
                $error = 'The draft was not found at this location.';
            } else {
                header('Location: '.app_url('normalized-customer.php?stage=activity&draft_deleted=1'));
                exit;
            }
        }
    } elseif (in_array($action, ['create_place','save_place_only'], true)) {
        $placeOnly = $action === 'save_place_only';
        $addendumRequest = !$activeTrip && (string)($_POST['session_mode'] ?? '') === 'addendum';
        $activityDate = trim((string)($_POST['activity_date'] ?? date('Y-m-d')));
        if ($activeSession) {
            $error = 'Leave the current location before starting another location visit.';
        } else {
            $business = trim((string)($_POST['business_name'] ?? ''));
            $destinationId = max(0, (int)($_POST['destination_id'] ?? 0));
            $destinationStatement = db()->prepare('SELECT destination_key FROM destinations WHERE id=? AND is_active=1');
            $destinationStatement->execute([$destinationId]);
            $destinationKey = (string)($destinationStatement->fetchColumn() ?: '');
            $isTaxiDestination = $destinationKey === 'taxi_rank';
            $locationId = max(0, (int)($_POST['location_id'] ?? 0));
            $area = trim((string)($_POST['area'] ?? ''));
            $google = trim((string)($_POST['google_location'] ?? ''));
            $shopTypeId = max(0, (int)($_POST['shop_type_id'] ?? 0));
            foreach ($destinations as $destination) {
                if ((int)$destination['id'] === $destinationId && destination_is_taxi_rank($destination)) {
                    $shopTypeId = 0;
                    break;
                }
            }
            $customerName = trim((string)($_POST['customer_name'] ?? ''));
            $phone = normalize_phone_number((string)($_POST['phone'] ?? ''));
            $arrival = trim((string)($_POST['arrival_time'] ?? ''));
            $feedback = trim((string)($_POST['feedback'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $location = location_by_id($locationId);
            $phoneConflict = !$placeOnly ? flow_phone_conflict($phone) : null;
            if ($phoneConflict !== null) {
                $error = $phoneConflict;
            } elseif ($addendumRequest && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$activityDate)) {
                $error = 'Select a valid Addendum date.';
            } elseif (!$addendumRequest && $google === '') {
                $error = 'Add the Google Location before saving the location.';
            } elseif (!$addendumRequest && !flow_time($arrival)) {
                $error = 'Enter the shop arrival time.';
            } elseif (($addendumRequest || !$placeOnly) && ($business === '' || !$destinationId || !$location || $area === '')) {
                $error = 'Complete the business name, destination, region, town, and area.';
            } elseif (!$placeOnly && ($customerName === '' || $phone === '' || !is_valid_phone_number($phone))) {
                $error = 'Enter the customer name and a valid phone number.';
            } elseif (!$placeOnly && ($feedback === '' || $notes === '')) {
                $error = 'Select feedback and enter a note to complete the registration.';
            } else {
                try {
                    db()->beginTransaction();
                    if (!$placeOnly) {
                        $duplicate = db()->prepare('SELECT customer_name FROM customers WHERE phone=? OR other_phone=? LIMIT 1');
                        $duplicate->execute([$phone,$phone]);
                        $existingCustomerName = (string)($duplicate->fetchColumn() ?: '');
                        if ($existingCustomerName !== '') {
                            throw new RuntimeException('This phone is already registered to ' . $existingCustomerName . '. Open the customer’s saved location instead.');
                        }
                    }
                    $normalizedGoogle = strtolower(rtrim(trim($google), '/'));
                    $duplicateLocation = db()->prepare(
                        "SELECT id FROM business_locations
                         WHERE is_active=1 AND is_legacy_placeholder=0 AND (
                            (?<>'' AND LOWER(TRIM(TRAILING '/' FROM TRIM(COALESCE(google_location,''))))=?)
                            OR (?<>'' AND ?>0 AND ?<>'' AND LOWER(TRIM(COALESCE(business_name,'')))=? AND location_id=? AND LOWER(TRIM(COALESCE(area,'')))=?)
                         )
                         ORDER BY id LIMIT 1 FOR UPDATE"
                    );
                    $normalizedBusiness = strtolower($business);
                    $normalizedArea = strtolower($area);
                    $duplicateLocation->execute([$normalizedGoogle,$normalizedGoogle,$normalizedBusiness,$locationId,$normalizedArea,$normalizedBusiness,$locationId,$normalizedArea]);
                    $placeId = (int)($duplicateLocation->fetchColumn() ?: 0);
                    $locationReused = $placeId > 0;
                    if (!$locationReused) {
                        $shopPicture = flow_upload('shop_picture', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES);
                        $shopPicture2 = flow_upload('shop_picture_2', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES);
                        $shopVideo = flow_upload('shop_video', ['video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov'], 30 * 1024 * 1024);
                        $statement = db()->prepare("INSERT INTO business_locations(bus_loc_ref,business_name,destination_id,location_id,area,google_location,shop_type_id,shop_picture,shop_picture_2,shop_video,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
                        $statement->execute([next_project_reference('place'),$business ?: null,$destinationId ?: null,$locationId ?: null,$area ?: null,$google ?: null,$shopTypeId ?: null,$shopPicture,$shopPicture2,$shopVideo,current_user_id()]);
                        $placeId = (int)db()->lastInsertId();
                    }
                    db()->prepare("INSERT INTO place_visit_sessions(session_ref,sales_trip_id,bus_loc_id,session_type,activity_date,arrival_time,recorded_by_user_id) VALUES(?,?,?,?,?,?,?)")
                        ->execute([next_project_reference('place_visit'),$addendumRequest ? null : (int)$activeTrip['id'],$placeId,$addendumRequest ? 'addendum' : 'trip',$addendumRequest ? $activityDate : (string)$activeTrip['trip_date'],$addendumRequest ? null : $arrival,current_user_id()]);
                    $placeSessionId = (int)db()->lastInsertId();

                    if ($placeOnly) {
                        db()->commit();
                        header('Location: ' . app_url('normalized-customer.php?stage=activity&place_saved=1'.($locationReused?'&location_reused=1':'')));
                        exit;
                    }

                    $customerPicture = flow_upload('customer_picture', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES);
                    $carPicture = $isTaxiDestination
                        ? flow_upload('car_picture', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES)
                        : null;
                    $jobTypeId=max(0,(int)($_POST['job_type_id']??0));$jobTypeName=null;if($jobTypeId){$jobTypeStatement=db()->prepare('SELECT job_type_name FROM job_types WHERE id=? AND is_active=1');$jobTypeStatement->execute([$jobTypeId]);$jobTypeName=$jobTypeStatement->fetchColumn()?:null;}$masterCustomerId=customer_master_id_for_job($jobTypeId,max(0,(int)($_POST['master_customer_id']??0)));
                    $statement = db()->prepare("INSERT INTO customers(customer_ref,bus_loc_id,vendor_id,customer_name,job_type,job_type_id,master_customer_id,phone,other_phone,customer_picture,vehicle_registration_no,vin_no,supervisor_name,supervisor_phone,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $statement->execute([next_project_reference('customer'),$placeId,(int)(current_vendor_profile()['id'] ?? 0) ?: null,$customerName,$jobTypeName,$jobTypeId?:null,$masterCustomerId,$phone,normalize_phone_number((string)($_POST['other_phone'] ?? '')) ?: null,$customerPicture,$isTaxiDestination ? (trim((string)($_POST['vehicle_registration_no'] ?? '')) ?: null) : null,$isTaxiDestination ? (trim((string)($_POST['vin_no'] ?? '')) ?: null) : null,$isTaxiDestination ? (trim((string)($_POST['supervisor_name'] ?? '')) ?: null) : null,$isTaxiDestination ? (normalize_phone_number((string)($_POST['supervisor_phone'] ?? '')) ?: null) : null,current_user_id()]);
                    $customerId = (int)db()->lastInsertId();

                    $evidence = null;
                    $statement = db()->prepare("INSERT INTO visits(visit_ref,sales_trip_id,place_session_id,bus_loc_id,customer_id,vendor_id,staff_id,recorded_by_user_id,visit_type,visit_date,arrival_time,visit_evidence,record_status) VALUES(?,?,?,?,?,?,?,?,'registration',CURDATE(),?,?,'completed')");
                    $statement->execute([next_project_reference('visit'),(int)$activeTrip['id'],$placeSessionId,$placeId,$customerId,(int)(current_vendor_profile()['id'] ?? 0) ?: null,current_staff_id(),current_user_id(),$arrival,$evidence]);
                    $visitId = (int)db()->lastInsertId();
                    flow_save_sale($visitId,$customerId,$placeId,$carPicture);
                    flow_save_note($visitId,$customerId,$feedback,$notes);
                    db()->commit();
                    header('Location: ' . app_url('normalized-customer.php?stage=activity&customer_saved=1'.($locationReused?'&location_reused=1':'').'&customer_name='.rawurlencode(flow_customer_name($customerId,$customerName))));
                    exit;
                } catch (Throwable $exception) {
                    if (db()->inTransaction()) db()->rollBack();
                    $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The location and customer registration could not be saved.';
                }
            }
        }
    } elseif ($action === 'select_place') {
        $placeId = max(0, (int)($_POST['bus_loc_id'] ?? 0));
        $existingAddendumRequest = !$activeTrip && (string)($_POST['session_mode'] ?? '') === 'addendum';
        $activityDate = trim((string)($_POST['activity_date'] ?? date('Y-m-d')));
        if ($activeSession) {
            $error = 'Leave the current location before continuing at another location.';
        } elseif($existingAddendumRequest && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$activityDate)) {
            $error = 'Select a valid Addendum date.';
        } else {
            $statement = db()->prepare('SELECT id FROM business_locations WHERE id=? AND is_active=1');
            $statement->execute([$placeId]);
            if (!$statement->fetchColumn()) {
                $error = 'Find and select an existing location.';
            } else {
                db()->prepare("INSERT INTO place_visit_sessions(session_ref,sales_trip_id,bus_loc_id,session_type,activity_date,arrival_time,recorded_by_user_id) VALUES(?,?,?,?,?,?,?)")
                    ->execute([next_project_reference('place_visit'),$existingAddendumRequest?null:(int)$activeTrip['id'],$placeId,$existingAddendumRequest?'addendum':'trip',$existingAddendumRequest?$activityDate:(string)$activeTrip['trip_date'],null,current_user_id()]);
                header('Location: ' . app_url('normalized-customer.php?stage=activity'));
                exit;
            }
        }
    } elseif ($action === 'set_arrival' && $activeSession) {
        $arrival = trim((string)($_POST['arrival_time'] ?? ''));
        $arrivalReturnUrl = 'normalized-customer.php?stage=activity' . ($draftId > 0 ? '&draft_id=' . $draftId : '');
        if ($activeSession['arrival_time']) {
            header('Location: ' . app_url($arrivalReturnUrl));
            exit;
        }
        if (!flow_time($arrival)) {
            $error = 'Select a valid shop arrival time.';
        } else {
            db()->prepare('UPDATE place_visit_sessions SET arrival_time=? WHERE id=? AND status=\'active\' AND arrival_time IS NULL')
                ->execute([$arrival,(int)$activeSession['id']]);
            header('Location: ' . app_url($arrivalReturnUrl));
            exit;
        }
    } elseif ($action === 'save_activity' && $activeSession) {
        $activityKind = 'new_customer';
        $customerId = (int)($draftRow['customer_id'] ?? 0);
        $customerName = trim((string)($_POST['customer_name'] ?? ''));
        $phone = normalize_phone_number((string)($_POST['phone'] ?? ''));
        $arrival = $activeSession['arrival_time']
            ? substr((string)$activeSession['arrival_time'], 0, 5)
            : trim((string)($_POST['arrival_time'] ?? ''));
        $feedback = trim((string)($_POST['feedback'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $isTaxiRankSession = (string)($activeSession['destination_key'] ?? '') === 'taxi_rank';
        $selectedVendorId=$isAddendumSession?($currentVendorId?:max(0,(int)($_POST['vendor_id']??$prefill['vendor_id']??0))):$currentVendorId;
        $selectedVendor=null;foreach($addendumVendors as $vendorOption){if((int)$vendorOption['id']===$selectedVendorId){$selectedVendor=$vendorOption;break;}}
        if($isAddendumSession)$payloadVendorId=$selectedVendor?(int)$selectedVendor['id']:0;
        $salesCustomerId = max(0,(int)($_POST['sales_customer_id'] ?? 0));
        $salesCustomerDraftId = max(0,(int)($_POST['sales_customer_draft_id'] ?? 0));
        $moveExistingCustomerId = max(0,(int)($_POST['move_existing_customer_id'] ?? 0));
        $movingExistingCustomer = null;
        if ($moveExistingCustomerId > 0) {
            $matchedCustomer = flow_customer_for_phone($phone);
            if ($matchedCustomer && (int)$matchedCustomer['id'] === $moveExistingCustomerId
                && (int)$matchedCustomer['bus_loc_id'] !== (int)$activeSession['bus_loc_id']) {
                $movingExistingCustomer = $matchedCustomer;
                $customerId = (int)$matchedCustomer['id'];
                $customerName = (string)$matchedCustomer['customer_name'];
            }
        }
        $customerValid = false;
        if ($activityKind === 'existing_customer') {
            $check = db()->prepare('SELECT id FROM customers WHERE id=? AND bus_loc_id=? AND is_active=1');
            $check->execute([$customerId, (int)$activeSession['bus_loc_id']]);
            $customerValid = (bool)$check->fetchColumn();
        } else {
            $customerValid = $customerName !== ''
                && $phone !== ''
                && is_valid_phone_number($phone);
        }
        $hasGoogleLocation = trim((string)($activeSession['google_location'] ?? '')) !== '';
        $hasArrivalTime = flow_time($arrival);
        $hasFeedbackAndNote = $feedback !== '' && $notes !== '';
        $complete = $customerValid && ($isAddendumSession || ($hasGoogleLocation && $hasArrivalTime)) && $hasFeedbackAndNote;
        $hasUploadedActivityFile =
            (isset($_FILES['customer_picture']) && ($_FILES['customer_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
            || ($isTaxiRankSession && isset($_FILES['car_picture']) && ($_FILES['car_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        $activityFields = [
            'customer_name','phone','other_phone','job_type_id',
            'sales_ref','promo_plug','feedback','notes',
        ];
        if ($isTaxiRankSession) {
            array_push($activityFields,'vehicle_registration_no','vin_no','supervisor_name','supervisor_phone');
        }
        if ($activityKind === 'existing_customer') {
            $activityFields = ['customer_id','sales_ref','promo_plug','feedback','notes'];
        }
        $hasActivityData = $hasUploadedActivityFile || isset($_POST['sale_confirmed']);
        foreach ((array)($_POST['sale_vin'] ?? []) as $saleVinValue) {
            if (trim((string)$saleVinValue) !== '') {$hasActivityData = true; break;}
        }
        foreach ((array)($_POST['sale_amount'] ?? []) as $saleAmountValue) {
            if (trim((string)$saleAmountValue) !== '') {$hasActivityData = true; break;}
        }
        foreach ($activityFields as $activityField) {
            if (trim((string)($_POST[$activityField] ?? '')) !== '') {
                $hasActivityData = true;
                break;
            }
        }
        $payload = $_POST;
        unset($payload['csrf_token'], $payload['form_action']);
        if($isAddendumSession)$payload['vendor_id']=$selectedVendorId;
        $phoneConflict = $activityKind === 'new_customer'
            && !$movingExistingCustomer ? flow_phone_conflict($phone, $draftId)
            : null;
        $customerSectionHasData = $customerName !== ''
            || $phone !== ''
            || trim((string)($_POST['other_phone'] ?? '')) !== ''
            || $feedback !== ''
            || $notes !== ''
            || $activityKind === 'existing_customer';
        $hasSalesData = isset($_POST['sale_confirmed'])
            || trim((string)($_POST['sales_ref'] ?? '')) !== ''
            || trim((string)($_POST['promo_plug'] ?? '')) !== ''
            || ($isTaxiRankSession && isset($_FILES['car_picture']) && ($_FILES['car_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        foreach ((array)($_POST['sale_vin'] ?? []) as $saleVinValue) {
            if (trim((string)$saleVinValue) !== '') {$hasSalesData = true; break;}
        }
        $salesOnlyCustomer = null;
        $salesOnlyDraft = null;
        if ($salesCustomerDraftId > 0 && !$customerSectionHasData && $hasSalesData) {
            $salesOnlyDraftStatement = db()->prepare(
                'SELECT d.id,d.draft_payload
                 FROM customer_visit_drafts d
                 INNER JOIN place_visit_sessions ps ON ps.id=d.place_session_id
                 WHERE d.id=? AND ps.bus_loc_id=? AND ps.sales_trip_id=?'
            );
            $salesOnlyDraftStatement->execute([$salesCustomerDraftId,(int)$activeSession['bus_loc_id'],(int)$activeSession['id']]);
            $salesOnlyDraft = $salesOnlyDraftStatement->fetch() ?: null;
        }
        if ($salesCustomerId > 0 && !$customerSectionHasData && $hasSalesData) {
            $salesOnlyStatement = db()->prepare(
                "SELECT c.id,c.customer_name,
                        (SELECT v.id FROM visits v
                         WHERE v.customer_id=c.id AND v.bus_loc_id=? AND v.record_status='completed'
                         ORDER BY v.created_at DESC,v.id DESC LIMIT 1) visit_id
                 FROM customers c WHERE c.id=? AND c.bus_loc_id=? AND c.is_active=1"
            );
            $salesOnlyStatement->execute([(int)$activeSession['bus_loc_id'],$salesCustomerId,(int)$activeSession['bus_loc_id']]);
            $salesOnlyCustomer = $salesOnlyStatement->fetch() ?: null;
        }

        if ($moveExistingCustomerId > 0 && !$movingExistingCustomer) {
            $error = 'The customer move could not be verified. Re-enter the registered phone number and try again.';
        } elseif ($isAddendumSession && !$selectedVendor) {
            $error = 'Select a vendor for this Addendum customer.';
        } elseif ($salesCustomerId === 0 && $salesCustomerDraftId === 0 && !$customerSectionHasData && $hasSalesData) {
            $error = 'Select a recent customer before saving these sales details.';
        } elseif ($salesCustomerDraftId > 0 && !$customerSectionHasData && !$hasSalesData) {
            $error = 'Enter the sales details for the selected draft customer.';
        } elseif ($salesCustomerDraftId > 0 && !$customerSectionHasData && !$salesOnlyDraft) {
            $error = 'Select a valid draft registration at this location.';
        } elseif ($salesOnlyDraft) {
            try {
                $draftSaleVins = is_array($_POST['sale_vin'] ?? null) ? $_POST['sale_vin'] : [];
                $draftSaleAmounts = is_array($_POST['sale_amount'] ?? null) ? $_POST['sale_amount'] : [];
                $savedDraftVins = [];
                $savedDraftAmounts = [];
                $seenDraftVins = [];
                $draftPurchaseCount = max(count($draftSaleVins),count($draftSaleAmounts));
                for ($draftPurchaseIndex=0;$draftPurchaseIndex<$draftPurchaseCount;$draftPurchaseIndex++) {
                    $draftSaleVin = strtoupper(preg_replace('/\s+/', '', trim((string)($draftSaleVins[$draftPurchaseIndex] ?? ''))) ?? '');
                    $draftSaleAmount = str_replace([',',' '], '', trim((string)($draftSaleAmounts[$draftPurchaseIndex] ?? '')));
                    if ($draftSaleVin === '' && $draftSaleAmount === '') continue;
                    if ($draftSaleVin === '' || $draftSaleAmount === '') throw new RuntimeException('Enter both the VIN and amount for every purchased vehicle.');
                    if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/',$draftSaleVin)) throw new RuntimeException($draftSaleVin.' is not a valid 17-character VIN.');
                    if (!is_numeric($draftSaleAmount) || (float)$draftSaleAmount<=0) throw new RuntimeException('Enter a valid amount greater than zero for VIN '.$draftSaleVin.'.');
                    if (isset($seenDraftVins[$draftSaleVin])) throw new RuntimeException('VIN '.$draftSaleVin.' was entered more than once.');
                    $duplicateVinStatement=db()->prepare('SELECT vin_no FROM customer_pos_sale_vins WHERE vin_no=? LIMIT 1');
                    $duplicateVinStatement->execute([$draftSaleVin]);
                    if($duplicateVinStatement->fetchColumn()) throw new RuntimeException('VIN '.$draftSaleVin.' has already been recorded as purchased.');
                    $seenDraftVins[$draftSaleVin]=true;
                    $savedDraftVins[]=$draftSaleVin;
                    $savedDraftAmounts[]=number_format((float)$draftSaleAmount,2,'.','');
                }
                $salesDraftPayload=json_decode((string)$salesOnlyDraft['draft_payload'],true) ?: [];
                $salesDraftPayload['sale_vin']=$savedDraftVins;
                $salesDraftPayload['sale_amount']=$savedDraftAmounts;
                $salesDraftPayload['sales_ref']=trim((string)($_POST['sales_ref']??''));
                $salesDraftPayload['promo_plug']=trim((string)($_POST['promo_plug']??''));
                if(isset($_POST['sale_confirmed']) || $savedDraftVins)$salesDraftPayload['sale_confirmed']='1';
                db()->prepare('UPDATE customer_visit_drafts SET draft_payload=? WHERE id=?')
                    ->execute([json_encode($salesDraftPayload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$salesOnlyDraft['id']]);
                header('Location: '.app_url('normalized-customer.php?stage=activity&draft_saved=1&customer_name='.rawurlencode(flow_customer_name((int)($salesDraftPayload['customer_id']??0),(string)($salesDraftPayload['customer_name']??'')))));
                exit;
            } catch (Throwable $exception) {
                $error=$exception instanceof RuntimeException?$exception->getMessage():'The draft sales details could not be saved.';
            }
        } elseif ($salesCustomerId > 0 && !$customerSectionHasData && !$hasSalesData) {
            $error = 'Enter the sales details for the selected customer.';
        } elseif ($salesCustomerId > 0 && !$customerSectionHasData && (!$salesOnlyCustomer || !(int)$salesOnlyCustomer['visit_id'])) {
            $error = 'Select a recently registered customer at this location.';
        } elseif ($salesOnlyCustomer) {
            try {
                db()->beginTransaction();
                $carPicture = $isTaxiRankSession
                    ? flow_upload('car_picture', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES)
                    : null;
                flow_save_sale((int)$salesOnlyCustomer['visit_id'],(int)$salesOnlyCustomer['id'],(int)$activeSession['bus_loc_id'],$carPicture);
                db()->commit();
                header('Location: '.app_url('normalized-customer.php?stage=activity&customer_saved=1&customer_name='.rawurlencode((string)$salesOnlyCustomer['customer_name'])));
                exit;
            } catch (Throwable $exception) {
                if (db()->inTransaction()) db()->rollBack();
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The sales details could not be saved.';
            }
        } elseif ($phoneConflict !== null) {
            $error = $phoneConflict;
        } elseif (!$complete && !$hasActivityData) {
            $error = 'Enter customer details before using Save / Next. A blank customer activity will not be saved as a draft.';
        } elseif (!$hasGoogleLocation) {
            $error = 'Add a Google location to this location before saving a customer draft.';
        } elseif (!$hasArrivalTime) {
            $error = 'Enter the shop arrival time before saving a customer draft.';
        } elseif (!$complete) {
            if (!$activeSession['arrival_time']) {
                db()->prepare('UPDATE place_visit_sessions SET arrival_time=? WHERE id=? AND status=\'active\' AND arrival_time IS NULL')
                    ->execute([$arrival,(int)$activeSession['id']]);
            }
            $jobTypeId=max(0,(int)($_POST['job_type_id']??0));$jobTypeName=null;if($jobTypeId){$jobTypeStatement=db()->prepare('SELECT job_type_name FROM job_types WHERE id=? AND is_active=1');$jobTypeStatement->execute([$jobTypeId]);$jobTypeName=$jobTypeStatement->fetchColumn()?:null;}
            if ($customerId <= 0) {
                $draftCustomerStatement=db()->prepare("INSERT INTO customers(customer_ref,bus_loc_id,vendor_id,customer_name,job_type,job_type_id,phone,other_phone,vehicle_registration_no,vin_no,supervisor_name,supervisor_phone,created_by_user_id,record_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?, 'draft')");
                $draftCustomerStatement->execute([next_project_reference('customer'),(int)$activeSession['bus_loc_id'],$selectedVendorId ?: null,$customerName?:'Incomplete customer',$jobTypeName,$jobTypeId?:null,$phone?:null,normalize_phone_number((string)($_POST['other_phone']??''))?:null,$isTaxiRankSession?(trim((string)($_POST['vehicle_registration_no']??''))?:null):null,$isTaxiRankSession?(trim((string)($_POST['vin_no']??''))?:null):null,$isTaxiRankSession?(trim((string)($_POST['supervisor_name']??''))?:null):null,$isTaxiRankSession?(normalize_phone_number((string)($_POST['supervisor_phone']??''))?:null):null,current_user_id()]);
                $customerId=(int)db()->lastInsertId();
            } else {
                db()->prepare("UPDATE customers SET vendor_id=?,customer_name=?,job_type=?,job_type_id=?,phone=?,other_phone=?,vehicle_registration_no=?,vin_no=?,supervisor_name=?,supervisor_phone=?,record_status='draft' WHERE id=?")->execute([$selectedVendorId ?: null,$customerName?:'Incomplete customer',$jobTypeName,$jobTypeId?:null,$phone?:null,normalize_phone_number((string)($_POST['other_phone']??''))?:null,$isTaxiRankSession?(trim((string)($_POST['vehicle_registration_no']??''))?:null):null,$isTaxiRankSession?(trim((string)($_POST['vin_no']??''))?:null):null,$isTaxiRankSession?(trim((string)($_POST['supervisor_name']??''))?:null):null,$isTaxiRankSession?(normalize_phone_number((string)($_POST['supervisor_phone']??''))?:null):null,$customerId]);
            }
            $payload['customer_id']=$customerId;
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($draftId > 0) {
                $statement = db()->prepare('UPDATE customer_visit_drafts SET customer_id=?,draft_payload=? WHERE id=? AND place_session_id=?');
                $statement->execute([$customerId ?: null,$json,$draftId,(int)$activeSession['id']]);
            } else {
                $statement = db()->prepare("INSERT INTO customer_visit_drafts(draft_ref,place_session_id,customer_id,draft_payload,recorded_by_user_id) VALUES(?,?,?,?,?)");
                $statement->execute([next_project_reference('visit_draft'),(int)$activeSession['id'],$customerId ?: null,$json,current_user_id()]);
                $draftId = (int)db()->lastInsertId();
            }
            header('Location: ' . app_url('normalized-customer.php?stage=activity&draft_saved=1&customer_name='.rawurlencode(flow_customer_name($customerId,$customerName))));
            exit;
        }

        if ($error === '') try {
            db()->beginTransaction();
            $carPicture = null;
            if (!$activeSession['arrival_time']) {
                db()->prepare('UPDATE place_visit_sessions SET arrival_time=? WHERE id=? AND status=\'active\'')
                    ->execute([$arrival,(int)$activeSession['id']]);
            }
            if ($movingExistingCustomer) {
                db()->prepare('UPDATE customers SET bus_loc_id=?,vendor_id=?,record_status=\'completed\',customer_status=\'registered\' WHERE id=? AND is_active=1')
                    ->execute([(int)$activeSession['bus_loc_id'],$selectedVendorId ?: null,$customerId]);
            }
            if ($activityKind === 'new_customer' && $customerId <= 0) {
                $duplicate = db()->prepare('SELECT id,customer_name FROM customers WHERE (phone=? OR other_phone=?) AND id<>? LIMIT 1');
                $duplicate->execute([$phone,$phone,$customerId]);
                $existingPhone = $duplicate->fetch();
                if ($existingPhone) {
                    throw new RuntimeException('This phone is already registered to ' . $existingPhone['customer_name'] . '. Use the Follow-up menu for an existing customer.');
                }
                $customerPicture = flow_upload('customer_picture', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES);
                $carPicture = $isTaxiRankSession
                    ? flow_upload('car_picture', ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'], APP_IMAGE_UPLOAD_MAX_BYTES)
                    : null;
                $jobTypeId=max(0,(int)($_POST['job_type_id']??0));$jobTypeName=null;if($jobTypeId){$jobTypeStatement=db()->prepare('SELECT job_type_name FROM job_types WHERE id=? AND is_active=1');$jobTypeStatement->execute([$jobTypeId]);$jobTypeName=$jobTypeStatement->fetchColumn()?:null;}$masterCustomerId=customer_master_id_for_job($jobTypeId,max(0,(int)($_POST['master_customer_id']??0)));
                $statement = db()->prepare("INSERT INTO customers(customer_ref,bus_loc_id,vendor_id,customer_name,job_type,job_type_id,master_customer_id,phone,other_phone,customer_picture,vehicle_registration_no,vin_no,supervisor_name,supervisor_phone,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $statement->execute([next_project_reference('customer'),(int)$activeSession['bus_loc_id'],$selectedVendorId ?: null,$customerName,$jobTypeName,$jobTypeId?:null,$masterCustomerId,$phone,normalize_phone_number((string)($_POST['other_phone'] ?? '')) ?: null,$customerPicture,$isTaxiRankSession ? (trim((string)($_POST['vehicle_registration_no'] ?? '')) ?: null) : null,$isTaxiRankSession ? (trim((string)($_POST['vin_no'] ?? '')) ?: null) : null,$isTaxiRankSession ? (trim((string)($_POST['supervisor_name'] ?? '')) ?: null) : null,$isTaxiRankSession ? (normalize_phone_number((string)($_POST['supervisor_phone'] ?? '')) ?: null) : null,current_user_id()]);
                $customerId = (int)db()->lastInsertId();
            } elseif ($customerId > 0 && !$movingExistingCustomer) {
                $jobTypeId=max(0,(int)($_POST['job_type_id']??0));$jobTypeName=null;if($jobTypeId){$jobTypeStatement=db()->prepare('SELECT job_type_name FROM job_types WHERE id=? AND is_active=1');$jobTypeStatement->execute([$jobTypeId]);$jobTypeName=$jobTypeStatement->fetchColumn()?:null;}$masterCustomerId=customer_master_id_for_job($jobTypeId,max(0,(int)($_POST['master_customer_id']??0)),$customerId);
                db()->prepare("UPDATE customers SET vendor_id=?,customer_name=?,job_type=?,job_type_id=?,master_customer_id=?,phone=?,other_phone=?,vehicle_registration_no=?,vin_no=?,supervisor_name=?,supervisor_phone=?,record_status='completed',customer_status='registered' WHERE id=?")->execute([$selectedVendorId ?: null,$customerName?:'Incomplete customer',$jobTypeName,$jobTypeId?:null,$masterCustomerId,$phone?:null,normalize_phone_number((string)($_POST['other_phone']??''))?:null,$isTaxiRankSession?(trim((string)($_POST['vehicle_registration_no']??''))?:null):null,$isTaxiRankSession?(trim((string)($_POST['vin_no']??''))?:null):null,$isTaxiRankSession?(trim((string)($_POST['supervisor_name']??''))?:null):null,$isTaxiRankSession?(normalize_phone_number((string)($_POST['supervisor_phone']??''))?:null):null,$customerId]);
            }
            $evidence = null;
            $statement = db()->prepare("INSERT INTO visits(visit_ref,sales_trip_id,place_session_id,bus_loc_id,customer_id,vendor_id,staff_id,recorded_by_user_id,visit_type,visit_date,arrival_time,visit_evidence,record_status) VALUES(?,?,?,?,?,?,?,?,'registration',?,?,?,'completed')");
            $statement->execute([next_project_reference('visit'),$isAddendumSession ? null : (int)$activeTrip['id'],(int)$activeSession['id'],(int)$activeSession['bus_loc_id'],$customerId,$selectedVendorId ?: null,current_staff_id(),current_user_id(),$isAddendumSession ? (string)$activeSession['activity_date'] : date('Y-m-d'),$isAddendumSession ? null : $arrival,$evidence]);
            $visitId = (int)db()->lastInsertId();
            flow_save_sale($visitId,$customerId,(int)$activeSession['bus_loc_id'],$carPicture);
            flow_save_note($visitId,$customerId,$feedback,$notes);
            if ($draftId > 0) {
                db()->prepare('DELETE FROM customer_visit_drafts WHERE id=? AND place_session_id=?')->execute([$draftId,(int)$activeSession['id']]);
            }
            db()->commit();
            header('Location: ' . app_url('normalized-customer.php?stage=activity&customer_saved=1&customer_name='.rawurlencode(flow_customer_name($customerId,$customerName))));
            exit;
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The customer activity could not be saved.';
        }
    } elseif ($action === 'close_session' && $activeSession) {
        $departure = trim((string)($_POST['departure_time'] ?? ''));
        $next = (string)($_POST['next_step'] ?? 'choice');
        $emptySession = $isAddendumSession || (!$activeSession['arrival_time'] && !flow_session_has_records((int)$activeSession['id']));
        if ($emptySession) {
            if ($isAddendumSession) {
                db()->prepare("UPDATE place_visit_sessions SET status='completed',departure_time=NULL WHERE id=? AND status='active'")->execute([(int)$activeSession['id']]);
            } else {
                db()->prepare('DELETE FROM place_visit_sessions WHERE id=? AND status=\'active\'')->execute([(int)$activeSession['id']]);
            }
            $target = $isAddendumSession
                ? 'normalized-customer.php?stage=new-place&addendum_closed=1'
                : ($next === 'end' ? 'sales-trip.php?section=trip&complete=' . (int)$activeTrip['id'] . '&location_left=1' : ($next === 'existing' ? 'normalized-customer.php?stage=existing-place&location_left=1' : 'normalized-customer.php?stage=new-place&location_left=1'));
            header('Location: ' . app_url($target));
            exit;
        } elseif (!flow_time($departure)) {
            $error = 'Enter the shop departure time before leaving this location.';
        } elseif ($activeSession['arrival_time'] && $departure < substr((string)$activeSession['arrival_time'], 0, 5)) {
            $error = 'Departure time cannot be earlier than the arrival time.';
        } else {
            db()->beginTransaction();
            db()->prepare("UPDATE place_visit_sessions SET departure_time=?,status='completed' WHERE id=? AND status='active'")
                ->execute([$departure,(int)$activeSession['id']]);
            db()->prepare('UPDATE visits SET departure_time=? WHERE place_session_id=? AND departure_time IS NULL')
                ->execute([$departure,(int)$activeSession['id']]);
            db()->commit();
            $target = $next === 'end'
                ? 'sales-trip.php?section=trip&complete=' . (int)$activeTrip['id'] . '&location_left=1'
                : ($next === 'existing' ? 'normalized-customer.php?stage=existing-place&location_left=1' : 'normalized-customer.php?location_left=1');
            header('Location: ' . app_url($target));
            exit;
        }
    }
}

$activeSession = $activeTrip ? flow_session((int)$activeTrip['id']) : flow_addendum_session();
$isAddendumSession = $activeSession && (string)($activeSession['session_type'] ?? 'trip') === 'addendum';
$activeDestinationIsTaxi = (string)($activeSession['destination_key'] ?? '') === 'taxi_rank';
$customerOnlyMode = $stage === 'activity';
$emptyActiveSession = $activeSession
    && !$activeSession['arrival_time']
    && !flow_session_has_records((int)$activeSession['id']);
$formData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $prefill;
$prefill = $formData;
$tripBackUrl = $activeTrip
    ? app_url('marketing-trip.php')
    : (current_user_role() === 'vendor' ? app_url('index.php') : app_url('sales-trip.php?section=trip'));

$pageTitle = $locationEntryMenu ? 'Location Registration' : ($customerEntryMenu ? 'Customer' : ($promoEntryMenu ? 'Promo Plug' : 'Customer Visit'));
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Marketing Trip']];
$workflowMenuReturn=(string)($_SESSION['normalized_customer_menu_return']??'');
$internalBackUrl = match (true) {
    $stage === 'activity' => app_url('normalized-customer.php'),
    $locationEntryMenu && (in_array($placeMode,['new','addendum'],true) || $stage === 'existing-place') => app_url('normalized-customer.php?stage=new-place&menu=location'),
    in_array($placeMode,['new','addendum'],true), $stage === 'existing-place' => app_url('normalized-customer.php?stage=new-place'),
    in_array($stage, ['leave','drafts','draft-view'], true) => app_url('normalized-customer.php?stage=activity'),
    default => $workflowMenuReturn!==''?$workflowMenuReturn:app_url('marketing.php?view=trip'),
};
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel customer-place-flow">
    <div class="management-heading customer-visit-heading">
        <div>
            <span class="section-kicker"><?= $activeTrip ? e($activeTrip['trip_code']) : ($isAddendumSession ? 'Addendum · '.e((string)$activeSession['activity_date']) : 'No active trip') ?></span>
            <h1><?= $locationEntryMenu ? 'Location Registration' : ($customerEntryMenu ? 'Customer' : ($promoEntryMenu ? 'Promo Plug' : 'Customer Visit')) ?></h1>
        </div>
        <?php if ($activeTrip && !$activeSession): ?>
            <a class="continue-existing-place" href="<?= e(app_url('normalized-customer.php?stage=existing-place')) ?>">
                <i class="fa-solid fa-location-dot"></i>
                <span>Continue Existing Location</span>
            </a>
        <?php else: ?>
            <div class="management-icon"><i class="fa-solid fa-route"></i></div>
        <?php endif; ?>
    </div>
    <?php if ($error): ?><div class="profile-message is-error"><?= e($error) ?></div><?php endif; ?><?php if((string)($_GET['standalone_sale_saved']??'')==='1'):?><div class="profile-message is-success"><?=$promoEntryMenu?'Promo Plug saved successfully.':'Sales details saved successfully.'?></div><?php endif;?>

    <?php if ($stage === 'new-place' && !$activeSession): ?>
        <?php if ($promoEntryMenu): ?>
        <div class="place-workspace place-workspace--new">
            <form class="record-form" method="post">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="form_action" value="save_standalone_sales">
                <?php $directPromoYes=isset($_POST['promo_enabled']);$directPromoNumber=trim((string)($_POST['promo_plug_number']??'')); ?>
                <input type="hidden" name="promo_plug" value="<?=$directPromoYes?e($directPromoNumber):'No'?>" data-direct-promo-value>
                <div class="sales-customer-picker" data-direct-promo-customer-picker>
                    <input type="hidden" name="sales_customer_id" value="<?=max(0,(int)($_POST['sales_customer_id']??0))?>" data-direct-promo-customer-id>
                    <input type="hidden" name="sales_customer_draft_id">
                    <span class="sales-customer-picker__label">Customer</span>
                    <button class="sales-customer-picker__button" type="button" data-direct-promo-customer-open><span><i class="fa-solid fa-user-check"></i><strong data-direct-promo-customer-name>Select customer</strong></span><i class="fa-solid fa-chevron-right"></i></button>
                    <button class="sales-customer-picker__clear" type="button" data-direct-promo-customer-clear hidden>Clear selection</button>
                </div>
                <label class="standalone-promo-choice">
                    <input type="checkbox" name="promo_enabled" value="1" data-direct-promo-toggle <?=$directPromoYes?'checked':''?>>
                    <span><strong>Promo Plug</strong><small data-direct-promo-state><?=$directPromoYes?'Yes':'No'?></small></span>
                </label>
                <div class="form-field" data-direct-promo-number-wrap <?=$directPromoYes?'':'hidden'?>>
                    <label for="direct_promo_plug_number">Plug Number</label>
                    <input id="direct_promo_plug_number" name="promo_plug_number" value="<?=e($directPromoNumber)?>" data-direct-promo-number <?=$directPromoYes?'required':''?>>
                </div>
                <div class="form-actions"><a class="secondary-button" href="<?=e(app_url('marketing.php?view=trip'))?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>Save Promo Plug</span></button></div>
            </form>
            <dialog class="sales-customer-dialog" data-direct-promo-customer-dialog><div class="sales-customer-dialog__header"><div><span>Customer records</span><h2>Select Customer</h2></div><button type="button" data-direct-promo-customer-close><i class="fa-solid fa-xmark"></i></button></div><label class="sales-customer-dialog__search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search customer, phone, reference, or location" data-direct-promo-customer-search></label><div class="sales-customer-dialog__list"><?php foreach($standaloneSalesCustomers as $salesCustomer):?><button type="button" class="sales-customer-option" data-direct-promo-customer-option data-customer-id="<?=(int)$salesCustomer['id']?>" data-customer-name="<?=e((string)$salesCustomer['customer_name'])?>" data-customer-search="<?=e(strtolower(implode(' ',[(string)$salesCustomer['customer_ref'],(string)$salesCustomer['customer_name'],(string)$salesCustomer['phone'],(string)$salesCustomer['other_phone'],(string)$salesCustomer['business_name']])))?>"><span><strong><?=e((string)$salesCustomer['customer_name'])?></strong><small><?=e((string)$salesCustomer['phone'])?> · <?=e((string)$salesCustomer['business_name'])?></small></span><span class="sales-customer-option__meta"><b><?=e((string)$salesCustomer['customer_ref'])?></b></span></button><?php endforeach;?></div><p class="empty-state" data-direct-promo-customer-empty <?=!$standaloneSalesCustomers?'':'hidden'?>>No completed customer registrations are available.</p></dialog>
            <script>
            (()=>{
                const toggle=document.querySelector('[data-direct-promo-toggle]'),value=document.querySelector('[data-direct-promo-value]'),state=document.querySelector('[data-direct-promo-state]'),numberWrap=document.querySelector('[data-direct-promo-number-wrap]'),numberInput=document.querySelector('[data-direct-promo-number]');
                const syncPromo=()=>{const enabled=!!toggle?.checked;if(state)state.textContent=enabled?'Yes':'No';if(numberWrap)numberWrap.hidden=!enabled;if(numberInput)numberInput.required=enabled;if(value)value.value=enabled?(numberInput?.value||''):'No';};
                toggle?.addEventListener('change',syncPromo);numberInput?.addEventListener('input',syncPromo);syncPromo();
                const dialog=document.querySelector('[data-direct-promo-customer-dialog]'),id=document.querySelector('[data-direct-promo-customer-id]'),name=document.querySelector('[data-direct-promo-customer-name]'),clear=document.querySelector('[data-direct-promo-customer-clear]'),search=document.querySelector('[data-direct-promo-customer-search]'),options=[...document.querySelectorAll('[data-direct-promo-customer-option]')],empty=document.querySelector('[data-direct-promo-customer-empty]');
                document.querySelector('[data-direct-promo-customer-open]')?.addEventListener('click',()=>dialog?.showModal());document.querySelector('[data-direct-promo-customer-close]')?.addEventListener('click',()=>dialog?.close());
                options.forEach(option=>option.addEventListener('click',()=>{if(id)id.value=option.dataset.customerId||'';if(name)name.textContent=option.dataset.customerName||'Selected customer';if(clear)clear.hidden=false;dialog?.close();}));
                clear?.addEventListener('click',()=>{if(id)id.value='';if(name)name.textContent='Select customer';clear.hidden=true;});
                search?.addEventListener('input',()=>{const term=search.value.trim().toLowerCase();let matches=0;options.forEach(option=>{const show=!term||(option.dataset.customerSearch||'').includes(term);option.hidden=!show;if(show)matches++;});if(empty)empty.hidden=matches>0;});
                const selected=options.find(option=>option.dataset.customerId===id?.value);if(selected&&name){name.textContent=selected.dataset.customerName||'Selected customer';if(clear)clear.hidden=false;}
            })();
            </script>
        </div>
        <?php elseif ($customerEntryMenu): ?>
        <div class="place-workspace place-workspace--new">
            <div class="active-trip-flow-actions active-trip-flow-actions--location-menu">
                <a class="active-trip-flow-action" href="<?=e(app_url('customers.php?return_to='.rawurlencode(app_url('normalized-customer.php?stage=new-place&menu=customer'))))?>"><span class="active-trip-flow-action__icon"><i class="fa-solid fa-users-gear"></i></span><span><strong>View / Edit Customers</strong></span><i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </div>
        <div class="form-actions"><a class="secondary-button" href="<?=e(app_url('marketing.php?view=trip'))?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a></div>
        <?php elseif ($placeMode === ''): ?>
        <div class="place-workspace place-workspace--new">
            <?php if(!$locationOnlyMenu): ?><details class="registration-accordion place-registration-toggle" name="new-registration-sections">
                <summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-shop"></i></span><span class="registration-accordion__title"><strong>Location Registration</strong><small>Register a new location or select an already-added location.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary>
                <div class="registration-accordion__body">
            <?php endif; ?>
                    <div class="active-trip-flow-actions active-trip-flow-actions--location-menu">
                        <?php if($activeTrip): ?>
                        <a class="active-trip-flow-action is-new" href="<?= e(app_url('normalized-customer.php?stage=new-place&place_mode=new&menu=location')) ?>"><span class="active-trip-flow-action__icon"><i class="fa-solid fa-plus"></i></span><span><strong>New Location</strong></span><i class="fa-solid fa-arrow-right"></i></a>
                        <?php else: ?>
                        <a class="active-trip-flow-action is-new" href="<?= e(app_url('normalized-customer.php?stage=new-place&place_mode=addendum')) ?>"><span class="active-trip-flow-action__icon"><i class="fa-solid fa-file-circle-plus"></i></span><span><strong>Addendum</strong></span><i class="fa-solid fa-arrow-right"></i></a>
                        <a class="active-trip-flow-action" href="<?=e(app_url('normalized-customer.php?stage=existing-place&existing_mode=addendum'))?>"><span class="active-trip-flow-action__icon"><i class="fa-solid fa-user-plus"></i></span><span><strong>Add Customer to Old Location</strong></span><i class="fa-solid fa-arrow-right"></i></a>
                        <?php endif; ?>
                        <a class="active-trip-flow-action" href="<?= e($activeTrip?app_url('normalized-customer.php?stage=existing-place&menu=location'):app_url('registration-records.php?tab=places')) ?>">
                            <span class="active-trip-flow-action__icon"><i class="fa-solid fa-pen-to-square"></i></span>
                            <span><strong>View / Edit Location</strong></span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                        <?php if($activeTrip): ?>
                        <a class="active-trip-flow-action" href="<?=e(app_url('normalized-customer.php?stage=existing-place&menu=location'))?>">
                            <span class="active-trip-flow-action__icon"><i class="fa-solid fa-user-plus"></i></span>
                            <span><strong>Add Customer to Old Location</strong></span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
            <?php if(!$locationOnlyMenu): ?></div></details><?php endif; ?>
            <?php if(!$locationOnlyMenu): ?>
            <details class="registration-accordion place-registration-toggle" name="new-registration-sections"><summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-user"></i></span><span class="registration-accordion__title"><strong>Customer</strong><small>View and edit saved customer registrations.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary><div class="registration-accordion__body"><div class="active-trip-flow-actions active-trip-flow-actions--single"><a class="active-trip-flow-action" href="<?=e(app_url('customers.php?return_to='.rawurlencode(app_url('normalized-customer.php?stage=new-place&menu=customer'))))?>"><span class="active-trip-flow-action__icon"><i class="fa-solid fa-users-gear"></i></span><span><strong>View / Edit Customers</strong></span><i class="fa-solid fa-arrow-right"></i></a></div></div></details>
            <details class="registration-accordion place-registration-toggle" name="new-registration-sections"><summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-bullhorn"></i></span><span class="registration-accordion__title"><strong>Promo Plug</strong><small>Record a business promotional plug.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary><div class="registration-accordion__body">
                <div class="active-trip-flow-actions active-trip-flow-actions--customer" data-sales-mode-menu>
                </div>                <form class="record-form" method="post"><input type="hidden" name="sales_mode" value="promo"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="save_standalone_sales">
                    <div class="sales-customer-picker" data-sales-customer-picker><input type="hidden" name="sales_customer_id" data-sales-customer-id><input type="hidden" name="sales_customer_draft_id" data-sales-customer-draft-id><span class="sales-customer-picker__label">Customer</span><button class="sales-customer-picker__button" type="button" data-open-sales-customer><span><i class="fa-solid fa-user-check"></i><strong data-sales-customer-name>Select customer</strong></span><i class="fa-solid fa-chevron-right"></i></button><button class="sales-customer-picker__clear" type="button" data-clear-sales-customer hidden>Clear selection</button></div>
                    <div class="form-field"><label>Promotional Plug</label><input name="promo_plug" required></div><div class="form-actions"><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>Save Promo Plug</span></button></div>
                </form>
                <dialog class="sales-customer-dialog" data-sales-customer-dialog><div class="sales-customer-dialog__header"><div><span>Customer records</span><h2>Select Customer</h2></div><button type="button" data-close-sales-customer><i class="fa-solid fa-xmark"></i></button></div><label class="sales-customer-dialog__search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search customer, phone, reference, or location" data-sales-customer-search></label><div class="sales-customer-dialog__list" data-sales-customer-list><?php foreach($standaloneSalesCustomers as $salesCustomer):?><button type="button" class="sales-customer-option" data-sales-customer-option data-customer-id="<?=(int)$salesCustomer['id']?>" data-customer-type="completed" data-customer-name="<?=e((string)$salesCustomer['customer_name'])?>" data-customer-search="<?=e(strtolower(implode(' ',[(string)$salesCustomer['customer_ref'],(string)$salesCustomer['customer_name'],(string)$salesCustomer['phone'],(string)$salesCustomer['other_phone'],(string)$salesCustomer['business_name']])))?>"><span><strong><?=e((string)$salesCustomer['customer_name'])?></strong><small><?=e((string)$salesCustomer['phone'])?> · <?=e((string)$salesCustomer['business_name'])?></small></span><span class="sales-customer-option__meta"><b><?=e((string)$salesCustomer['customer_ref'])?></b></span></button><?php endforeach;?></div><p class="empty-state" data-sales-customer-empty <?=!$standaloneSalesCustomers?'':'hidden'?>>No completed customer registrations are available.</p></dialog>
            </div></details>
            <?php endif; ?>
        </div>
        <div class="form-actions"><a class="secondary-button" href="<?= e(app_url('marketing.php?view=trip')) ?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a></div>
        <?php else: ?>
        <form class="record-form mobile-line-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_intent" value="create_place"><input type="hidden" name="session_mode" value="<?=$isAddendumMode?'addendum':'trip'?>">
            <div class="place-workspace place-workspace--new">
                <details class="registration-accordion place-registration-toggle" name="new-registration-sections" open>
                    <summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-shop"></i></span><span class="registration-accordion__title"><strong>Location Registration</strong><small>Complete the permanent location and media details.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary>
                    <div class="registration-accordion__body">
                        <div class="form-grid">
                            <?php if($isAddendumMode):?><div class="form-field"><label>Addendum Date</label><input name="activity_date" type="date" value="<?=e((string)($formData['activity_date']??date('Y-m-d')))?>" required></div><?php else:?><div class="form-field"><label>Shop Arrival Time</label><div class="field-control-row"><input id="new_place_arrival_time" name="arrival_time" value="<?= e((string)($formData['arrival_time'] ?? '')) ?>" data-time-picker readonly required><button class="secondary-button secondary-button--small" type="button" data-current-time-target="new_place_arrival_time">Now</button></div></div><?php endif;?>
                            <div class="form-field"><label>Workshop/Business Name</label><input name="business_name" value="<?= e((string)($formData['business_name'] ?? '')) ?>" required></div>
                            <div class="form-field"><label>Destination Type</label><select name="destination_id" data-destination-shop-type-toggle required><option value="">Select destination</option><?php foreach ($destinations as $item): ?><option value="<?= (int)$item['id'] ?>" data-destination-key="<?=e((string)$item['destination_key'])?>" <?= (int)($formData['destination_id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= e($item['destination_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="form-field" data-shop-type-field><label>Shop Type</label><select name="shop_type_id"><option value="">Select shop type</option><?php foreach ($shopTypes as $item): ?><option value="<?= (int)$item['id'] ?>" <?= (int)($formData['shop_type_id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= e($item['shop_type_name']) ?></option><?php endforeach; ?></select></div>
                            <?php $selectedLocationId=(int)($formData['location_id']??0);$selectedLocationRegion='';foreach($locations as $item){if((int)$item['id']===$selectedLocationId){$selectedLocationRegion=(string)($item['region_code']?:$item['region_name']);break;}} ?>
                            <div class="form-field"><label>Region</label><select data-location-region-select required><option value="">Select region</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e((string)$regionKey)?>" <?=$selectedLocationRegion===(string)$regionKey?'selected':''?>><?=e($regionName)?></option><?php endforeach;?></select></div>
                            <div class="form-field"><label>Town</label><select name="location_id" data-location-town-select required><option value="">Select town</option><?php foreach ($locations as $item): ?><option value="<?= (int)$item['id'] ?>" data-region-key="<?=e((string)($item['region_code']?:$item['region_name']))?>" data-mmda-name="<?=e((string)$item['mmda_name'])?>" <?= $selectedLocationId === (int)$item['id'] ? 'selected' : '' ?>><?= e($item['town_name']) ?><?= (int)$item['is_capital']===1?' *':'' ?></option><?php endforeach; ?></select><small data-location-mmda-output></small></div>
                            <div class="form-field"><label>Area</label><input name="area" value="<?= e((string)($formData['area'] ?? '')) ?>" required></div>
                            <div class="form-field"><label>Google Location<?php if($isAddendumMode):?> <span class="muted-text">(optional)</span><?php endif;?></label><div class="field-control-row"><input id="google_location" name="google_location" type="url" value="<?= e((string)($formData['google_location'] ?? '')) ?>" <?=$isAddendumMode?'':'required'?>><button class="secondary-button secondary-button--small" type="button" data-current-location-target="google_location">Use GPS</button></div></div>
                            <div class="form-field"><label>Shop/Station Picture <span class="muted-text">(optional)</span></label><input name="shop_picture" type="file" accept="image/*" data-photo-source-choice></div>
                            <div class="form-field"><label>Additional Location Picture <span class="muted-text">(optional)</span></label><input name="shop_picture_2" type="file" accept="image/*" data-photo-source-choice></div>
                            <div class="form-field"><label>Shop Video <span class="muted-text">(when required)</span></label><input name="shop_video" type="file" accept="video/*"></div>
                        </div>
                        <div class="accordion-inline-actions"><button class="secondary-button" type="submit" name="form_action" value="save_place_only" formnovalidate><i class="fa-solid fa-floppy-disk"></i><span>Save Location</span></button></div>
                    </div>
                </details>
                <?php if(!$locationEntryMenu): ?>
                <details class="registration-accordion place-registration-toggle is-locked" name="new-registration-sections" data-locked-accordion>
                    <summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-user-plus"></i></span><span class="registration-accordion__title"><strong>Customer</strong><small>Register the first customer and their activity at this location.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary>
                    <div class="registration-accordion__body">
                        <div class="form-grid">
                            <div class="form-field"><label>Customer/Owner/Driver Name</label><input name="customer_name" value="<?= e((string)($formData['customer_name'] ?? '')) ?>" required></div>
                            <div class="form-field"><label>Phone</label><input name="phone" type="tel" data-phone-input data-customer-phone-check="<?=e(app_url('customer-phone-check.php'))?>" data-exclude-draft-id="0" value="<?= e((string)($formData['phone'] ?? '')) ?>" required><div class="customer-phone-match" data-customer-phone-match hidden></div></div>
                            <div class="form-field"><label>Other Phone</label><input name="other_phone" type="tel" data-phone-input value="<?= e((string)($formData['other_phone'] ?? '')) ?>"></div>
                            <div class="form-field"><label>Job Type</label><select name="job_type_id" data-popup-select data-apprentice-job-select><option value="">Select job type</option><?php foreach($jobTypes as $jobType):?><option value="<?=(int)$jobType['id']?>" data-is-apprentice="<?=strcasecmp((string)$jobType['job_type_name'],'Apprentice')===0?'1':'0'?>" <?=((int)($formData['job_type_id']??0)===(int)$jobType['id'] || (string)($formData['job_type']??'')===(string)$jobType['job_type_name'])?'selected':''?>><?=e((string)$jobType['job_type_name'])?></option><?php endforeach;?></select></div><div class="form-field" data-apprentice-master-field hidden><label>Master</label><select name="master_customer_id" data-popup-select data-popup-search disabled><option value="">Select master</option><?php foreach($masterCustomers as $master):?><option value="<?=(int)$master['id']?>"><?=e(implode(' · ',array_filter([(string)$master['customer_name'],(string)$master['customer_ref'],(string)$master['phone']])))?></option><?php endforeach;?></select></div>
                            <div class="form-field"><label>Customer Picture <span class="muted-text">(optional)</span></label><input name="customer_picture" type="file" accept="image/*" data-photo-source-choice></div>
                            <div class="form-field" data-taxi-customer-field><label>Car Registration Number</label><input name="vehicle_registration_no" value="<?= e((string)($formData['vehicle_registration_no'] ?? '')) ?>" data-registration-number-check="<?=e(app_url('registration-number-check.php'))?>" data-exclude-draft-id="0"><small class="phone-duplicate-message registration-number-message" data-registration-number-match hidden></small></div>
                            <div class="form-field" data-taxi-customer-field><label>VIN</label><input name="vin_no" value="<?= e((string)($formData['vin_no'] ?? '')) ?>"></div>
                            <div class="form-field" data-taxi-customer-field><label>Supervisor Name</label><input name="supervisor_name" value="<?= e((string)($formData['supervisor_name'] ?? '')) ?>"></div>
                            <div class="form-field" data-taxi-customer-field><label>Supervisor Phone</label><input name="supervisor_phone" type="tel" data-phone-input value="<?= e((string)($formData['supervisor_phone'] ?? '')) ?>"></div>
                            <div class="form-field"><label>Feedback</label><select name="feedback" required><option value="">Select feedback</option><?php foreach ($feedbackOptions as $item): ?><option value="<?= e($item['feedback_label']) ?>" <?= ($formData['feedback'] ?? '') === $item['feedback_label'] ? 'selected' : '' ?>><?= e($item['feedback_label']) ?></option><?php endforeach; ?></select></div>
                            <div class="form-field form-field--wide"><label>Notes</label><textarea name="notes" rows="3" required><?= e((string)($formData['notes'] ?? '')) ?></textarea></div>
                        </div>
                    </div>
                </details>
                <details class="registration-accordion place-registration-toggle is-locked" name="new-registration-sections" data-locked-accordion>
                    <summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-bullhorn"></i></span><span class="registration-accordion__title"><strong>Promo Plug</strong><small>Record a business promotional plug.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary>
                    <div class="registration-accordion__body">
                        <div class="form-grid">
                            <div class="form-field"><label>Promotional Plug</label><input name="promo_plug" value="<?= e((string)($formData['promo_plug'] ?? '')) ?>"></div>
                            <div class="form-field" data-taxi-customer-field><label>Car Picture</label><input name="car_picture" type="file" accept="image/*" data-photo-source-choice></div>
                        </div>
                    </div>
                </details>
                <?php endif; ?>
            </div>
            <div class="form-actions"><a class="secondary-button" href="<?= e($locationEntryMenu?app_url('normalized-customer.php?stage=new-place&menu=location'):($isAddendumMode?app_url('normalized-customer.php?stage=new-place'):$tripBackUrl)) ?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a></div>
        </form>
        <?php endif; ?>

    <?php elseif ($stage === 'existing-place' && !$activeSession): ?>
        <form class="record-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="select_place"><?php if($isExistingAddendumMode):?><input type="hidden" name="session_mode" value="addendum"><input type="hidden" name="existing_mode" value="addendum"><?php endif;?>
            <?php if($isExistingAddendumMode):?><div class="form-grid"><div class="form-field"><label>Addendum Date</label><input name="activity_date" type="date" value="<?=e((string)($_POST['activity_date']??date('Y-m-d')))?>" required></div></div><?php endif;?>
            <div class="place-workspace"><div class="form-grid"><div class="form-field form-field--wide"><label>Find a location</label><div class="place-search-control"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Browse or start typing..." autocomplete="off" data-place-search></div></div><div class="date-range-row form-field--wide"><div class="form-field"><label>Date from</label><input type="date" data-place-date-from></div><div class="form-field"><label>Date to</label><input type="date" data-place-date-to></div></div><div class="form-field form-field--wide"><label>Search Results <span class="muted-text" data-place-result-count></span></label><select class="place-result-source" name="bus_loc_id" data-place-select aria-hidden="true" tabindex="-1"><option value="">Select a matching location</option><?php foreach ($places as $place): ?><option value="<?= (int)$place['id'] ?>" data-created-date="<?=e(substr((string)$place['created_at'],0,10))?>" data-search="<?= e(strtolower(implode(' ', [$place['business_display_name'],(string)($place['town_name'] ?? ''),(string)($place['area'] ?? ''),$place['google_location']]))) ?>" <?= (int)($formData['bus_loc_id'] ?? 0) === (int)$place['id'] ? 'selected' : '' ?>><?= e($place['bus_loc_ref'] . ' · ' . $place['business_display_name'] . ' · ' . ($place['town_name'] ?? '') . ' · ' . ($place['area'] ?? '')) ?></option><?php endforeach; ?></select><div class="place-live-results" data-place-live-results></div></div></div>
            </div>
            <div class="form-actions"><a class="secondary-button" href="<?= e(app_url('normalized-customer.php?stage=new-place'.($entryMenu==='location'?'&menu=location':''))) ?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><span class="muted-text">Select a location to open its details and continue.</span></div>
        </form>

    <?php elseif ($stage === 'leave' && $activeSession): ?>
        <?php $next = in_array((string)($_GET['next'] ?? ''), ['choice','new','existing','end'], true) ? (string)$_GET['next'] : 'choice'; ?>
<div class="registration-flow-banner"><div class="registration-flow-banner__copy"><span class="section-kicker"><?=$isAddendumSession?'Closing Addendum':'Leaving'?> <?= e($activeSession['business_name']) ?></span><h2><?=$isAddendumSession?'Close this Addendum?':($emptyActiveSession?'Leave this empty location visit?':'Record the shop departure time')?></h2><p><?=$isAddendumSession?'The Addendum date and customer records will remain saved. No departure time is required.':($emptyActiveSession?'No arrival or customer activity was recorded, so a departure time is not required.':'Departure is required before moving to another location or ending the trip.')?></p></div></div>
<form class="record-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="close_session"><input type="hidden" name="next_step" value="<?= e($next) ?>"><?php if (!$emptyActiveSession && !$isAddendumSession): ?><div class="form-grid"><div class="form-field"><label>Arrival Time</label><input value="<?= e(substr((string)$activeSession['arrival_time'], 0, 5)) ?>" readonly></div><div class="form-field"><label>Shop Departure Time</label><div class="field-control-row"><input id="departure_time" name="departure_time" value="<?= e((string)($formData['departure_time'] ?? '')) ?>" data-time-picker readonly required><button class="secondary-button secondary-button--small" type="button" data-current-time-target="departure_time">Now</button></div></div></div><?php endif; ?><div class="form-actions"><a class="secondary-button" href="<?= e(app_url('normalized-customer.php?stage=activity')) ?>"><i class="fa-solid fa-arrow-left"></i><span>Stay at This Location</span></a><button class="login-button" type="submit"><?= $isAddendumSession ? 'Close Addendum' : ($emptyActiveSession ? 'Leave Location' : 'Save Departure & Continue') ?></button></div></form>

    <?php elseif ($stage === 'draft-view' && $activeSession && $draftRow): ?>
        <?php
        $draftActivityKind = ($prefill['activity_kind'] ?? 'new_customer') === 'existing_customer'
            ? 'Existing Customer / Follow-up'
            : 'Register New Customer';
        $draftCustomerName = trim((string)($prefill['customer_name'] ?? ''));
        if ($draftActivityKind === 'Existing Customer / Follow-up') {
            foreach ($placeCustomers as $placeCustomer) {
                if ((int)$placeCustomer['id'] === (int)($prefill['customer_id'] ?? 0)) {
                    $draftCustomerName = trim((string)$placeCustomer['customer_name']);
                    break;
                }
            }
        }
        $draftSections = [
            'Visit' => [
                'Location' => $activeSession['business_name'] ?? '',
                'Arrival Time' => $prefill['arrival_time'] ?? ($activeSession['arrival_time'] ?? ''),
                'Customer Activity' => $draftActivityKind,
            ],
            'Customer' => [
                'Customer Name' => $draftCustomerName,
                'Phone' => $prefill['phone'] ?? '',
                'Other Phone' => $prefill['other_phone'] ?? '',
                'Job Type' => $jobTypeNamesById[(int)($prefill['job_type_id'] ?? 0)] ?? ($prefill['job_type'] ?? ''),
                'Vehicle Registration' => $prefill['vehicle_registration_no'] ?? '',
                'VIN' => $prefill['vin_no'] ?? '',
                'Supervisor Name' => $prefill['supervisor_name'] ?? '',
                'Supervisor Phone' => $prefill['supervisor_phone'] ?? '',
            ],
            'Sales' => [
                'Promotional Plug' => $prefill['promo_plug'] ?? '',
            ],
            'Note' => [
                'Feedback' => $prefill['feedback'] ?? '',
                'Notes' => $prefill['notes'] ?? '',
            ],
        ];
        if (!$activeDestinationIsTaxi) {
            unset($draftSections['Customer']['Vehicle Registration'],$draftSections['Customer']['VIN'],$draftSections['Customer']['Supervisor Name'],$draftSections['Customer']['Supervisor Phone']);
        }
        ?>
        <div class="current-place-strip"><div><span class="section-kicker">Draft · <?= e((string)$draftRow['draft_ref']) ?></span><h2>Draft Customer Visit</h2><p><i class="fa-solid fa-location-dot"></i> <?= e($activeSession['business_name']) ?> · <?= e(trim(($activeSession['town_name'] ?? '') . ' / ' . $activeSession['area'], ' /')) ?></p></div><span class="status-badge is-warning">Incomplete</span></div>
        <div class="detail-grid detail-grid--plain">
            <?php foreach ($draftSections as $sectionTitle => $sectionFields): ?>
                <dl>
                    <dt><?= e($sectionTitle) ?></dt>
                    <dd>
                        <?php foreach ($sectionFields as $label => $value): ?>
                            <div class="draft-detail-row"><span><?= e($label) ?></span><strong class="<?= trim((string)$value) === '' ? 'muted-text' : '' ?>"><?= trim((string)$value) !== '' ? nl2br(e((string)$value)) : 'Not provided' ?></strong></div>
                        <?php endforeach; ?>
                    </dd>
                </dl>
            <?php endforeach; ?>
        </div>
        <div class="form-actions"><a class="secondary-button" href="<?= e(app_url('normalized-customer.php?stage=activity')) ?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><form method="post" action="<?=e(app_url('normalized-customer.php?stage=activity'))?>" data-confirm-title="Delete draft" data-confirm-message="Delete this draft permanently?"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="delete_draft"><input type="hidden" name="draft_id" value="<?=$draftId?>"><button class="danger-button" type="submit"><i class="fa-solid fa-trash"></i><span>Delete Draft</span></button></form><a class="login-button" href="<?= e(app_url('normalized-customer.php?stage=activity&draft_id=' . $draftId)) ?>"><i class="fa-solid fa-pen"></i><span>Continue Editing</span></a></div>

    <?php elseif ($activeSession && ($stage === 'activity' || $stage === 'drafts' || $outcome !== '')): ?>
        <?php if(!$customerOnlyMode):?><div class="current-place-strip current-place-strip--compact"><div><span class="section-kicker"><?=$isAddendumSession?'Addendum Location':'Current Location'?></span><h2><?= e($activeSession['business_name']) ?></h2></div><div class="current-place-strip__actions"><strong><?=$isAddendumSession?'Date '.e((string)$activeSession['activity_date']):($activeSession['arrival_time'] ? 'Arrived ' . e(substr((string)$activeSession['arrival_time'], 0, 5)) : 'Arrival pending')?></strong><a class="secondary-button secondary-button--small" href="<?=e(app_url('place-details.php?id='.(int)$activeSession['bus_loc_id'].'&edit=1&return_to='.rawurlencode(app_url('normalized-customer.php?stage=activity'))))?>"><i class="fa-solid fa-pen"></i><span>Edit</span></a></div></div><?php endif;?>
        <?php if ($customerSaved): ?><div class="profile-message is-success"><?=e($savedCustomerName)?> saved successfully.</div><?php endif; ?>
        <?php if ($locationReused): ?><div class="profile-message is-success">An existing matching business location was reused, so its location reference was kept.</div><?php endif; ?>
        <?php if ($draftSaved): ?><div class="profile-message is-success"><?=e($savedCustomerName)?> saved as draft.</div><?php endif; ?>
        <?php if ($draftDeleted): ?><div class="profile-message is-success">Draft deleted successfully.</div><?php endif; ?>
        <nav class="customer-number-chain" aria-label="Customers in this location visit">
            <?php foreach ($sessionCustomerChain as $chainIndex => $chainEntry): $chainNumber = $chainIndex + 1; ?>
                <a class="customer-number-chain__item <?= $chainEntry['type'] === 'draft' ? 'is-draft' : 'is-complete' ?> <?= $chainEntry['type'] === 'draft' && $chainEntry['id'] === $draftId ? 'is-current' : '' ?>" href="<?= e($chainEntry['type'] === 'draft' ? app_url('normalized-customer.php?stage=draft-view&draft_id=' . $chainEntry['id']) : app_url('normalized-visit-details.php?id=' . $chainEntry['id'].'&return_to='.rawurlencode(app_url('normalized-customer.php?stage=activity')))) ?>" title="<?= e($chainEntry['title']) ?>" aria-label="Open customer <?= $chainNumber ?>"><?= $chainNumber ?></a>
            <?php endforeach; ?>
            <?php if ($draftId === 0): ?><span class="customer-number-chain__item is-current" title="New customer" aria-label="Current new customer"><?= count($sessionCustomerChain) + 1 ?></span><?php endif; ?>
        </nav>
        <?php if (!$isAddendumSession && !$activeSession['arrival_time']): ?>
            <form class="record-form visit-arrival-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="form_action" value="set_arrival">
                <input type="hidden" name="draft_id" value="<?= $draftId ?>">
                <div class="visit-arrival-field">
                    <div><strong>Shop Arrival Time</strong><small>Set the arrival time once for this location visit.</small></div>
                    <div class="field-control-row"><input id="arrival_time" name="arrival_time" value="<?= e((string)($prefill['arrival_time'] ?? '')) ?>" data-time-picker readonly required><button class="login-button secondary-button--small" type="button" data-current-time-target="arrival_time" data-submit-current-time>Now</button></div>
                </div>
            </form>
        <?php endif; ?>
        <form class="record-form mobile-line-form activity-action-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('normalized-customer.php?stage=activity'.($customerOnlyMode?'&customer_only=1':''))) ?>" data-customer-activity-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="save_activity"><input type="hidden" name="draft_id" value="<?= $draftId ?>"><input type="hidden" name="move_existing_customer_id" value="" data-move-existing-customer-id>
            <?php if ($activeSession['arrival_time']): ?>
                <input type="hidden" name="arrival_time" value="<?= e(substr((string)$activeSession['arrival_time'], 0, 5)) ?>">
            <?php endif; ?>
            <details class="registration-accordion" name="active-trip-sections"<?= ($draftId > 0||$customerOnlyMode) ? ' open' : '' ?>><summary class="registration-accordion__summary"<?=$customerOnlyMode?' hidden':''?>><span class="registration-accordion__number"><i class="fa-solid fa-user"></i></span><span class="registration-accordion__title"><strong>Customer</strong><small>Register a new customer or view and edit existing customer records.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary><div class="registration-accordion__body">
                <?php if(!$customerOnlyMode):?><div class="active-trip-flow-actions active-trip-flow-actions--customer active-trip-flow-actions--single">
                    <a class="active-trip-flow-action" href="<?= e(app_url('customers.php?return_to='.rawurlencode(app_url('normalized-customer.php?stage=new-place&menu=customer')))) ?>"><span class="active-trip-flow-action__icon"><i class="fa-solid fa-users-gear"></i></span><span><strong>View / Edit Customers</strong></span><i class="fa-solid fa-arrow-right"></i></a>
                </div><?php endif;?>
                <div id="new-customer-form">
                <input type="hidden" name="activity_kind" value="new_customer">
                <?php if($isAddendumSession): ?>
                <div class="sales-customer-picker addendum-vendor-picker" data-addendum-vendor-picker>
                    <input type="hidden" name="vendor_id" value="<?=max(0,(int)($prefill['vendor_id']??$currentVendorId))?>" data-addendum-vendor-id>
                    <span class="sales-customer-picker__label">Vendor</span>
                    <?php if($currentVendorId > 0): $ownVendorName='Assigned vendor'; foreach($addendumVendors as $vendorOption){if((int)$vendorOption['id']===$currentVendorId){$ownVendorName=(string)$vendorOption['vendor_name'];break;}} ?>
                    <div class="sales-customer-picker__button"><span><i class="fa-solid fa-store"></i><strong><?=e($ownVendorName)?></strong></span><i class="fa-solid fa-circle-check"></i></div>
                    <?php else: ?>
                    <button class="sales-customer-picker__button" type="button" data-open-addendum-vendor><span><i class="fa-solid fa-store"></i><strong data-addendum-vendor-name>Select vendor</strong></span><i class="fa-solid fa-chevron-right"></i></button>
                    <button class="sales-customer-picker__clear" type="button" data-clear-addendum-vendor hidden>Clear selection</button>
                    <?php endif; ?>
                </div>
                <?php if($currentVendorId <= 0): ?>
                <dialog class="sales-customer-dialog" data-addendum-vendor-dialog><div class="sales-customer-dialog__header"><div><span>Active vendors</span><h2>Select Vendor</h2></div><button type="button" data-close-addendum-vendor aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><label class="sales-customer-dialog__search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search vendor name or phone" data-addendum-vendor-search></label><div class="sales-customer-dialog__list"><?php foreach($addendumVendors as $vendorOption): ?><button type="button" class="sales-customer-option" data-addendum-vendor-option data-vendor-id="<?=(int)$vendorOption['id']?>" data-vendor-name="<?=e((string)$vendorOption['vendor_name'])?>" data-vendor-search="<?=e(strtolower(implode(' ',[(string)$vendorOption['vendor_name'],(string)$vendorOption['phone']])))?>"><span><strong><?=e((string)$vendorOption['vendor_name'])?></strong><small><?=e((string)$vendorOption['phone'])?></small></span></button><?php endforeach; ?></div><p class="empty-state" data-addendum-vendor-empty <?=!$addendumVendors?'':'hidden'?>>No active vendor matches your search.</p></dialog>
                <?php endif; ?>
                <?php endif; ?>
                <div data-new-customer-fields><div class="form-grid"><div class="form-field"><label>Customer/Owner/Driver Name</label><input name="customer_name" value="<?= e((string)($prefill['customer_name'] ?? '')) ?>"></div><div class="form-field"><label>Phone</label><input name="phone" type="tel" data-phone-input data-customer-phone-check="<?=e(app_url('customer-phone-check.php'))?>" data-exclude-draft-id="<?=$draftId?>" value="<?= e((string)($prefill['phone'] ?? '')) ?>"><div class="customer-phone-match" data-customer-phone-match hidden></div></div><div class="form-field"><label>Other Phone</label><input name="other_phone" type="tel" data-phone-input value="<?= e((string)($prefill['other_phone'] ?? '')) ?>"></div><div class="form-field"><label>Job Type</label><select name="job_type_id" data-popup-select><option value="">Select job type</option><?php foreach($jobTypes as $jobType):?><option value="<?=(int)$jobType['id']?>" <?=((int)($prefill['job_type_id']??0)===(int)$jobType['id'] || (string)($prefill['job_type']??'')===(string)$jobType['job_type_name'])?'selected':''?>><?=e((string)$jobType['job_type_name'])?></option><?php endforeach;?></select></div><div class="form-field"><label>Customer Picture <span class="muted-text">(optional)</span></label><input name="customer_picture" type="file" accept="image/*" data-photo-source-choice></div><?php if($activeDestinationIsTaxi):?><div class="form-field"><label>Car Registration Number</label><input name="vehicle_registration_no" value="<?= e((string)($prefill['vehicle_registration_no'] ?? '')) ?>" data-registration-number-check="<?=e(app_url('registration-number-check.php'))?>" data-exclude-draft-id="<?=$draftId?>"><small class="phone-duplicate-message registration-number-message" data-registration-number-match hidden></small></div><div class="form-field"><label>VIN</label><input name="vin_no" value="<?= e((string)($prefill['vin_no'] ?? '')) ?>"></div><div class="form-field"><label>Supervisor Name</label><input name="supervisor_name" value="<?= e((string)($prefill['supervisor_name'] ?? '')) ?>"></div><div class="form-field"><label>Supervisor Phone</label><input name="supervisor_phone" value="<?= e((string)($prefill['supervisor_phone'] ?? '')) ?>"></div><?php endif;?></div></div>
                <div class="form-grid"><div class="form-field"><label>Feedback</label><select name="feedback"><option value="">Select feedback</option><?php foreach ($feedbackOptions as $item): ?><option value="<?= e($item['feedback_label']) ?>" <?= ($prefill['feedback'] ?? '') === $item['feedback_label'] ? 'selected' : '' ?>><?= e($item['feedback_label']) ?></option><?php endforeach; ?></select></div><div class="form-field form-field--wide"><label>Notes</label><textarea name="notes" rows="3"><?= e((string)($prefill['notes'] ?? '')) ?></textarea></div></div>
                </div>
            </div></details>

            <?php if(!$customerOnlyMode):?><details class="registration-accordion" name="active-trip-sections"><summary class="registration-accordion__summary"><span class="registration-accordion__number"><i class="fa-solid fa-bullhorn"></i></span><span class="registration-accordion__title"><strong>Promo Plug</strong><small>Record a business promotional plug.</small></span><i class="fa-solid fa-chevron-down registration-accordion__icon"></i></summary><div class="registration-accordion__body">
                <input type="hidden" name="sales_mode" value="promo">
                <div class="sales-customer-picker" data-sales-customer-picker hidden>
                    <input type="hidden" name="sales_customer_id" value="<?=max(0,(int)($prefill['sales_customer_id']??0))?>" data-sales-customer-id>
                    <input type="hidden" name="sales_customer_draft_id" value="<?=max(0,(int)($prefill['sales_customer_draft_id']??0))?>" data-sales-customer-draft-id>
                    <span class="sales-customer-picker__label">Customer</span>
                    <button class="sales-customer-picker__button" type="button" data-open-sales-customer>
                        <span><i class="fa-solid fa-user-check"></i><strong data-sales-customer-name><?=max(0,(int)($prefill['sales_customer_id']??0))>0?'Selected customer':'Select recent customer'?></strong></span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                    <button class="sales-customer-picker__clear" type="button" data-clear-sales-customer hidden>Clear selection</button>
                </div>
                <div class="form-grid"><div class="form-field"><label>Promotional Plug</label><input name="promo_plug" value="<?= e((string)($prefill['promo_plug'] ?? '')) ?>"></div><?php if($activeDestinationIsTaxi):?><div class="form-field" data-new-customer-fields><label>Car Picture</label><input name="car_picture" type="file" accept="image/*" data-photo-source-choice></div><?php endif;?></div></div></details><?php endif;?>
            <div class="form-actions"><?php if(!$customerOnlyMode):?><a class="secondary-button" href="<?= e(app_url('normalized-customer.php?stage=new-place')) ?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><?php endif;?><a class="secondary-button leave-place-button" href="<?= e(app_url('normalized-customer.php?stage=leave&next='.($isAddendumSession?'end':'choice'))) ?>"><i class="fa-solid fa-arrow-right-from-bracket"></i><span><?=$isAddendumSession?'Close Addendum':'Leave Location'?></span></a><?php if(!$isAddendumSession&&!$customerOnlyMode):?><a class="danger-button" href="<?= e(app_url('normalized-customer.php?stage=leave&next=end')) ?>"><i class="fa-solid fa-flag-checkered"></i><span>End Trip</span></a><?php endif;?><button class="login-button" type="submit"><span>Save / Next</span><i class="fa-solid fa-arrow-right"></i></button></div>
        </form>
        <dialog class="sales-customer-dialog" data-sales-customer-dialog>
            <div class="sales-customer-dialog__header"><div><span>Recent registrations</span><h2>Select Customer</h2></div><button type="button" data-close-sales-customer aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
            <label class="sales-customer-dialog__search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search name, phone, or reference" data-sales-customer-search></label>
            <div class="sales-customer-dialog__list" data-sales-customer-list>
                <?php foreach($recentPlaceCustomers as $recentCustomer): ?>
                    <button type="button" class="sales-customer-option" data-sales-customer-option data-customer-id="<?=(int)$recentCustomer['id']?>" data-customer-type="<?=!empty($recentCustomer['is_draft'])?'draft':'completed'?>" data-customer-name="<?=e((string)$recentCustomer['customer_name'])?>" data-customer-search="<?=e(strtolower(implode(' ',[(string)$recentCustomer['customer_ref'],(string)$recentCustomer['customer_name'],(string)$recentCustomer['phone'],(string)$recentCustomer['other_phone'],!empty($recentCustomer['is_draft'])?'draft':'completed'])))?>">
                        <span><strong><?=e((string)$recentCustomer['customer_name'])?></strong><small><?=e(trim((string)$recentCustomer['phone']))?><?=trim((string)$recentCustomer['other_phone'])!==''?' · '.e((string)$recentCustomer['other_phone']):''?></small></span>
                        <span class="sales-customer-option__meta"><b><?=e((string)$recentCustomer['customer_ref'])?></b><?php if(!empty($recentCustomer['is_draft'])):?><em>Draft</em><?php endif;?><small><?=!empty($recentCustomer['latest_registration_at'])?e(date('d M Y',strtotime((string)$recentCustomer['latest_registration_at']))):''?></small></span>
                    </button>
                <?php endforeach; ?>
                <?php if(!$recentPlaceCustomers): ?><p class="empty-state">No customer registrations are available at this location.</p><?php endif; ?>
            </div>
            <p class="empty-state" data-sales-customer-empty hidden>No customer matches your search.</p>
        </dialog>

    <?php elseif ($activeSession): ?>
        <div class="flow-top-actions"><a class="flow-back-link" href="<?=e($tripBackUrl)?>"><i class="fa-solid fa-arrow-left"></i><span><?=current_user_role()==='vendor'?'Back to Menu':'Back to Trip'?></span></a></div>
        <div class="current-place-strip"><div><span class="section-kicker">Active Location Visit</span><h2><?= e($activeSession['business_name']) ?></h2><p><?= e(trim(($activeSession['town_name'] ?? '') . ' / ' . $activeSession['area'], ' /')) ?></p></div><span class="status-badge is-success"><?= $activeSession['arrival_time'] ? 'Arrived ' . e(substr((string)$activeSession['arrival_time'], 0, 5)) : 'Arrival pending' ?></span></div>
        <div class="report-destination-grid"><a class="report-destination-card" href="<?= e(app_url('normalized-customer.php?stage=activity&customer_only=1')) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-user-plus"></i></span><strong>Add Customer at This Location</strong><span>Reuse location and arrival details</span></a><a class="report-destination-card" href="<?= e(app_url('normalized-customer.php?stage=leave&next=existing')) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-clock-rotate-left"></i></span><strong>Add Customer to Old Location</strong><span><?= $emptyActiveSession ? 'Choose a saved location' : 'Record departure, then choose a saved location' ?></span></a><a class="report-destination-card" href="<?= e(app_url('normalized-customer.php?stage=leave&next=choice')) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-arrow-right-from-bracket"></i></span><strong>Leave This Location</strong><span><?= $emptyActiveSession ? 'No departure required' : 'Record departure before moving' ?></span></a><a class="report-destination-card" href="<?= e(app_url('normalized-customer.php?stage=leave&next=end')) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-flag-checkered"></i></span><strong>End Trip</strong><span><?= $emptyActiveSession ? 'Leave this location and complete the journey' : 'Record departure and complete the journey' ?></span></a></div>

    <?php else: ?>
        <div class="flow-top-actions"><a class="flow-back-link" href="<?=e($tripBackUrl)?>"><i class="fa-solid fa-arrow-left"></i><span><?=current_user_role()==='vendor'?'Back to Menu':'Back to Trip'?></span></a></div>
        <div class="place-entry-menu">
            <a class="place-entry-card place-entry-card--primary" href="<?= e(app_url('normalized-customer.php?stage=new-place')) ?>"><span class="place-entry-card__icon"><i class="fa-solid fa-shop"></i></span><span><strong>Register New Location</strong><small>Create the workshop, location, and reusable shop media.</small></span><i class="fa-solid fa-arrow-right"></i></a>
            <?php foreach ($places as $place): ?>
                <a class="place-entry-card" href="<?= e(app_url('place-details.php?id=' . (int)$place['id'])) ?>">
                    <span class="place-entry-card__icon"><i class="fa-solid fa-location-dot"></i></span>
                    <span><strong><?= e($place['business_display_name']) ?></strong><small><?= e($place['bus_loc_ref'] . ' · ' . (trim(($place['town_name'] ?? '') . ' / ' . ($place['area'] ?? ''), ' /') ?: 'Details pending')) ?></small></span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (!$places): ?><p class="empty-state">No saved locations are available yet.</p><?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($outcome && $activeSession): ?>
<dialog class="flow-decision-dialog" data-flow-dialog>
    <div class="flow-decision-dialog__icon"><i class="fa-solid <?= $outcome === 'draft' ? 'fa-file-pen' : 'fa-circle-check' ?>"></i></div>
    <h2><?= $outcome === 'draft' ? 'Saved as Draft' : 'Customer Activity Saved' ?></h2>
    <p><?= $outcome === 'draft' ? 'The entry is incomplete, so it was safely saved as a draft. What would you like to do next?' : 'What would you like to do next at this point in the trip?' ?></p>
    <div class="flow-decision-actions">
        <?php if ($outcome === 'draft' && $draftId): ?><a class="secondary-button" href="<?= e(app_url('normalized-customer.php?stage=activity&draft_id=' . $draftId)) ?>"><i class="fa-solid fa-pen"></i> Continue Draft</a><?php endif; ?>
        <a class="login-button" href="<?= e(app_url('normalized-customer.php?stage=activity&customer_only=1')) ?>"><i class="fa-solid fa-user-plus"></i> Another Customer Here</a>
        <a class="secondary-button" href="<?= e(app_url('normalized-customer.php?stage=leave&next=choice')) ?>"><i class="fa-solid fa-arrow-right-from-bracket"></i> End Visit & Choose Location</a>
        <a class="danger-button" href="<?= e(app_url('normalized-customer.php?stage=leave&next=end')) ?>"><i class="fa-solid fa-flag-checkered"></i> End Trip</a>
    </div>
</dialog>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-locked-accordion]').forEach(accordion => {
        accordion.open = false;
        accordion.querySelector('summary')?.addEventListener('click', event => event.preventDefault());
        accordion.addEventListener('toggle', () => {
            if (accordion.open) accordion.open = false;
        });
    });

    const placeSearch = document.querySelector('[data-place-search]');
    const placeSelect = document.querySelector('[data-place-select]');
    const placeResults = document.querySelector('[data-place-live-results]');
    const placeResultCount = document.querySelector('[data-place-result-count]');
    const placeDateFrom = document.querySelector('[data-place-date-from]');
    const placeDateTo = document.querySelector('[data-place-date-to]');
    const renderPlaceResults = () => {
        if (!placeSearch || !placeSelect || !placeResults) return;
        const query = placeSearch.value.trim().toLowerCase();
        const options = Array.from(placeSelect.options).slice(1);
        const dateFrom = placeDateFrom?.value || '';
        const dateTo = placeDateTo?.value || '';
        const allMatches = options.filter(option => {
            const createdDate = option.dataset.createdDate || '';
            return (query === '' || (option.dataset.search || '').includes(query))
                && (dateFrom === '' || (createdDate !== '' && createdDate >= dateFrom))
                && (dateTo === '' || (createdDate !== '' && createdDate <= dateTo));
        });
        const matches = allMatches.slice(0, 50);
        if (placeResultCount) {
            placeResultCount.textContent = '(' + allMatches.length + ' found)';
        }
        placeResults.replaceChildren();
        if (matches.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'is-empty';
            empty.textContent = 'No matching location found. Try another name, town, or area.';
            placeResults.append(empty);
            return;
        }
        matches.forEach(option => {
            const result = document.createElement('div');
            result.className = 'place-live-result';
            result.dataset.placeResultValue = option.value;
            const selectPlace = document.createElement('a');
            selectPlace.className = 'place-live-result__select';
            selectPlace.href = <?= json_encode(app_url('place-details.php?id='), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + encodeURIComponent(option.value);
            const icon = document.createElement('span');
            icon.className = 'place-live-result__icon';
            icon.innerHTML = '<i class="fa-solid fa-location-dot"></i>';
            const text = document.createElement('span');
            text.textContent = option.textContent.trim();
            const selectedIcon = document.createElement('i');
            selectedIcon.className = 'fa-solid fa-chevron-right';
            selectPlace.append(icon, text, selectedIcon);
            result.append(selectPlace);
            placeResults.append(result);
        });
    };
    placeSearch?.addEventListener('input', renderPlaceResults);
    placeDateFrom?.addEventListener('change', renderPlaceResults);
    placeDateTo?.addEventListener('change', renderPlaceResults);
    renderPlaceResults();

    document.querySelectorAll('[data-sale-purchase-editor]').forEach(editor => {
        const rows = editor.querySelector('[data-sale-purchase-rows]');
        const template = editor.querySelector('[data-sale-purchase-template]');
        const updateRemoveButtons = () => {
            const purchaseRows = editor.querySelectorAll('[data-sale-purchase-row]');
            purchaseRows.forEach(row => {
                const removeButton = row.querySelector('[data-remove-sale-purchase]');
                if (removeButton) removeButton.hidden = false;
            });
        };
        editor.addEventListener('input', event => {
            if (event.target.matches('[data-sale-vin-input]')) {
                event.target.value = event.target.value.toUpperCase().replace(/[^A-HJ-NPR-Z0-9]/g, '').slice(0, 17);
            }
        });
        editor.addEventListener('click', event => {
            const addButton = event.target.closest('[data-add-sale-purchase]');
            if (addButton && rows && template) {
                rows.append(template.content.cloneNode(true));
                updateRemoveButtons();
                rows.lastElementChild?.querySelector('[data-sale-vin-input]')?.focus();
                return;
            }
            const removeButton = event.target.closest('[data-remove-sale-purchase]');
            if (removeButton) {
                const row = removeButton.closest('[data-sale-purchase-row]');
                if (editor.querySelectorAll('[data-sale-purchase-row]').length === 1) {
                    row?.querySelectorAll('input').forEach(input => input.value = '');
                } else {
                    row?.remove();
                }
                updateRemoveButtons();
            }
        });
        updateRemoveButtons();
    });

    const activityKind = document.querySelector('[data-activity-kind]');
    const updateActivity = () => {
        const existing = activityKind?.value === 'existing_customer';
        document.querySelectorAll('[data-new-customer-fields]').forEach(node => {
            node.hidden = existing;
            node.querySelectorAll('input,select,textarea').forEach(field => field.disabled = existing);
        });
        document.querySelectorAll('[data-existing-customer-fields]').forEach(node => {
            node.hidden = !existing;
            node.querySelectorAll('input,select,textarea').forEach(field => field.disabled = !existing);
        });
    };
    activityKind?.addEventListener('change', updateActivity);
    updateActivity();

    const addendumVendorDialog = document.querySelector('[data-addendum-vendor-dialog]');
    const addendumVendorId = document.querySelector('[data-addendum-vendor-id]');
    const addendumVendorName = document.querySelector('[data-addendum-vendor-name]');
    const clearAddendumVendor = document.querySelector('[data-clear-addendum-vendor]');
    const selectAddendumVendor = (id, name) => { if(addendumVendorId)addendumVendorId.value=id||''; if(addendumVendorName)addendumVendorName.textContent=name||'Select vendor'; if(clearAddendumVendor)clearAddendumVendor.hidden=!id; addendumVendorDialog?.close(); };
    document.querySelector('[data-open-addendum-vendor]')?.addEventListener('click',()=>addendumVendorDialog?.showModal());
    document.querySelector('[data-close-addendum-vendor]')?.addEventListener('click',()=>addendumVendorDialog?.close());
    clearAddendumVendor?.addEventListener('click',()=>selectAddendumVendor('',''));
    document.querySelectorAll('[data-addendum-vendor-option]').forEach(option=>{ option.addEventListener('click',()=>selectAddendumVendor(option.dataset.vendorId,option.dataset.vendorName)); if(addendumVendorId?.value===option.dataset.vendorId)selectAddendumVendor(option.dataset.vendorId,option.dataset.vendorName); });
    const addendumVendorSearch=document.querySelector('[data-addendum-vendor-search]');
    addendumVendorSearch?.addEventListener('input',()=>{const query=addendumVendorSearch.value.trim().toLowerCase();let matches=0;document.querySelectorAll('[data-addendum-vendor-option]').forEach(option=>{const visible=query===''||(option.dataset.vendorSearch||'').includes(query);option.hidden=!visible;if(visible)matches++;});const empty=document.querySelector('[data-addendum-vendor-empty]');if(empty)empty.hidden=matches>0;});
    const salesCustomerPicker = document.querySelector('[data-sales-customer-picker]');
    const salesCustomerDialog = document.querySelector('[data-sales-customer-dialog]');
    const salesCustomerId = document.querySelector('[data-sales-customer-id]');
    const salesCustomerDraftId = document.querySelector('[data-sales-customer-draft-id]');
    const salesCustomerName = document.querySelector('[data-sales-customer-name]');
    const clearSalesCustomer = document.querySelector('[data-clear-sales-customer]');
    const customerFormSection = document.querySelector('#new-customer-form');
    const customerSectionHasData = () => {
        if (activityKind?.value === 'existing_customer') {
            return !!customerFormSection?.querySelector('[data-existing-customer-fields] select')?.value;
        }
        return Array.from(customerFormSection?.querySelectorAll('[data-new-customer-fields] input, textarea[name="notes"], select[name="feedback"]') || [])
            .some(field => field.type === 'file' ? field.files?.length : String(field.value || '').trim() !== '');
    };
    const updateSalesCustomerPicker = () => {
        if (!salesCustomerPicker) return;
        const salesModeScope = salesCustomerPicker.closest('[data-sales-mode-scope]');
        salesCustomerPicker.hidden = !salesModeScope?.dataset.selectedSalesMode || customerSectionHasData();
    };
    const selectSalesCustomer = (id, name, type = 'completed') => {
        if (salesCustomerId) salesCustomerId.value = type === 'completed' ? (id || '') : '';
        if (salesCustomerDraftId) salesCustomerDraftId.value = type === 'draft' ? (id || '') : '';
        if (salesCustomerName) salesCustomerName.textContent = name || 'Select recent customer';
        if (clearSalesCustomer) clearSalesCustomer.hidden = !id;
        salesCustomerDialog?.close();
    };
    document.querySelector('[data-open-sales-customer]')?.addEventListener('click', () => {
        if (salesCustomerDialog?.showModal) salesCustomerDialog.showModal();
    });
    document.querySelector('[data-close-sales-customer]')?.addEventListener('click', () => salesCustomerDialog?.close());
    clearSalesCustomer?.addEventListener('click', () => selectSalesCustomer('', '', 'completed'));
    document.querySelectorAll('[data-sales-customer-option]').forEach(option => {
        option.addEventListener('click', () => selectSalesCustomer(option.dataset.customerId, option.dataset.customerName, option.dataset.customerType));
        const selected = option.dataset.customerType === 'draft'
            ? salesCustomerDraftId?.value === option.dataset.customerId
            : salesCustomerId?.value === option.dataset.customerId;
        if (selected) {
            selectSalesCustomer(option.dataset.customerId, option.dataset.customerName, option.dataset.customerType);
        }
    });
    const salesCustomerSearch = document.querySelector('[data-sales-customer-search]');
    salesCustomerSearch?.addEventListener('input', () => {
        const query = salesCustomerSearch.value.trim().toLowerCase();
        let matches = 0;
        document.querySelectorAll('[data-sales-customer-option]').forEach(option => {
            const visible = query === '' || (option.dataset.customerSearch || '').includes(query);
            option.hidden = !visible;
            if (visible) matches++;
        });
        const empty = document.querySelector('[data-sales-customer-empty]');
        if (empty) empty.hidden = matches > 0;
    });
    customerFormSection?.addEventListener('input', updateSalesCustomerPicker);
    customerFormSection?.addEventListener('change', updateSalesCustomerPicker);
    activityKind?.addEventListener('change', updateSalesCustomerPicker);
    updateSalesCustomerPicker();

    document.querySelectorAll('[data-sales-mode-scope]').forEach(scope => {
        const picker = scope.querySelector('[data-sales-customer-picker]');
        const salesEditor = scope.querySelector('[data-sale-purchase-editor]');
        const salesRefField = scope.querySelector('[name="sales_ref"]')?.closest('.form-field');
        const promoField = scope.querySelector('[name="promo_plug"]')?.closest('.form-field');
        const promoInput = promoField?.querySelector('[name="promo_plug"]');
        const promoToggle = promoField?.querySelector('[data-business-promo-toggle]');
        const promoState = promoField?.querySelector('[data-business-promo-state]');
        const promoInputWrap = promoField?.querySelector('[data-business-promo-input]');
        const syncPromoChoice = () => { const enabled=!!promoToggle?.checked; if(promoState)promoState.textContent=enabled?'Yes':'No'; if(promoInputWrap)promoInputWrap.hidden=!enabled; if(promoInput)promoInput.disabled=!enabled; if(!enabled&&promoInput){promoInput.value='';promoInput.dispatchEvent(new Event('input',{bubbles:true}));} };
        promoToggle?.addEventListener('change',syncPromoChoice);
        const confirmedField = scope.querySelector('[name="sale_confirmed"]')?.closest('.checkbox-row');
        const modeInput = scope.querySelector('[data-sales-mode-input]');
        const saveButton = scope.querySelector('button[type="submit"] span');
        const setBlock = (block, visible) => { if(!block)return; block.hidden=!visible; block.querySelectorAll('input,select,textarea,button').forEach(field=>field.disabled=!visible); };
        const selectMode = mode => {
            scope.dataset.selectedSalesMode=mode;
            if(modeInput)modeInput.value=mode;
            scope.querySelectorAll('[data-sales-mode]').forEach(button=>button.classList.toggle('is-selected',button.dataset.salesMode===mode));
            if(picker)picker.hidden=false;
            setBlock(salesEditor,mode==='sales'); setBlock(salesRefField,mode==='sales'); setBlock(confirmedField,mode==='sales'); setBlock(promoField,mode==='promo'); if(mode==='promo')syncPromoChoice();
            if(saveButton)saveButton.textContent=mode==='promo'?'Save Business Promo':'Save Sales';
        };
        scope.querySelectorAll('[data-sales-mode]').forEach(button=>button.addEventListener('click',()=>selectMode(button.dataset.salesMode)));
        setBlock(salesEditor,false); setBlock(salesRefField,false); setBlock(confirmedField,false); setBlock(promoField,false); if(picker)picker.hidden=true;
    });

    const registeredPhones = <?= json_encode($registeredPhones, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const phoneInput = document.querySelector('[data-customer-phone-check]');
    const phoneMatch = document.querySelector('[data-customer-phone-match]');
    const customerActivityForm = document.querySelector('[data-customer-activity-form]');
    const moveExistingCustomerId = document.querySelector('[data-move-existing-customer-id]');
    const phoneDigits = value => (value || '').replace(/\D/g, '');
    const phoneComparable = value => phoneDigits(value).slice(-9);
    let promptedPhoneMatch = '';
    const promptCustomerMove = (match, force = false) => {
        const matchKey = String(match.id) + ':' + phoneComparable(phoneInput?.value);
        if ((!force && promptedPhoneMatch === matchKey) || moveExistingCustomerId?.value === String(match.id)) return;
        promptedPhoneMatch = matchKey;
        showConfirmDialog({
            title: 'Customer already registered',
            message: 'This phone number belongs to ' + match.customer_name + ' at ' + match.business_name + '. Would you like to move this customer to ' + <?= json_encode((string)($activeSession['business_name'] ?? 'the active location'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + '?',
            confirmLabel: 'Move Customer',
            onConfirm: () => {
                moveExistingCustomerId.value = String(match.id);
                phoneInput.setCustomValidity('');
                checkRegisteredPhone(false);
            }
        });
    };
    const checkRegisteredPhone = (allowPrompt = false) => {
        if (!phoneInput || !phoneMatch) return;
        const entered = phoneDigits(phoneInput.value);
        const comparable = phoneComparable(entered);
        const match = entered.length >= 9
            ? registeredPhones.find(customer => phoneComparable(customer.phone) === comparable || phoneComparable(customer.other_phone) === comparable)
            : null;
        if (moveExistingCustomerId?.value && (!match || moveExistingCustomerId.value !== String(match.id))) {
            moveExistingCustomerId.value = '';
        }
        phoneMatch.hidden = !match;
        phoneInput.classList.toggle('has-registered-match', !!match);
        phoneInput.classList.toggle('is-duplicate', !!match);
        const samePlace = match && Number(match.bus_loc_id) === <?= (int)($activeSession['bus_loc_id'] ?? 0) ?>;
        const approvedMove = match && moveExistingCustomerId?.value === String(match.id);
        phoneInput.setCustomValidity(match && (match.is_draft || samePlace)
            ? (match.is_draft ? 'This phone number is already saved in a draft.' : 'This phone number is already registered at this location.')
            : '');
        if (!match) {
            phoneMatch.textContent = '';
            return;
        }
        phoneMatch.replaceChildren();
        const warningIcon = document.createElement('i');
        warningIcon.className = 'fa-solid fa-circle-exclamation';
        const warningCopy = document.createElement('span');
        const warningTitle = document.createElement('strong');
        warningTitle.textContent = match.is_draft ? 'Number already saved as draft' : 'Number already registered';
        const warningDetail = document.createElement('small');
        warningDetail.textContent = (match.is_draft ? 'Saved for ' : 'Registered to ') + match.customer_name + ' (' + match.customer_ref + ') at ' + match.business_name + '.';
        warningCopy.append(warningTitle, warningDetail);
        if (match.is_draft) {
            const continueDraft = document.createElement('button');
            continueDraft.type = 'button';
            continueDraft.textContent = 'Continue Draft';
            continueDraft.addEventListener('click', () => {
                window.location.href = <?= json_encode(app_url('normalized-customer.php?stage=activity&draft_id='), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + encodeURIComponent(match.id);
            });
            warningCopy.append(continueDraft);
        } else if (samePlace) {
            const followUpHint = document.createElement('small');
            followUpHint.textContent = 'Use the Follow-up menu on the dashboard for this customer.';
            warningCopy.append(followUpHint);
        } else {
            const differentPlace = document.createElement('small');
            differentPlace.textContent = approvedMove ? 'Customer approved to move to this active location.' : 'You can move this customer to the active location when you save.';
            warningCopy.append(differentPlace);
        }
        phoneMatch.append(warningIcon, warningCopy);
        if (allowPrompt && !match.is_draft && !samePlace && !approvedMove) promptCustomerMove(match);
    };
    phoneInput?.addEventListener('input', () => checkRegisteredPhone(true));
    phoneInput?.addEventListener('blur', () => checkRegisteredPhone(true));
    checkRegisteredPhone(false);

    customerActivityForm?.addEventListener('submit', event => {
        const entered = phoneDigits(phoneInput?.value);
        const comparable = phoneComparable(entered);
        const match = entered.length >= 9
            ? registeredPhones.find(customer => !customer.is_draft && (phoneComparable(customer.phone) === comparable || phoneComparable(customer.other_phone) === comparable))
            : null;
        if (!match || Number(match.bus_loc_id) === <?= (int)($activeSession['bus_loc_id'] ?? 0) ?>
            || moveExistingCustomerId?.value === String(match.id)) return;
        event.preventDefault();
        promptCustomerMove(match, true);
    });

    document.querySelectorAll('details.registration-accordion[name]').forEach(section => {
        section.addEventListener('toggle', () => {
            if (!section.open) return;
            const sectionName = section.getAttribute('name');
            const group = Array.from(document.querySelectorAll(`details.registration-accordion[name="${sectionName}"]`));
            group.forEach(other => {
                if (other !== section) other.open = false;
            });
        });
    });
    document.querySelectorAll('.registration-accordion :is(input, select, textarea)').forEach(field => {
        field.addEventListener('invalid', () => {
            const section = field.closest('details.registration-accordion');
            if (section) section.open = true;
        });
    });

    document.querySelectorAll('select[name="job_type_id"]').forEach(function(select){
        const form=select.closest('form');let field=form?.querySelector('[data-apprentice-master-field]');
        if(!field){field=document.createElement('div');field.className='form-field';field.dataset.apprenticeMasterField='';field.hidden=true;const label=document.createElement('label');label.textContent='Master';const masterSelect=document.createElement('select');masterSelect.name='master_customer_id';masterSelect.disabled=true;masterSelect.setAttribute('data-popup-select','');masterSelect.setAttribute('data-popup-search','');masterSelect.innerHTML='<option value="">Select master</option>';const masters=<?=json_encode(array_map(static fn(array $master):array=>['id'=>(int)$master['id'],'label'=>implode(' · ',array_filter([(string)$master['customer_name'],(string)$master['customer_ref'],(string)$master['phone']]))],$masterCustomers),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;masters.forEach(function(item){const option=document.createElement('option');option.value=item.id;option.textContent=item.label;masterSelect.appendChild(option);});field.append(label,masterSelect);select.closest('.form-field')?.after(field);if(typeof createLookupButton==='function'){createLookupButton(masterSelect,{buttonClass:'form-lookup-button',emptyText:'Select master'});masterSelect.addEventListener('change',function(){updateLookupButton(masterSelect);});}}
        const master=field?.querySelector('select');
        const sync=function(){const option=select.selectedOptions[0];const apprentice=option?.dataset.isApprentice==='1'||option?.textContent.trim().toLowerCase()==='apprentice';if(field)field.hidden=!apprentice;if(master){master.disabled=!apprentice;master.required=apprentice;}if(typeof updateLookupButton==='function'&&master)updateLookupButton(master);};
        select.addEventListener('change',sync);sync();
    });

    const dialog = document.querySelector('[data-flow-dialog]');
    if (dialog?.showModal) dialog.showModal();
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php';
