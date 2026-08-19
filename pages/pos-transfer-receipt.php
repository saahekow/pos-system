<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('pos');
ensure_pos_transfer_schema();

$transferId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$transferPersonnel=current_vendor_personnel();
$vendor = current_user_role() === 'vendor'||$transferPersonnel ? current_vendor_profile() : null;
$statement = db()->prepare("SELECT t.*,creator.full_name AS recorded_by,responder.full_name AS responded_by
    FROM pos_transfers t
    LEFT JOIN users creator ON creator.id=t.recorded_by_user_id
    LEFT JOIN users responder ON responder.id=t.responded_by_user_id
    WHERE t.id=? LIMIT 1");
$statement->execute([$transferId]);
$transfer = $statement->fetch();
$canViewVendorTransfer=$vendor&&(current_user_role()==='vendor'||($transferPersonnel&&((int)($transferPersonnel['can_reports']??0)===1||(int)($transferPersonnel['can_transfer']??0)===1)));
$canView = $transfer && (is_admin_user() || (int)$transfer['recorded_by_user_id'] === (int)current_user_id() || ($canViewVendorTransfer && (int)$transfer['vendor_id'] === (int)$vendor['id']));
if (!$canView) {
    http_response_code(404);
    exit('Transfer not found.');
}
$isRecipient = $vendor && (int)$transfer['vendor_id'] === (int)$vendor['id'];
$canRespond = $isRecipient && (current_user_role()==='vendor'||($transferPersonnel&&(int)($transferPersonnel['can_transfer']??0)===1)) && in_array((string)$transfer['status'], ['dispatched', 'disputed'], true);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$canRespond) {
        $error = 'This transfer cannot be updated.';
    } else {
        $action = (string)($_POST['response_action'] ?? '');
        $responseNote = trim((string)($_POST['response_note'] ?? ''));
        try {
            db()->beginTransaction();
            if ($action === 'accept') {
                db()->prepare('UPDATE pos_transfer_items SET received_box_quantity=box_quantity,discrepancy_note=NULL WHERE transfer_id=?')->execute([$transferId]);
                db()->prepare("UPDATE pos_transfers SET status='received',responded_by_user_id=?,response_note=?,responded_at=NOW(),received_at=NOW() WHERE id=?")->execute([current_user_id(), $responseNote ?: null, $transferId]);
            } elseif ($action === 'reject') {
                if ($responseNote === '') throw new DomainException('Enter a reason for rejecting the transfer.');
                db()->prepare("UPDATE pos_transfers SET status='rejected',responded_by_user_id=?,response_note=?,responded_at=NOW() WHERE id=?")->execute([current_user_id(), $responseNote, $transferId]);
            } elseif ($action === 'difference') {
                $received = (array)($_POST['received_boxes'] ?? []);
                $lineNotes = (array)($_POST['line_note'] ?? []);
                $items = db()->prepare('SELECT id,box_quantity FROM pos_transfer_items WHERE transfer_id=? ORDER BY id');
                $items->execute([$transferId]);
                $itemRows = $items->fetchAll();
                $hasDifference = false;
                $update = db()->prepare('UPDATE pos_transfer_items SET received_box_quantity=?,discrepancy_note=? WHERE id=? AND transfer_id=?');
                foreach ($itemRows as $item) {
                    $itemId = (int)$item['id'];
                    $quantity = max(0, (int)($received[$itemId] ?? $item['box_quantity']));
                    $note = trim((string)($lineNotes[$itemId] ?? ''));
                    if ($quantity !== (int)$item['box_quantity']) $hasDifference = true;
                    $update->execute([$quantity, $note ?: null, $itemId, $transferId]);
                }
                if (!$hasDifference && $responseNote === '') throw new DomainException('Change a received quantity or add a discrepancy note.');
                db()->prepare("UPDATE pos_transfers SET status='disputed',responded_by_user_id=?,response_note=?,responded_at=NOW() WHERE id=?")->execute([current_user_id(), $responseNote ?: null, $transferId]);
            } else {
                throw new DomainException('Select a valid response.');
            }
            db()->commit();
            header('Location: ' . app_url('pos-transfer-receipt.php?id=' . $transferId . '&updated=1'));
            exit;
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $error = $exception instanceof DomainException ? $exception->getMessage() : 'The transfer response could not be saved.';
        }
    }
}

