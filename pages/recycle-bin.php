<?php
require_once __DIR__.'/../config/app.php';
require_auth();
ensure_recycle_bin_schema();

$menus=[
    'marketing'=>['title'=>'Marketing Reports','return'=>'reports.php','sections'=>['customers'=>'Customers','vendors'=>'Vendors','staff'=>'Staff','attendance'=>'Attendance','vehicles'=>'Vehicle Records','feedback'=>'Feedback','followups'=>'Follow-up Reports','visit-summary'=>'Trip']],
    'pos'=>['title'=>'POS Reports','return'=>'pos-reports.php','sections'=>['sales'=>'Sales','transfers'=>'Transfers','refunds'=>'Refunds','notes'=>'Notes']],
    'vendor'=>['title'=>'Vendor Reports','return'=>'vendor-reports.php','sections'=>['customers'=>'Customers','vendors'=>'Vendors','personnel'=>'Personnel']],
];
$module=(string)($_GET['module']??$_POST['module']??'marketing');if(!isset($menus[$module]))$module='marketing';
$section=(string)($_GET['section']??$_POST['section']??array_key_first($menus[$module]['sections']));if(!isset($menus[$module]['sections'][$section]))$section=array_key_first($menus[$module]['sections']);
$returnUrl=requested_return_url(app_url($menus[$module]['return']));
$error='';$message='';
if($_SERVER['REQUEST_METHOD']==='POST'&&(string)($_POST['form_action']??'')==='restore'){
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    elseif(!is_admin_user())$error='Only an administrator can restore deleted records.';
    else{try{db()->beginTransaction();restore_soft_deleted_record(max(0,(int)($_POST['deletion_id']??0)));db()->commit();$message='Record restored successfully.';}catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error=$exception instanceof DomainException?$exception->getMessage():'The record could not be restored.';}}
}
$recordModule=$module==='marketing'&&$section==='vendors'?'vendor':$module;
$sql='SELECT rd.*,u.full_name deleted_by FROM record_deletions rd LEFT JOIN users u ON u.id=rd.deleted_by_user_id WHERE rd.module_key=? AND rd.section_key=? AND rd.restored_at IS NULL';$params=[$recordModule,$section];
if(!is_admin_user()){$sql.=' AND rd.deleted_by_user_id=?';$params[]=current_user_id();}$sql.=' ORDER BY rd.deleted_at DESC,rd.id DESC';$statement=db()->prepare($sql);$statement->execute($params);$rows=$statement->fetchAll();
$pageTitle=$menus[$module]['title'].' Recycle Bin';$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>$menus[$module]['title'],'url'=>$returnUrl],['label'=>'Recycle Bin']];require_once __DIR__.'/../includes/header.php';
?>
<section class="management-panel management-panel--table">
<div class="management-heading"><div><span class="section-kicker">Recycle Bin</span><h1><?=e($menus[$module]['title'])?></h1><p>Deleted records remain stored and can be reviewed here.</p></div><a class="secondary-button" href="<?=e($returnUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Back to Reports</span></a></div>
<?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
<nav class="recycle-report-menu" aria-label="Deleted report types"><?php foreach($menus[$module]['sections'] as $key=>$label):?><a class="<?=$section===$key?'is-active':''?>" href="<?=e(app_url('recycle-bin.php?module='.$module.'&section='.$key.'&return_to='.rawurlencode($returnUrl)))?>"><?=e($label)?></a><?php endforeach;?></nav>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Record</th><th>Reference</th><th>Reason</th><th>Deleted by</th><th>Deleted</th><th>Action</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><td><?=e((string)$row['record_label'])?></td><td><strong><?=e((string)($row['record_reference']?:'#'.$row['entity_id']))?></strong></td><td><?=nl2br(e((string)$row['deletion_reason']))?></td><td><?=e((string)($row['deleted_by']?:'Unknown'))?></td><td><?=e(date('d M Y, H:i',strtotime((string)$row['deleted_at'])))?></td><td><?php if(is_admin_user()):?><form method="post" data-confirm-title="Restore record?" data-confirm-message="This record will return to its normal report."><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="restore"><input type="hidden" name="module" value="<?=e($module)?>"><input type="hidden" name="section" value="<?=e($section)?>"><input type="hidden" name="return_to" value="<?=e($returnUrl)?>"><input type="hidden" name="deletion_id" value="<?=(int)$row['id']?>"><button class="action-button" type="submit"><i class="fa-solid fa-rotate-left"></i><span>Restore</span></button></form><?php else:?><span class="muted-text">Admin restore</span><?php endif;?></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="6" class="empty-state">No deleted <?=e(strtolower($menus[$module]['sections'][$section]))?> records.</td></tr><?php endif;?></tbody></table></div>
</section>
<?php require_once __DIR__.'/../includes/footer.php';
