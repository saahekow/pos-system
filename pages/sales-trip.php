<?php
require_once __DIR__ . '/../config/app.php';
unset($_SESSION['normalized_customer_workflow']);
unset($_SESSION['normalized_customer_menu_return']);

require_module_access('sales_trip');
ensure_destination_visit_schema();

const TRIP_KILOMETER_PHOTO_MAX_BYTES = 25 * 1024 * 1024;
const TRIP_KILOMETER_PHOTO_MAX_LABEL = '25MB';

$requestedSection = (string)($_GET['section'] ?? '');
$pageTitle = $requestedSection === 'trip' ? 'Trip Registration' : 'Marketing Trip';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => $pageTitle],
];
$internalBackUrl=$requestedSection==='trip'?app_url('marketing.php?view=trip'):app_url('marketing.php?view=trip');

function active_sales_trip_for_user(?int $userId, ?int $staffId, ?int $vendorId): ?array
{
    ensure_sales_trip_assignment_schema();
    $activeStatement = db()->prepare(
        'SELECT sales_trips.id, sales_trips.trip_code, sales_trips.trip_date, sales_trips.journey_start_time,
                sales_trips.journey_start_kilometers, sales_trips.journey_start_kilometer_photo, sales_trips.vendor_id, sales_trips.recorded_by_user_id, vendors.vendor_name AS vendor_name, staff.full_name AS main_staff_name,
                companion.full_name AS companion_staff_name,
                vehicles.plate_number AS vehicle_plate_number
         FROM sales_trips
         LEFT JOIN staff ON staff.id = sales_trips.staff_id
         LEFT JOIN staff AS companion ON companion.id = sales_trips.companion_staff_id
         LEFT JOIN vehicles ON vehicles.id = sales_trips.vehicle_id
         LEFT JOIN vendors ON vendors.id = sales_trips.vendor_id
         WHERE sales_trips.status = ? AND (
                sales_trips.recorded_by_user_id = ?
                OR EXISTS (SELECT 1 FROM sales_trip_staff_assignments stsa WHERE stsa.sales_trip_id=sales_trips.id AND stsa.staff_id=?)
                OR EXISTS (SELECT 1 FROM sales_trip_vendor_assignments stva WHERE stva.sales_trip_id=sales_trips.id AND stva.vendor_id=?)
                OR (sales_trips.staff_id=? AND sales_trips.recorded_by_user_id IS NULL)
              )
         ORDER BY sales_trips.id DESC
         LIMIT 1'
    );
    $activeStatement->execute(['in_progress', $userId, $staffId ?: 0, $vendorId ?: 0, $staffId ?: 0]);

    return $activeStatement->fetch() ?: null;
}

function save_visit_upload(string $fieldName, array $allowedTypes, int $maxBytes): ?string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $upload = $_FILES[$fieldName];

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The selected file could not be uploaded.');
    }

    if (($upload['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('One of the uploaded files is larger than allowed.');
    }

    $tmpName = (string) ($upload['tmp_name'] ?? '');
    $mimeType = '';

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMimeType = (string) $fileInfo->file($tmpName);

    if (str_starts_with($detectedMimeType, 'image/')) {
        $imageInfo = @getimagesize($tmpName);
        $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : $detectedMimeType;
    } else {
        $mimeType = $detectedMimeType;
    }

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Upload only the supported file types.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/visits';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $fileName = $fieldName . '-' . bin2hex(random_bytes(10)) . '.' . $allowedTypes[$mimeType];
    $targetPath = $uploadDir . '/' . $fileName;

    if (str_starts_with($mimeType, 'video/')) {
        $fileName = $fieldName . '-' . bin2hex(random_bytes(10)) . '.mp4';
        $targetPath = $uploadDir . '/' . $fileName;
        if (compress_uploaded_video($tmpName, $targetPath)) {
            return 'assets/uploads/visits/' . $fileName;
        }
        $fileName = $fieldName . '-' . bin2hex(random_bytes(10)) . '.' . $allowedTypes[$mimeType];
        $targetPath = $uploadDir . '/' . $fileName;
    }

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('The uploaded file could not be saved.');
    }

    return 'assets/uploads/visits/' . $fileName;
}

function compress_visit_image_upload(string $sourcePath, string $mimeType, string $targetPath): bool
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return false;
    }

    $sourceImage = false;
    if ($mimeType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $sourceImage = @imagecreatefromjpeg($sourcePath);
    } elseif ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
        $sourceImage = @imagecreatefrompng($sourcePath);
    } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $sourceImage = @imagecreatefromwebp($sourcePath);
    }

    if (!$sourceImage) {
        return false;
    }

    $sourceImage = orient_uploaded_jpeg($sourceImage, $sourcePath, $mimeType);

    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);

    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($sourceImage);
        return false;
    }

    $maxDimension = 1600;
    $scale = min(1, $maxDimension / max($sourceWidth, $sourceHeight));
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

    if (!$targetImage) {
        imagedestroy($sourceImage);
        return false;
    }

    $white = imagecolorallocate($targetImage, 255, 255, 255);
    imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $white);

    $resampled = imagecopyresampled(
        $targetImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );

    $saved = $resampled && imagejpeg($targetImage, $targetPath, 78);

    imagedestroy($targetImage);
    imagedestroy($sourceImage);

    return $saved;
}

function save_visit_image_upload(string $fieldName, array $allowedTypes, int $maxBytes): ?string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $upload = $_FILES[$fieldName];

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The selected image could not be uploaded.');
    }

    if (($upload['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('One of the uploaded images is larger than ' . APP_IMAGE_UPLOAD_MAX_LABEL . '.');
    }

    $tmpName = (string) ($upload['tmp_name'] ?? '');
    $originalName = strtolower((string) ($upload['name'] ?? ''));
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMimeType = (string) $fileInfo->file($tmpName);
    $imageInfo = @getimagesize($tmpName);
    $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : $detectedMimeType;

    if (!isset($allowedTypes[$mimeType])) {
        $extensionMimeTypes = [
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        ];
        $mimeType = $extensionMimeTypes[$extension] ?? $mimeType;
    }

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Upload only the supported image formats.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/visits';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $compressibleTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $canCompress = in_array($mimeType, $compressibleTypes, true);
    $fileExtension = $canCompress ? 'jpg' : $allowedTypes[$mimeType];
    $fileName = $fieldName . '-' . bin2hex(random_bytes(10)) . '.' . $fileExtension;
    $targetPath = $uploadDir . '/' . $fileName;

    if ($canCompress && compress_visit_image_upload($tmpName, $mimeType, $targetPath)) {
        return 'assets/uploads/visits/' . $fileName;
    }

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }

    return 'assets/uploads/visits/' . $fileName;
}

function save_trip_kilometer_photo(string $fieldName): ?string
{
    $allowedTypes=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic','image/heif'=>'heif'];
    if(!isset($_FILES[$fieldName])||!is_array($_FILES[$fieldName])||($_FILES[$fieldName]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){
        return null;
    }
    $upload=$_FILES[$fieldName];
    $uploadError=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
    if($uploadError!==UPLOAD_ERR_OK){
        $uploadMessages=[
            UPLOAD_ERR_INI_SIZE=>'The kilometer picture is larger than the server upload limit.',
            UPLOAD_ERR_FORM_SIZE=>'The kilometer picture is larger than allowed.',
            UPLOAD_ERR_PARTIAL=>'The kilometer picture upload did not finish. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR=>'The server upload temp folder is missing.',
            UPLOAD_ERR_CANT_WRITE=>'The server could not write the uploaded picture.',
            UPLOAD_ERR_EXTENSION=>'The server stopped the picture upload.',
        ];
        throw new RuntimeException($uploadMessages[$uploadError]??'The kilometer picture could not be uploaded.');
    }
    if((int)($upload['size']??0)>TRIP_KILOMETER_PHOTO_MAX_BYTES)throw new RuntimeException('The kilometer picture must not exceed '.TRIP_KILOMETER_PHOTO_MAX_LABEL.'.');
    $tmp=(string)($upload['tmp_name']??'');$extension=strtolower(pathinfo((string)($upload['name']??''),PATHINFO_EXTENSION));
    if($tmp===''||!is_uploaded_file($tmp)||!is_file($tmp))throw new RuntimeException('The uploaded kilometer picture is no longer available. Please try again.');
    $mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$imageInfo=@getimagesize($tmp);if(is_array($imageInfo))$mime=(string)($imageInfo['mime']??$mime);
    if(!isset($allowedTypes[$mime]))$mime=['heic'=>'image/heic','heif'=>'image/heif'][$extension]??$mime;
    if(!isset($allowedTypes[$mime]))throw new RuntimeException('Use a JPEG, PNG, WebP, HEIC, or HEIF picture for the kilometer reading.');
    $dir=__DIR__.'/../assets/uploads/trip-kilometers';
    if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('The kilometer picture folder could not be created.');
    if(!is_writable($dir))throw new RuntimeException('The kilometer picture folder is not writable on this server.');
    $fileName=$fieldName.'-'.bin2hex(random_bytes(10)).'.'.$allowedTypes[$mime];
    $target=$dir.'/'.$fileName;
    if(!move_uploaded_file($tmp,$target)){
        error_log('SPW Sales kilometer picture save failed: target='.$target.' writable='.(is_writable($dir)?'yes':'no').' upload_error='.$uploadError.' tmp='.$tmp);
        throw new RuntimeException('The kilometer picture could not be saved. Please contact the administrator to check the upload folder permissions.');
    }
    return 'assets/uploads/trip-kilometers/'.$fileName;
}

function trip_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($unit) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
}

