<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('reports');
ensure_attendance_schema();

$pageTitle = 'Attendance Report';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Reports', 'url' => app_url('reports.php')],
    ['label' => 'Attendance Report'],
];
$internalBackUrl = requested_return_url(app_url('reports.php'));

$isAdminReport = is_admin_user();
$currentStaffId = current_staff_id() ?: 0;
$selectedDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? '')) ? (string) $_GET['date_from'] : '';
$selectedDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? '')) ? (string) $_GET['date_to'] : '';
$selectedStatus = in_array((string) ($_GET['status'] ?? ''), ['present', 'absent', 'late', 'excused'], true) ? (string) $_GET['status'] : '';
$selectedStaffId = $isAdminReport ? max(0, (int) ($_GET['staff_id'] ?? 0)) : $currentStaffId;

$where = [];
$params = [];
if($selectedDateFrom!==''&&$selectedDateTo!==''){$where[]='attendance_services.service_date BETWEEN ? AND ?';array_push($params,$selectedDateFrom,$selectedDateTo);}
elseif($selectedDateFrom!==''){$where[]='attendance_services.service_date >= ?';$params[]=$selectedDateFrom;}
elseif($selectedDateTo!==''){$where[]='attendance_services.service_date <= ?';$params[]=$selectedDateTo;}

if ($selectedStatus !== '') {
    $where[] = 'attendance_records.status = ?';
    $params[] = $selectedStatus;
}

if ($selectedStaffId > 0) {
    $where[] = 'attendance_records.staff_id = ?';
    $params[] = $selectedStaffId;
}

$sql = "SELECT attendance_records.*, attendance_services.service_name, attendance_services.service_type,
            attendance_services.service_date, attendance_services.start_time, attendance_services.end_time,
            staff.staff_code, staff.full_name, staff.phone, users.full_name AS marked_by_name
        FROM attendance_records
        INNER JOIN attendance_services ON attendance_services.id = attendance_records.service_id
        INNER JOIN staff ON staff.id = attendance_records.staff_id
        LEFT JOIN users ON users.id = attendance_records.marked_by_user_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY attendance_services.service_date DESC, attendance_records.marked_at DESC';
$statement = db()->prepare($sql);
$statement->execute($params);
$records = $statement->fetchAll();

$staffList = $isAdminReport
    ? db()->query('SELECT id, staff_code, full_name FROM staff WHERE is_active = 1 ORDER BY full_name')->fetchAll()
    : [];

$totalPresent = 0;
$totalLate = 0;
$totalOther = 0;
foreach ($records as $record) {
    if ($record['status'] === 'present') {
        $totalPresent++;
    } elseif ($record['status'] === 'late') {
        $totalLate++;
    } else {
        $totalOther++;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="attendance-report-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Reports</span>
            <h1 id="attendance-report-title">Attendance Report</h1>
            <p>Review staff attendance records by date, staff, and status.</p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-clipboard-list"></i>
        </div>
    </div>

    <form class="record-form attendance-filter-form" method="get" action="<?= e(app_url('attendance-report.php')) ?>" data-auto-submit-filter>
        <input type="hidden" name="filter_applied" value="1">
        <input type="hidden" name="return_to" value="<?= e($internalBackUrl) ?>">
        <div class="form-grid">
            <div class="form-field">
                <label for="date_from">Date from</label>
                <input id="date_from" name="date_from" type="date" value="<?= e($selectedDateFrom) ?>">
            </div>
            <div class="form-field">
                <label for="date_to">Date to</label>
                <input id="date_to" name="date_to" type="date" value="<?= e($selectedDateTo) ?>">
            </div>
            <div class="form-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All Status</option>
                    <?php foreach (['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($isAdminReport): ?>
                <div class="form-field">
                    <label for="staff_id">Staff</label>
                    <select id="staff_id" name="staff_id">
                        <option value="0">All Staff</option>
                        <?php foreach ($staffList as $staff): ?>
                            <option value="<?= (int) $staff['id'] ?>" <?= $selectedStaffId === (int) $staff['id'] ? 'selected' : '' ?>>
                                <?= e((string) $staff['full_name']) ?> / <?= e((string) $staff['staff_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <noscript><button class="login-button" type="submit"><span>Filter</span><i class="fa-solid fa-filter" aria-hidden="true"></i></button></noscript>
            <a class="secondary-button" href="<?= e(app_url('attendance-report.php?return_to='.rawurlencode($internalBackUrl))) ?>">Clear</a>
        </div>
    </form>

    <div class="attendance-summary-strip">
        <span><strong><?= count($records) ?></strong> records</span>
        <span><strong><?= $totalPresent ?></strong> present</span>
        <span><strong><?= $totalLate ?></strong> late</span>
        <span><strong><?= $totalOther ?></strong> other</span>
    </div>
</section>

<section class="management-panel management-panel--table" aria-labelledby="attendance-records-title">
    <div class="management-heading management-heading--compact">
        <div>
            <span class="section-kicker">Records</span>
            <h2 id="attendance-records-title">Attendance Records</h2>
        </div>
    </div>

    <?php if (!$records): ?>
        <p class="empty-state">No attendance records found for the selected filters.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table--attendance">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Session</th>
                        <th>Staff</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>GPS</th>
                        <th>Marked At</th>
                        <th>Marked By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?= e(date('l, d M Y', strtotime((string) $record['service_date']))) ?></td>
                            <td>
                                <strong><?= e((string) $record['service_name']) ?></strong><br>
                                <small><?= e((string) $record['service_type']) ?></small>
                            </td>
                            <td>
                                <strong><?= e((string) $record['full_name']) ?></strong><br>
                                <small><?= e((string) $record['staff_code']) ?></small>
                            </td>
                            <td><?= e((string) ($record['phone'] ?? '')) ?></td>
                            <td><span class="status-pill status-pill--<?= e((string) $record['status']) ?>"><?= e(ucfirst((string) $record['status'])) ?></span></td>
                            <td>
                                <?= (int) $record['location_verified'] === 1 ? 'Verified' : 'Manual' ?>
                                <?php if ($record['distance_meters'] !== null): ?>
                                    <br><small><?= e(number_format((float) $record['distance_meters'], 1)) ?>m</small>
                                <?php endif; ?>
                            </td>
                            <td><?= e(date('d M Y H:i', strtotime((string) $record['marked_at']))) ?></td>
                            <td><?= e((string) ($record['marked_by_name'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
