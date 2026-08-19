<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('admin');

$pageTitle = 'Role Setup';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Setup', 'url' => app_url('setup.php')],
    ['label' => 'Role Setup'],
];
$message = '';
$error = '';
$editRoleId = max(0, (int) ($_GET['edit'] ?? 0));
$roleName = '';
$status = '1';

if ($editRoleId > 0) {
    $editStatement = db()->prepare('SELECT id, role_name, is_active FROM staff_roles WHERE id = ? LIMIT 1');
    $editStatement->execute([$editRoleId]);
    $editRole = $editStatement->fetch();

    if ($editRole) {
        $roleName = (string) $editRole['role_name'];
        $status = (string) (int) $editRole['is_active'];
    } else {
        $editRoleId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['form_action'] ?? 'save_role');
    $roleName = trim((string) ($_POST['role_name'] ?? ''));
    $status = (string) ($_POST['status'] ?? '1');
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);

        if ($roleId <= 0) {
            $error = 'Select a valid role to delete.';
        } else {
            $statement = db()->prepare('DELETE FROM staff_roles WHERE id = ?');
            $statement->execute([$roleId]);
            $message = 'Role deleted successfully.';
            $roleName = '';
            $status = '1';
            $editRoleId = 0;
        }
    } elseif ($roleName === '') {
        $error = 'Role name is required.';
    } else {
        try {
            $postedRoleId = (int) ($_POST['role_id'] ?? 0);

            if ($postedRoleId > 0) {
                $statement = db()->prepare('UPDATE staff_roles SET role_name = ?, is_active = ? WHERE id = ?');
                $statement->execute([$roleName, $status === '0' ? 0 : 1, $postedRoleId]);
                $message = 'Role updated successfully.';
                $editRoleId = 0;
            } else {
                $statement = db()->prepare('INSERT INTO staff_roles (role_name, is_active) VALUES (?, ?)');
                $statement->execute([$roleName, $status === '0' ? 0 : 1]);
                $message = 'Role added successfully.';
            }

            $roleName = '';
            $status = '1';
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $error = 'That role already exists.';
            } else {
                $error = 'The role could not be saved.';
            }
        }
    }
}

$roles = db()
    ->query('SELECT id, role_name, is_active, created_at FROM staff_roles ORDER BY role_name')
    ->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="role-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Setup</span>
            <h1 id="role-title">Role Setup</h1>
            <p>Create the role list used by Staff Setup.</p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-user-tag"></i>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="record-form" method="post" action="<?= e(app_url('role-setup.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="save_role">
        <input type="hidden" name="role_id" value="<?= e((string) $editRoleId) ?>">

        <div class="form-grid">
            <div class="form-field">
                <label for="role_name">Role name</label>
                <input id="role_name" name="role_name" type="text" value="<?= e($roleName) ?>" required>
            </div>

            <div class="form-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button class="login-button" type="submit">
                <span><?= $editRoleId > 0 ? 'Update role' : 'Save role' ?></span>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            </button>
            <?php if ($editRoleId > 0): ?>
                <a class="secondary-button" href="<?= e(app_url('role-setup.php')) ?>">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="management-panel management-panel--table" aria-labelledby="role-list-title">
    <div class="management-heading management-heading--compact">
        <div>
            <span class="section-kicker">Lookup</span>
            <h2 id="role-list-title">Roles</h2>
        </div>
    </div>

    <?php if (!$roles): ?>
        <p class="empty-state">No roles available yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table--compact">
                <thead>
                    <tr>
                        <th>Role name</th>
                        <th>Status</th>
                        <th>Date added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><?= e((string) $role['role_name']) ?></td>
                            <td>
                                <span class="status-badge <?= (int) $role['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                    <?= (int) $role['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= e(date('d M Y', strtotime((string) $role['created_at']))) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a class="action-button" href="<?= e(app_url('role-setup.php?edit=' . (int) $role['id'])) ?>">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                        <span>Edit</span>
                                    </a>
                                    <form method="post" action="<?= e(app_url('role-setup.php')) ?>" data-confirm-title="Delete role?" data-confirm-message="This will permanently remove this role from Role Setup. Staff records that used it may no longer show that role.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="form_action" value="delete_role">
                                        <input type="hidden" name="role_id" value="<?= e((string) $role['id']) ?>">
                                        <button class="action-button is-danger" type="submit">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                            <span>Delete</span>
                                        </button>
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
