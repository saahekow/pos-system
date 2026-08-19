<?php
require_once __DIR__.'/../config/app.php';
require_module_access('admin');
ensure_vendor_module_assignments_schema();
ensure_vendor_town_assignments_schema();
$vendorProvisioning=provision_unlinked_vendor_accounts();

$pageTitle='Vendor Assignments';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Admin','url'=>app_url('admin.php')],['label'=>'Vendor Assignments']];
$internalBackUrl=requested_return_url(app_url('admin.php?view=assignment'));
$message=$error='';
$vendorId=max(0,(int)($_GET['vendor_id']??$_POST['vendor_id']??0));
$vendorModules=array_intersect_key(application_modules(),array_flip(vendor_assignable_module_keys()));
$vendorModules['customer_visit']['title']='Marketing — Trip';$vendorModules['customer_visit']['description']='Open location, customer, sales, and Promo Plug workflows under Marketing Trip.';
$vendorModules['customer_followup']['title']='Customer Follow-up';$vendorModules['customer_followup']['description']='Keep Customer Follow-up as a direct Home menu until it is placed.';
$vendorModules['vendor_customers']['title']='Marketing — Customer Setup';$vendorModules['vendor_customers']['description']='Create customers from the Marketing administration flow.';
$vendorModules['vendor_reports']['title']='Marketing — Reports';$vendorModules['vendor_reports']['description']='Open Marketing customer reports. This remains enabled by default.';
$vendorModules['pos']['description']='Open the new POS menu and permitted POS workflows.';
$vendorModules['vin_search']['title']='Data Management';$vendorModules['vin_search']['description']='Open Data Management and VIN Search 1.';
$vendorModules['registration_records']['description']='Keep Registration Records as a direct Home menu. This remains enabled by default.';
$vendorModuleGroups=[
 ['title'=>'Marketing','items'=>array_intersect_key($vendorModules,array_flip(['customer_visit','vendor_customers','vendor_reports']))],
 ['title'=>'POS','items'=>array_intersect_key($vendorModules,array_flip(['pos']))],
 ['title'=>'Data Management','items'=>array_intersect_key($vendorModules,array_flip(['vin_search']))],
 ['title'=>'Unassigned Home Menus','items'=>array_intersect_key($vendorModules,array_flip(['customer_followup','registration_records']))],
];
$defaultModules=['registration_records','vendor_reports'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    $moduleKeys=array_values(array_unique(array_merge($defaultModules,array_intersect(array_keys($vendorModules),array_map('strval',(array)($_POST['module_keys']??[]))))));
    $townIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['location_ids']??[])),static function (int $id): bool { return $id>0; })));
    $vendorCheck=db()->prepare('SELECT id,location_id FROM vendors WHERE id=? AND is_active=1 LIMIT 1');$vendorCheck->execute([$vendorId]);$vendorRecord=$vendorCheck->fetch();$primaryTownId=(int)($vendorRecord['location_id']??0);
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    elseif(!$vendorRecord)$error='Select a valid active vendor.';
    elseif(!$primaryTownId&&!$townIds)$error='Select at least one managed town. The first selection will become the primary town.';
    else{
        if($primaryTownId)$townIds=array_values(array_unique(array_merge([$primaryTownId],$townIds)));
        if($townIds){$marks=implode(',',array_fill(0,count($townIds),'?'));$check=db()->prepare("SELECT COUNT(*) FROM locations WHERE is_active=1 AND id IN ($marks)");$check->execute($townIds);if((int)$check->fetchColumn()!==count($townIds))$error='One or more selected locations are invalid.';}
    }
    if($error===''){db()->beginTransaction();try{
        if(!$primaryTownId){$primaryTownId=(int)$townIds[0];db()->prepare('UPDATE vendors SET location_id=? WHERE id=?')->execute([$primaryTownId,$vendorId]);}
        db()->prepare('DELETE FROM vendor_module_assignments WHERE vendor_id=?')->execute([$vendorId]);$moduleInsert=db()->prepare('INSERT INTO vendor_module_assignments (vendor_id,module_key,assigned_by_user_id) VALUES (?,?,?)');foreach($moduleKeys as $key)$moduleInsert->execute([$vendorId,$key,current_user_id()]);
        db()->prepare('DELETE FROM vendor_town_assignments WHERE vendor_id=?')->execute([$vendorId]);$townInsert=db()->prepare('INSERT INTO vendor_town_assignments (vendor_id,location_id,assigned_by_user_id) VALUES (?,?,?)');foreach($townIds as $townId)if($townId!==$primaryTownId)$townInsert->execute([$vendorId,$townId,current_user_id()]);
        db()->commit();$message='Vendor menus and managed towns updated successfully.';
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error='Vendor assignments could not be updated.';}}
}

