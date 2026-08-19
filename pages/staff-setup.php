<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('admin');
sync_staff_login_phones();

$pageTitle = 'Staff Setup';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Staff Setup'],
];
$internalBackUrl=app_url('admin.php?view=setup');

$message = '';
$error = '';
$editStaffId = max(0, (int) ($_GET['edit'] ?? 0));
$viewStaffId = max(0, (int) ($_GET['view'] ?? 0));
$viewStaff = null;
$formData = [
    'staff_id' => '',
    'staff_ref_no' => '',
    'name' => '',
    'email' => '',
    'phone' => '',
    'ghana_card_no' => '',
    'role_id' => '',
    'status' => '1',
];

$nextStaffRefNo = next_staff_ref_no();

if ($editStaffId > 0) {
    $editStatement = db()->prepare(
        'SELECT id, staff_code, full_name, email, phone, ghana_card_no, role_id, is_active
         FROM staff WHERE id = ? LIMIT 1'
    );
    $editStatement->execute([$editStaffId]);
    $editStaff = $editStatement->fetch();

    if ($editStaff) {
        $formData = [
            'staff_id' => (string) $editStaff['id'],
            'staff_ref_no' => (string) $editStaff['staff_code'],
            'name' => (string) $editStaff['full_name'],
            'email' => (string) ($editStaff['email'] ?? ''),
            'phone' => (string) ($editStaff['phone'] ?? ''),
            'ghana_card_no' => (string) ($editStaff['ghana_card_no'] ?? ''),
            'role_id' => (string) ($editStaff['role_id'] ?? ''),
            'status' => (string) (int) $editStaff['is_active'],
        ];
    } else {
        $editStaffId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['form_action'] ?? 'save_staff');
    $postedStaffId = (int) ($_POST['staff_id'] ?? 0);
    $formData['staff_id'] = $postedStaffId > 0 ? (string) $postedStaffId : '';
    $formData['staff_ref_no'] = trim((string) ($_POST['staff_ref_no'] ?? ''));
    $formData['name'] = trim((string) ($_POST['name'] ?? ''));
    $formData['email'] = normalize_email_address((string) ($_POST['email'] ?? ''));
    $formData['phone'] = normalize_phone_number((string) ($_POST['phone'] ?? ''));
    $formData['ghana_card_no'] = normalize_ghana_card_no((string) ($_POST['ghana_card_no'] ?? ''));
    $formData['role_id'] = (string) ($_POST['role_id'] ?? '');
    $formData['status'] = (string) ($_POST['status'] ?? '1');
    $token = (string) ($_POST['csrf_token'] ?? '');
    $profileImagePath = null;
    $roleName = '';

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete_staff') {
        if ($postedStaffId <= 0) {
            $error = 'Select a valid staff record to delete.';
        } else {
            $userStatement = db()->prepare('SELECT user_id FROM staff WHERE id = ? LIMIT 1');
            $userStatement->execute([$postedStaffId]);
            $linkedUserId = (int) ($userStatement->fetchColumn() ?: 0);

            $statement = db()->prepare('DELETE FROM staff WHERE id = ?');
            $statement->execute([$postedStaffId]);

            if ($linkedUserId > 0) {
                $userDelete = db()->prepare('DELETE FROM users WHERE id = ? AND role = ?');
                $userDelete->execute([$linkedUserId, 'staff']);
            }

            $message = 'Staff record deleted successfully.';
            $formData = [
                'staff_id' => '',
                'staff_ref_no' => '',
                'name' => '',
                'email' => '',
                'phone' => '',
                'ghana_card_no' => '',
                'role_id' => '',
                'status' => '1',
            ];
            $editStaffId = 0;
        }
    } elseif ($formData['name'] === '') {
        $error = 'Staff name is required.';
    } elseif ($formData['email'] === '') {
        $error = 'Staff email is required because it will be used for login.';
    } elseif ($formData['email'] !== '' && !is_valid_email_address($formData['email'])) {
        $error = 'Enter a valid email address.';
    } elseif ($formData['phone'] !== '' && !is_valid_phone_number($formData['phone'])) {
        $error = 'Enter a valid Ghana phone number, for example 0240000000 or +233240000000.';
    } elseif ($formData['ghana_card_no'] !== '' && !is_valid_ghana_card_no($formData['ghana_card_no'])) {
        $error = 'Enter a valid Ghana Card number in the format GHA-123456789-1.';
    } else {
        if ($formData['role_id'] !== '') {
            $roleStatement = db()->prepare('SELECT role_name FROM staff_roles WHERE id = ? AND is_active = 1 LIMIT 1');
            $roleStatement->execute([(int) $formData['role_id']]);
            $roleName = (string) ($roleStatement->fetchColumn() ?: '');

            if ($roleName === '') {
                $error = 'Select a valid active role.';
            }
        }

        if (isset($_FILES['profile_pic']) && is_array($_FILES['profile_pic']) && ($_FILES['profile_pic']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
            ];
            $upload = $_FILES['profile_pic'];

            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'The staff profile picture could not be uploaded.';
            } elseif (($upload['size'] ?? 0) > APP_IMAGE_UPLOAD_MAX_BYTES) {
                $error = 'Choose a profile picture smaller than ' . APP_IMAGE_UPLOAD_MAX_LABEL . '.';
            } else {
                $imageInfo = @getimagesize((string) $upload['tmp_name']);
                $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
                $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
                if (!isset($allowedTypes[$mimeType])) $mimeType = ['heic'=>'image/heic','heif'=>'image/heif'][$extension] ?? $mimeType;

                if (!isset($allowedTypes[$mimeType])) {
                    $error = 'Upload a JPG, PNG, WEBP, GIF, HEIC, or HEIF image.';
                } else {
                    $uploadDir = __DIR__ . '/../assets/uploads/staff';

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }

                    $fileBase = $postedStaffId > 0 && $formData['staff_ref_no'] !== '' ? $formData['staff_ref_no'] : $nextStaffRefNo;
                    $compressible = in_array($mimeType, ['image/jpeg','image/png','image/webp'], true);
                    $fileName = 'staff-' . strtolower($fileBase) . '-' . bin2hex(random_bytes(8)) . '.' . ($compressible ? 'jpg' : $allowedTypes[$mimeType]);
                    $targetPath = $uploadDir . '/' . $fileName;

                    if ($compressible && compress_uploaded_image((string)$upload['tmp_name'], $mimeType, $targetPath)) {
                        $profileImagePath = 'assets/uploads/staff/' . $fileName;
                    } else {
                        if ($compressible) {
                            $fileName = 'staff-' . strtolower($fileBase) . '-' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
                            $targetPath = $uploadDir . '/' . $fileName;
                        }
                    if (!move_uploaded_file((string) $upload['tmp_name'], $targetPath)) {
                        $error = 'The staff profile picture could not be saved.';
                    } else {
                        $profileImagePath = 'assets/uploads/staff/' . $fileName;
                    }
                    }
                }
            }
        }

        if ($error === '') {
            try {
                db()->beginTransaction();

                if ($postedStaffId > 0) {
                    $userStatement = db()->prepare('SELECT user_id FROM staff WHERE id = ? LIMIT 1');
                    $userStatement->execute([$postedStaffId]);
                    $linkedUserId = (int) ($userStatement->fetchColumn() ?: 0);

                    if ($linkedUserId > 0) {
                        $userUpdateSql = 'UPDATE users
                                          SET full_name = ?, email = ?, phone = ?, role = ?, is_active = ?';
                        $userUpdateParams = [
                            $formData['name'],
                            $formData['email'],
                            $formData['phone'] !== '' ? $formData['phone'] : null,
                            'staff',
                            $formData['status'] === '0' ? 0 : 1,
                        ];

                        if ($profileImagePath !== null) {
                            $userUpdateSql .= ', profile_image = ?';
                            $userUpdateParams[] = $profileImagePath;
                        }

                        $userUpdateSql .= ' WHERE id = ?';
                        $userUpdateParams[] = $linkedUserId;
                        db()->prepare($userUpdateSql)->execute($userUpdateParams);
                    } else {
                        $userCreate = db()->prepare(
                            'INSERT INTO users (full_name, email, phone, password_hash, profile_image, role, force_password_change, is_active)
                             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
                        );
                        $userCreate->execute([
                            $formData['name'],
                            $formData['email'],
                            $formData['phone'] !== '' ? $formData['phone'] : null,
                            password_hash(STAFF_DEFAULT_PASSWORD, PASSWORD_DEFAULT),
                            $profileImagePath,
                            'staff',
                            $formData['status'] === '0' ? 0 : 1,
                        ]);
                        $linkedUserId = (int) db()->lastInsertId();
                    }

                    $sql = 'UPDATE staff
                            SET user_id = ?, full_name = ?, email = ?, phone = ?, ghana_card_no = ?, role_id = ?, position = ?, is_active = ?';
                    $params = [
                        $linkedUserId,
                        $formData['name'],
                        $formData['email'] !== '' ? $formData['email'] : null,
                        $formData['phone'] !== '' ? $formData['phone'] : null,
                        $formData['ghana_card_no'] !== '' ? $formData['ghana_card_no'] : null,
                        $formData['role_id'] !== '' ? (int) $formData['role_id'] : null,
                        $roleName !== '' ? $roleName : null,
                        $formData['status'] === '0' ? 0 : 1,
                    ];

                    if ($profileImagePath !== null) {
                        $sql .= ', profile_image = ?';
                        $params[] = $profileImagePath;
                    }

                    $sql .= ' WHERE id = ?';
                    $params[] = $postedStaffId;
                    $statement = db()->prepare($sql);
                    $statement->execute($params);
                    db()->commit();
                    $message = 'Staff record updated successfully.';
                    $editStaffId = 0;
                } else {
                    $userCreate = db()->prepare(
                        'INSERT INTO users (full_name, email, phone, password_hash, profile_image, role, force_password_change, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
                    );
                    $userCreate->execute([
                        $formData['name'],
                        $formData['email'],
                        $formData['phone'] !== '' ? $formData['phone'] : null,
                        password_hash(STAFF_DEFAULT_PASSWORD, PASSWORD_DEFAULT),
                        $profileImagePath,
                        'staff',
                        $formData['status'] === '0' ? 0 : 1,
                    ]);
                    $linkedUserId = (int) db()->lastInsertId();

                    $statement = db()->prepare(
                        'INSERT INTO staff (user_id, staff_code, full_name, profile_image, email, phone, ghana_card_no, role_id, position, is_active, added_by_user_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $statement->execute([
                        $linkedUserId,
                        $nextStaffRefNo,
                        $formData['name'],
                        $profileImagePath,
                        $formData['email'] !== '' ? $formData['email'] : null,
                        $formData['phone'] !== '' ? $formData['phone'] : null,
                        $formData['ghana_card_no'] !== '' ? $formData['ghana_card_no'] : null,
                        $formData['role_id'] !== '' ? (int) $formData['role_id'] : null,
                        $roleName !== '' ? $roleName : null,
                        $formData['status'] === '0' ? 0 : 1,
                        current_user_id(),
                    ]);
                    db()->commit();
                    $message = 'Staff record added successfully. Default login password: ' . STAFF_DEFAULT_PASSWORD;
                }

                $formData = [
                    'staff_id' => '',
                    'staff_ref_no' => '',
                    'name' => '',
                    'email' => '',
                    'phone' => '',
                    'ghana_card_no' => '',
                    'role_id' => '',
                    'status' => '1',
                ];
                $nextStaffRefNo = next_staff_ref_no();
            } catch (PDOException $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }

                if ((string) $exception->getCode() === '23000') {
                    $databaseMessage = strtolower((string) ($exception->errorInfo[2] ?? ''));

                    if (str_contains($databaseMessage, 'ghana_card_no')) {
                        $error = 'The Ghana Card number already exists.';
                    } elseif (str_contains($databaseMessage, 'email')) {
                        $error = 'The staff email already exists.';
                    } elseif (str_contains($databaseMessage, 'staff_code')) {
                        $error = 'The generated staff ref no already exists. Please try saving again.';
                    } else {
                        $error = 'The staff record already exists.';
                    }
                } else {
                    $error = 'The staff record could not be saved.';
                }
            }
        }
    }
}

