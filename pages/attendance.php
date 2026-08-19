<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('attendance');
ensure_attendance_schema();

$pageTitle = 'Staff Attendance';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Staff Attendance'],
];
$internalBackUrl=requested_return_url(app_url('admin.php'));

$activeService = attendance_active_service_for_today();
$currentStaffId = current_staff_id();
$locationSettings = attendance_location_settings();
$locationReady = attendance_location_is_configured($locationSettings);
$alreadyMarked = $activeService && $currentStaffId ? attendance_staff_record_for_service((int) $activeService['id'], $currentStaffId) : null;
$isAdminAttendance = is_admin_user();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel attendance-marking-panel" aria-labelledby="attendance-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Working Space</span>
            <h1 id="attendance-title">Staff Attendance</h1>
            <p><?= $isAdminAttendance ? 'Search staff and mark attendance for the selected session.' : 'Mark your attendance when you are within the saved attendance location.' ?></p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
    </div>

    <div class="attendance-actions-row">
        <?php if (is_admin_user()): ?>
            <a class="secondary-button" href="<?= e(app_url('attendance-setup.php')) ?>">
                <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                <span>Setup</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="attendance-service-card">
        <?php if ($activeService): ?>
            <strong><?= e((string) $activeService['service_name']) ?></strong>
            <small><?= e((string) $activeService['service_type']) ?> / <?= e(date('d M Y', strtotime((string) $activeService['service_date']))) ?></small>
            <p><?= e(substr((string) ($activeService['start_time'] ?? ''), 0, 5) ?: 'Open') ?> - <?= e(substr((string) ($activeService['end_time'] ?? ''), 0, 5) ?: 'Open') ?></p>
            <input id="attendanceServiceId" type="hidden" value="<?= (int) $activeService['id'] ?>">
        <?php else: ?>
            <strong>No active session today</strong>
            <small>Create or activate a session in Attendance Setup before marking attendance.</small>
        <?php endif; ?>
    </div>

    <?php if (!$activeService): ?>
        <p class="empty-state">No active attendance session is available for today.</p>
    <?php elseif (!$locationReady): ?>
        <p class="profile-message is-error" data-persistent-message>Attendance location has not been configured yet.</p>
    <?php elseif ($isAdminAttendance): ?>
        <div class="form-field attendance-search-field">
            <label for="attendanceStaffSearch">Search Staff</label>
            <input
                id="attendanceStaffSearch"
                type="search"
                placeholder="Search staff name, phone, email, staff ref..."
                autocomplete="off"
                data-attendance-search
                data-search-endpoint="<?= e(app_url('attendance-search-staff.php')) ?>"
            >
        </div>

        <div id="attendanceMessage" class="attendance-live-message" aria-live="polite"></div>

        <div class="table-wrap attendance-results-wrap">
            <table class="data-table data-table--attendance">
                <thead>
                    <tr>
                        <th>Staff ref</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="attendanceResults">
                    <tr>
                        <td colspan="5" class="empty-state">Search for a staff member to mark attendance.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <section class="attendance-self-card" data-member-attendance-location data-mark-endpoint="<?= e(app_url('attendance-mark-present.php')) ?>">
            <p><?= $alreadyMarked ? 'Your attendance has already been marked for this session.' : 'Use GPS to mark your attendance for today.' ?></p>
            <button
                class="login-button"
                type="button"
                data-member-attendance-button
                data-service-id="<?= (int) $activeService['id'] ?>"
                <?= $alreadyMarked ? 'disabled' : '' ?>
            >
                <span><?= $alreadyMarked ? 'Already marked' : 'Mark my attendance' ?></span>
                <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i>
            </button>
            <div class="attendance-live-message" data-member-attendance-message aria-live="polite"></div>
            <div class="attendance-location-result" data-member-attendance-result hidden></div>
        </section>
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