$vendors=db()->query("SELECT v.id,v.vendor_name,v.phone,v.email,v.location_id,l.town_name,u.id user_account_id FROM vendors v LEFT JOIN users u ON u.id=v.user_id AND u.role='vendor' LEFT JOIN locations l ON l.id=v.location_id WHERE v.is_active=1 ORDER BY v.vendor_name")->fetchAll();
$selectedVendor=null;foreach($vendors as $vendor)if((int)$vendor['id']===$vendorId){$selectedVendor=$vendor;break;}
$towns=active_locations();
$assignedTownIds=$vendorId?array_map(static function (array $row): int { return (int)$row['id']; },assigned_towns_for_vendor($vendorId)):[];
$assignedKeys=$vendorId?assigned_module_keys_for_vendor($vendorId):[];
$rows=db()->query("SELECT v.id,v.vendor_name,v.email,u.id user_account_id,pt.town_name primary_town,GROUP_CONCAT(DISTINCT at.town_name ORDER BY at.town_name SEPARATOR ', ') assigned_towns FROM vendors v LEFT JOIN users u ON u.id=v.user_id AND u.role='vendor' LEFT JOIN locations pt ON pt.id=v.location_id LEFT JOIN vendor_town_assignments vta ON vta.vendor_id=v.id AND vta.is_active=1 LEFT JOIN locations at ON at.id=vta.location_id WHERE v.is_active=1 GROUP BY v.id,v.vendor_name,v.email,u.id,pt.town_name ORDER BY v.vendor_name")->fetchAll();
require_once __DIR__.'/../includes/header.php';
?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">Admin</span><h1>Vendor Assignments</h1><p>Assign vendor menus and all towns where each vendor may manage customers.</p></div><div class="management-icon"><i class="fa-solid fa-store"></i></div></div>
<?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
<?php if(!$vendors):?><p class="empty-state">No active vendors available.</p><?php else:?><form class="record-form assignment-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><div class="assignment-layout"><div class="assignment-staff-panel"><span class="section-kicker">Vendor Account</span><label for="vendor_lookup">Vendor</label><input type="hidden" name="vendor_id" value="<?=$vendorId?>" data-assignment-account-id><input id="vendor_lookup" type="search" list="vendor_assignment_accounts" value="<?=$selectedVendor?e((string)$selectedVendor['vendor_name'].' - '.(string)($selectedVendor['town_name']??'No primary town')):''?>" placeholder="Type vendor name or town" autocomplete="off" data-assignment-account-lookup data-assignment-account-url="<?=e(app_url('vendor-assignments.php?vendor_id='))?>"><datalist id="vendor_assignment_accounts"><?php foreach($vendors as $vendor):?><option value="<?=e((string)$vendor['vendor_name'].' - '.(string)($vendor['town_name']??'No primary town'))?>" data-account-id="<?=(int)$vendor['id']?>"></option><?php endforeach;?></datalist><p class="muted-text"><?=$selectedVendor?'Primary town: '.e((string)($selectedVendor['town_name']??'Not assigned')):'Select a vendor to manage access.'?></p></div><div class="assignment-permissions-panel"><span class="section-kicker">Menu Access</span><div class="permission-groups"><?php foreach($vendorModuleGroups as $group):?><section class="permission-group" data-permission-group><label class="permission-group__heading"><input type="checkbox" data-permission-group-toggle><span><strong><?=e((string)$group['title'])?></strong><small>Select every optional menu in this group</small></span></label><div class="permission-list"><?php foreach($group['items'] as $key=>$module):$default=in_array($key,$defaultModules,true);?><label class="permission-row"><input type="checkbox" name="module_keys[]" value="<?=e($key)?>" data-permission-item <?=in_array($key,$assignedKeys,true)?'checked':''?> <?=$default?'disabled':''?>><?php if($default):?><input type="hidden" name="module_keys[]" value="<?=e($key)?>"><?php endif;?><span class="permission-row__icon"><i class="<?=e((string)$module['icon'])?>"></i></span><span class="permission-row__text"><strong><?=e((string)$module['title'])?><?=$default?' (Default)':''?></strong><small><?=e((string)$module['description'])?></small></span></label><?php endforeach;?></div></section><?php endforeach;?></div></div></div>
<div class="vendor-town-picker"><div class="vendor-town-picker__header"><div><span class="section-kicker">Customer Coverage</span><h2>Managed locations</h2><p>Select every location where this vendor can create and view customers.</p></div><span class="access-pill" data-town-assignment-count><?=number_format(count($assignedTownIds))?> selected</span></div><div class="vendor-town-picker__list" data-town-assignment-list><?php foreach($towns as $town):$isPrimary=(int)$town['id']===(int)($selectedVendor['location_id']??0);?><label class="vendor-town-option" data-town-assignment-item data-town-id="<?=(int)$town['id']?>"><input type="checkbox" name="location_ids[]" value="<?=(int)$town['id']?>" <?=in_array((int)$town['id'],$assignedTownIds,true)?'checked':''?> <?=$isPrimary?'disabled':''?>><span class="vendor-town-option__icon"><i class="fa-solid fa-location-dot"></i></span><span class="vendor-town-option__text"><strong><?=e((string)$town['town_name'])?></strong><small><?=e((string)$town['region_name'].' · '.(string)$town['mmda_name'])?></small></span><?php if($isPrimary):?><span class="vendor-town-option__primary">Primary</span><?php endif;?></label><?php endforeach;?></div></div>
<div class="form-actions"><button class="login-button"><span>Save vendor assignments</span><i class="fa-solid fa-floppy-disk"></i></button></div></form><?php endif;?></section>
<section class="management-panel management-panel--table"><div class="management-heading management-heading--compact"><div><span class="section-kicker">Records</span><h2>Vendor Town Access</h2></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Vendor</th><th>Login</th><th>Primary town</th><th>Additional managed towns</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?=e((string)$row['vendor_name'])?></td><td><span class="status-badge <?=$row['user_account_id']?'is-active':'is-warning'?>"><?=$row['user_account_id']?'Phone login ready':'Pending valid phone'?></span></td><td><?=e((string)($row['primary_town']??''))?></td><td><?=e((string)($row['assigned_towns']??'None'))?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php require_once __DIR__.'/../includes/footer.php';?>
