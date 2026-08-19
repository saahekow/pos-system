<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('vin_search');
$pageTitle='Data Management';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Data Management']];
require_once __DIR__ . '/../includes/header.php';
$cards=[];
if(can_access_menu_item('data_vin_search_1'))$cards[]=['title'=>'VIN Search 1','description'=>'Search and save vehicle information using the current VIN service.','icon'=>'fa-solid fa-barcode','url'=>app_url('vin-search.php'),'disabled'=>false];
$cards[]=['title'=>'VIN Search 2','description'=>'Accurately analyze vehicle model information using the VIN114 interface.','icon'=>'fa-solid fa-code-branch','url'=>app_url('vin-search-2.php'),'disabled'=>false];
if(can_access_menu_item('data_reports'))$cards[]=['title'=>'Reports','description'=>'Review all VIN searches saved by the current service.','icon'=>'fa-solid fa-chart-column','url'=>app_url('vin-search.php?view=report'),'disabled'=>false];
?>
<section class="dashboard"><div class="tile-grid tile-grid--three"><?php foreach($cards as $card): ?>
<?php if($card['disabled']): ?><div class="module-card is-disabled" aria-disabled="true"><?php else: ?><a class="module-card" href="<?=e($card['url'])?>"><?php endif; ?>
<span class="module-card__icon"><i class="<?=e($card['icon'])?>"></i></span><span class="module-card__content"><h2><?=e($card['title'])?></h2><p><?=e($card['description'])?></p></span><span class="module-card__arrow"><i class="fa-solid <?=$card['disabled']?'fa-clock':'fa-arrow-right'?>"></i></span>
<?php if($card['disabled']): ?></div><?php else: ?></a><?php endif; ?>
<?php endforeach; ?></div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
