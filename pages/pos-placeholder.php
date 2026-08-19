<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('pos');

$posSection = isset($posSection) ? (string) $posSection : 'POS';
$pageTitle = 'POS ' . $posSection;
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'POS', 'url' => app_url('pos.php')],
    ['label' => $posSection],
];
$internalBackUrl=requested_return_url(app_url('pos.php'));
require_once __DIR__ . '/../includes/header.php';
?>
<section class="content-panel" aria-labelledby="pos-section-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">POS</span>
            <h1 id="pos-section-title"><?= e($posSection) ?></h1>
            <p>This menu is ready. Its contents will be added next.</p>
        </div>
        <div class="management-icon"><i class="fa-solid fa-receipt"></i></div>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
