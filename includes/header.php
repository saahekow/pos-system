<?php
require_once __DIR__ . '/../config/app.php';

require_auth();

$pageTitle = $pageTitle ?? APP_NAME;
$breadcrumbs = $breadcrumbs ?? [];
$profileImageUrl = current_user_profile_image_url();
$currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$isHomePage = $currentScript === 'index.php';
$deleteNotice = '';
$systemSuccessNotice = (string)($_GET['location_left'] ?? '') === '1'
    ? 'Location left successfully.'
    : '';
$pagesWithOwnDeleteNotice = ['attendance-setup.php', 'activity-log.php', 'normalized-customer.php'];
if (!in_array($currentScript, $pagesWithOwnDeleteNotice, true)) {
    if ((string)($_GET['trip_deleted'] ?? '') === '1') {
        $deleteNotice = 'Trip deleted successfully.';
    } elseif ((string)($_GET['draft_deleted'] ?? '') === '1') {
        $deleteNotice = 'Draft deleted successfully.';
    } elseif ((string)($_GET['visit_deleted'] ?? '') === '1') {
        $deleteNotice = 'Registration deleted successfully.';
    } elseif ((string)($_GET['deleted'] ?? '') === '1') {
        $deleteNotice = $currentScript === 'registration-records.php'
            ? 'Draft deleted successfully.'
            : ($currentScript === 'reports.php'
                ? 'Registration deleted successfully.'
                : 'Record deleted successfully.');
    }
}
$internalBackUrl = safe_app_return_url(trim((string)($internalBackUrl ?? '')), '');
if ($internalBackUrl === '') {
    $internalBackUrl = requested_return_url('');
    if ($internalBackUrl === '') {
        for ($breadcrumbIndex = count($breadcrumbs) - 2; $breadcrumbIndex >= 0; $breadcrumbIndex--) {
            $breadcrumbUrl = safe_app_return_url(trim((string)($breadcrumbs[$breadcrumbIndex]['url'] ?? '')), '');
            if ($breadcrumbUrl !== '') {
                $internalBackUrl = $breadcrumbUrl;
                break;
            }
        }
    }
    if ($internalBackUrl === '') {
        $referrerBackUrl = safe_app_return_url((string)($_SERVER['HTTP_REFERER'] ?? ''), '');
        $currentRequestUrl = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($referrerBackUrl !== '' && $referrerBackUrl !== $currentRequestUrl) $internalBackUrl = $referrerBackUrl;
    }
    if ($internalBackUrl === '') $internalBackUrl = app_url('index.php');
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
<body class="<?= $isHomePage ? 'app-page--home' : 'app-page--internal' ?>">
    <?php if ($systemSuccessNotice !== ''): ?>
        <div class="system-notification system-notification--success" role="status" data-system-notification>
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span><?=e($systemSuccessNotice)?></span>
            <button type="button" aria-label="Close notification" data-dismiss-system-notification>
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    <?php endif; ?>
    <?php if ($deleteNotice !== ''): ?>
        <div class="system-notification system-notification--success" role="status" data-system-notification>
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span><?=e($deleteNotice)?></span>
            <button type="button" aria-label="Close notification" data-dismiss-system-notification>
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    <?php endif; ?>
    <div class="app-shell">
        <?php if ($isHomePage): ?>
        <header class="app-header app-header--home">
            <a class="brand" href="<?= e(app_url('index.php')) ?>" aria-label="<?= e(APP_NAME) ?> home">
                <span class="brand__logo" aria-hidden="true">
                    <i class="fa-solid fa-cart-shopping"></i>
                </span>
                <span class="brand__wordmark">
                    <span class="brand__name">
                        <strong><?= e(APP_NAME) ?></strong>
                    </span>
                </span>
            </a>

            <div class="header-actions" aria-label="Session actions">
                <a class="header-icon-button header-icon-button--profile" href="<?= e(app_url('profile.php')) ?>" title="Profile: <?= e(current_user_name()) ?>" aria-label="Open profile for <?= e(current_user_name()) ?>">
                    <?php if ($profileImageUrl !== ''): ?>
                        <img class="user-avatar" src="<?= e($profileImageUrl) ?>" alt="">
                    <?php else: ?>
                        <i class="fa-regular fa-user" aria-hidden="true"></i>
                    <?php endif; ?>
                </a>
                <a class="header-icon-button header-icon-button--logout" href="<?= e(app_url('logout.php')) ?>" title="Sign out" aria-label="Sign out">
                    <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                </a>
            </div>
        </header>
        <?php else: ?>
        <header class="app-header app-header--internal">
            <a class="internal-page-header__back" href="<?=e($internalBackUrl)?>" data-internal-page-back aria-label="Go back">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            </a>
            <h1 class="internal-page-header__title"><?=e($pageTitle)?></h1>
            <a class="internal-page-header__logout" href="<?=e(app_url('logout.php'))?>" title="Sign out" aria-label="Sign out and return to the SPW Sales login">
                <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                <span>Logout</span>
            </a>
        </header>
        <?php endif; ?>

        <div class="breadcrumb-wrap<?= $isHomePage ? '' : ' breadcrumb-wrap--internal' ?>">
            <?php if ($breadcrumbs): ?>
                <?php render_breadcrumb($breadcrumbs); ?>
            <?php endif; ?>
        </div>

        <main class="app-main">
