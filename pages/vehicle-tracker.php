<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('vehicle_log');

$pageTitle = 'Vehicle Log';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Vehicle Log'],
];
$internalBackUrl=requested_return_url(app_url('admin.php'));
$vehicleHubUrl=app_url('vehicles.php?return_to='.rawurlencode($internalBackUrl));
require_once __DIR__ . '/../includes/header.php';

$modules = [
    [
        'title' => 'Fuel',
        'description' => 'Record and review vehicle fuel usage and refill activity.',
        'icon' => 'fa-solid fa-gas-pump',
        'url' => app_url('fuel.php?return_to='.rawurlencode($vehicleHubUrl)),
    ],
    [
        'title' => 'Log Book',
        'description' => 'Capture vehicle logs, driver entries, and movement notes.',
        'icon' => 'fa-solid fa-book-open',
        'url' => app_url('log-book.php?return_to='.rawurlencode($vehicleHubUrl)),
    ],
];
?>
<section class="dashboard" aria-labelledby="vehicle-title">
    <p class="dashboard__intro" id="vehicle-title">
        Vehicle tracking tools are organized for quick access to daily fleet records.
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
