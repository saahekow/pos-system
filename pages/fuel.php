<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('vehicle_log');

$pageTitle = 'Fuel';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Vehicle Log', 'url' => app_url('vehicles.php')],
    ['label' => 'Fuel'],
];
$internalBackUrl=requested_return_url(app_url('vehicles.php'));

function compress_fuel_image_upload(string $sourcePath, string $mimeType, string $targetPath): bool
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

function save_fuel_image_upload(string $fieldName): ?string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $upload = $_FILES[$fieldName];

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The selected image could not be uploaded.');
    }

    if (($upload['size'] ?? 0) > APP_IMAGE_UPLOAD_MAX_BYTES) {
        throw new RuntimeException('One of the uploaded images is larger than ' . APP_IMAGE_UPLOAD_MAX_LABEL . '.');
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/heic-sequence' => 'heic',
        'image/heif-sequence' => 'heif',
    ];
    $tmpName = (string) ($upload['tmp_name'] ?? '');
    $originalName = strtolower((string) ($upload['name'] ?? ''));
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMimeType = (string) $fileInfo->file($tmpName);
    $imageInfo = @getimagesize($tmpName);
    $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : $detectedMimeType;

    if (!isset($allowedTypes[$mimeType])) {
        $mimeType = ['heic' => 'image/heic', 'heif' => 'image/heif'][$extension] ?? $mimeType;
    }

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Upload only supported image formats.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/fuel';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $compressibleTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $canCompress = in_array($mimeType, $compressibleTypes, true);
    $fileExtension = $canCompress ? 'jpg' : $allowedTypes[$mimeType];
    $fileName = $fieldName . '-' . bin2hex(random_bytes(10)) . '.' . $fileExtension;
    $targetPath = $uploadDir . '/' . $fileName;

    if ($canCompress && compress_fuel_image_upload($tmpName, $mimeType, $targetPath)) {
        return 'assets/uploads/fuel/' . $fileName;
    }

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }

    return 'assets/uploads/fuel/' . $fileName;
}

ensure_fuel_log_upload_schema();

