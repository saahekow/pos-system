<?php
require_once __DIR__ . '/config/app.php';

redirect_if_authenticated();

$pageTitle = 'Login';
$error = '';
$message = isset($_GET['reset']) && $_GET['reset'] === 'success'
    ? 'Your password has been reset. You can now sign in.'
    : '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $keepLoggedIn = isset($_POST['keep_logged_in']) && (string)$_POST['keep_logged_in'] === '1';
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Enter your email or phone number and password.';
    } else {
        try {
            if (attempt_login($identifier, $password)) {
                if ($keepLoggedIn && current_user_id()) {
                    issue_remember_login_token((int)current_user_id());
                }
                $destination = must_change_password()
                    ? app_url('change-password.php')
                    : app_url('index.php');
                unset($_SESSION['intended_url']);

                header('Location: ' . $destination);
                exit;
            }

            $error = 'Invalid email, phone number, or password.';
        } catch (Throwable $exception) {
            $error = 'Login is unavailable. Check the database connection.';
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
    <link rel="icon" type="image/png" href="<?= e(asset_url('favico.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset_url('favico.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/styles.css')) ?>">
</head>
<body class="login-body spwsales-login">
    <main class="login-shell">
        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-brand">
                <span class="brand__logo" aria-hidden="true">
                    <i class="fa-solid fa-cart-shopping"></i>
                </span>
                <span class="brand__company"><?= e(COMPANY_NAME) ?></span>
                <h1 id="login-title"><?= e(APP_NAME) ?></h1>
                <p>Sign in to manage sales, stock transfers, reports, and POS operations.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="login-alert" role="alert">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php elseif ($message !== ''): ?>
                <div class="login-alert login-alert--success" role="status">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <span><?= e($message) ?></span>
                </div>
            <?php endif; ?>

            <form class="login-form" method="post" action="<?= e(app_url('login.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label for="identifier">Email or phone number</label>
                <div class="login-field">
                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                    <input id="identifier" name="identifier" type="text" value="<?= e($identifier) ?>" autocomplete="username" inputmode="text" required autofocus>
                </div>

                <label for="password">Password</label>
                <div class="login-field">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <button class="login-password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle>
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="login-form__help">
                    <label class="login-remember"><input type="checkbox" name="keep_logged_in" value="1" <?=isset($_POST['keep_logged_in'])?'checked':''?>><span>Always keep me logged in</span></label>
                    <a href="<?= e(app_url('forgot-password.php')) ?>">Forgot password?</a>
                </div>

                <button class="login-button" type="submit">
                    <span>Sign in</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
        </section>
    </main>
    <script>
    document.querySelector('[data-password-toggle]')?.addEventListener('click', function () {
        const password = document.getElementById('password');
        const showing = password.type === 'text';
        password.type = showing ? 'password' : 'text';
        this.setAttribute('aria-pressed', showing ? 'false' : 'true');
        this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        const icon = this.querySelector('i');
        if (icon) icon.className = showing ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    });
    </script>
</body>
</html>