$roles = db()
    ->query('SELECT id, role_name FROM staff_roles WHERE is_active = 1 ORDER BY role_name')
    ->fetchAll();

$staffRecords = db()
    ->query(
        'SELECT staff.id, staff.staff_code, staff.full_name, staff.profile_image, staff.email, staff.phone, staff.ghana_card_no,
                COALESCE(staff_roles.role_name, staff.position) AS role_name,
                staff.is_active, staff.created_at, users.full_name AS added_by
         FROM staff
         LEFT JOIN users ON users.id = staff.added_by_user_id
         LEFT JOIN staff_roles ON staff_roles.id = staff.role_id
         ORDER BY staff.created_at DESC, staff.id DESC'
    )
    ->fetchAll();

if ($viewStaffId > 0) {
    $viewStatement = db()->prepare(
        'SELECT staff.id, staff.staff_code, staff.full_name, staff.profile_image, staff.email, staff.phone, staff.ghana_card_no,
                COALESCE(staff_roles.role_name, staff.position) AS role_name,
                staff.is_active, staff.created_at, users.full_name AS added_by
         FROM staff
         LEFT JOIN users ON users.id = staff.added_by_user_id
         LEFT JOIN staff_roles ON staff_roles.id = staff.role_id
         WHERE staff.id = ?
         LIMIT 1'
    );
    $viewStatement->execute([$viewStaffId]);
    $viewStaff = $viewStatement->fetch() ?: null;
}

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="staff-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Admin</span>
            <h1 id="staff-title">Staff Setup</h1>
            <p>Add staff profiles for vehicle logs, assignments, and marketing trip operations.</p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-id-card-clip"></i>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="record-form mobile-line-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('staff-setup.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="save_staff">
        <input type="hidden" name="staff_id" value="<?= e($formData['staff_id']) ?>">
        <input type="hidden" name="staff_ref_no" value="<?= e($formData['staff_ref_no']) ?>">

        <div class="form-grid">
            <div class="form-field">
                <label for="profile_pic">Profile pic</label>
                <input id="profile_pic" name="profile_pic" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" data-photo-source-choice>
            </div>

            <div class="form-field">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" value="<?= e($formData['name']) ?>" required>
            </div>

            <div class="form-field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="<?= e($formData['email']) ?>" placeholder="name@example.com" maxlength="160" autocomplete="email" spellcheck="false" data-email-input required>
            </div>

            <div class="form-field">
                <label for="phone">Phone</label>
                <input id="phone" name="phone" type="tel" value="<?= e($formData['phone']) ?>" placeholder="0240000000" maxlength="13" inputmode="tel" autocomplete="tel" data-phone-input>
            </div>

            <div class="form-field">
                <label for="ghana_card_no">Ghana Card number</label>
                <input id="ghana_card_no" name="ghana_card_no" type="text" value="<?= e($formData['ghana_card_no']) ?>" placeholder="GHA-123456789-1" maxlength="15" pattern="GHA-[0-9]{9}-[0-9}" inputmode="text" data-ghana-card-input>
            </div>

            <div class="form-field">
                <label for="role_id">Role / position</label>
                <select id="role_id" name="role_id">
                    <option value="">Select role</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e((string) $role['id']) ?>" <?= $formData['role_id'] === (string) $role['id'] ? 'selected' : '' ?>>
                            <?= e((string) $role['role_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="1" <?= $formData['status'] === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $formData['status'] === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

        </div>

        <div class="form-actions">
            <button class="login-button" type="submit">
                <span><?= $formData['staff_id'] !== '' ? 'Update staff' : 'Save staff' ?></span>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            </button>
            <?php if ($formData['staff_id'] !== ''): ?>
                <a class="secondary-button" href="<?= e(app_url('staff-setup.php')) ?>">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="management-panel management-panel--table" aria-labelledby="staff-list-title">
    <div class="management-heading management-heading--compact">
        <div>
            <span class="section-kicker">Records</span>
            <h2 id="staff-list-title">Staff List</h2>
        </div>
    </div>

    <?php if (!$staffRecords): ?>
        <p class="empty-state">No staff records available yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Staff ref no</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Ghana Card</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Date added</th>
                        <th>Added by</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staffRecords as $staff): ?>
                        <tr>
                            <td>
                                <span class="table-avatar">
                                    <?php if (!empty($staff['profile_image'])): ?>
                                        <img src="<?= e(app_url((string) $staff['profile_image'])) ?>" alt="">
                                    <?php else: ?>
                                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td><?= e((string) $staff['staff_code']) ?></td>
                            <td><?= e((string) $staff['full_name']) ?></td>
                            <td><?= e((string) ($staff['email'] ?? '')) ?></td>
                            <td><?= e((string) ($staff['phone'] ?? '')) ?></td>
                            <td><?= e((string) ($staff['ghana_card_no'] ?? '')) ?></td>
                            <td><?= e((string) ($staff['role_name'] ?? '')) ?></td>
                            <td>
                                <span class="status-badge <?= (int) $staff['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                    <?= (int) $staff['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= e(date('d M Y', strtotime((string) $staff['created_at']))) ?></td>
                            <td><?= e((string) ($staff['added_by'] ?? '')) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a class="action-button" href="<?= e(app_url('staff-setup.php?view=' . (int) $staff['id'])) ?>">
                                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                        <span>View</span>
                                    </a>
                                    <a class="action-button" href="<?= e(app_url('staff-setup.php?edit=' . (int) $staff['id'])) ?>">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                        <span>Edit</span>
                                    </a>
                                    <form method="post" action="<?= e(app_url('staff-setup.php')) ?>" data-confirm-title="Delete staff record?" data-confirm-message="This will permanently remove this staff record from Staff Setup. This action cannot be undone.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="form_action" value="delete_staff">
                                        <input type="hidden" name="staff_id" value="<?= e((string) $staff['id']) ?>">
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

<?php if ($viewStaff): ?>
    <div class="modal-backdrop" role="presentation">
        <section class="staff-modal" role="dialog" aria-modal="true" aria-labelledby="staff-view-title">
            <div class="staff-modal__header">
                <div class="staff-modal__title">
                    <span class="section-kicker">Staff Details</span>
                    <h2 id="staff-view-title"><?= e((string) $viewStaff['full_name']) ?></h2>
                    <p><?= e((string) $viewStaff['staff_code']) ?></p>
                </div>
                <div class="staff-modal__header-actions">
                    <span class="status-badge <?= (int) $viewStaff['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>">
                        <?= (int) $viewStaff['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                    </span>
                    <a class="modal-close" href="<?= e(app_url('staff-setup.php')) ?>" aria-label="Close staff details">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <div class="staff-view-profile">
                <div class="profile-photo profile-photo--small staff-view-profile__photo">
                    <?php if (!empty($viewStaff['profile_image'])): ?>
                        <img src="<?= e(app_url((string) $viewStaff['profile_image'])) ?>" alt="">
                    <?php else: ?>
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>
                <div class="staff-view-profile__summary">
                    <span>Assigned role</span>
                    <strong><?= e((string) ($viewStaff['role_name'] ?? 'No role assigned')) ?></strong>
                    <div class="staff-view-profile__chips">
                        <span><i class="fa-solid fa-phone" aria-hidden="true"></i><?= e((string) (($viewStaff['phone'] ?? '') !== '' ? $viewStaff['phone'] : 'No phone')) ?></span>
                        <span><i class="fa-solid fa-envelope" aria-hidden="true"></i><?= e((string) (($viewStaff['email'] ?? '') !== '' ? $viewStaff['email'] : 'No email')) ?></span>
                    </div>
                </div>
            </div>

            <div class="staff-view-details">
                <div>
                    <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                    <span><span class="staff-view-details__label">Ghana Card</span><strong><?= e((string) (($viewStaff['ghana_card_no'] ?? '') !== '' ? $viewStaff['ghana_card_no'] : 'Not provided')) ?></strong></span>
                </div>
                <div>
                    <i class="fa-solid fa-calendar-plus" aria-hidden="true"></i>
                    <span><span class="staff-view-details__label">Date added</span><strong><?= e(date('d M Y', strtotime((string) $viewStaff['created_at']))) ?></strong></span>
                </div>
                <div class="staff-view-details__wide">
                    <i class="fa-solid fa-user-check" aria-hidden="true"></i>
                    <span><span class="staff-view-details__label">Added by</span><strong><?= e((string) (($viewStaff['added_by'] ?? '') !== '' ? $viewStaff['added_by'] : 'System')) ?></strong></span>
                </div>
            </div>

            <div class="staff-modal__actions">
                <a class="secondary-button" href="<?= e(app_url('staff-setup.php?edit=' . (int) $viewStaff['id'])) ?>">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    <span>Edit staff</span>
                </a>
            </div>
        </section>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