$message = '';
$error = '';
$currentStaffId = current_staff_id();
$form = [
    'vehicle_id' => '',
    'odometer_reading' => '',
    'liters' => '',
    'cost' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $form['vehicle_id'] = (string) ($_POST['vehicle_id'] ?? '');
    $form['odometer_reading'] = trim((string) ($_POST['odometer_reading'] ?? ''));
    $form['liters'] = trim((string) ($_POST['liters'] ?? ''));
    $form['cost'] = trim((string) ($_POST['cost'] ?? ''));
    $vehicleId = (int) $form['vehicle_id'];
    $odometerReading = ctype_digit($form['odometer_reading']) ? (int) $form['odometer_reading'] : null;
    $liters = is_numeric($form['liters']) ? (float) $form['liters'] : null;
    $cost = is_numeric($form['cost']) ? (float) $form['cost'] : null;

    $vehicleStatement = db()->prepare("SELECT COUNT(*) FROM vehicles WHERE id = ? AND status = 'active'");
    $vehicleStatement->execute([$vehicleId]);
    $vehicleIsValid = (int) $vehicleStatement->fetchColumn() > 0;

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($vehicleId <= 0 || !$vehicleIsValid) {
        $error = 'Select a valid car number.';
    } elseif ($odometerReading === null) {
        $error = 'Enter the current mileage.';
    } elseif (!isset($_FILES['odometer_picture']) || ($_FILES['odometer_picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Take or upload the current mileage picture.';
    } elseif ($liters === null || $liters <= 0) {
        $error = 'Enter the fuel purchase liters.';
    } elseif ($cost === null || $cost <= 0) {
        $error = 'Enter the fuel purchase amount.';
    } elseif (!isset($_FILES['odometer_picture_2']) || ($_FILES['odometer_picture_2']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Take or upload the final fuel picture.';
    } else {
        try {
            $odometerPicture = save_fuel_image_upload('odometer_picture');
            $odometerPicture2 = save_fuel_image_upload('odometer_picture_2');

            $statement = db()->prepare(
                'INSERT INTO fuel_logs (
                    vehicle_id,
                    staff_id,
                    fuel_date,
                    liters,
                    cost,
                    odometer_reading,
                    odometer_picture,
                    odometer_picture_2,
                    notes
                 ) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $vehicleId,
                $currentStaffId,
                $liters,
                $cost,
                $odometerReading,
                $odometerPicture,
                $odometerPicture2,
                null,
            ]);

            $message = 'Fuel record saved successfully.';
            $form = [
                'vehicle_id' => '',
                'odometer_reading' => '',
                'liters' => '',
                'cost' => '',
            ];
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            $error = 'The fuel record could not be saved.';
        }
    }
}

$vehicles = db()
    ->query("SELECT id, plate_number FROM vehicles WHERE status = 'active' ORDER BY plate_number")
    ->fetchAll();

$where = '';
$params = [];

if (!is_admin_user()) {
    $where = 'WHERE fuel_logs.staff_id = ?';
    $params[] = $currentStaffId ?: 0;
}

$statement = db()->prepare(
    "SELECT fuel_logs.*, vehicles.plate_number, staff.full_name AS staff_name
     FROM fuel_logs
     INNER JOIN vehicles ON vehicles.id = fuel_logs.vehicle_id
     LEFT JOIN staff ON staff.id = fuel_logs.staff_id
     {$where}
     ORDER BY fuel_logs.created_at DESC
     LIMIT 60"
);
$statement->execute($params);
$fuelLogs = $statement->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="fuel-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Vehicle Log</span>
            <h1 id="fuel-title">Fuel</h1>
            <p>Record fuel purchases with mileage and odometer photos.</p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-gas-pump"></i>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$vehicles): ?>
        <p class="empty-state">No active car numbers are available yet. Add them in Vehicle Setup first.</p>
    <?php else: ?>
        <form class="record-form mobile-line-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('fuel.php?return_to='.rawurlencode($internalBackUrl))) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="form-grid">
                <div class="form-field">
                    <label for="vehicle_id">Car number</label>
                    <select id="vehicle_id" name="vehicle_id" required>
                        <option value="">Select car number</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= e((string) $vehicle['id']) ?>" <?= $form['vehicle_id'] === (string) $vehicle['id'] ? 'selected' : '' ?>>
                                <?= e((string) $vehicle['plate_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="odometer_reading">Current mileage</label>
                    <input id="odometer_reading" name="odometer_reading" type="number" min="0" step="1" value="<?= e($form['odometer_reading']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="odometer_picture">Current mileage picture</label>
                    <input id="odometer_picture" name="odometer_picture" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" capture="environment" data-photo-source-choice required>
                    <small class="muted-text">Take a clear picture showing the mileage entered above.</small>
                </div>

                <div class="form-field">
                    <label for="liters">Fuel purchase (liters)</label>
                    <input id="liters" name="liters" type="number" min="0" step="0.01" value="<?= e($form['liters']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="cost">Fuel purchase (amount)</label>
                    <input id="cost" name="cost" type="number" min="0" step="0.01" value="<?= e($form['cost']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="odometer_picture_2">Final fuel picture</label>
                    <input id="odometer_picture_2" name="odometer_picture_2" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" capture="environment" data-photo-source-choice required>
                </div>
            </div>

            <div class="form-actions">
                <button class="login-button" type="submit">
                    <span>Save fuel record</span>
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                </button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="management-panel management-panel--table" aria-labelledby="fuel-records-title">
    <div class="management-heading management-heading--compact">
        <div>
            <span class="section-kicker">Records</span>
            <h2 id="fuel-records-title">Fuel Records</h2>
        </div>
    </div>

    <?php if (!$fuelLogs): ?>
        <p class="empty-state">No fuel records available yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table--sales-trip">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Car number</th>
                        <th>Staff</th>
                        <th>Mileage</th>
                        <th>Liters</th>
                        <th>Amount</th>
                        <th>Pictures</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fuelLogs as $log): ?>
                        <tr>
                            <td><?= e(date('d M Y', strtotime((string) $log['fuel_date']))) ?></td>
                            <td><?= e((string) $log['plate_number']) ?></td>
                            <td><?= e((string) ($log['staff_name'] ?? '')) ?></td>
                            <td><?= e(number_format((float) $log['odometer_reading'])) ?></td>
                            <td><?= e(number_format((float) $log['liters'], 2)) ?></td>
                            <td><?= e(number_format((float) $log['cost'], 2)) ?></td>
                            <td>
                                <div class="table-actions">
                                    <?php if ((string) ($log['odometer_picture'] ?? '') !== ''): ?>
                                        <a class="secondary-button secondary-button--small" href="<?= e(app_url((string) $log['odometer_picture'])) ?>" data-media-viewer="image" data-media-title="Odometer picture 1">View 1</a>
                                    <?php endif; ?>
                                    <?php if ((string) ($log['odometer_picture_2'] ?? '') !== ''): ?>
                                        <a class="secondary-button secondary-button--small" href="<?= e(app_url((string) $log['odometer_picture_2'])) ?>" data-media-viewer="image" data-media-title="Odometer picture 2">View 2</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
