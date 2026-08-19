<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$canAttendance=can_access_module('attendance');
$canCustomerFollowup=can_access_module('sales_trip')||can_access_module('customer_followup');
$canVehicleLog=can_access_menu_item('admin_vehicle_log');
$canSystemAdmin=can_access_module('admin');
$canReports=can_access_menu_item('admin_reports');
if(!$canAttendance&&!$canCustomerFollowup&&!$canVehicleLog&&!$canSystemAdmin&&!$canReports){header('Location: '.app_url('index.php'));exit;}

$view=(string)($_GET['view']??'menu');
if(!in_array($view,['menu','system','setup','assignment'],true))$view='menu';
if($view!=='menu'&&!$canSystemAdmin)$view='menu';
$pageTitle=match($view){'system'=>'Administration','setup'=>'Admin Setup','assignment'=>'Admin Assignment',default=>'Admin'};
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Admin','url'=>app_url('admin.php')]];
if($view!=='menu')$breadcrumbs[]=['label'=>$pageTitle];
$internalBackUrl=$view==='menu'?app_url('index.php'):requested_return_url($view==='system'?app_url('admin.php'):app_url('admin.php?view=system'));
require_once __DIR__ . '/../includes/header.php';

$modules=[];
if($view==='menu'){
 if($canAttendance)$modules[]=['title'=>'Attendance','description'=>'Mark attendance and open attendance tools.','icon'=>'fa-solid fa-calendar-check','url'=>app_url('attendance.php?return_to='.rawurlencode(app_url('admin.php')))];
 if($canCustomerFollowup)$modules[]=['title'=>'Customer Follow-up','description'=>'Find registered customers and record phone or physical follow-ups.','icon'=>'fa-solid fa-clipboard-check','url'=>app_url('followup.php?return_to='.rawurlencode(app_url('admin.php')))];
 if($canVehicleLog)$modules[]=['title'=>'Vehicle Log','description'=>'Open fuel, log book, and fleet movement records.','icon'=>'fa-solid fa-car-side','url'=>app_url('vehicles.php?return_to='.rawurlencode(app_url('admin.php')))];
 if($canSystemAdmin)$modules[]=['title'=>'Admin','description'=>'Open system setup and assignment menus.','icon'=>'fa-solid fa-gears','url'=>app_url('admin.php?view=system')];
 if($canReports)$modules[]=['title'=>'Reports','description'=>'Open administrative and operational reports.','icon'=>'fa-solid fa-chart-line','url'=>app_url('reports.php?return_to='.rawurlencode(app_url('admin.php')))];
}elseif($view==='system'){
 $modules[]=['title'=>'Setup','description'=>'Configure accounts, staff, vehicles, locations, and system defaults.','icon'=>'fa-solid fa-sliders','url'=>app_url('admin.php?view=setup')];
 $modules[]=['title'=>'Assignment','description'=>'Assign staff, vendors, menus, towns, and customers.','icon'=>'fa-solid fa-clipboard-check','url'=>app_url('admin.php?view=assignment')];
 if(is_super_admin())$modules[]=['title'=>'Vendor Password Reset','description'=>'Reset a vendor login to a temporary password.','icon'=>'fa-solid fa-key','url'=>app_url('vendor-password-reset.php')];
}elseif($view==='setup'){
 $modules=[
 ['title'=>'Accounts','description'=>'Prepare user accounts and access controls.','icon'=>'fa-solid fa-users-gear','url'=>app_url('accounts.php')],
 ['title'=>'System Setup','description'=>'Configure core operational defaults and lookup lists.','icon'=>'fa-solid fa-sliders','url'=>app_url('setup.php')],
 ['title'=>'Staff Setup','description'=>'Manage staff profiles and team records.','icon'=>'fa-solid fa-id-card-clip','url'=>app_url('staff-setup.php')],
 ['title'=>'Vehicle Setup','description'=>'Manage vehicles available to trips and vehicle logs.','icon'=>'fa-solid fa-car-side','url'=>app_url('vehicle-setup.php')],
 ['title'=>'Attendance Setup','description'=>'Manage attendance sessions and GPS locations.','icon'=>'fa-solid fa-calendar-plus','url'=>app_url('attendance-setup.php')],
 ];
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
