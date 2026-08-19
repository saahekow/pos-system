<?php
require_once __DIR__ . '/config/app.php';

redirect_if_authenticated();
$pageTitle = 'Forgot Password';
$error = '';
$message = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalize_email_address((string) ($_POST['email'] ?? ''));
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!is_valid_email_address($email)) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            request_password_reset($email);
            $message = 'If an active account matches that email, a password reset link has been sent.';
            $email = '';
        } catch (Throwable $exception) {
            $error = 'The reset email could not be sent because email delivery is not configured. Please contact the administrator.';
        }
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
    <section class="login-panel" aria-labelledby="forgot-title">
        <div class="login-brand">
            <span class="brand__logo" aria-hidden="true"><i class="fa-solid fa-key"></i></span>
            <span class="brand__company"><?= e(COMPANY_NAME) ?></span>
            <h1 id="forgot-title">Forgot your password?</h1>
            <p>Enter your account email and we’ll send you a secure reset link.</p>
        </div>
        <?php if ($error !== ''): ?>
            <div class="login-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($error) ?></span></div>
        <?php elseif ($message !== ''): ?>
            <div class="login-alert login-alert--success" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span><?= e($message) ?></span></div>
        <?php endif; ?>
        <form class="login-form" method="post" action="<?= e(app_url('forgot-password.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="email">Email address</label>
            <div class="login-field"><i class="fa-regular fa-envelope" aria-hidden="true"></i><input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="email" required autofocus></div>
            <button class="login-button" type="submit"><span>Send reset link</span><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
        </form>
        <p class="login-form__footer"><a href="<?= e(app_url('login.php')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
    </section>
</main>
</body>
</html>
