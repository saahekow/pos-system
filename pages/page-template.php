<?php
require_once __DIR__ . '/../includes/header.php';
$panelIcon = $panelIcon ?? 'fa-solid fa-circle-info';
?>
<section class="content-panel" aria-labelledby="page-title">
    <div class="content-panel__icon" aria-hidden="true">
        <i class="<?= e($panelIcon) ?>"></i>
    </div>
    <h1 id="page-title"><?= e($pageTitle) ?></h1>
    <p class="empty-state">No data available yet.</p>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
