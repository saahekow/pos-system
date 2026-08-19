<?php
require_once __DIR__ . '/config/app.php';

require_auth();

$pageTitle = 'Change Password';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Your new password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The passwords do not match.';
    } else {
        change_current_user_password($newPassword);
        header('Location: ' . app_url('index.php'));
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/styles.css')) ?>">
</head>
<body class="login-body">
    <main class="login-shell">
        <section class="login-panel" aria-labelledby="password-title">
            <div class="login-brand">
                <span class="brand__logo" aria-hidden="true">
                    <i class="fa-solid fa-key"></i>
                </span>
                <span class="brand__company"><?= e(COMPANY_NAME) ?></span>
                <h1 id="password-title">Change Password</h1>
                <p>You must create a new password before continuing.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="login-alert" role="alert">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form class="login-form" method="post" action="<?= e(app_url('change-password.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label for="new_password">New password</label>
                <div class="login-field">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <input id="new_password" name="new_password" type="password" autocomplete="new-password" required autofocus>
                    <button class="login-password-toggle" type="button" aria-label="Show new password" aria-pressed="false" data-password-toggle data-password-target="new_password"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                </div>

                <label for="confirm_password">Confirm password</label>
                <div class="login-field">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
                    <button class="login-password-toggle" type="button" aria-label="Show confirmed password" aria-pressed="false" data-password-toggle data-password-target="confirm_password"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                </div>

                <button class="login-button" type="submit">
                    <span>Update password</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
        </section>
    </main>
    <script>
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var password = document.getElementById(this.dataset.passwordTarget);
            if (!password) return;
            var showing = password.type === 'text';
            password.type = showing ? 'password' : 'text';
            this.setAttribute('aria-pressed', showing ? 'false' : 'true');
            this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            var icon = this.querySelector('i');
            if (icon) icon.className = showing ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
        });
    });
    </script>
</body>
</html>
