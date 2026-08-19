<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('admin');

$pageTitle = 'Vehicle Setup';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Setup', 'url' => app_url('setup.php')],
    ['label' => 'Vehicle Setup'],
];
$internalBackUrl=app_url('admin.php?view=setup');

$message = '';
$error = '';
$editVehicleId = max(0, (int) ($_GET['edit'] ?? 0));
$carNumber = '';
$status = 'active';

if ($editVehicleId > 0) {
    $statement = db()->prepare('SELECT id, plate_number, status FROM vehicles WHERE id = ? LIMIT 1');
    $statement->execute([$editVehicleId]);
    $vehicle = $statement->fetch();

    if ($vehicle) {
        $carNumber = (string) $vehicle['plate_number'];
        $status = (string) $vehicle['status'];
    } else {
        $editVehicleId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['form_action'] ?? 'save_vehicle');
    $postedVehicleId = max(0, (int) ($_POST['vehicle_id'] ?? 0));
    $carNumber = strtoupper(trim((string) ($_POST['car_number'] ?? '')));
    $postedStatus = (string) ($_POST['status'] ?? 'active');
    $status = in_array($postedStatus, ['active', 'inactive'], true) ? $postedStatus : 'active';

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete_vehicle') {
        if ($postedVehicleId <= 0) {
            $error = 'Select a valid vehicle to delete.';
        } else {
            try {
                $statement = db()->prepare('DELETE FROM vehicles WHERE id = ?');
                $statement->execute([$postedVehicleId]);
                $message = 'Vehicle deleted successfully.';
                $carNumber = '';
                $status = 'active';
                $editVehicleId = 0;
            } catch (PDOException $exception) {
                $error = 'This vehicle is already linked to records, so it cannot be deleted. You can deactivate it instead.';
            }
        }
    } elseif ($carNumber === '') {
        $error = 'Car number is required.';
    } else {
        try {
            if ($postedVehicleId > 0) {
                $statement = db()->prepare(
                    'UPDATE vehicles
                     SET plate_number = ?, vehicle_name = ?, status = ?
                     WHERE id = ?'
                );
                $statement->execute([$carNumber, $carNumber, $status, $postedVehicleId]);
                $message = 'Vehicle updated successfully.';
                $editVehicleId = 0;
            } else {
                $statement = db()->prepare(
                    'INSERT INTO vehicles (plate_number, vehicle_name, status)
                     VALUES (?, ?, ?)'
                );
                $statement->execute([$carNumber, $carNumber, $status]);
                $message = 'Vehicle added successfully.';
            }

            $carNumber = '';
            $status = 'active';
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $error = 'That car number already exists.';
            } else {
                $error = 'The vehicle could not be saved.';
            }
        }
    }
}

$vehicles = db()
    ->query('SELECT id, plate_number, status, created_at FROM vehicles ORDER BY status = "active" DESC, plate_number')
    ->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="vehicle-setup-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Setup</span>
            <h1 id="vehicle-setup-title">Vehicle Setup</h1>
            <p>Add the car numbers staff will select when starting a marketing trip.</p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-car-side"></i>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="record-form" method="post" action="<?= e(app_url('vehicle-setup.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="save_vehicle">
        <input type="hidden" name="vehicle_id" value="<?= e((string) $editVehicleId) ?>">

        <div class="form-grid">
            <div class="form-field">
                <label for="car_number">Car number</label>
                <input id="car_number" name="car_number" type="text" value="<?= e($carNumber) ?>" required>
            </div>

            <div class="form-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button class="login-button" type="submit">
                <span><?= $editVehicleId > 0 ? 'Update vehicle' : 'Save vehicle' ?></span>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            </button>
            <?php if ($editVehicleId > 0): ?>
                <a class="secondary-button" href="<?= e(app_url('vehicle-setup.php')) ?>">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="management-panel management-panel--table" aria-labelledby="vehicle-list-title">
    <div class="management-heading management-heading--compact">
        <div>
            <span class="section-kicker">Lookup</span>
            <h2 id="vehicle-list-title">Vehicles</h2>
        </div>
    </div>

    <?php if (!$vehicles): ?>
        <p class="empty-state">No vehicle numbers available yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table--compact">
                <thead>
                    <tr>
                        <th>Car number</th>
                        <th>Status</th>
                        <th>Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <tr>
                            <td><?= e((string) $vehicle['plate_number']) ?></td>
                            <td><?= e(ucfirst((string) $vehicle['status'])) ?></td>
                            <td><?= e(date('d M Y', strtotime((string) $vehicle['created_at']))) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a class="secondary-button secondary-button--small" href="<?= e(app_url('vehicle-setup.php?edit=' . (int) $vehicle['id'])) ?>">Edit</a>
                                    <form method="post" action="<?= e(app_url('vehicle-setup.php')) ?>" data-confirm-title="Delete vehicle" data-confirm-message="Delete this vehicle number?">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="form_action" value="delete_vehicle">
                                        <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicle['id']) ?>">
                                        <button class="secondary-button secondary-button--small" type="submit">Delete</button>
                                    </form>
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
