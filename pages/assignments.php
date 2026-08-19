<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('admin');

$pageTitle='Staff Assignments';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Admin','url'=>app_url('admin.php')],['label'=>'Staff Assignments']];
$internalBackUrl=requested_return_url(app_url('admin.php?view=assignment'));
$message=$error='';
$selectedStaffId=max(0,(int)($_GET['staff_id']??$_POST['staff_id']??0));
$assignableModules=staff_child_menu_definitions();
$assignableGroups=[];foreach($assignableModules as $key=>$module){$group=(string)$module['group'];if(!isset($assignableGroups[$group]))$assignableGroups[$group]=['title'=>$group,'items'=>[]];$assignableGroups[$group]['items'][$key]=$module;}
$assignmentModuleTitles=array_map(static fn(array $module):string=>(string)$module['title'],$assignableModules);

if($_SERVER['REQUEST_METHOD']==='POST'){
    $keys=array_values(array_intersect(array_keys($assignableModules),array_map('strval',(array)($_POST['module_keys']??[]))));
    $check=db()->prepare("SELECT COUNT(*) FROM staff s INNER JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.is_active=1 AND u.role='staff'");$check->execute([$selectedStaffId]);
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    elseif(!(int)$check->fetchColumn())$error='Select a valid active staff account.';
    else{db()->beginTransaction();try{db()->prepare('DELETE FROM module_assignments WHERE staff_id=?')->execute([$selectedStaffId]);$insert=db()->prepare('INSERT INTO module_assignments (staff_id,module_key,assigned_by_user_id) VALUES (?,?,?)');foreach($keys as $key)$insert->execute([$selectedStaffId,$key,current_user_id()]);db()->commit();$message='Staff menu assignments updated successfully.';}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error='Staff assignments could not be updated.';}}
}

$staffList=db()->query("SELECT s.id,s.staff_code,s.full_name,s.email FROM staff s INNER JOIN users u ON u.id=s.user_id WHERE u.role='staff' AND s.is_active=1 ORDER BY s.full_name")->fetchAll();
if(!$selectedStaffId&&$staffList)$selectedStaffId=(int)$staffList[0]['id'];
$selectedStaff=null;foreach($staffList as $staff)if((int)$staff['id']===$selectedStaffId){$selectedStaff=$staff;break;}
$assignedKeys=$selectedStaffId?assigned_module_keys_for_staff($selectedStaffId):[];
$rows=db()->query("SELECT s.staff_code,s.full_name,s.email,GROUP_CONCAT(ma.module_key ORDER BY ma.module_key SEPARATOR ', ') modules FROM staff s INNER JOIN users u ON u.id=s.user_id LEFT JOIN module_assignments ma ON ma.staff_id=s.id AND ma.is_active=1 WHERE u.role='staff' GROUP BY s.id,s.staff_code,s.full_name,s.email ORDER BY s.full_name")->fetchAll();
require_once __DIR__.'/../includes/header.php';
?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">Admin</span><h1>Staff Assignments</h1><p>Assign optional operational menus to staff accounts. Staff Attendance is available automatically.</p></div><div class="management-icon"><i class="fa-solid fa-id-card-clip"></i></div></div>
<?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
<?php if(!$staffList):?><p class="empty-state">No active staff accounts available.</p><?php else:?><form class="record-form assignment-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><div class="assignment-layout"><div class="assignment-staff-panel"><span class="section-kicker">Staff Account</span><label for="staff_lookup">Staff</label><input type="hidden" name="staff_id" value="<?=$selectedStaffId?>" data-assignment-account-id><input id="staff_lookup" type="search" list="staff_assignment_accounts" value="<?=e((string)($selectedStaff['staff_code']??'').' - '.(string)($selectedStaff['full_name']??''))?>" placeholder="Type staff code or name" autocomplete="off" data-assignment-account-lookup data-assignment-account-url="<?=e(app_url('assignments.php?staff_id='))?>"><datalist id="staff_assignment_accounts"><?php foreach($staffList as $staff):?><option value="<?=e((string)$staff['staff_code'].' - '.(string)$staff['full_name'])?>" data-account-id="<?=(int)$staff['id']?>"></option><?php endforeach;?></datalist></div><div class="assignment-permissions-panel"><span class="section-kicker">Menu Access</span><div class="permission-groups"><?php foreach($assignableGroups as $group):?><section class="permission-group" data-permission-group><label class="permission-group__heading"><input type="checkbox" data-permission-group-toggle><span><strong><?=e((string)$group['title'])?></strong><small>Select every menu in this group</small></span></label><div class="permission-list"><?php foreach($group['items'] as $key=>$module):?><label class="permission-row"><input type="checkbox" name="module_keys[]" value="<?=e($key)?>" data-permission-item <?=in_array($key,$assignedKeys,true)?'checked':''?>><span class="permission-row__icon"><i class="<?=e((string)$module['icon'])?>"></i></span><span class="permission-row__text"><strong><?=e((string)$module['title'])?></strong><small><?=e((string)$module['description'])?></small></span></label><?php endforeach;?></div></section><?php endforeach;?></div></div></div><div class="form-actions"><button class="login-button"><span>Save staff assignments</span><i class="fa-solid fa-floppy-disk"></i></button></div></form><?php endif;?></section>
<section class="management-panel management-panel--table"><div class="management-heading management-heading--compact"><div><span class="section-kicker">Records</span><h2>Staff Menu Access</h2></div></div><?php if(!$rows):?><p class="empty-state">No staff records available.</p><?php else:?><div class="table-wrap"><table class="data-table data-table--compact"><thead><tr><th>Staff ref</th><th>Name</th><th>Email</th><th>Menus</th></tr></thead><tbody><?php foreach($rows as $row):$keys=array_filter(array_map('trim',explode(',',(string)($row['modules']??''))));?><tr><td><?=e((string)$row['staff_code'])?></td><td><?=e((string)$row['full_name'])?></td><td><?=e((string)$row['email'])?></td><td><div class="access-pill-list"><?php foreach($keys as $key):?><span class="access-pill"><?=e((string)($assignmentModuleTitles[$key]??$key))?></span><?php endforeach;?></div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php require_once __DIR__.'/../includes/footer.php';?>