$statement->execute([$transferId]);
$transfer = $statement->fetch();
$items = db()->prepare('SELECT * FROM pos_transfer_items WHERE transfer_id=? ORDER BY id');
$items->execute([$transferId]);
$transferItems = $items->fetchAll();
$returnTo = trim((string)($_GET['return_to'] ?? ''));
$internalBackUrl = $returnTo !== '' && str_starts_with($returnTo, app_url('pos-reports.php')) ? $returnTo : app_url(current_user_role()==='vendor' ? 'pos-incoming-transfers.php' : 'pos-reports.php?view=transfers&mode=lookup');
$pageTitle = 'Transfer ' . (string)$transfer['transfer_ref'];
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'POS','url'=>app_url('pos.php')],['label'=>'Transfer Receipt']];
require_once __DIR__ . '/../includes/header.php';
?>
<section class="content-panel pos-transfer-receipt">
    <div class="pos-transfer-receipt__top"><div><span class="section-kicker">Transfer Receipt</span><h1><?=e((string)$transfer['transfer_ref'])?></h1><p>Goods dispatched to <?=e((string)$transfer['vendor_name'])?>.</p></div><button class="secondary-button pos-print-button" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i><span>Print</span></button></div>
    <?php if((string)($_GET['updated']??'')==='1'):?><div class="profile-message is-success">Transfer response saved successfully.</div><?php endif;?>
    <?php if($error!==''):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
    <div class="pos-transfer-receipt__meta"><div><small>Date</small><strong><?=e(date('d M Y',strtotime((string)$transfer['transfer_date'])))?></strong></div><div class="pos-transfer-receipt__vendor"><small>Vendor</small><strong><?=e((string)$transfer['vendor_name'])?></strong><?php if(trim((string)$transfer['vendor_phone'])!==''):?><span><i class="fa-solid fa-phone" aria-hidden="true"></i><?=e((string)$transfer['vendor_phone'])?></span><?php endif;?></div><div><small>Status</small><strong class="status-badge <?=in_array((string)$transfer['status'],['received'],true)?'is-active':(in_array((string)$transfer['status'],['rejected','cancelled'],true)?'is-inactive':'')?>"><?=e(ucfirst((string)$transfer['status']))?></strong></div></div>
    <form method="post" data-transfer-response-form>
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$transferId?>">
        <div class="table-wrap pos-transfer-receipt__items"><table class="data-table"><thead><tr><th>Product</th><th>Sent boxes</th><th>Pieces</th><th>Unit price</th><th>Total</th><?php if($canRespond || (string)$transfer['status']==='disputed'):?><th>Received boxes</th><th>Difference note</th><?php endif;?></tr></thead><tbody><?php foreach($transferItems as $item):?><tr><td><span class="pos-transfer-product-name"><strong><?=e((string)$item['brand_name'])?></strong><small><?=e((string)$item['plug_number'])?></small></span></td><td><?=number_format((int)$item['box_quantity'])?></td><td><?=number_format((int)$item['total_pieces'])?></td><td>GHS <?=number_format((float)$item['unit_price'],2)?></td><td>GHS <?=number_format((float)$item['total_amount'],2)?></td><?php if($canRespond || (string)$transfer['status']==='disputed'):?><td><input class="pos-received-boxes" type="number" min="0" name="received_boxes[<?=(int)$item['id']?>]" value="<?=e((string)($item['received_box_quantity']??$item['box_quantity']))?>" <?= $canRespond ? '' : 'disabled' ?>></td><td><input type="text" name="line_note[<?=(int)$item['id']?>]" value="<?=e((string)($item['discrepancy_note']??''))?>" placeholder="Optional" <?= $canRespond ? '' : 'disabled' ?>></td><?php endif;?></tr><?php endforeach;?></tbody></table></div>
        <div class="pos-transfer-receipt__total"><span>Total</span><strong>GHS <?=number_format((float)$transfer['total_amount'],2)?></strong></div>
        <?php if($transfer['response_note']):?><div class="pos-transfer-response-note"><small>Vendor response</small><p><?=nl2br(e((string)$transfer['response_note']))?></p></div><?php endif;?>
        <?php if($canRespond):?><label class="pos-transfer-response-note"><small>Response note</small><textarea name="response_note" rows="3" placeholder="Required when rejecting; optional when accepting"></textarea></label><div class="pos-transfer-response-actions"><button class="login-button" name="response_action" value="accept"><i class="fa-solid fa-circle-check"></i><span>Accept Transfer</span></button><button class="secondary-button" name="response_action" value="difference"><i class="fa-solid fa-triangle-exclamation"></i><span>Report Difference</span></button><button class="danger-button" name="response_action" value="reject"><i class="fa-solid fa-circle-xmark"></i><span>Reject</span></button></div><?php endif;?>
    </form>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
