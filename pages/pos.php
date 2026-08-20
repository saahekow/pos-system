<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('pos');

$view=(string)($_GET['view']??'menu');
if(!in_array($view,['menu','sales','audit','direct-reports','reports','sor','sor-sale','sor-refund','sor-report','sor-audit','admin','setup'],true))$view='menu';
$vendorProfile=current_vendor_profile();
$vendorIsSor=current_vendor_is_sor();
$posPersonnel=current_vendor_personnel();
if($posPersonnel&&$view==='sor'&&(int)$posPersonnel['can_sor']!==1)$view='menu';
if($vendorIsSor&&in_array($view,['sales','audit','direct-reports'],true))$view='sor';
if($vendorProfile&&!$vendorIsSor&&($view==='sor'||str_starts_with($view,'sor-')))$view='menu';
$canPosSales=can_access_menu_item('pos_shop_sales')||can_access_menu_item('pos_trip_sales')||can_access_menu_item('pos_promo');
if(($view==='sales'&&!$canPosSales)||($view==='audit'&&!can_access_menu_item('pos_audit'))||(in_array($view,['direct-reports','reports'],true)&&!can_access_menu_item('pos_reports')))$view='menu';
if(($view==='sor-audit'&&!can_access_menu_item('pos_audit'))||($view==='sor-report'&&!can_access_menu_item('pos_reports'))||($view==='sor-refund'&&!can_access_menu_item('pos_refund')))$view='menu';
$pageTitle=match($view){'sales'=>'POS Direct Sales','audit'=>'POS Audit','direct-reports'=>'Direct Sales Reports','reports'=>'POS Reports','sor'=>'POS SoR','sor-sale'=>'SoR Sale','sor-refund'=>'SoR Refund','sor-report'=>'SoR Report','sor-audit'=>'SoR Audit','admin'=>'POS Admin','setup'=>'POS Setup',default=>'POS'};
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'POS','url'=>app_url('pos.php')]];
if(in_array($view,['audit','direct-reports'],true))$breadcrumbs[]=['label'=>'Direct Sales','url'=>app_url('pos.php?view=sales')];
if(str_starts_with($view,'sor-'))$breadcrumbs[]=['label'=>'SoR','url'=>app_url('pos.php?view=sor')];
if($view!=='menu')$breadcrumbs[]=['label'=>$pageTitle];
$internalBackUrl=$view==='menu'?app_url('index.php'):($view==='setup'?app_url('pos.php?view=admin'):(str_starts_with($view,'sor-')?app_url('pos.php?view=sor'):(in_array($view,['audit','direct-reports'],true)?app_url('pos.php?view=sales'):app_url('pos.php'))));
require_once __DIR__ . '/../includes/header.php';

