<?php
require_once __DIR__ . '/config/app.php';

redirect_if_authenticated();
$pageTitle = 'Reset Password';
$error = '';
$token = (string) ($_POST['reset_token'] ?? $_GET['token'] ?? '');
$validToken = false;

try {
    $validToken = password_reset_is_valid($token);
} catch (Throwable $exception) {
    $error = 'Password reset is temporarily unavailable. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$validToken) {
        $error = 'This reset link is invalid or has expired.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Your new password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The passwords do not match.';
    } else {
        try {
            if (reset_password_with_token($token, $newPassword)) {
                header('Location: ' . app_url('login.php?reset=success'));
                exit;
            }
            $error = 'This reset link is invalid or has expired.';
            $validToken = false;
        } catch (Throwable $exception) {
            $error = 'Password reset is temporarily unavailable. Please try again later.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/styles.css')) ?>">
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-panel" aria-labelledby="reset-title">
        <div class="login-brand">
            <span class="brand__logo" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
            <span class="brand__company"><?= e(COMPANY_NAME) ?></span>
            <h1 id="reset-title">Create a new password</h1>
            <p>Choose a password with at least 8 characters.</p>
        </div>
        <?php if ($error !== ''): ?><div class="login-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($error) ?></span></div><?php endif; ?>
        <?php if ($validToken): ?>
            <form class="login-form" method="post" action="<?= e(app_url('reset-password.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="reset_token" value="<?= e($token) ?>">
                <label for="new_password">New password</label>
                <div class="login-field"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required autofocus></div>
                <label for="confirm_password">Confirm password</label>
                <div class="login-field"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></div>
                <button class="login-button" type="submit"><span>Reset password</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </form>
        <?php else: ?>
            <p class="login-form__footer"><a href="<?= e(app_url('forgot-password.php')) ?>">Request a new reset link</a></p>
        <?php endif; ?>
        <p class="login-form__footer"><a href="<?= e(app_url('login.php')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
    </section>
</main>
</body>
</html>
