<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('admin');
ensure_pos_referral_source_schema();

$pageTitle = 'Referral Source Setup';
$breadcrumbs = [
    ['label'=>'Home','url'=>app_url('index.php')],
    ['label'=>'Admin','url'=>app_url('admin.php')],
    ['label'=>'Setup','url'=>app_url('setup.php')],
    ['label'=>'Referral Source Setup'],
];
$internalBackUrl=requested_return_url(app_url('pos.php?view=setup'));
$message = '';
$error = '';
$editId = max(0,(int)($_GET['edit'] ?? 0));
$sourceName = '';
$status = '1';

if ($editId > 0) {
    $statement = db()->prepare('SELECT id,source_name,is_active FROM pos_referral_sources WHERE id=?');
    $statement->execute([$editId]);
    $editRow = $statement->fetch();
    if ($editRow) {
        $sourceName = (string)$editRow['source_name'];
        $status = (string)(int)$editRow['is_active'];
    } else $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['form_action'] ?? 'save');
    $postedId = max(0,(int)($_POST['source_id'] ?? 0));
    $sourceName = trim((string)($_POST['source_name'] ?? ''));
    $status = (string)($_POST['status'] ?? '1');
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete' && $postedId > 0) {
        db()->prepare('DELETE FROM pos_referral_sources WHERE id=?')->execute([$postedId]);
        $message = 'Referral source deleted successfully.';
        $sourceName = ''; $status = '1'; $editId = 0;
    } elseif ($sourceName === '') {
        $error = 'Referral source name is required.';
    } else {
        try {
            if ($postedId > 0) {
                db()->prepare('UPDATE pos_referral_sources SET source_name=?,is_active=? WHERE id=?')->execute([$sourceName,$status==='0'?0:1,$postedId]);
                $message = 'Referral source updated successfully.';
            } else {
                db()->prepare('INSERT INTO pos_referral_sources(source_name,is_active) VALUES(?,?)')->execute([$sourceName,$status==='0'?0:1]);
                $message = 'Referral source added successfully.';
            }
            $sourceName = ''; $status = '1'; $editId = 0;
        } catch (PDOException $exception) {
            $error = (string)$exception->getCode()==='23000' ? 'That referral source already exists.' : 'The referral source could not be saved.';
        }
    }
}

$sources = db()->query('SELECT id,source_name,is_active,created_at FROM pos_referral_sources ORDER BY source_name')->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker">POS Setup</span><h1>How Did You Know Us?</h1><p>Create the referral-source choices used in POS Sales.</p></div><div class="management-icon"><i class="fa-solid fa-bullhorn"></i></div></div>
    <?php if($message!==''):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?>
    <?php if($error!==''):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
    <form class="record-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="save"><input type="hidden" name="source_id" value="<?=$editId?>"><div class="form-grid"><div class="form-field"><label for="source_name">Referral source</label><input id="source_name" name="source_name" value="<?=e($sourceName)?>" placeholder="Example: Radio" required></div><div class="form-field"><label for="status">Status</label><select id="status" name="status"><option value="1" <?=$status==='1'?'selected':''?>>Active</option><option value="0" <?=$status==='0'?'selected':''?>>Inactive</option></select></div></div><div class="form-actions"><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span><?=$editId>0?'Update Source':'Save Source'?></span></button><?php if($editId>0):?><a class="secondary-button" href="<?=e(app_url('pos-referral-setup.php'))?>">Cancel</a><?php endif;?></div></form>
</section>
<section class="management-panel management-panel--table"><div class="management-heading management-heading--compact"><div><span class="section-kicker">Lookup</span><h2>Referral Sources</h2></div></div><?php if(!$sources):?><p class="empty-state">No referral sources have been added.</p><?php else:?><div class="table-wrap"><table class="data-table data-table--compact"><thead><tr><th>Referral Source</th><th>Status</th><th>Date Added</th><th>Actions</th></tr></thead><tbody><?php foreach($sources as $source):?><tr><td><?=e((string)$source['source_name'])?></td><td><span class="status-badge <?=(int)$source['is_active']===1?'is-active':'is-inactive'?>"><?=(int)$source['is_active']===1?'Active':'Inactive'?></span></td><td><?=e(date('d M Y',strtotime((string)$source['created_at'])))?></td><td><div class="table-actions"><a class="action-button" href="<?=e(app_url('pos-referral-setup.php?edit='.(int)$source['id']))?>"><i class="fa-solid fa-pen"></i><span>Edit</span></a><form method="post" data-confirm-title="Delete referral source?" data-confirm-message="This will remove the option from POS Sales."><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="delete"><input type="hidden" name="source_id" value="<?=(int)$source['id']?>"><button class="action-button is-danger" type="submit"><i class="fa-solid fa-trash"></i><span>Delete</span></button></form></div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