function default_sales_visit_form(): array
{
    return [
        'destination_id' => '',
        'vendor_id' => '',
        'place_group_key' => '',
        'visit_type' => 'registration',
        'sales_ref' => '',
        'promo_plug' => '',
        'parent_visit_id' => '',
        'shop_arrival_time' => '',
        'shop_departure_time' => '',
        'company_name' => '',
        'owner_name' => '',
        'phone' => '',
        'other_phone' => '',
        'google_location' => '',
        'location_id' => '',
        'area' => '',
        'shop_type_id' => '',
        'driver_name' => '',
        'car_registration_no' => '',
        'supervisor_name' => '',
        'supervisor_phone' => '',
        'vin_no' => '',
        'free_plug' => '',
        'feedback_option_id' => '',
        'note' => '',
    ];
}

$message = '';
$error = '';
$visitFormSaved = false;
$tripCompleted = false;
$placeChoiceVisitId = 0;
$currentUserId = current_user_id();
$staffStatement = db()->prepare('SELECT id FROM staff WHERE user_id = ? LIMIT 1');
$staffStatement->execute([$currentUserId]);
$currentStaffId = (int) ($staffStatement->fetchColumn() ?: 0);
$currentVendor=current_vendor_profile();
$currentVendorId=(int)($currentVendor['id']??0);
$canStartTrip=is_admin_user()||$currentStaffId>0;
$action = (string) ($_GET['action'] ?? '');
$section=(string)($_GET['section']??'');
$section=in_array($section,['visits','trip','customer'],true)?$section:'';
if($action==='start')$section='trip';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($section, ['', 'visits'], true)) {
    header('Location: ' . app_url('index.php'));
    exit;
}
$completeTripId = max(0, (int) ($_GET['complete'] ?? 0));
$startForm = [
    'staff_ids' => [],
    'vehicle_id' => '',
    'vendor_ids' => [],
    'journey_start_time' => '',
    'journey_start_kilometers' => '',
];
$endForm = [
    'journey_end_time' => '',
    'journey_end_kilometers' => '',
];
$visitForm = default_sales_visit_form();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');
    $requestBytes = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postLimitBytes = trip_ini_bytes((string)ini_get('post_max_size'));

    if ($requestBytes > 0 && $postLimitBytes > 0 && $requestBytes > $postLimitBytes) {
        $error = 'The selected picture is too large for one submission. Use a smaller picture and try again.';
    } elseif (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($formAction === 'start_trip' && !$canStartTrip) {
        $error = 'Only authorized staff can start a marketing trip.';
    } elseif ($formAction === 'start_trip') {
        $startForm['staff_ids'] = array_values(array_unique(array_filter(array_map('intval',(array)($_POST['staff_ids']??[])),static function (int $id): bool { return $id>0; })));
        $startForm['vehicle_id'] = (string) ($_POST['vehicle_id'] ?? '');
        $startForm['vendor_ids'] = array_values(array_unique(array_filter(array_map('intval',(array)($_POST['vendor_ids']??[])),static function (int $id): bool { return $id>0; })));
        $startForm['journey_start_time'] = trim((string) ($_POST['journey_start_time'] ?? ''));
        $startForm['journey_start_kilometers'] = trim((string) ($_POST['journey_start_kilometers'] ?? ''));
        $startKilometers = is_numeric($startForm['journey_start_kilometers']) ? (float) $startForm['journey_start_kilometers'] : null;
        $selectedStaffIds = array_values(array_filter($startForm['staff_ids'],static function (int $id) use ($currentStaffId): bool { return $id!==$currentStaffId; }));
        $companionStaffId = $selectedStaffIds[0] ?? null;
        $vehicleId = (int) $startForm['vehicle_id'];
        $vendorIds = $startForm['vendor_ids'];

        $companionIsValid=true;
        if($selectedStaffIds){$staffMarks=implode(',',array_fill(0,count($selectedStaffIds),'?'));$companionStatement=db()->prepare("SELECT COUNT(*) FROM staff WHERE id IN ($staffMarks) AND is_active=1");$companionStatement->execute($selectedStaffIds);$companionIsValid=(int)$companionStatement->fetchColumn()===count($selectedStaffIds);}

        $vehicleStatement = db()->prepare("SELECT COUNT(*) FROM vehicles WHERE id = ? AND status = 'active'");
        $vehicleStatement->execute([$vehicleId]);
        $vehicleIsValid = (int) $vehicleStatement->fetchColumn() > 0;
        $activeVehicleStatement = db()->prepare("SELECT trip_code FROM sales_trips WHERE vehicle_id = ? AND status = 'in_progress' LIMIT 1");
        $activeVehicleStatement->execute([$vehicleId]);
        $activeVehicleTripCode = (string) ($activeVehicleStatement->fetchColumn() ?: '');
        $vendorIsValid=true;
        if($vendorIds){$marks=implode(',',array_fill(0,count($vendorIds),'?'));$vendorStatement=db()->prepare("SELECT COUNT(*) FROM vendors WHERE id IN ($marks) AND is_active=1");$vendorStatement->execute($vendorIds);$vendorIsValid=(int)$vendorStatement->fetchColumn()===count($vendorIds);}

        if ($vehicleId <= 0 || !$vehicleIsValid) {
            $error = 'Select a valid car number.';
        } elseif ($activeVehicleTripCode !== '') {
            $error = 'This car is already being used by active trip ' . $activeVehicleTripCode . '. Complete that trip before starting another one.';
        } elseif (!$vendorIsValid) {
            $error = 'One or more selected vendors are invalid.';
        } elseif ($startForm['journey_start_time'] === '') {
            $error = 'Journey start time is required.';
        } elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startForm['journey_start_time'])) {
            $error = 'Enter a valid journey start time.';
        } elseif ($startKilometers === null || $startKilometers < 0) {
            $error = 'Enter a valid journey start kilometer reading.';
        } elseif (!$companionIsValid) {
            $error = 'Select a valid companion staff member.';
        } else {
            try {
                $startKilometerPhoto=save_trip_kilometer_photo('journey_start_kilometer_photo');
                db()->beginTransaction();
                $nextTripRefNo = next_sales_trip_ref_no();
                $statement = db()->prepare(
                    'INSERT INTO sales_trips (
                        trip_code,
                        staff_id,
                        companion_staff_id,
                        vehicle_id,
                        vendor_id,
                        recorded_by_user_id,
                        destination,
                        trip_date,
                        journey_start_time,
                        journey_start_kilometers,
                        journey_start_kilometer_photo,
                        status
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)'
                );
                $statement->execute([
                    $nextTripRefNo,
                    $currentStaffId > 0 ? $currentStaffId : null,
                    $companionStaffId,
                    $vehicleId,
                    $vendorIds[0] ?? null,
                    $currentUserId,
                    'Marketing Trip',
                    $startForm['journey_start_time'],
                    $startKilometers,
                    $startKilometerPhoto,
                    'in_progress',
                ]);

                $tripId=(int)db()->lastInsertId();$vendorInsert=db()->prepare('INSERT INTO sales_trip_vendor_assignments (sales_trip_id,vendor_id) VALUES (?,?)');foreach($vendorIds as $vendorId)$vendorInsert->execute([$tripId,$vendorId]);$tripStaffIds=array_values(array_unique(array_filter(array_merge($currentStaffId?[$currentStaffId]:[],$selectedStaffIds))));$staffInsert=db()->prepare('INSERT INTO sales_trip_staff_assignments (sales_trip_id,staff_id) VALUES (?,?)');foreach($tripStaffIds as $assignedStaffId)$staffInsert->execute([$tripId,$assignedStaffId]);
                db()->commit();

                $message = 'Marketing trip started successfully.';
                $action = '';
                $startForm = [
                    'staff_ids' => [],
                    'vehicle_id' => '',
                    'vendor_ids' => [],
                    'journey_start_time' => '',
                    'journey_start_kilometers' => '',
                ];
            } catch (RuntimeException $exception) {
                if(db()->inTransaction())db()->rollBack();
                $error=$exception->getMessage();
            } catch (PDOException $exception) {
                if(db()->inTransaction())db()->rollBack();
                if ((string) $exception->getCode() === '23000' && str_contains($exception->getMessage(), 'uq_sales_trips_active_vehicle')) {
                    $error = 'This car already has an active trip. Complete that trip before starting another one.';
                } elseif ((string) $exception->getCode() === '23000') {
                    $error = 'The generated trip ref already exists. Please try starting again.';
                } else {
                    $error = 'The marketing trip could not be started.';
                }
            }
        }
    } elseif ($formAction === 'register_visit') {
        foreach ($visitForm as $field => $value) {
            $visitForm[$field] = trim((string) ($_POST[$field] ?? ''));
        }

        $visitForm['phone'] = normalize_phone_number($visitForm['phone']);
        $visitForm['other_phone'] = normalize_phone_number($visitForm['other_phone']);
        $visitForm['supervisor_phone'] = normalize_phone_number($visitForm['supervisor_phone']);
        $duplicatePhone = $visitForm['phone'] !== '' ? registered_customer_for_phone($visitForm['phone']) : null;
        $duplicateOtherPhone = $visitForm['other_phone'] !== '' ? registered_customer_for_phone($visitForm['other_phone']) : null;

        $afterVisitAction = (string) ($_POST['after_visit_action'] ?? 'save');
        $afterVisitAction = in_array($afterVisitAction, ['save', 'end'], true) ? $afterVisitAction : 'save';
        $visitHasUpload = false;

        foreach (['owner_pic', 'shop_pic', 'shop_video', 'car_pic', 'driver_pic', 'station_pic'] as $uploadField) {
            if (isset($_FILES[$uploadField]) && is_array($_FILES[$uploadField]) && ($_FILES[$uploadField]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $visitHasUpload = true;
                break;
            }
        }

        $visitHasData = $visitHasUpload;

        $visitContentFields = [
            'shop_arrival_time', 'shop_departure_time', 'sales_ref', 'promo_plug',
            'company_name', 'owner_name', 'phone', 'other_phone', 'area',
            'google_location', 'driver_name', 'car_registration_no',
            'supervisor_name', 'supervisor_phone', 'vin_no', 'free_plug',
            'feedback_option_id', 'note',
        ];

        foreach ($visitContentFields as $field) {
            if (($visitForm[$field] ?? '') !== '') {
                $visitHasData = true;
                break;
            }
        }

        $activeTripForVisit = active_sales_trip_for_user($currentUserId,$currentStaffId,$currentVendorId);
        $destinationId = (int) $visitForm['destination_id'];
        $destination = null;
        $isTaxiRankVisit = false;
        $visitType = 'registration';
        $parentVisitId = (int) $visitForm['parent_visit_id'];
        $locationId = (int) $visitForm['location_id'];
        $feedbackOptionId = (int) $visitForm['feedback_option_id'];
        $vendorId = $currentVendorId > 0 ? $currentVendorId : (int) $visitForm['vendor_id'];
        if ($currentVendorId > 0) {
            $visitForm['vendor_id'] = (string) $currentVendorId;
        }
        $feedbackValue = null;
        $townIsValid = location_by_id($locationId)!==null;

        if ($destinationId > 0) {
            $destinationStatement = db()->prepare('SELECT id, destination_name, destination_key FROM destinations WHERE id = ? AND is_active = 1 LIMIT 1');
            $destinationStatement->execute([$destinationId]);
            $destination = $destinationStatement->fetch() ?: null;
            $isTaxiRankVisit = $destination ? destination_is_taxi_rank($destination) : false;
        }
        $vendorIsValid=$vendorId<=0;
        if($vendorId>0){$tripVendorCheck=db()->prepare('SELECT COUNT(*) FROM sales_trip_vendor_assignments sta INNER JOIN vendors v ON v.id=sta.vendor_id WHERE sta.sales_trip_id=? AND sta.vendor_id=? AND v.is_active=1');$tripVendorCheck->execute([(int)($activeTripForVisit['id']??0),$vendorId]);$vendorIsValid=(int)$tripVendorCheck->fetchColumn()>0;}
        if(!$vendorIsValid&&(int)($activeTripForVisit['vendor_id']??0)===$vendorId){$legacyVendorCheck=db()->prepare('SELECT COUNT(*) FROM vendors WHERE id=? AND is_active=1');$legacyVendorCheck->execute([$vendorId]);$vendorIsValid=(int)$legacyVendorCheck->fetchColumn()>0;}

        if ($feedbackOptionId > 0) {
            $feedbackStatement = db()->prepare('SELECT feedback_label FROM visit_feedback_options WHERE id = ? AND is_active = 1 LIMIT 1');
            $feedbackStatement->execute([$feedbackOptionId]);
            $feedbackValue = $feedbackStatement->fetchColumn();
            $feedbackValue = $feedbackValue !== false ? (string) $feedbackValue : null;
        }

        $requiredVisitValues = $isTaxiRankVisit
            ? [$visitForm['shop_arrival_time'],$visitForm['shop_departure_time'],$locationId,$visitForm['area'],$visitForm['driver_name'],$visitForm['car_registration_no']]
            : [$visitForm['shop_arrival_time'],$visitForm['shop_departure_time'],$locationId,$visitForm['area'],$visitForm['company_name'],$visitForm['owner_name']];
        $saveAsDraft = $afterVisitAction === 'save' && count(array_filter($requiredVisitValues, static fn($value): bool => $value === '' || $value === 0)) > 0;

        if (!$activeTripForVisit) {
            $error = 'Start a marketing trip before registering a visit.';
        } elseif ($afterVisitAction === 'end' && !$visitHasData) {
            $completeTripId = (int) $activeTripForVisit['id'];
            $section = 'trip';
            $message = 'Enter the ending time and kilometers to complete the trip.';
        } elseif (!$destination) {
            $error = 'Select a destination.';
        } elseif (!$vendorIsValid) {
            $error = 'Select a valid vendor.';
        } elseif ($visitForm['google_location'] === '') {
            $error = 'Google Location is required. Use GPS or paste a Google Maps location before saving.';
        } elseif (!$saveAsDraft && $visitForm['shop_arrival_time'] === '') {
            $error = 'Arrival time is required.';
        } elseif ($visitForm['shop_arrival_time'] !== '' && !preg_match('/^\d{2}:\d{2}$/', $visitForm['shop_arrival_time'])) {
            $error = 'Enter a valid arrival time.';
        } elseif (!$saveAsDraft && $visitForm['shop_departure_time'] === '') {
            $error = 'Departure time is required.';
        } elseif ($visitForm['shop_departure_time'] !== '' && !preg_match('/^\d{2}:\d{2}$/', $visitForm['shop_departure_time'])) {
            $error = 'Enter a valid departure time.';
        } elseif ($visitForm['shop_arrival_time'] !== '' && $visitForm['shop_departure_time'] !== '' && $visitForm['shop_departure_time'] < $visitForm['shop_arrival_time']) {
            $error = 'Departure time cannot be earlier than arrival time.';
        } elseif (!$saveAsDraft && $isTaxiRankVisit && $visitForm['driver_name'] === '') {
            $error = "Driver's name is required.";
        } elseif (!$saveAsDraft && $isTaxiRankVisit && $visitForm['car_registration_no'] === '') {
            $error = 'Car registration number is required.';
        } elseif (!$saveAsDraft && $isTaxiRankVisit && $regionId <= 0) {
            $error = 'Select a region.';
        } elseif (!$saveAsDraft && $isTaxiRankVisit && !$townIsValid) {
            $error = 'Select a valid town for the selected region.';
        } elseif (!$saveAsDraft && $isTaxiRankVisit && $visitForm['area'] === '') {
            $error = 'Area is required.';
        } elseif ($visitForm['phone'] !== '' && !is_valid_phone_number($visitForm['phone'])) {
            $error = 'Enter a valid phone number.';
        } elseif ($visitForm['other_phone'] !== '' && !is_valid_phone_number($visitForm['other_phone'])) {
            $error = 'Enter a valid other phone number.';
        } elseif ($duplicatePhone || $duplicateOtherPhone) {
            $duplicate = $duplicatePhone ?: $duplicateOtherPhone;
            $duplicateName = trim((string)($duplicate['company_name'] ?: $duplicate['owner_name']));
            $error = 'This phone number has already been registered' . ($duplicateName !== '' ? ' to ' . $duplicateName : '') . '.';
        } elseif ($isTaxiRankVisit && $visitForm['supervisor_phone'] !== '' && !is_valid_phone_number($visitForm['supervisor_phone'])) {
            $error = 'Enter a valid supervisor phone number.';
        } elseif (!$saveAsDraft && $visitType === 'registration' && $regionId <= 0) {
            $error = 'Select a region.';
        } elseif (!$saveAsDraft && $visitType === 'registration' && !$townIsValid) {
            $error = 'Select a valid town for the selected region.';
        } elseif (!$saveAsDraft && $visitType === 'registration' && $visitForm['area'] === '') {
            $error = 'Area is required.';
        } elseif (!$saveAsDraft && $visitType === 'registration' && !$isTaxiRankVisit && $visitForm['company_name'] === '') {
            $error = 'Business or location name is required.';
        } elseif (!$saveAsDraft && $visitType === 'registration' && !$isTaxiRankVisit && $visitForm['owner_name'] === '') {
            $error = 'Contact name is required.';
        } elseif ($feedbackOptionId > 0 && $feedbackValue === null) {
            $error = 'Select a valid feedback option.';
        } else {
            try {
                $imageTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    'image/heic' => 'heic',
                    'image/heif' => 'heif',
                    'image/heic-sequence' => 'heic',
                    'image/heif-sequence' => 'heif',
                ];
                $imageMaxBytes = APP_IMAGE_UPLOAD_MAX_BYTES;
                $videoTypes = [
                    'video/mp4' => 'mp4',
                    'video/webm' => 'webm',
                    'video/quicktime' => 'mov',
                ];

                $contactPic = $locationPic = $additionalPic = $locationVideo = null;
                if ($isTaxiRankVisit) {
                    $additionalPic = save_visit_image_upload('car_pic', $imageTypes, $imageMaxBytes);
                    $contactPic = save_visit_image_upload('driver_pic', $imageTypes, $imageMaxBytes);
                    $locationPic = save_visit_image_upload('station_pic', $imageTypes, $imageMaxBytes);
                } else {
                    $contactPic = save_visit_image_upload('owner_pic', $imageTypes, $imageMaxBytes);
                    $locationPic = save_visit_image_upload('shop_pic', $imageTypes, $imageMaxBytes);
                    $locationVideo = save_visit_upload('shop_video', $videoTypes, 30 * 1024 * 1024);
                }

                $shopTypeId = (int) $visitForm['shop_type_id'];
                if ($shopTypeId > 0) {
                    $shopTypeCheck = db()->prepare('SELECT COUNT(*) FROM shop_types WHERE id = ? AND is_active = 1');
                    $shopTypeCheck->execute([$shopTypeId]);
                    if ((int) $shopTypeCheck->fetchColumn() === 0) {
                        throw new RuntimeException('Select a valid Shop Type.');
                    }
                }

                $placeGroupKey=preg_match('/^[a-f0-9]{32}$/',$visitForm['place_group_key'])?$visitForm['place_group_key']:bin2hex(random_bytes(16));
                $visitStatement = db()->prepare(
                    'INSERT INTO destination_visits (
                        sales_trip_id, destination_id, vendor_id, place_group_key, parent_visit_id, staff_id, recorded_by_user_id,
                        visit_type, sales_ref, promo_plug, shop_arrival_time, shop_departure_time,
                        company_name, owner_name, phone, other_phone, location_id, area,
                        google_location, shop_type_id, vehicle_registration_no, supervisor_name,
                        supervisor_phone, vin_no, owner_pic, shop_pic, car_pic,
                        shop_video, feedback, note, record_status
                     ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $visitStatement->execute([
                    (int) $activeTripForVisit['id'], $destinationId, $vendorId, $placeGroupKey, $parentVisitId ?: null,
                    $currentStaffId > 0 ? $currentStaffId : null, $currentUserId, $visitType,
                    $visitForm['sales_ref'] ?: null,
                    ($isTaxiRankVisit ? $visitForm['free_plug'] : $visitForm['promo_plug']) ?: null,
                    $visitForm['shop_arrival_time'] ?: null, $visitForm['shop_departure_time'] ?: null,
                    $isTaxiRankVisit ? (string) $destination['destination_name'] : $visitForm['company_name'],
                    $isTaxiRankVisit ? $visitForm['driver_name'] : $visitForm['owner_name'],
                    $visitForm['phone'] ?: null, $visitForm['other_phone'] ?: null,
                    $locationId ?: null, $visitForm['area'] ?: null,
                    $visitForm['google_location'] ?: null, $shopTypeId ?: null,
                    $isTaxiRankVisit ? ($visitForm['car_registration_no'] ?: null) : null, $isTaxiRankVisit ? ($visitForm['supervisor_name'] ?: null) : null,
                    $isTaxiRankVisit ? ($visitForm['supervisor_phone'] ?: null) : null, $isTaxiRankVisit ? ($visitForm['vin_no'] ?: null) : null,
                    $contactPic, $locationPic, $additionalPic, $locationVideo,
                    $feedbackValue, $visitForm['note'] ?: null, $saveAsDraft ? 'draft' : 'completed',
                ]);
                $savedVisitId=(int)db()->lastInsertId();

                if ($saveAsDraft) {
                    $message = 'Visit saved as draft because some compulsory details are missing. Use Continue to finish it later.';
                } elseif ($afterVisitAction === 'end') {
                    $completeTripId = (int) $activeTripForVisit['id'];
                    $section = 'trip';
                    $message = 'Visit saved successfully. Enter the ending details to complete the trip.';
                } else {
                    $message = 'Visit saved successfully. A new visit form is ready.';
                }

                $samePlaceValues = [
                    'destination_id' => (string) $destinationId,
                    'vendor_id' => (string) $vendorId,
                    'location_id' => (string) $locationId,
                    'area' => $visitForm['area'],
                    'google_location' => $visitForm['google_location'],
                    'shop_type_id' => (string) $shopTypeId,
                    'place_group_key' => $placeGroupKey,
                ];
                $visitForm = default_sales_visit_form();
                if (!$saveAsDraft && $afterVisitAction === 'save') {
                    foreach ($samePlaceValues as $samePlaceKey => $samePlaceValue) {
                        $visitForm[$samePlaceKey] = $samePlaceValue;
                    }
                }
                $visitFormSaved = true;
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (PDOException $exception) {
                $error = 'The visit could not be saved.';
            }
        }
    } elseif ($formAction === 'complete_trip') {
        $postedTripId = max(0, (int) ($_POST['trip_id'] ?? 0));
        $endForm['journey_end_time'] = trim((string) ($_POST['journey_end_time'] ?? ''));
        $endForm['journey_end_kilometers'] = trim((string) ($_POST['journey_end_kilometers'] ?? ''));
        $endKilometers = is_numeric($endForm['journey_end_kilometers']) ? (float) $endForm['journey_end_kilometers'] : null;

        $tripStatement = db()->prepare(
            'SELECT id, journey_start_time, journey_start_kilometers
             FROM sales_trips
             WHERE id = ? AND status = ? AND (recorded_by_user_id = ? OR recorded_by_user_id IS NULL)
             LIMIT 1'
        );
        $tripStatement->execute([$postedTripId, 'in_progress', $currentUserId]);
        $tripToComplete = $tripStatement->fetch();
        $activePlaceSession = false;
        if ($tripToComplete) {
            $placeSessionStatement = db()->prepare("SELECT COUNT(*) FROM place_visit_sessions WHERE sales_trip_id=? AND status='active'");
            $placeSessionStatement->execute([$postedTripId]);
            $activePlaceSession = (int)$placeSessionStatement->fetchColumn() > 0;
        }
        $startKilometers = $tripToComplete ? (float) $tripToComplete['journey_start_kilometers'] : null;
        $distanceKilometers = $startKilometers !== null && $endKilometers !== null ? $endKilometers - $startKilometers : null;

        if (!$tripToComplete) {
            $error = 'Select a valid active marketing trip to complete.';
        } elseif ($activePlaceSession) {
            $error = 'Record the current shop departure time before completing the trip.';
        } elseif ($endForm['journey_end_time'] === '') {
            $error = 'Journey end time is required.';
        } elseif (!preg_match('/^\d{2}:\d{2}$/', $endForm['journey_end_time'])) {
            $error = 'Enter a valid journey end time.';
        } elseif ($endForm['journey_end_time'] < substr((string) $tripToComplete['journey_start_time'], 0, 5)) {
            $error = 'Journey end time cannot be earlier than the start time.';
        } elseif ($endKilometers === null || $endKilometers < 0) {
            $error = 'Enter a valid journey end kilometer reading.';
        } elseif ($distanceKilometers === null || $distanceKilometers < 0) {
            $error = 'Journey end kilometers cannot be less than start kilometers.';
        } else {
            try {
                $endKilometerPhoto=save_trip_kilometer_photo('journey_end_kilometer_photo');
                $statement = db()->prepare(
                    'UPDATE sales_trips
                     SET journey_end_time = ?, journey_end_kilometers = ?, journey_end_kilometer_photo = ?,
                         journey_distance_kilometers = ?, status = ? WHERE id = ?'
                );
                $statement->execute([$endForm['journey_end_time'],$endKilometers,$endKilometerPhoto,$distanceKilometers,'completed',$postedTripId]);

                $message = 'Marketing trip completed successfully.';
                $tripCompleted = true;
                $completeTripId = 0;
                $endForm = ['journey_end_time' => '','journey_end_kilometers' => ''];
            } catch(RuntimeException $exception){
                $error=$exception->getMessage();
            } catch(PDOException $exception){
                $error='The marketing trip could not be completed.';
            }
        }
    }
}

$activeTrip = active_sales_trip_for_user($currentUserId,$currentStaffId,$currentVendorId);

if ($completeTripId > 0 && (!$activeTrip || (int) $activeTrip['id'] !== $completeTripId)) {
    $completeTripId = 0;
}
$canCompleteTrip=$activeTrip&&(int)($activeTrip['recorded_by_user_id']??0)===(int)$currentUserId;
$canRegisterVisit=(bool)$activeTrip&&($canCompleteTrip||$currentVendorId>0||$currentStaffId>0);

$locations=active_locations();
$locationRegions=[];foreach($locations as $location){$key=(string)($location['region_code']?:$location['region_name']);$locationRegions[$key]=(string)$location['region_name'];}asort($locationRegions);

$feedbackOptions = db()
    ->query('SELECT id, feedback_label FROM visit_feedback_options WHERE is_active = 1 ORDER BY feedback_label')
    ->fetchAll();

$destinations = db()
    ->query('SELECT id, destination_name, destination_key FROM destinations WHERE is_active = 1 ORDER BY is_default DESC, destination_name')
    ->fetchAll();

$shopTypes = db()
    ->query('SELECT id, shop_type_name FROM shop_types WHERE is_active = 1 ORDER BY shop_type_name')
    ->fetchAll();

$vehicles = db()
    ->query("SELECT id, plate_number FROM vehicles WHERE status = 'active' ORDER BY plate_number")
    ->fetchAll();

$staffLookup = db()
    ->query('SELECT id, staff_code, full_name FROM staff WHERE is_active = 1 ORDER BY full_name')
    ->fetchAll();

$vendors=db()->query('SELECT id,vendor_name,phone,location_id FROM vendors WHERE is_active=1 ORDER BY vendor_name')->fetchAll();
$tripVendors=[];
$tripStaffMembers=[];

$registeredVisits = [];
$activeTripVisitCount = 0;
$nextVisitNumber = 1;

if ($activeTrip) {
    $samePlaceId=max(0,(int)($_GET['same_place']??0));
    if($_SERVER['REQUEST_METHOD']!=='POST'&&$samePlaceId>0){$sameStatement=db()->prepare('SELECT destination_id,vendor_id,location_id,area,google_location,shop_type_id,place_group_key FROM destination_visits WHERE id=? AND sales_trip_id=? LIMIT 1');$sameStatement->execute([$samePlaceId,(int)$activeTrip['id']]);$same=$sameStatement->fetch();if($same){foreach(['destination_id','vendor_id','location_id','area','google_location','shop_type_id','place_group_key'] as $sameKey)$visitForm[$sameKey]=(string)($same[$sameKey]??'');$visitForm['place_group_key']=(string)($same['place_group_key']?:bin2hex(random_bytes(16)));}}
    $tripVendorStatement=db()->prepare('SELECT v.id,v.vendor_name,v.phone FROM sales_trip_vendor_assignments sta INNER JOIN vendors v ON v.id=sta.vendor_id WHERE sta.sales_trip_id=? AND v.is_active=1 ORDER BY v.vendor_name');$tripVendorStatement->execute([(int)$activeTrip['id']]);$tripVendors=$tripVendorStatement->fetchAll();
    $tripStaffStatement=db()->prepare('SELECT s.id,s.full_name FROM sales_trip_staff_assignments stsa INNER JOIN staff s ON s.id=stsa.staff_id WHERE stsa.sales_trip_id=? AND s.is_active=1 ORDER BY s.full_name');$tripStaffStatement->execute([(int)$activeTrip['id']]);$tripStaffMembers=$tripStaffStatement->fetchAll();
    if(!$tripVendors&&(int)($activeTrip['vendor_id']??0)>0){foreach($vendors as $vendor)if((int)$vendor['id']===(int)$activeTrip['vendor_id']){$tripVendors[]=$vendor;break;}}
    if ($visitForm['vendor_id'] === '' && count($tripVendors)===1) $visitForm['vendor_id']=(string)$tripVendors[0]['id'];
    $visitCountStatement = db()->prepare("SELECT COUNT(*) FROM destination_visits WHERE sales_trip_id = ? AND record_status='completed'");
    $visitCountStatement->execute([(int) $activeTrip['id']]);
    $activeTripVisitCount = (int) $visitCountStatement->fetchColumn();
    $nextVisitNumber = $activeTripVisitCount + 1;

    $visitStatement = db()->prepare(
        'SELECT destination_visits.*, destination_visits.shop_arrival_time AS shop_arrival_time,
                destination_visits.shop_departure_time AS shop_departure_time,
                destination_visits.company_name AS company_name,
                destination_visits.owner_name AS owner_name,
                destination_visits.vehicle_registration_no AS car_registration_no,
                destination_visits.owner_name AS driver_name,
                destination_visits.phone AS taxi_phone, destination_visits.other_phone AS taxi_other_phone,
                destination_visits.area AS taxi_area, destination_visits.promo_plug AS free_plug,
                destinations.destination_name, destinations.destination_key,
                shop_types.shop_type_name, locations.region_name, locations.mmda_name AS district_name, locations.town_name, locations.is_capital
         FROM destination_visits
         INNER JOIN destinations ON destinations.id = destination_visits.destination_id
         LEFT JOIN shop_types ON shop_types.id = destination_visits.shop_type_id
         LEFT JOIN locations ON locations.id = destination_visits.location_id
         WHERE destination_visits.sales_trip_id = ? AND destination_visits.record_status=\'completed\'
         ORDER BY destination_visits.created_at DESC, destination_visits.id DESC
         LIMIT 20'
    );
    $visitStatement->execute([(int) $activeTrip['id']]);
    $registeredVisits = $visitStatement->fetchAll();
}

$draftStatement=db()->prepare("SELECT dv.id,dv.company_name,dv.owner_name,dv.updated_at,dv.created_at,d.destination_name,v.vendor_name FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id LEFT JOIN vendors v ON v.id=dv.vendor_id WHERE dv.record_status='draft' AND (dv.recorded_by_user_id=? OR dv.staff_id=?) ORDER BY COALESCE(dv.updated_at,dv.created_at) DESC");
$draftStatement->execute([$currentUserId,$currentStaffId]);$draftVisits=$draftStatement->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="sales-trip-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Operations</span>
            <h1 id="sales-trip-title"><?= $section === 'trip' ? 'Trip Registration' : 'Marketing Trip' ?></h1>
            <p><?= $section === 'trip' ? 'Start, view, or complete the current marketing trip.' : 'Register a new location or continue work at an existing location.' ?></p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-route"></i>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if($section!==''): ?>
    <?php $backFallback=$section==='trip'?app_url('marketing.php?view=trip'):app_url('normalized-customer.php'); ?>
    <div class="sales-page-back"><a class="secondary-button secondary-button--small" href="<?=e($backFallback)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a></div>
    <?php endif; ?>

    <?php if($draftVisits): ?><details class="draft-panel"><summary class="draft-panel__toggle"><i class="fa-solid fa-file-pen"></i><strong>Draft Visits</strong><span class="draft-panel__count"><?=number_format(count($draftVisits))?></span><i class="fa-solid fa-chevron-down draft-panel__chevron"></i></summary><div class="draft-list"><?php foreach($draftVisits as $draft):?><article class="draft-card"><div class="draft-card__content"><div class="draft-card__title-row"><h3><?=e((string)($draft['company_name']?:$draft['owner_name']?:'Unnamed visit'))?></h3><span class="draft-badge">Draft</span></div><div class="draft-card__meta"><span><?=e((string)$draft['destination_name'])?></span><?php if(($draft['vendor_name']??'')!==''):?><span><?=e((string)$draft['vendor_name'])?></span><?php endif;?><span><?=e(date('d M Y, H:i',strtotime((string)($draft['updated_at']?:$draft['created_at']))))?></span></div></div><a class="draft-card__continue" href="<?=e(app_url('visit-edit.php?id='.(int)$draft['id'].'&return_to='.rawurlencode(app_url('sales-trip.php?section=customer'))))?>"><span>Continue</span><i class="fa-solid fa-arrow-right"></i></a></article><?php endforeach;?></div></details><?php endif; ?>

    <?php if($section==='customer'&&!$activeTrip): ?><div class="sales-step-card sales-step-card--action"><div><h2>No active trip</h2><p>Register and start a trip before recording customer visits.</p></div><a class="login-button sales-trip-action" href="<?=e(app_url('sales-trip.php?section=trip'))?>"><span>Trip Registration</span><i class="fa-solid fa-arrow-right"></i></a></div><?php endif; ?>

    <?php if (!$activeTrip && $section === 'trip' && $canStartTrip): ?>
        <form class="record-form sales-step-form mobile-line-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('sales-trip.php?section=trip')) ?>" autocomplete="off" data-form-recovery-key="sales-trip-start-v2-<?=current_user_id()?>" data-form-recovery-remove-key="sales-trip-start-<?=current_user_id()?>" data-form-recovery-clear="<?=$tripCompleted?'true':'false'?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="form_action" value="start_trip">

            <div class="sales-step-card__header sales-step-card__header--plain">
                <div>
                    <h2>Trip Registration</h2>
                    <p>Record the journey start details before the staff moves to customer visits.</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-field">
                    <label for="vehicle_id">Car number</label>
                    <select id="vehicle_id" name="vehicle_id" data-popup-open-delay="700" required>
                        <option value="">Select car number</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= e((string) $vehicle['id']) ?>" <?= $startForm['vehicle_id'] === (string) $vehicle['id'] ? 'selected' : '' ?>>
                                <?= e((string) $vehicle['plate_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="staff_ids">Other staff <span class="muted-text">(optional)</span></label>
                    <div class="trip-token-picker" data-trip-token-picker data-trip-token-name="staff_ids[]"><div class="trip-token-picker__control" data-trip-token-control><div class="trip-token-picker__tokens" data-trip-token-list></div><input id="staff_ids" type="search" placeholder="Type staff name or code" autocomplete="off" data-trip-token-search></div><div class="trip-token-picker__options" data-trip-token-options><?php foreach ($staffLookup as $staffMember):if((int)$staffMember['id']===$currentStaffId)continue;$staffLabel=(string)$staffMember['full_name'].($staffMember['staff_code']!==null?' - '.(string)$staffMember['staff_code']:'');?><button type="button" data-trip-token-option data-trip-token-value="<?=(int)$staffMember['id']?>" data-trip-token-label="<?=e($staffLabel)?>" data-trip-token-selected="<?=in_array((int)$staffMember['id'],$startForm['staff_ids'],true)?'true':'false'?>"><span><?=e($staffLabel)?></span><i class="fa-solid fa-plus" aria-hidden="true"></i></button><?php endforeach;?></div></div>
                </div>

                <div class="form-field">
                    <label for="vendor_ids">Vendors <span class="muted-text">(optional)</span></label>
                    <div class="trip-token-picker" data-trip-token-picker data-trip-token-name="vendor_ids[]"><div class="trip-token-picker__control" data-trip-token-control><div class="trip-token-picker__tokens" data-trip-token-list></div><input id="vendor_ids" class="vendor-selector-input" type="search" placeholder="Type vendor name or phone" autocomplete="off" data-trip-token-search></div><div class="trip-token-picker__options" data-trip-token-options><?php foreach($vendors as $vendor):$vendorLabel=implode(' · ',array_filter([(string)$vendor['vendor_name'],(string)($vendor['phone']??'')]));?><button type="button" data-trip-token-option data-trip-token-value="<?=(int)$vendor['id']?>" data-trip-token-label="<?=e($vendorLabel)?>" data-trip-token-selected="<?=in_array((int)$vendor['id'],$startForm['vendor_ids'],true)?'true':'false'?>"><span><?=e($vendorLabel)?></span><i class="fa-solid fa-plus" aria-hidden="true"></i></button><?php endforeach;?></div></div>
                </div>

                <div class="form-field">
                    <label for="journey_start_time">Journey start time</label>
                    <div class="field-control-row">
                        <input id="journey_start_time" class="time-picker-input" name="journey_start_time" type="text" inputmode="numeric" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" maxlength="5" placeholder="--:--" value="<?= e($startForm['journey_start_time']) ?>" data-time-picker readonly required>
                        <button class="secondary-button secondary-button--small" type="button" data-current-time-target="journey_start_time">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            <span>Now</span>
                        </button>
                    </div>
                </div>

                <div class="form-field">
                    <label for="journey_start_kilometers">Journey start kilometers</label>
                    <input id="journey_start_kilometers" name="journey_start_kilometers" type="number" min="0" step="0.01" value="<?= e($startForm['journey_start_kilometers']) ?>" required>
                </div>
                <div class="form-field">
                    <label for="journey_start_kilometer_photo">Start kilometer picture <span class="muted-text">(optional)</span></label>
                    <input id="journey_start_kilometer_photo" name="journey_start_kilometer_photo" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif" capture="environment" data-photo-source-choice>
                </div>
            </div>

            <div class="form-actions">
                <a class="secondary-button" href="<?= e(app_url('marketing.php?view=trip')) ?>">Cancel</a>
                <button class="login-button" type="submit">
                    <span>Save start</span>
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                </button>
            </div>
        </form>
    <?php endif; ?>
    <?php if(!$activeTrip&&$section==='trip'&&!$canStartTrip): ?><div class="sales-step-card"><div><h2>Trip registration unavailable</h2><p>An authorized staff member or administrator must register the trip.</p></div></div><?php endif; ?>

    <?php if ($activeTrip && in_array($section,['trip','customer'],true)): ?>
        <?php if($section==='trip'): ?>
        <div class="active-trip-card">
            <div class="active-trip-card__header">
                <div>
                    <span class="section-kicker">In Progress</span>
                    <h2><?= e((string) $activeTrip['trip_code']) ?></h2>
                </div>
                <span class="status-badge is-warning">In progress</span>
            </div>
            <div class="trip-summary-grid">
                <div>
                    <span>Trip date</span>
                    <strong><?= e(date('d M Y', strtotime((string) $activeTrip['trip_date']))) ?></strong>
                </div>
                <div>
                    <span>Start time</span>
                    <strong><?= e(substr((string) $activeTrip['journey_start_time'], 0, 5)) ?></strong>
                </div>
                <div>
                    <span>Start kilometers</span>
                    <strong><?= e(number_format((float) $activeTrip['journey_start_kilometers'], 2)) ?></strong>
                </div>
                <?php if(!empty($activeTrip['journey_start_kilometer_photo'])): ?><div><span>Start picture</span><a class="compact-location-link" href="<?=e(app_url((string)$activeTrip['journey_start_kilometer_photo']))?>" data-media-viewer="image" data-media-title="Start kilometer picture" aria-label="View start kilometer picture"><i class="fa-solid fa-camera"></i></a></div><?php endif; ?>
                <div>
                    <span>Car number</span>
                    <strong><?= e((string) ($activeTrip['vehicle_plate_number'] ?? '')) ?></strong>
                </div>
                <div>
                    <span>Staff</span>
                    <strong><?= e((string) ($activeTrip['main_staff_name'] ?? current_user_name())) ?></strong>
                </div>
                <div>
                    <span>Trip staff</span>
                    <strong><?= e(implode(', ',array_column($tripStaffMembers,'full_name')) ?: (string)($activeTrip['main_staff_name'] ?? 'None')) ?></strong>
                </div>
                <div>
                    <span>Trip vendors</span>
                    <strong><?= e(implode(', ',array_column($tripVendors,'vendor_name')) ?: 'None') ?></strong>
                </div>
                <div>
                    <span>Visits recorded</span>
                    <strong><?= e(number_format($activeTripVisitCount)) ?></strong>
                </div>
            </div>
        </div>
        <?php if($completeTripId!==(int)$activeTrip['id']): ?><div class="form-actions"><?php if(is_admin_user()||(int)$activeTrip['recorded_by_user_id']===$currentUserId): ?><a class="secondary-button" href="<?=e(app_url('trip-edit.php?id='.(int)$activeTrip['id']))?>"><i class="fa-solid fa-pen-to-square"></i><span>Edit Trip</span></a><?php endif;?><?php if($canCompleteTrip): ?><a class="danger-button" href="<?=e(app_url('sales-trip.php?section=trip&complete='.(int)$activeTrip['id']))?>"><i class="fa-solid fa-flag-checkered"></i><span>Complete Trip</span></a><?php endif; ?></div><?php endif; ?>
        <?php endif; ?>

        <?php if ($section==='customer' && $completeTripId !== (int) $activeTrip['id'] && $canRegisterVisit): ?>
        <form class="record-form record-form--section visit-registration-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('sales-trip.php?section=customer')) ?>" data-form-recovery-key="sales-visit-<?=current_user_id()?>-<?=(int)$activeTrip['id']?>" data-form-recovery-clear="<?=$visitFormSaved?'true':'false'?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="place_group_key" value="<?=e($visitForm['place_group_key'])?>">
            <input type="hidden" name="form_action" value="register_visit">

            <div class="sales-step-card__header">
                <div class="sales-step-card__meta">
                    <strong>VISIT <?= e((string) $nextVisitNumber) ?></strong>
                </div>
                <div>
                    <h2>Visit <?= e((string) $nextVisitNumber) ?></h2>
                    <p>Record the customer visit details before moving to the next shop or ending the trip.</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-field form-field--wide">
                    <label for="destination_id">Destination</label>
                    <select id="destination_id" name="destination_id" data-destination-select required>
                        <option value="">Select destination</option>
                        <?php foreach ($destinations as $destination): ?>
                            <?php $destinationMode = (string) ($destination['destination_key'] ?? '') === taxi_rank_destination_key() ? 'taxi_rank' : 'registration'; ?>
                            <option
                                value="<?= e((string) $destination['id']) ?>"
                                data-destination-mode="<?= e($destinationMode) ?>"
                                <?= $visitForm['destination_id'] === (string) $destination['id'] ? 'selected' : '' ?>
                            >
                                <?= e((string) $destination['destination_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if($currentVendorId>0): ?><input type="hidden" name="vendor_id" value="<?=$currentVendorId?>"><?php else: ?><div class="form-field"><label for="visit_vendor_id">Assigned vendor <span class="muted-text">(optional)</span></label><select id="visit_vendor_id" name="vendor_id" data-vendor-selector data-popup-select data-popup-search data-popup-hide-empty><option value="">No vendor</option><?php foreach($tripVendors as $vendor):?><option value="<?=(int)$vendor['id']?>" <?=$visitForm['vendor_id']===(string)$vendor['id']?'selected':''?>><?=e(implode(' · ',array_filter([(string)$vendor['vendor_name'],(string)($vendor['phone']??'')])))?></option><?php endforeach;?></select></div><?php endif; ?>

                <div class="form-field">
                    <label for="shop_arrival_time">Shop Arrival Time</label>
                    <div class="field-control-row">
                        <input id="shop_arrival_time" class="time-picker-input" name="shop_arrival_time" type="text" inputmode="numeric" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" maxlength="5" placeholder="--:--" value="<?= e($visitForm['shop_arrival_time']) ?>" data-time-picker readonly required>
                        <button class="secondary-button secondary-button--small" type="button" data-current-time-target="shop_arrival_time">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            <span>Now</span>
                        </button>
                    </div>
                </div>

                <div class="form-field">
                    <label for="sales_ref">Sales Ref</label>
                    <textarea id="sales_ref" name="sales_ref" rows="2" autocomplete="off"><?= e($visitForm['sales_ref']) ?></textarea>
                </div>

                <div class="form-field" data-visit-mode="registration">
                    <label for="promo_plug">Promo Plug</label>
                    <input id="promo_plug" name="promo_plug" type="text" value="<?= e($visitForm['promo_plug']) ?>">
                </div>

                <input type="hidden" name="visit_type" value="registration">

                <?php $selectedLocationRegion='';foreach($locations as $location){if($visitForm['location_id']===(string)$location['id']){$selectedLocationRegion=(string)($location['region_code']?:$location['region_name']);break;}} ?>
                <div class="form-field" data-visit-mode="registration taxi_rank"><label for="visit_region">Region</label><select id="visit_region" data-location-region-select required><option value="">Select region</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e((string)$regionKey)?>" <?=$selectedLocationRegion===(string)$regionKey?'selected':''?>><?=e($regionName)?></option><?php endforeach;?></select></div>
                <div class="form-field" data-visit-mode="registration taxi_rank"><label for="location_id">Town</label><select id="location_id" name="location_id" data-location-town-select required><option value="">Select town</option><option value="__other__" data-add-town-option="true">Other — add a new town</option><?php foreach($locations as $location):?><option value="<?=(int)$location['id']?>" data-region-key="<?=e((string)($location['region_code']?:$location['region_name']))?>" data-mmda-name="<?=e((string)$location['mmda_name'])?>" <?=$visitForm['location_id']===(string)$location['id']?'selected':''?>><?=e((string)$location['town_name'])?><?= (int)$location['is_capital']===1?' *':'' ?></option><?php endforeach;?></select><small data-location-mmda-output></small></div>

                <div class="form-field" data-visit-mode="registration taxi_rank">
                    <label for="area">Area</label>
                    <input id="area" name="area" type="text" value="<?= e($visitForm['area']) ?>" required>
                </div>

                <div class="form-field" data-visit-mode="registration">
                    <label for="company_name">Comp Name</label>
                    <input id="company_name" name="company_name" type="text" value="<?= e($visitForm['company_name']) ?>" required>
                </div>

                <div class="form-field" data-visit-mode="registration">
                    <label for="owner_name">Owner's Name</label>
                    <input id="owner_name" name="owner_name" type="text" value="<?= e($visitForm['owner_name']) ?>" required>
                </div>

                <div class="form-field" data-visit-mode="registration taxi_rank">
                    <label for="phone">Phone</label>
                    <input id="phone" name="phone" type="tel" value="<?= e($visitForm['phone']) ?>" placeholder="0240000000" maxlength="13" inputmode="tel" autocomplete="tel" data-phone-input data-customer-phone-check="<?=e(app_url('customer-phone-check.php'))?>">
                </div>

                <div class="form-field" data-visit-mode="registration taxi_rank">
                    <label for="other_phone">Other Phone</label>
                    <input id="other_phone" name="other_phone" type="tel" value="<?= e($visitForm['other_phone']) ?>" placeholder="0240000000" maxlength="13" inputmode="tel" autocomplete="tel" data-phone-input data-customer-phone-check="<?=e(app_url('customer-phone-check.php'))?>">
                </div>

                <div class="form-field" data-visit-mode="registration taxi_rank">
                    <label for="google_location">Google Location</label>
                    <div class="field-control-row">
                        <input id="google_location" name="google_location" type="url" value="<?= e($visitForm['google_location']) ?>" placeholder="Use GPS or paste a Google Maps link" required>
                        <button class="secondary-button secondary-button--small location-button" type="button" data-current-location-target="google_location">
                            <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i>
                            <span>Use GPS</span>
                        </button>
                    </div>
                </div>

                <div class="form-field" data-visit-mode="registration">
                    <label for="shop_type_id">Shop Type</label>
                    <select id="shop_type_id" name="shop_type_id">
                        <option value="">Select shop type</option>
                        <?php foreach ($shopTypes as $shopType): ?>
                            <option value="<?= e((string) $shopType['id']) ?>" <?= $visitForm['shop_type_id'] === (string) $shopType['id'] ? 'selected' : '' ?>><?= e((string) $shopType['shop_type_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="driver_name">Driver's Name</label>
                    <input id="driver_name" name="driver_name" type="text" value="<?= e($visitForm['driver_name']) ?>" required>
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="car_registration_no">Car Registration No</label>
                    <input id="car_registration_no" name="car_registration_no" type="text" value="<?= e($visitForm['car_registration_no']) ?>" required>
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="supervisor_name">Supervisor Name</label>
                    <input id="supervisor_name" name="supervisor_name" type="text" value="<?= e($visitForm['supervisor_name']) ?>">
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="supervisor_phone">Supervisor Phone</label>
                    <input id="supervisor_phone" name="supervisor_phone" type="tel" value="<?= e($visitForm['supervisor_phone']) ?>" placeholder="0240000000" maxlength="13" inputmode="tel" autocomplete="tel" data-phone-input>
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="vin_no">VIN No</label>
                    <input id="vin_no" name="vin_no" type="text" value="<?= e($visitForm['vin_no']) ?>">
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="car_pic">Car Pic</label>
                    <input id="car_pic" name="car_pic" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" data-photo-source-choice>
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="driver_pic">Driver's Pic</label>
                    <input id="driver_pic" name="driver_pic" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" data-photo-source-choice>
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="station_pic">Station Pic</label>
                    <input id="station_pic" name="station_pic" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" data-photo-source-choice>
                </div>

                <div class="form-field" data-visit-mode="taxi_rank">
                    <label for="free_plug">Promo Plug</label>
                    <input id="free_plug" name="free_plug" type="text" value="<?= e($visitForm['free_plug']) ?>">
                </div>

                <div class="form-field" data-visit-mode="registration">
                    <label for="owner_pic">Owner's Pic</label>
                    <input id="owner_pic" name="owner_pic" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" data-photo-source-choice>
                </div>

                <div class="form-field" data-visit-mode="registration">
                    <label for="shop_pic">Shop Pic</label>
                    <input id="shop_pic" name="shop_pic" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" data-photo-source-choice>
                </div>

                <div class="form-field" data-visit-mode="registration">
                    <label for="shop_video">Shop Vid</label>
                    <input id="shop_video" name="shop_video" type="file" accept="video/mp4,video/webm,video/quicktime">
                </div>

                <div class="form-field" data-visit-mode="registration">
                    <label for="feedback">Feedback</label>
                    <select id="feedback" name="feedback_option_id">
                        <option value="">Select feedback</option>
                        <?php foreach ($feedbackOptions as $feedbackOption): ?>
                            <option value="<?= e((string) $feedbackOption['id']) ?>" <?= $visitForm['feedback_option_id'] === (string) $feedbackOption['id'] ? 'selected' : '' ?>>
                                <?= e((string) $feedbackOption['feedback_label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field form-field--wide" data-visit-mode="registration">
                    <label for="note">Note</label>
                    <textarea id="note" name="note" rows="3"><?= e($visitForm['note']) ?></textarea>
                </div>

                <div class="form-field form-field--wide">
                    <label for="shop_departure_time">Shop Departure Time</label>
                    <div class="field-control-row">
                        <input id="shop_departure_time" class="time-picker-input" name="shop_departure_time" type="text" inputmode="numeric" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" maxlength="5" placeholder="--:--" value="<?= e($visitForm['shop_departure_time']) ?>" data-time-picker readonly required>
                        <button class="secondary-button secondary-button--small" type="button" data-current-time-target="shop_departure_time">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            <span>Now</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="form-actions sales-step-actions">
                <?php if($canCompleteTrip): ?><button class="danger-button" type="submit" name="after_visit_action" value="end" formnovalidate>
                    <span>End Trip</span>
                    <i class="fa-solid fa-flag-checkered" aria-hidden="true"></i>
                </button><?php endif; ?>
                <button class="login-button" type="submit" name="after_visit_action" value="save" formnovalidate>
                    <span>Save / Register Another Here</span>
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                </button>
            </div>
        </form>

        <div class="visit-list">
            <div class="management-heading management-heading--compact">
                <div>
                    <span class="section-kicker">Trip Activity</span>
                    <h2>Visits and Follow-ups On This Trip</h2>
                </div>
            </div>

            <?php if (!$registeredVisits): ?>
                <p class="empty-state">No visits or follow-ups recorded on this active trip yet.</p>
            <?php else: ?>
                <div class="table-wrap"><table class="data-table data-table--sales-trip"><thead><tr><th>Type</th><th>Destination</th><th>Customer</th><th>Phone</th><th>Area</th><th>Sales Status</th><th>Arrival</th><th>Departure</th><th>Feedback</th></tr></thead><tbody>
                    <?php foreach ($registeredVisits as $visit):$isFollowup=(string)($visit['visit_type']??'registration')==='follow_up';$isTaxiRankRow=!$isFollowup&&(string)($visit['destination_key']??'')===taxi_rank_destination_key();$activityLabel=$isFollowup?ucwords(str_replace('_',' ',(string)($visit['follow_up_method']?:'physical_visit'))):'Registration';$customerName=(string)($isTaxiRankRow?($visit['driver_name']??''):($visit['company_name']?:$visit['owner_name']));$phone=(string)($isTaxiRankRow?($visit['taxi_phone']??''):($visit['phone']??''));$area=(string)($isTaxiRankRow?($visit['taxi_area']??''):($visit['area']??''));$rowCanEdit=is_admin_user()||(current_user_role()==='staff'&&((int)($visit['recorded_by_user_id']??0)===(int)$currentUserId||($currentStaffId>0&&(int)($visit['staff_id']??0)===$currentStaffId)));$editUrl=app_url('visit-edit.php?id='.(int)$visit['id'].'&return_to='.rawurlencode(app_url('sales-trip.php?section=customer')));$sold=customer_has_completed_pos_sale((int)($visit['customer_id']??0));?>
                    <tr class="customer-sales-row <?=$sold?'is-sold':'is-unsold'?>" data-customer-sales-row <?=$rowCanEdit?'data-clickable-listing data-listing-url="'.e($editUrl).'"':''?>><td><span class="status-badge <?=$isFollowup?'is-warning':'is-active'?>"><?=e($activityLabel)?></span></td><td><?=e((string)($visit['destination_name']??'Visit'))?></td><td><strong><?=e($customerName)?></strong></td><td><?=e($phone)?></td><td><?=e($area)?><span class="muted-text"><?=town_name_html((string)($visit['town_name']??''),$visit['is_capital']??0)?></span></td><td><span class="status-badge <?=$sold?'is-success':'is-warning'?>"><?=$sold?'Yes':'No'?></span></td><td><?=e(substr((string)$visit['shop_arrival_time'],0,5))?></td><td><?=e(substr((string)$visit['shop_departure_time'],0,5))?></td><td><?=e((string)($visit['feedback']??''))?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($section==='trip' && $activeTrip && $completeTripId === (int) $activeTrip['id'] && $canCompleteTrip): ?>
        <form class="record-form record-form--section" method="post" enctype="multipart/form-data" action="<?= e(app_url('sales-trip.php?section=trip&complete=' . (int) $activeTrip['id'])) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="form_action" value="complete_trip">
            <input type="hidden" name="trip_id" value="<?= e((string) $activeTrip['id']) ?>">

            <div class="sales-step-card__header">
                <div class="sales-step-card__meta">
                    <strong>DONE</strong>
                </div>
                <div>
                    <h2>End Trip</h2>
                    <p>Enter the final journey time and kilometers to close this marketing trip.</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-field">
                    <label for="journey_end_time">Journey end time</label>
                    <div class="field-control-row">
                        <input id="journey_end_time" class="time-picker-input" name="journey_end_time" type="text" inputmode="numeric" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" maxlength="5" placeholder="--:--" value="<?= e($endForm['journey_end_time']) ?>" data-time-picker readonly required>
                        <button class="secondary-button secondary-button--small" type="button" data-current-time-target="journey_end_time">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            <span>Now</span>
                        </button>
                    </div>
                </div>

                <div class="form-field">
                    <label for="journey_end_kilometers">Journey end kilometers</label>
                    <input id="journey_end_kilometers" name="journey_end_kilometers" type="number" min="0" step="0.01" value="<?= e($endForm['journey_end_kilometers']) ?>" data-trip-end-km required>
                </div>
                <div class="form-field">
                    <label for="journey_end_kilometer_photo">End kilometer picture <span class="muted-text">(optional)</span></label>
                    <input id="journey_end_kilometer_photo" name="journey_end_kilometer_photo" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif" data-photo-source-choice>
                    <small class="muted-text">You can attach a clear picture showing the final kilometer reading.</small>
                </div>
            </div>

            <div class="form-actions">
                <a class="secondary-button" href="<?= e(app_url('sales-trip.php?section=trip')) ?>">Cancel</a>
                <button class="login-button" type="submit">
                    <span>Complete trip</span>
                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                </button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php if($placeChoiceVisitId>0): ?>
<div class="place-choice-backdrop" data-place-choice-modal><section class="place-choice-dialog" role="dialog" aria-modal="true" aria-labelledby="place-choice-title"><span class="place-choice-dialog__icon"><i class="fa-solid fa-location-dot"></i></span><div><span class="section-kicker">Visit saved</span><h2 id="place-choice-title">Where is the next customer?</h2><p>Choose Same Location to reuse and group this location, or Other Location to start with a clean location.</p></div><div class="place-choice-dialog__actions"><a class="login-button" href="<?=e(app_url('sales-trip.php?section=customer&same_place='.$placeChoiceVisitId))?>"><i class="fa-solid fa-location-dot"></i><span>Same Location</span></a><a class="secondary-button" href="<?=e(app_url('sales-trip.php?section=customer'))?>"><i class="fa-solid fa-map-location-dot"></i><span>Other Location</span></a><button class="place-choice-finish" type="button" data-place-choice-close>Finish</button></div></section></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