$modules=[];
if($view==='menu'){
 $modules=[];
 if($canPosSales&&!$vendorIsSor)$modules[]=['title'=>'Direct Sales','description'=>'Open sales, promotions, refunds, reports, and stock audit.','icon'=>'fa-solid fa-cart-shopping','url'=>app_url('pos.php?view=sales')];
 if((!$vendorProfile&&!$posPersonnel)||($vendorIsSor&&(!$posPersonnel||(int)$posPersonnel['can_sor']===1)))$modules[]=['title'=>'SoR','description'=>'Open Sale or Return sales, refunds, reports, and audit.','icon'=>'fa-solid fa-boxes-stacked','url'=>app_url('pos.php?view=sor')];
 if(can_access_menu_item('pos_transfer')){$vendorAccount=current_vendor_profile()!==null;$modules[]=['title'=>$vendorAccount?'Incoming Transfers':'Transfer','description'=>$vendorAccount?'Review and accept goods sent to you.':'Transfer stock to a vendor.','icon'=>'fa-solid fa-right-left','url'=>app_url($vendorAccount?'pos-incoming-transfers.php':'pos-transfer.php')];}
 if(can_access_menu_item('pos_reports'))$modules[]=['title'=>'Reports','description'=>'Open sales, transfer, refund, and notes reports.','icon'=>'fa-solid fa-chart-column','url'=>app_url('pos.php?view=reports')];
 if(current_user_role()==='vendor'&&can_access_module('vendor_personnel'))$modules[]=['title'=>'Personnel Assignment','description'=>'Add and manage personnel for your vendor account.','icon'=>'fa-solid fa-people-group','url'=>app_url('vendor-personnel.php')];
 if(is_admin_user())$modules[]=['title'=>'Admin','description'=>'Open POS setup and assignment tools.','icon'=>'fa-solid fa-gears','url'=>app_url('pos.php?view=admin')];
}elseif($view==='sales'){
 $modules=[];
 if(can_access_menu_item('pos_shop_sales')||can_access_menu_item('pos_trip_sales'))$modules[]=['title'=>'Sale','description'=>'Record a direct sale.','icon'=>'fa-solid fa-cart-plus','url'=>app_url('pos-sales.php?return_to='.rawurlencode(app_url('pos.php?view=sales')))];
 if(can_access_menu_item('pos_promo'))$modules[]=['title'=>'Promo','description'=>'Select a customer and record their Promo Plug response.','icon'=>'fa-solid fa-bullhorn','url'=>app_url('pos-promo.php')];
 if(can_access_menu_item('pos_refund'))$modules[]=['title'=>'Refund','description'=>'Direct Sales refund menu.','icon'=>'fa-solid fa-rotate-left','url'=>'#'];
 if(can_access_menu_item('pos_reports'))$modules[]=['title'=>'Report','description'=>'Open direct sales, refund, and notes reports.','icon'=>'fa-solid fa-chart-column','url'=>app_url('pos.php?view=direct-reports')];
 if(can_access_menu_item('pos_audit'))$modules[]=['title'=>'Audit','description'=>'Open stock deduction and stock addition.','icon'=>'fa-solid fa-clipboard-list','url'=>app_url('pos.php?view=audit')];
 if(current_user_role()==='vendor'&&$vendorProfile&&(int)$vendorProfile['user_id']===(int)current_user_id()){$todayClosure=vendor_day_is_closed((int)$vendorProfile['id']);$modules[]=['title'=>$todayClosure?'Sales Closed · '.date('H:i',strtotime((string)$todayClosure['closed_at'])):'Close Day','description'=>$todayClosure?'Today is closed for this vendor. Reports and receipts remain available.':'Review today’s complete vendor summary and close sales for the day.','icon'=>$todayClosure?'fa-solid fa-lock':'fa-solid fa-calendar-check','url'=>app_url('close-day.php')];}
}elseif($view==='direct-reports'){
 foreach([['Sales','sales'],['Refund','refunds'],['Notes','notes']] as [$title,$reportView])$modules[]=['title'=>$title,'description'=>'Open the direct sales '.strtolower($title).' report.','icon'=>'fa-solid fa-chart-simple','url'=>app_url('pos-reports.php?view='.$reportView.'&source=pos&return_to='.rawurlencode(app_url('pos.php?view=direct-reports')))];
}elseif($view==='sor'){
 $modules=[];
 if(!$posPersonnel||(int)$posPersonnel['can_sor']===1)$modules[]=['title'=>'Sale','description'=>'Record an SoR sale using the complete sales workflow.','icon'=>'fa-solid fa-cart-plus','url'=>app_url('pos-sales.php?source=sor&return_to='.rawurlencode(app_url('pos.php?view=sor')))];
 if(can_access_menu_item('pos_refund'))$modules[]=['title'=>'Refund','description'=>'SoR refund menu.','icon'=>'fa-solid fa-rotate-left','url'=>'#'];
 if(can_access_menu_item('pos_reports'))$modules[]=['title'=>'Report','description'=>'Open sales, refund, and notes reports containing only SoR transactions.','icon'=>'fa-solid fa-chart-column','url'=>app_url('pos-reports.php?view=menu&source=sor&return_to='.rawurlencode(app_url('pos.php?view=sor')))];
 if(can_access_menu_item('pos_audit'))$modules[]=['title'=>'Audit','description'=>'Open the SoR stock audit menus.','icon'=>'fa-solid fa-clipboard-list','url'=>app_url('pos.php?view=sor-audit')];
 if(current_user_role()==='vendor'&&$vendorProfile&&(int)$vendorProfile['user_id']===(int)current_user_id()){$todayClosure=vendor_day_is_closed((int)$vendorProfile['id']);$modules[]=['title'=>$todayClosure?'Sales Closed · '.date('H:i',strtotime((string)$todayClosure['closed_at'])):'Close Day','description'=>$todayClosure?'Today is closed for this vendor. Reports and receipts remain available.':'Review today’s complete vendor summary and close sales for the day.','icon'=>$todayClosure?'fa-solid fa-lock':'fa-solid fa-calendar-check','url'=>app_url('close-day.php')];}
}elseif($view==='reports'){
 foreach([['Sales','sales'],['Transfer','transfers'],['Refund','refunds'],['Notes','notes']] as [$title,$reportView])$modules[]=['title'=>$title,'description'=>'Open the POS '.strtolower($title).' report.','icon'=>'fa-solid fa-chart-simple','url'=>app_url('pos-reports.php?view='.$reportView)];
 if(current_user_role()==='vendor'&&$vendorProfile&&(int)$vendorProfile['user_id']===(int)current_user_id())$modules[]=['title'=>'Day Closures','description'=>'Review your previous Close Day records and immutable summary snapshots.','icon'=>'fa-solid fa-calendar-check','url'=>app_url('close-day.php?view=history')];
 if(is_super_admin())$modules[]=['title'=>'Reopening Audit','description'=>'Review the permanent audit trail for every reopened vendor day.','icon'=>'fa-solid fa-clock-rotate-left','url'=>app_url('close-day.php?view=audit')];
}elseif($view==='admin'){
 if(!is_admin_user()){header('Location: '.app_url('pos.php'));exit;}
 $modules=[
 ['title'=>'Setup','description'=>'Configure POS referral and commission options.','icon'=>'fa-solid fa-sliders','url'=>app_url('pos.php?view=setup')],
 ['title'=>'Personnel Assignment','description'=>'Create and assign vendor personnel for SPW Sales access.','icon'=>'fa-solid fa-people-group','url'=>app_url('vendor-personnel.php')],
 ... (is_super_admin() ? [['title'=>'Day Closures','description'=>'View vendor closures, snapshots, and reopen audit history.','icon'=>'fa-solid fa-lock-open','url'=>app_url('close-day.php')]] : []),
 ];
}elseif($view==='setup'){
 if(!is_admin_user()){header('Location: '.app_url('pos.php'));exit;}
 $modules=[
 ['title'=>'Referral Source Setup','description'=>'Manage referral choices used by POS Sales.','icon'=>'fa-solid fa-bullhorn','url'=>app_url('pos-referral-setup.php?return_to='.rawurlencode(app_url('pos.php?view=setup')))],
 ['title'=>'Plug Commission Setup','description'=>'Manage spark plug commission percentages.','icon'=>'fa-solid fa-percent','url'=>app_url('pos-discount-setup.php?return_to='.rawurlencode(app_url('pos.php?view=setup')))],
 ];
}
?>
<section class="dashboard"><div class="tile-grid tile-grid--three">
<?php if($view==='audit'): foreach([['Stock Deduction','deduction','fa-solid fa-arrow-down'],['Stock Addition','addition','fa-solid fa-arrow-up']] as [$title,$direction,$icon]): ?><a class="module-card" href="#"><span class="module-card__icon"><i class="<?=e($icon)?>"></i></span><span class="module-card__content"><h2><?=e($title)?></h2><p>Direct Sales audit menu.</p></span><span class="module-card__arrow"><i class="fa-solid fa-arrow-right"></i></span></a><?php endforeach; else: ?>
<?php if($view==='sor-audit'): foreach([['Stock Deduction','deduction','fa-solid fa-arrow-down'],['Stock Addition','addition','fa-solid fa-arrow-up']] as [$title,$direction,$icon]): ?><a class="module-card" href="#"><span class="module-card__icon"><i class="<?=e($icon)?>"></i></span><span class="module-card__content"><h2><?=e($title)?></h2><p>SoR audit menu.</p></span><span class="module-card__arrow"><i class="fa-solid fa-arrow-right"></i></span></a><?php endforeach; elseif(in_array($view,['sor-sale','sor-refund','sor-report'],true)): ?><div class="content-panel"><div class="management-heading"><div><span class="section-kicker">SoR</span><h2><?=e(match($view){'sor-sale'=>'Sale','sor-refund'=>'Refund',default=>'Report'})?></h2><p>This menu is ready for its workflow when the requirements are defined.</p></div><div class="management-icon"><i class="fa-solid fa-boxes-stacked"></i></div></div></div><?php else: ?>
<?php foreach($modules as $module): ?><a class="module-card" href="<?=e($module['url'])?>"><span class="module-card__icon"><i class="<?=e($module['icon'])?>"></i></span><span class="module-card__content"><h2><?=e($module['title'])?></h2><p><?=e($module['description'])?></p></span><span class="module-card__arrow"><i class="fa-solid fa-arrow-right"></i></span></a><?php endforeach; ?>
<?php endif; ?><?php endif; ?></div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
