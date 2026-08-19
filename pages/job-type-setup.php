<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('admin');
ensure_job_type_schema();

$pageTitle = 'Customer Type Setup';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Setup', 'url' => app_url('setup.php')],
    ['label' => 'Customer Type Setup'],
];
$message = '';
$error = '';
$editId = max(0, (int)($_GET['edit'] ?? 0));
$name = '';
$status = '1';

if ($editId) {
    $statement = db()->prepare('SELECT * FROM job_types WHERE id=?');
    $statement->execute([$editId]);
    $row = $statement->fetch();
    if ($row) {
        $name = (string)$row['job_type_name'];
        $status = (string)(int)$row['is_active'];
    } else {
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['job_type_name'] ?? ''));
    $status = (string)($_POST['status'] ?? '1');
    $id = max(0, (int)($_POST['job_type_id'] ?? 0));
    $action = (string)($_POST['form_action'] ?? 'save');

    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete' && $id) {
        try {
            db()->prepare('DELETE FROM job_types WHERE id=?')->execute([$id]);
            $message = 'Job type deleted successfully.';
        } catch (PDOException $exception) {
            $error = 'This customer type is already used and cannot be deleted. You can make it inactive instead.';
        }
    } elseif ($name === '') {
        $error = 'Job type name is required.';
    } else {
        try {
            if ($id) {
                db()->prepare('UPDATE job_types SET job_type_name=?,is_active=? WHERE id=?')
                    ->execute([$name, $status === '0' ? 0 : 1, $id]);
                db()->prepare('UPDATE customers SET job_type=? WHERE job_type_id=?')->execute([$name, $id]);
                $message = 'Job type updated successfully.';
            } else {
                db()->prepare('INSERT INTO job_types (job_type_name,is_active) VALUES (?,?)')
                    ->execute([$name, $status === '0' ? 0 : 1]);
                $message = 'Job type added successfully.';
            }
            $name = '';
            $status = '1';
            $editId = 0;
        } catch (PDOException $exception) {
            $error = 'That customer type already exists.';
        }
    }
}

$rows = db()->query(
    'SELECT jt.*,COUNT(c.id) customer_count
     FROM job_types jt
     LEFT JOIN customers c ON c.job_type_id=jt.id
     GROUP BY jt.id
     ORDER BY jt.job_type_name'
)->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker">Setup</span><h1>Customer Type Setup</h1><p>Manage the customer type choices used in Sales and customer registration.</p></div><div class="management-icon"><i class="fa-solid fa-briefcase"></i></div></div>
    <?php if ($message): ?><div class="profile-message is-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="profile-message is-error"><?= e($error) ?></div><?php endif; ?>
    <form class="record-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="save">
        <input type="hidden" name="job_type_id" value="<?= $editId ?>">
        <div class="form-grid">
            <div class="form-field"><label for="job_type_name">Customer Type</label><input id="job_type_name" name="job_type_name" value="<?= e($name) ?>" required></div>
            <div class="form-field"><label for="status">Status</label><select id="status" name="status" data-popup-select><option value="1" <?= $status === '1' ? 'selected' : '' ?>>Active</option><option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactive</option></select></div>
        </div>
        <div class="form-actions"><button class="login-button"><?= $editId ? 'Update' : 'Save' ?> Customer Type</button><?php if ($editId): ?><a class="secondary-button" href="<?= e(app_url('job-type-setup.php')) ?>">Cancel</a><?php endif; ?></div>
    </form>
</section>
<section class="management-panel management-panel--table">
    <div class="management-heading management-heading--compact"><div><h2>Customer Types</h2><p>Inactive options remain on old records but are hidden from new registrations and sales.</p></div></div>
    <?php if (!$rows): ?><p class="empty-state">No customer types added yet.</p><?php else: ?>
    <div class="table-wrap"><table class="data-table data-table--compact"><thead><tr><th>Customer Type</th><th>Customers</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><td><?= e((string)$row['job_type_name']) ?></td><td><?= number_format((int)$row['customer_count']) ?></td><td><span class="status-badge <?= $row['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><div class="table-actions"><a class="action-button" href="<?= e(app_url('job-type-setup.php?edit='.(int)$row['id'])) ?>">Edit</a><form method="post" data-confirm-title="Delete customer type?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_action" value="delete"><input type="hidden" name="job_type_id" value="<?= (int)$row['id'] ?>"><button class="action-button is-danger">Delete</button></form></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
