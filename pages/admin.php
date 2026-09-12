<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$setupPermissionKeys=['setup_accounts','setup_roles','setup_feedback','setup_referrals','setup_commissions','setup_destinations','setup_locations','setup_vendors','setup_shop_types','setup_customer_types','setup_vehicles','setup_staff'];
$canSetup=(bool)array_filter($setupPermissionKeys,'can_access_menu_item');
$canFullAdmin=is_admin_user()||(current_user_role()==='staff'&&in_array('admin',current_user_assigned_module_keys(),true));
$canSystemAdmin=$canFullAdmin||$canSetup;
$canReports=can_access_menu_item('admin_reports');
if(!$canSystemAdmin&&!$canReports){header('Location: '.app_url('index.php'));exit;}

$view=(string)($_GET['view']??'menu');
if(!in_array($view,['menu','system','setup','assignment'],true))$view='menu';
if($view!=='menu'&&!$canSystemAdmin)$view='menu';
if($view==='setup'&&!$canSetup)$view=$canFullAdmin?'system':'menu';
if($view==='assignment'&&!$canFullAdmin)$view=$canSetup?'system':'menu';
$pageTitle=match($view){'system'=>'Administration','setup'=>'Admin Setup','assignment'=>'Admin Assignment',default=>'Admin'};
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Admin','url'=>app_url('admin.php')]];
if($view!=='menu')$breadcrumbs[]=['label'=>$pageTitle];
$internalBackUrl=$view==='menu'?app_url('index.php'):requested_return_url($view==='system'?app_url('admin.php'):app_url('admin.php?view=system'));
require_once __DIR__ . '/../includes/header.php';

$modules=[];
if($view==='menu'){
 if($canFullAdmin)$modules[]=['title'=>'Admin','description'=>'Open system setup and assignment menus.','icon'=>'fa-solid fa-gears','url'=>app_url('admin.php?view=system')];
 elseif($canSetup)$modules[]=['title'=>'Setup','description'=>'Open the setup menus assigned to your account.','icon'=>'fa-solid fa-sliders','url'=>app_url('setup.php')];
 if($canReports)$modules[]=['title'=>'Reports','description'=>'Open administrative and operational reports.','icon'=>'fa-solid fa-chart-line','url'=>app_url('reports.php?return_to='.rawurlencode(app_url('admin.php')))];
}elseif($view==='system'){
 if($canSetup)$modules[]=['title'=>'Setup','description'=>'Configure the setup menus assigned to your account.','icon'=>'fa-solid fa-sliders','url'=>app_url('admin.php?view=setup')];
 if($canFullAdmin)$modules[]=['title'=>'Assignment','description'=>'Assign staff, vendors, menus, towns, and customers.','icon'=>'fa-solid fa-clipboard-check','url'=>app_url('admin.php?view=assignment')];
 if(is_super_admin())$modules[]=['title'=>'Vendor Password Reset','description'=>'Reset a vendor login to a temporary password.','icon'=>'fa-solid fa-key','url'=>app_url('vendor-password-reset.php')];
}elseif($view==='setup'){
 $modules=[];
 if(can_access_menu_item('setup_accounts'))$modules[]=['title'=>'Accounts','description'=>'Prepare user accounts and access controls.','icon'=>'fa-solid fa-users-gear','url'=>app_url('accounts.php')];
 $modules[]=['title'=>'System Setup','description'=>'Open all setup menus assigned to your account.','icon'=>'fa-solid fa-sliders','url'=>app_url('setup.php')];
 if(can_access_menu_item('setup_staff'))$modules[]=['title'=>'Staff Setup','description'=>'Manage staff profiles and team records.','icon'=>'fa-solid fa-id-card-clip','url'=>app_url('staff-setup.php')];
 if(can_access_menu_item('setup_vehicles'))$modules[]=['title'=>'Vehicle Setup','description'=>'Manage vehicles available to trips and vehicle logs.','icon'=>'fa-solid fa-car-side','url'=>app_url('vehicle-setup.php')];
}else{
 $modules=[
 ['title'=>'Staff Assignments','description'=>'Assign operational menu access to staff accounts.','icon'=>'fa-solid fa-user-check','url'=>app_url('assignments.php?return_to='.rawurlencode(app_url('admin.php?view=assignment')))],
 ['title'=>'Vendor Assignments','description'=>'Assign vendor menus and towns.','icon'=>'fa-solid fa-store','url'=>app_url('vendor-assignments.php?return_to='.rawurlencode(app_url('admin.php?view=assignment')))],
 ['title'=>'Assign Existing Customers','description'=>'Assign vendors to existing customer visits.','icon'=>'fa-solid fa-user-tag','url'=>app_url('customer-vendor-assignments.php?return_to='.rawurlencode(app_url('admin.php?view=assignment')))],
 ];
}
?>
<section class="dashboard"><div class="tile-grid tile-grid--three"><?php foreach($modules as $module): ?><a class="module-card" href="<?=e($module['url'])?>"><span class="module-card__icon"><i class="<?=e($module['icon'])?>"></i></span><span class="module-card__content"><h2><?=e($module['title'])?></h2><p><?=e($module['description'])?></p></span><span class="module-card__arrow"><i class="fa-solid fa-arrow-right"></i></span></a><?php endforeach; ?></div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
