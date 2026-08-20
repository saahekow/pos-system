<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('admin');

$pageTitle = 'Setup';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Setup'],
];
$internalBackUrl=app_url('admin.php?view=setup');
require_once __DIR__ . '/../includes/header.php';

$modules = [
    [
        'title' => 'Role Setup',
        'description' => 'Manage staff roles used in Staff Setup and assignments.',
        'icon' => 'fa-solid fa-user-tag',
        'url' => app_url('role-setup.php'),
    ],
    [
        'title' => 'Feedback Setup',
        'description' => 'Manage feedback options used during destination visits.',
        'icon' => 'fa-solid fa-message',
        'url' => app_url('feedback-setup.php'),
    ],
    [
        'title' => 'Referral Source Setup',
        'description' => 'Manage the How Did You Know Us choices used by POS Sales.',
        'icon' => 'fa-solid fa-bullhorn',
        'url' => app_url('pos-referral-setup.php'),
    ],
    [
        'title' => 'Plug Commission Setup',
        'description' => 'Assign box commission percentages to spark plug numbers.',
        'icon' => 'fa-solid fa-percent',
        'url' => app_url('pos-discount-setup.php'),
    ],
    [
        'title' => 'Destination Setup',
        'description' => 'Manage marketing trip destinations and the default Taxi Rank workflow.',
        'icon' => 'fa-solid fa-map-location-dot',
        'url' => app_url('destination-setup.php'),
    ],
    [
        'title' => 'Location Setup',
        'description' => 'Manage flat Region → MMDA → Town locations manually or with CSV uploads.',
        'icon' => 'fa-solid fa-map-location-dot',
        'url' => app_url('location-setup.php'),
    ],
    [
        'title' => 'Vendor Setup',
        'description' => 'Manage vendors independently from destination visits.',
        'icon' => 'fa-solid fa-truck-field',
        'url' => app_url('vendor-setup.php'),
    ],
    [
        'title' => 'Shop Type Setup',
        'description' => 'Manage Shop Type options used for Shop visits.',
        'icon' => 'fa-solid fa-store',
        'url' => app_url('shop-type-setup.php'),
    ],
    [
        'title' => 'Customer Type Setup',
        'description' => 'Manage customer type choices used in Sales and customer registration.',
        'icon' => 'fa-solid fa-briefcase',
        'url' => app_url('job-type-setup.php'),
    ],
    [
        'title' => 'Vehicle Setup',
        'description' => 'Manage the car numbers staff can select when starting trips.',
        'icon' => 'fa-solid fa-car-side',
        'url' => app_url('vehicle-setup.php'),
    ],
    [
        'title' => 'Attendance Setup',
        'description' => 'Create attendance sessions and save the GPS attendance point.',
        'icon' => 'fa-solid fa-calendar-plus',
        'url' => app_url('attendance-setup.php'),
    ],
];
?>
<section class="dashboard" aria-labelledby="setup-title">
    <p class="dashboard__intro" id="setup-title">
        Configure lookup lists and operational defaults used across the system.
    </p>

    <div class="tile-grid tile-grid--compact">
        <?php foreach ($modules as $module): ?>
            <a class="module-card" href="<?= e($module['url']) ?>">
                <span class="module-card__icon" aria-hidden="true">
                    <i class="<?= e($module['icon']) ?>"></i>
                </span>
                <span class="module-card__content">
                    <h2><?= e($module['title']) ?></h2>
                    <p><?= e($module['description']) ?></p>
                </span>
                <span class="module-card__arrow" aria-hidden="true">
                    <i class="fa-solid fa-arrow-right"></i>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
