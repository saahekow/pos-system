<?php
require_once __DIR__ . '/config/app.php';

require_auth();
refresh_current_user();

$pageTitle = 'Profile';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Profile'],
];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $formAction = (string) ($_POST['form_action'] ?? 'upload_picture');

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($formAction === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!verify_current_user_password($currentPassword)) {
            $error = 'Your current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Your new password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The new passwords do not match.';
        } elseif ($newPassword === $currentPassword) {
            $error = 'Choose a new password that is different from your current password.';
        } else {
            change_current_user_password($newPassword);
            session_regenerate_id(true);
            $message = 'Password changed successfully.';
        }
    } elseif ($formAction !== 'upload_picture') {
        $error = 'The selected profile action is invalid.';
    } elseif (!isset($_FILES['profile_image']) || !is_array($_FILES['profile_image'])) {
        $error = 'Choose an image to upload.';
    } else {
        $upload = $_FILES['profile_image'];
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'The image could not be uploaded.';
        } elseif (($upload['size'] ?? 0) > APP_IMAGE_UPLOAD_MAX_BYTES) {
            $error = 'Choose an image smaller than ' . APP_IMAGE_UPLOAD_MAX_LABEL . '.';
        } else {
            $imageInfo = @getimagesize((string) $upload['tmp_name']);
            $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';

            if (!isset($allowedTypes[$mimeType])) {
                $error = 'Upload a JPG, PNG, WEBP, or GIF image.';
            } else {
                $uploadDir = __DIR__ . '/assets/uploads/profiles';

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $fileName = 'user-' . current_user_id() . '-' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
                $targetPath = $uploadDir . '/' . $fileName;
                $publicPath = 'assets/uploads/profiles/' . $fileName;

                if (!move_uploaded_file((string) $upload['tmp_name'], $targetPath)) {
                    $error = 'The image could not be saved.';
                } else {
                    $statement = db()->prepare('UPDATE users SET profile_image = ? WHERE id = ?');
                    $statement->execute([$publicPath, current_user_id()]);
                    $_SESSION['profile_image'] = $publicPath;
                    $message = 'Profile picture updated.';
                }
            }
        }
    }
}

$profileImageUrl = current_user_profile_image_url();
require_once __DIR__ . '/includes/header.php';
?>
<div class="profile-layout">
<section class="content-panel profile-panel profile-panel--identity" aria-labelledby="profile-title">
    <div class="profile-photo">
        <?php if ($profileImageUrl !== ''): ?>
            <img src="<?= e($profileImageUrl) ?>" alt="">
        <?php else: ?>
            <i class="fa-solid fa-user" aria-hidden="true"></i>
        <?php endif; ?>
    </div>

    <h1 id="profile-title"><?= e(current_user_name()) ?></h1>
    <p class="empty-state"><?= e((string) ($_SESSION['user_email'] ?? '')) ?> · <?= e(ucfirst(current_user_role())) ?></p>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="profile-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('profile.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="upload_picture">

        <label for="profile_image">Profile picture</label>
        <input id="profile_image" name="profile_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required>

        <button class="login-button" type="submit">
            <span>Upload picture</span>
            <i class="fa-solid fa-upload" aria-hidden="true"></i>
        </button>
    </form>
</section>

<section class="content-panel profile-panel profile-panel--security" aria-labelledby="change-password-title">
    <div class="profile-security-heading">
        <div class="profile-photo profile-photo--small" aria-hidden="true"><i class="fa-solid fa-key"></i></div>
        <div>
            <span class="profile-security-kicker"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Account security</span>
            <h2 id="change-password-title">Change password</h2>
            <p class="empty-state">Confirm your current password and create a secure replacement.</p>
        </div>
    </div>

    <form class="profile-form profile-password-form" method="post" action="<?= e(app_url('profile.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="change_password">

        <label for="current_password">Current password</label>
        <div class="profile-password-field">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" placeholder="Enter current password" required>
            <button type="button" data-password-toggle aria-label="Show current password"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
        </div>

        <label for="new_password">New password</label>
        <div class="profile-password-field">
            <i class="fa-solid fa-key" aria-hidden="true"></i>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" placeholder="At least 8 characters" required>
            <button type="button" data-password-toggle aria-label="Show new password"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
        </div>

        <label for="confirm_password">Confirm new password</label>
        <div class="profile-password-field">
            <i class="fa-solid fa-check" aria-hidden="true"></i>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" placeholder="Repeat new password" required>
            <button type="button" data-password-toggle aria-label="Show confirmed password"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
        </div>

        <p class="profile-password-hint"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Use 8 or more characters and avoid your current password.</p>

        <button class="login-button" type="submit">
            <span>Change password</span>
            <i class="fa-solid fa-key" aria-hidden="true"></i>
        </button>
    </form>
</section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
