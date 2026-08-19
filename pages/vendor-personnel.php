<?php
require_once __DIR__.'/../config/app.php';
require_module_access('vendor_personnel');
ensure_vendor_personnel_schema();
ensure_user_phone_schema();

$vendor=current_vendor_profile();
$vendorId=(int)($vendor['id']??0);
if(is_admin_user()){
    $requestedVendor=max(0,(int)($_GET['vendor_id']??$_POST['vendor_id']??0));
    if($requestedVendor){$statement=db()->prepare('SELECT * FROM vendors WHERE id=? AND is_active=1');$statement->execute([$requestedVendor]);$vendor=$statement->fetch()?:null;$vendorId=(int)($vendor['id']??0);}
}
$vendors=is_admin_user()?db()->query('SELECT id,vendor_name,phone FROM vendors WHERE is_active=1 ORDER BY vendor_name')->fetchAll():[];
$vendorIsSor=(string)($vendor['vendor_type']??'regular')==='sor';
$view=(string)($_GET['view']??'menu');if(!in_array($view,['menu','existing','create','reports'],true))$view='menu';
if($view==='existing'&&!is_admin_user())$view='menu';
$message=$error='';
if((string)($_GET['created']??'')==='1')$message='Personnel login created. Temporary password: '.VENDOR_DEFAULT_PASSWORD;
$form=['full_name'=>'','email'=>'','phone'=>'','personnel_role'=>'salesperson','can_make_sales'=>$vendorIsSor?'0':'1','can_sor'=>$vendorIsSor?'1':'0','can_transfer'=>'0','can_refund'=>'0','can_audit'=>'0','can_reports'=>'0','is_active'=>'1'];
$editId=max(0,(int)($_GET['edit']??0));
if($editId&&$vendorId){$view='create';$statement=db()->prepare('SELECT vp.*,u.full_name,u.email,u.phone FROM vendor_personnel vp INNER JOIN users u ON u.id=vp.user_id WHERE vp.id=? AND vp.vendor_id=?');$statement->execute([$editId,$vendorId]);if($row=$statement->fetch())foreach(array_keys($form) as $key)$form[$key]=(string)($row[$key]??'');else $editId=0;}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['form_action']??'save');$postedId=max(0,(int)($_POST['personnel_id']??0));
    foreach(array_keys($form) as $key)$form[$key]=trim((string)($_POST[$key]??''));
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    elseif(!$vendorId)$error='Select a vendor account.';
    elseif($action==='assign_staff'&&!is_admin_user()){
        $error='Only an administrator can assign an existing staff account.';
    }
    elseif($action==='assign_staff'){
        $staffUserId=max(0,(int)($_POST['staff_user_id']??0));
        $staffCanSell=!$vendorIsSor&&isset($_POST['can_make_sales'])?1:0;
        $staffCanSor=$vendorIsSor&&isset($_POST['can_sor'])?1:0;
        $staffCanTransfer=isset($_POST['can_transfer'])?1:0;
        $staffCanRefund=isset($_POST['can_refund'])?1:0;
        $staffCanAudit=isset($_POST['can_audit'])?1:0;
        $staffCanReports=isset($_POST['can_reports'])?1:0;
        $statement=db()->prepare("SELECT u.id FROM users u INNER JOIN staff s ON s.user_id=u.id WHERE u.id=? AND u.role='staff' AND u.is_active=1 AND s.is_active=1 LIMIT 1");$statement->execute([$staffUserId]);
        if(!(int)$statement->fetchColumn())$error='Select a valid active staff account.';
        else{
            $statement=db()->prepare('SELECT vp.vendor_id,v.vendor_name FROM vendor_personnel vp INNER JOIN vendors v ON v.id=vp.vendor_id WHERE vp.user_id=? AND vp.is_active=1 AND vp.vendor_id<>? LIMIT 1');$statement->execute([$staffUserId,$vendorId]);$otherAssignment=$statement->fetch();
            if($otherAssignment)$error='That staff member is already assigned to '.(string)$otherAssignment['vendor_name'].'. Remove that assignment first.';
            else{try{db()->prepare("INSERT INTO vendor_personnel(vendor_id,user_id,personnel_role,can_make_sales,can_sor,can_transfer,can_refund,can_audit,can_reports,is_active,added_by_user_id) VALUES(?,?,'salesperson',?,?,?,?,?,?,1,?) ON DUPLICATE KEY UPDATE personnel_role='salesperson',can_make_sales=VALUES(can_make_sales),can_sor=VALUES(can_sor),can_transfer=VALUES(can_transfer),can_refund=VALUES(can_refund),can_audit=VALUES(can_audit),can_reports=VALUES(can_reports),is_active=1,added_by_user_id=VALUES(added_by_user_id)")->execute([$vendorId,$staffUserId,$staffCanSell,$staffCanSor,$staffCanTransfer,$staffCanRefund,$staffCanAudit,$staffCanReports,current_user_id()]);$message='Staff member assigned with the selected POS permissions.';}catch(Throwable $exception){$error='The staff member could not be assigned.';}}
        }
    }
    elseif($action==='delete'){
        $statement=db()->prepare('SELECT user_id FROM vendor_personnel WHERE id=? AND vendor_id=?');$statement->execute([$postedId,$vendorId]);$userId=(int)($statement->fetchColumn()?:0);
        if(!$userId)$error='Personnel record not found.';
        else{db()->beginTransaction();try{db()->prepare('DELETE FROM vendor_personnel WHERE id=? AND vendor_id=?')->execute([$postedId,$vendorId]);db()->prepare("UPDATE users SET is_active=0 WHERE id=? AND role='user'")->execute([$userId]);db()->commit();$message='Personnel access removed successfully.';}catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error='Personnel access could not be removed.';}}
    }elseif($form['full_name']==='')$error='Enter the personnel name.';
    elseif(!is_valid_email_address($form['email']))$error='Enter a valid login email address.';
    elseif($form['phone']!==''&&!is_valid_phone_number($form['phone']))$error='Enter a valid Ghana phone number.';
    elseif(!in_array($form['personnel_role'],['manager','salesperson'],true))$error='Select a valid personnel role.';
    else{
        try{
            $active=$form['is_active']==='0'?0:1;
            $canSell=!$vendorIsSor&&$form['can_make_sales']==='1'?1:0;
            $canSor=$vendorIsSor&&$form['can_sor']==='1'?1:0;
            $canTransfer=$form['can_transfer']==='1'?1:0;
            $canRefund=$form['can_refund']==='1'?1:0;
            $canAudit=$form['can_audit']==='1'?1:0;
            $canReports=$form['can_reports']==='1'?1:0;
            $email=strtolower($form['email']);$phone=normalize_phone_number($form['phone'])?:null;
            db()->beginTransaction();
            if($postedId){
                $statement=db()->prepare('SELECT vp.user_id,u.role FROM vendor_personnel vp INNER JOIN users u ON u.id=vp.user_id WHERE vp.id=? AND vp.vendor_id=?');$statement->execute([$postedId,$vendorId]);$personnelAccount=$statement->fetch();$userId=(int)($personnelAccount['user_id']??0);if(!$userId)throw new DomainException('Personnel record not found.');
                if((string)$personnelAccount['role']!=='staff')db()->prepare('UPDATE users SET full_name=?,email=?,phone=?,is_active=? WHERE id=?')->execute([$form['full_name'],$email,$phone,$active,$userId]);
                db()->prepare('UPDATE vendor_personnel SET personnel_role=?,can_make_sales=?,can_sor=?,can_transfer=?,can_refund=?,can_audit=?,can_reports=?,is_active=? WHERE id=? AND vendor_id=?')->execute([$form['personnel_role'],$canSell,$canSor,$canTransfer,$canRefund,$canAudit,$canReports,$active,$postedId,$vendorId]);
                $message='Personnel account updated successfully.';
            }else{
                $statement=db()->prepare("INSERT INTO users(full_name,email,phone,password_hash,role,force_password_change,is_active) VALUES(?,?,?,?,'user',1,?)");$statement->execute([$form['full_name'],$email,$phone,password_hash(VENDOR_DEFAULT_PASSWORD,PASSWORD_DEFAULT),$active]);$userId=(int)db()->lastInsertId();
                db()->prepare('INSERT INTO vendor_personnel(vendor_id,user_id,personnel_role,can_make_sales,can_sor,can_transfer,can_refund,can_audit,can_reports,is_active,added_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$vendorId,$userId,$form['personnel_role'],$canSell,$canSor,$canTransfer,$canRefund,$canAudit,$canReports,$active,current_user_id()]);
            }
            db()->commit();
            if(!$postedId){header('Location: '.app_url('vendor-personnel.php?created=1'.(is_admin_user()?'&vendor_id='.$vendorId:'')));exit;}
            $editId=0;$form=['full_name'=>'','email'=>'','phone'=>'','personnel_role'=>'salesperson','can_make_sales'=>$vendorIsSor?'0':'1','can_sor'=>$vendorIsSor?'1':'0','can_transfer'=>'0','can_refund'=>'0','can_audit'=>'0','can_reports'=>'0','is_active'=>'1'];
        }catch(PDOException $exception){if(db()->inTransaction())db()->rollBack();$error=$exception->getCode()==='23000'?'That email address or phone number is already attached to another login.':'The personnel account could not be saved.';}
        catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error=$exception->getMessage();}
    }
}
$rows=[];if($vendorId){$statement=db()->prepare('SELECT vp.*,u.full_name,u.email,u.phone,u.force_password_change,adder.full_name added_by FROM vendor_personnel vp INNER JOIN users u ON u.id=vp.user_id LEFT JOIN users adder ON adder.id=vp.added_by_user_id WHERE vp.vendor_id=? ORDER BY vp.is_active DESC,u.full_name');$statement->execute([$vendorId]);$rows=$statement->fetchAll();}
$availableStaff=is_admin_user()?db()->query("SELECT u.id,u.full_name,u.email,s.staff_code FROM users u INNER JOIN staff s ON s.user_id=u.id WHERE u.role='staff' AND u.is_active=1 AND s.is_active=1 ORDER BY u.full_name")->fetchAll():[];
$pageTitle='Personnel Assignment';$breadcrumbs=[['label'=>'POS','url'=>app_url('pos.php')],['label'=>'Personnel Assignment']];$internalBackUrl=app_url('pos.php?view=admin');require_once __DIR__.'/../includes/header.php';
?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">SPW Sales</span><h1>Personnel Assignment</h1><p>Create and assign individual sales logins under <?=e((string)($vendor['vendor_name']??'a vendor account'))?>.</p></div><div class="management-icon"><i class="fa-solid fa-people-group"></i></div></div>
<?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
<?php if(is_admin_user()):?><form method="get" class="record-form"><div class="form-field"><label for="vendor_id">Vendor account</label><select id="vendor_id" name="vendor_id" data-vendor-selector data-popup-select data-popup-search data-popup-hide-empty onchange="this.form.submit()"><option value="">Search or select vendor</option><?php foreach($vendors as $item):?><option value="<?=(int)$item['id']?>" <?=$vendorId===(int)$item['id']?'selected':''?>><?=e(implode(' · ',array_filter([(string)$item['vendor_name'],(string)($item['phone']??'')])))?></option><?php endforeach;?></select></div></form><?php endif;?>
<?php $vendorQuery=is_admin_user()?'&vendor_id='.$vendorId:'';$personnelHome=app_url('vendor-personnel.php'.(is_admin_user()?'?vendor_id='.$vendorId:''));$permissionOptions=[($vendorIsSor?'can_sor':'can_make_sales')=>($vendorIsSor?'SoR Sales':'Sales'),'can_refund'=>'Refund','can_audit'=>'Audit','can_reports'=>'Reports','can_transfer'=>'Transfer']; ?>
<div class="vendor-personnel-menu-wrap">
<?php if($vendorId&&$view==='menu'):?><div class="tile-grid tile-grid--three"><?php if(is_admin_user()):?><a class="module-card" href="<?=e(app_url('vendor-personnel.php?view=existing'.$vendorQuery))?>"><span class="module-card__icon"><i class="fa-solid fa-user-check"></i></span><span class="module-card__content"><h2>Add Existing Staff</h2><p>Assign an existing company staff login to sell for this vendor.</p></span><span class="module-card__arrow"><i class="fa-solid fa-arrow-right"></i></span></a><?php endif;?><a class="module-card" href="<?=e(app_url('vendor-personnel.php?view=create'.$vendorQuery))?>"><span class="module-card__icon"><i class="fa-solid fa-user-plus"></i></span><span class="module-card__content"><h2>Create Personnel</h2><p>Create a new individual login for someone working under this vendor.</p></span><span class="module-card__arrow"><i class="fa-solid fa-arrow-right"></i></span></a><a class="module-card" href="<?=e(app_url('vendor-personnel.php?view=reports'.$vendorQuery))?>"><span class="module-card__icon"><i class="fa-solid fa-chart-column"></i></span><span class="module-card__content"><h2>Reports</h2><p>Review and manage all personnel assigned to this vendor account.</p></span><span class="module-card__arrow"><i class="fa-solid fa-arrow-right"></i></span></a></div><?php endif;?>
</div>
<?php if($vendorId&&$view==='existing'):?><?php if($availableStaff):?><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="vendor_id" value="<?=$vendorId?>"><input type="hidden" name="form_action" value="assign_staff"><div class="form-grid"><div class="form-field"><label for="staff_user_id">Select existing staff</label><select id="staff_user_id" name="staff_user_id" required><option value="">Select staff member</option><?php foreach($availableStaff as $staffMember):?><option value="<?=(int)$staffMember['id']?>"><?=e((string)$staffMember['full_name'].' · '.(string)$staffMember['staff_code'].' · '.(string)$staffMember['email'])?></option><?php endforeach;?></select></div></div><div class="vendor-personnel-access"><p>Select the SPW Sales permissions for this staff member.</p><?php foreach($permissionOptions as $permissionKey=>$permissionLabel):?><label class="standalone-promo-choice"><input type="checkbox" name="<?=e($permissionKey)?>" value="1"><span><strong><?=e($permissionLabel)?></strong><small data-access-state>Not allowed</small></span></label><?php endforeach;?></div><div class="form-actions"><a class="secondary-button" href="<?=e($personnelHome)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><button class="login-button" type="submit"><i class="fa-solid fa-user-check"></i><span>Assign Staff</span></button></div></form><?php else:?><p class="empty-state">No active staff accounts are available.</p><div class="form-actions"><a class="secondary-button" href="<?=e($personnelHome)?>">Back</a></div><?php endif;?><?php endif;?>
<?php if($vendorId&&$view==='create'):?><form method="post" class="record-form mobile-line-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="vendor_id" value="<?=$vendorId?>"><input type="hidden" name="personnel_id" value="<?=$editId?>"><input type="hidden" name="form_action" value="save"><div class="form-grid">
<?php foreach(['full_name'=>'Full name','email'=>'Login email','phone'=>'Phone'] as $key=>$label):?><div class="form-field"><label for="<?=$key?>"><?=$label?></label><input id="<?=$key?>" name="<?=$key?>" type="<?=$key==='email'?'email':'text'?>" value="<?=e($form[$key])?>" <?=$key!=='phone'?'required':''?>></div><?php endforeach;?>
<div class="form-field"><label for="personnel_role">Role</label><select id="personnel_role" name="personnel_role"><option value="salesperson" <?=$form['personnel_role']==='salesperson'?'selected':''?>>Salesperson</option><option value="manager" <?=$form['personnel_role']==='manager'?'selected':''?>>Manager</option></select></div>
<div class="form-field"><label for="is_active">Status</label><select id="is_active" name="is_active"><option value="1" <?=$form['is_active']!=='0'?'selected':''?>>Active</option><option value="0" <?=$form['is_active']==='0'?'selected':''?>>Inactive</option></select></div>
<div class="vendor-personnel-access"><p>Select each SPW Sales permission separately.</p><?php foreach($permissionOptions as $permissionKey=>$permissionLabel):$permissionAllowed=$form[$permissionKey]==='1';?><label class="standalone-promo-choice"><input type="checkbox" name="<?=e($permissionKey)?>" value="1" <?=$permissionAllowed?'checked':''?>><span><strong><?=e($permissionLabel)?></strong><small data-access-state><?=$permissionAllowed?'Allowed':'Not allowed'?></small></span></label><?php endforeach;?></div></div><div class="form-actions"><a class="secondary-button" href="<?=e($personnelHome)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span><?=$editId?'Update personnel':'Create Personnel'?></span></button></div></form><?php endif;?></section>
<?php if($vendorId&&$view==='reports'):?><section class="management-panel management-panel--table"><div class="management-heading"><div><span class="section-kicker">Personnel reports</span><h2>Personnel Permissions</h2><p><?=number_format(count($rows))?> personnel record<?=count($rows)===1?'':'s'?> assigned to this vendor.</p></div><a class="secondary-button" href="<?=e($personnelHome)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Login</th><th>Role</th><th>Sales</th><th>Refund</th><th>Audit</th><th>Reports</th><th>Transfer</th><th>Status</th><th>Added by</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $row):$salesAllowed=(int)$row['can_make_sales']===1||(int)$row['can_sor']===1;?><tr><td><strong><?=e((string)$row['full_name'])?></strong><span class="muted-text"><?=e((string)($row['phone']??''))?></span></td><td><?=e((string)$row['email'])?></td><td><?=e(ucfirst((string)$row['personnel_role']))?></td><?php foreach([$salesAllowed,(int)$row['can_refund']===1,(int)$row['can_audit']===1,(int)$row['can_reports']===1,(int)$row['can_transfer']===1] as $allowed):?><td><span class="status-badge <?=$allowed?'is-active':'is-inactive'?>"><?=$allowed?'Allowed':'Blocked'?></span></td><?php endforeach;?><td><span class="status-badge <?=$row['is_active']?'is-active':'is-inactive'?>"><?=$row['is_active']?'Active':'Inactive'?></span></td><td><?=e((string)($row['added_by']??'System'))?></td><td><div class="table-actions"><a class="action-button" href="<?=e(app_url('vendor-personnel.php?view=create&edit='.(int)$row['id'].$vendorQuery))?>"><i class="fa-solid fa-pen"></i></a><form method="post" data-confirm-title="Remove personnel access?" data-confirm-message="The login will be disabled, while recorded sales remain in the system."><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="vendor_id" value="<?=$vendorId?>"><input type="hidden" name="personnel_id" value="<?=(int)$row['id']?>"><input type="hidden" name="form_action" value="delete"><button class="action-button action-button--danger" type="submit"><i class="fa-solid fa-trash"></i></button></form></div></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="11" class="empty-state">No personnel have been added yet.</td></tr><?php endif;?></tbody></table></div></section><?php endif;?>
<script>
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.vendor-personnel-access input[type="checkbox"]').forEach(function(toggle){const state=toggle.closest('label')?.querySelector('[data-access-state]');const sync=()=>{if(state)state.textContent=toggle.checked?'Allowed':'Not allowed';};toggle.addEventListener('change',sync);sync();});
});
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
