<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('admin');
ensure_attendance_schema();

$pageTitle = 'Attendance Setup';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Setup', 'url' => app_url('setup.php')],
    ['label' => 'Attendance Setup'],
];
$internalBackUrl=app_url('admin.php?view=setup');

$message = '';
$error = '';
$editId = max(0, (int) ($_GET['edit'] ?? 0));
$serviceToEdit = null;

if (isset($_GET['delete'])) {
    $deleteId = max(0, (int) $_GET['delete']);

    if ($deleteId > 0) {
        $delete = db()->prepare('DELETE FROM attendance_services WHERE id = ?');
        $delete->execute([$deleteId]);
        header('Location: ' . app_url('attendance-setup.php?deleted=1'));
        exit;
    }
}

if (isset($_GET['toggle_status'])) {
    $toggleId = max(0, (int) $_GET['toggle_status']);

    if ($toggleId > 0) {
        db()->prepare(
            "UPDATE attendance_services
             SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END
             WHERE id = ?"
        )->execute([$toggleId]);
        header('Location: ' . app_url('attendance-setup.php?saved=1'));
        exit;
    }
}

if ($editId > 0) {
    $statement = db()->prepare('SELECT * FROM attendance_services WHERE id = ? LIMIT 1');
    $statement->execute([$editId]);
    $serviceToEdit = $statement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $formAction = (string) ($_POST['form_action'] ?? 'save_session');

    if ($formAction === 'save_weekday_schedule') {
        $name = trim((string) ($_POST['weekday_service_name'] ?? ''));
        $type = trim((string) ($_POST['weekday_service_type'] ?? ''));
        $start = trim((string) ($_POST['weekday_start_time'] ?? ''));
        $end = trim((string) ($_POST['weekday_end_time'] ?? ''));
        $active = isset($_POST['weekday_active']) ? 1 : 0;

        if (!verify_csrf_token($token)) {
            $error = 'Your session expired. Please try again.';
        } elseif ($name === '' || $type === '') {
            $error = 'Enter the weekday session name and type.';
        } elseif ($start !== '' && $end !== '' && $end <= $start) {
            $error = 'The weekday end time must be later than the start time.';
        } else {
            $id = (int) (db()->query('SELECT id FROM attendance_weekday_schedule ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
            if ($id > 0) {
                db()->prepare('UPDATE attendance_weekday_schedule SET service_name=?,service_type=?,start_time=?,end_time=?,is_active=?,updated_by_user_id=? WHERE id=?')
                    ->execute([$name,$type,$start !== '' ? $start : null,$end !== '' ? $end : null,$active,current_user_id(),$id]);
            } else {
                db()->prepare('INSERT INTO attendance_weekday_schedule (service_name,service_type,start_time,end_time,is_active,updated_by_user_id) VALUES (?,?,?,?,?,?)')
                    ->execute([$name,$type,$start !== '' ? $start : null,$end !== '' ? $end : null,$active,current_user_id()]);
            }
            header('Location: ' . app_url('attendance-setup.php?weekday_saved=1'));
            exit;
        }
    }

    if ($formAction === 'save_session') {
    $serviceId = max(0, (int) ($_POST['service_id'] ?? 0));
    $serviceName = trim((string) ($_POST['service_name'] ?? ''));
    $serviceType = trim((string) ($_POST['service_type'] ?? ''));
    $serviceDate = trim((string) ($_POST['service_date'] ?? ''));
    $startTime = trim((string) ($_POST['start_time'] ?? ''));
    $endTime = trim((string) ($_POST['end_time'] ?? ''));
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    $status = in_array((string) ($_POST['status'] ?? 'active'), ['active', 'inactive'], true) ? (string) $_POST['status'] : 'active';

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($serviceName === '' || $serviceType === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate)) {
        $error = 'Enter the service name, type, and date.';
    } else {
        try {
            if ($isDefault === 1) {
                db()->prepare('UPDATE attendance_services SET is_default = 0 WHERE service_date = ?')->execute([$serviceDate]);
            }

            if ($serviceId > 0) {
                $statement = db()->prepare(
                    'UPDATE attendance_services
                     SET service_name = ?, service_type = ?, service_date = ?, start_time = ?, end_time = ?,
                         is_default = ?, status = ?
                     WHERE id = ?'
                );
                $statement->execute([
                    $serviceName,
                    $serviceType,
                    $serviceDate,
                    $startTime !== '' ? $startTime : null,
                    $endTime !== '' ? $endTime : null,
                    $isDefault,
                    $status,
                    $serviceId,
                ]);
            } else {
                $statement = db()->prepare(
                    'INSERT INTO attendance_services
                        (service_name, service_type, service_date, start_time, end_time, is_default, status, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([
                    $serviceName,
                    $serviceType,
                    $serviceDate,
                    $startTime !== '' ? $startTime : null,
                    $endTime !== '' ? $endTime : null,
                    $isDefault,
                    $status,
                    current_user_id(),
                ]);
            }

            header('Location: ' . app_url('attendance-setup.php?saved=1'));
            exit;
        } catch (Throwable $exception) {
            $error = 'Attendance session could not be saved. Check if the same type already exists for that date.';
        }
    }
    }
}

if (isset($_GET['saved'])) {
    $message = 'Attendance setup saved successfully.';
} elseif (isset($_GET['weekday_saved'])) {
    $message = 'Monday-Friday attendance schedule saved successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'Attendance session deleted successfully.';
}

$locationSettings = attendance_location_settings();
$locationConfigured = attendance_location_is_configured($locationSettings);
$weekdaySchedule = db()->query('SELECT * FROM attendance_weekday_schedule ORDER BY id ASC LIMIT 1')->fetch() ?: [];
$services = db()
    ->query(
        'SELECT attendance_services.*, users.full_name AS created_by_name
         FROM attendance_services
         LEFT JOIN users ON users.id = attendance_services.created_by_user_id
         ORDER BY service_date DESC, start_time DESC, id DESC
         LIMIT 80'
    )
    ->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="attendance-setup-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Setup</span>
            <h1 id="attendance-setup-title">Attendance Setup</h1>
            <p>Create attendance sessions and save the GPS point staff must be near when marking attendance.</p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-calendar-plus"></i>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="attendance-location-card" data-attendance-location-admin data-location-endpoint="<?= e(app_url('attendance-location.php')) ?>">
        <div class="management-heading management-heading--compact">
            <div>
                <span class="section-kicker">GPS Location</span>
                <h2>Attendance Location</h2>
                <p>Stand at the attendance point and save the location used for staff GPS verification.</p>
            </div>
        </div>

        <div class="attendance-location-grid">
            <div class="attendance-location-stat">
                <span>Status</span>
                <strong><?= $locationConfigured ? 'Configured' : 'Not set' ?></strong>
            </div>
            <div class="attendance-location-stat">
                <span>Latitude</span>
                <strong data-location-latitude><?= e((string) ($locationSettings['latitude'] ?? '')) ?></strong>
            </div>
            <div class="attendance-location-stat">
                <span>Longitude</span>
                <strong data-location-longitude><?= e((string) ($locationSettings['longitude'] ?? '')) ?></strong>
            </div>
            <div class="attendance-location-stat">
                <span>Radius</span>
                <strong><span data-location-radius-label><?= (int) ($locationSettings['attendance_radius'] ?? 100) ?></span> metres</strong>
            </div>
        </div>

        <div class="form-grid attendance-location-controls">
            <div class="form-field">
                <label for="attendanceRadius">Attendance Radius</label>
                <input id="attendanceRadius" type="number" min="20" max="1000" step="1" value="<?= (int) ($locationSettings['attendance_radius'] ?? 100) ?>" data-attendance-radius>
            </div>
            <div class="form-field attendance-location-actions">
                <label>&nbsp;</label>
                <button class="login-button" type="button" data-attendance-save-location>
                    <span>Use GPS</span>
                    <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="attendance-live-message" data-location-message aria-live="polite"></div>
        <div class="attendance-location-result" data-location-result hidden></div>
    </section>

    <form class="record-form record-form--section" method="post" action="<?= e(app_url('attendance-setup.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="save_weekday_schedule">
        <div class="management-heading management-heading--compact">
            <div>
                <span class="section-kicker">Recurring schedule</span>
                <h2>Monday–Friday Workplace Attendance</h2>
                <p>Set this once. Attendance will be available automatically every weekday.</p>
            </div>
        </div>
        <div class="form-grid">
            <div class="form-field">
                <label for="weekday_service_name">Session name</label>
                <input id="weekday_service_name" name="weekday_service_name" type="text" value="<?= e((string) ($weekdaySchedule['service_name'] ?? 'Daily Attendance')) ?>" required>
            </div>
            <div class="form-field">
                <label for="weekday_service_type">Type</label>
                <input id="weekday_service_type" name="weekday_service_type" type="text" value="<?= e((string) ($weekdaySchedule['service_type'] ?? 'Workday')) ?>" required>
            </div>
            <div class="form-field">
                <label for="weekday_start_time">Start time</label>
                <input id="weekday_start_time" name="weekday_start_time" type="time" value="<?= e(substr((string) ($weekdaySchedule['start_time'] ?? ''), 0, 5)) ?>" data-time-picker>
            </div>
            <div class="form-field">
                <label for="weekday_end_time">End time</label>
                <input id="weekday_end_time" name="weekday_end_time" type="time" value="<?= e(substr((string) ($weekdaySchedule['end_time'] ?? ''), 0, 5)) ?>" data-time-picker>
            </div>
        </div>
        <label class="checkbox-row">
            <input type="checkbox" name="weekday_active" value="1" <?= (int) ($weekdaySchedule['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
            <span>Enable automatically every Monday through Friday</span>
        </label>
        <div class="form-actions">
            <button class="login-button" type="submit"><span>Save weekday schedule</span><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i></button>
        </div>
    </form>

    <form class="record-form record-form--section" method="post" action="<?= e(app_url('attendance-setup.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="save_session">
        <input type="hidden" name="service_id" value="<?= e((string) ($serviceToEdit['id'] ?? 0)) ?>">

        <div class="form-grid">
            <div class="form-field">
                <label for="service_name">Session name</label>
                <input id="service_name" name="service_name" type="text" value="<?= e((string) ($serviceToEdit['service_name'] ?? 'Daily Attendance')) ?>" required>
            </div>
            <div class="form-field">
                <label for="service_type">Type</label>
                <input id="service_type" name="service_type" type="text" value="<?= e((string) ($serviceToEdit['service_type'] ?? 'General')) ?>" required>
            </div>
            <div class="form-field">
                <label for="service_date">Date</label>
                <input id="service_date" name="service_date" type="date" value="<?= e((string) ($serviceToEdit['service_date'] ?? date('Y-m-d'))) ?>" data-date-day-source required>
                <small data-date-day-label aria-live="polite"></small>
            </div>
            <div class="form-field">
                <label for="start_time">Start time</label>
                <input id="start_time" name="start_time" type="time" value="<?= e(substr((string) ($serviceToEdit['start_time'] ?? ''), 0, 5)) ?>" data-time-picker>
            </div>
            <div class="form-field">
                <label for="end_time">End time</label>
                <input id="end_time" name="end_time" type="time" value="<?= e(substr((string) ($serviceToEdit['end_time'] ?? ''), 0, 5)) ?>" data-time-picker>
            </div>
            <div class="form-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php $currentStatus = (string) ($serviceToEdit['status'] ?? 'active'); ?>
                    <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <label class="checkbox-row">
            <input type="checkbox" name="is_default" value="1" <?= (int) ($serviceToEdit['is_default'] ?? 1) === 1 ? 'checked' : '' ?>>
            <span>Make this the default session for the selected date</span>
        </label>

        <div class="form-actions">
            <button class="login-button" type="submit">
                <span><?= $serviceToEdit ? 'Update session' : 'Save session' ?></span>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            </button>
            <?php if ($serviceToEdit): ?>
                <a class="secondary-button" href="<?= e(app_url('attendance-setup.php')) ?>">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="management-panel management-panel--table" aria-labelledby="attendance-sessions-title">
    <div class="management-heading management-heading--compact">
        <div>
            <span class="section-kicker">Sessions</span>
            <h2 id="attendance-sessions-title">Attendance Sessions</h2>
        </div>
    </div>

    <?php if (!$services): ?>
        <p class="empty-state">No attendance sessions have been created yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table--attendance">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Session</th>
                        <th>Type</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?= e(date('l, d M Y', strtotime((string) $service['service_date']))) ?></td>
                            <td><?= e((string) $service['service_name']) ?></td>
                            <td><?= e((string) $service['service_type']) ?></td>
                            <td><?= e(substr((string) ($service['start_time'] ?? ''), 0, 5) ?: '-') ?> - <?= e(substr((string) ($service['end_time'] ?? ''), 0, 5) ?: '-') ?></td>
                            <td><?= e(ucfirst((string) $service['status'])) ?><?= (int) $service['is_default'] === 1 ? ' / Default' : '' ?></td>
                            <td>
                                <div class="table-actions">
                                    <a class="secondary-button secondary-button--small" href="<?= e(app_url('attendance-setup.php?edit=' . (int) $service['id'])) ?>">Edit</a>
                                    <a class="secondary-button secondary-button--small" href="<?= e(app_url('attendance-setup.php?toggle_status=' . (int) $service['id'])) ?>"><?= $service['status'] === 'active' ? 'Deactivate' : 'Activate' ?></a>
                                    <a class="secondary-button secondary-button--small" href="<?= e(app_url('attendance-setup.php?delete=' . (int) $service['id'])) ?>" onclick="return confirm('Delete this attendance session?')">Delete</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<script>
    window.attendanceConfig = {
        csrfToken: <?= json_encode(csrf_token()) ?>,
        markEndpoint: <?= json_encode(app_url('attendance-mark-present.php')) ?>,
    };
</script>
<script src="<?= e(asset_url('assets/js/attendance.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
