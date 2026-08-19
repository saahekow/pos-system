<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('pos');
if(current_vendor_personnel()&&!can_access_menu_item('pos_transfer')){http_response_code(403);exit('Transfer access has not been assigned to your account.');}
$transferPersonnel=current_vendor_personnel();
if($transferPersonnel&&(int)$transferPersonnel['can_transfer']!==1){http_response_code(403);exit('Transfer access has not been assigned to your account.');}
ensure_pos_transfer_schema();
$vendor = current_vendor_profile();
if (!$vendor) {
    http_response_code(403);
    exit('A vendor account is required to view incoming transfers.');
}
$requestedFilter = (string)($_GET['status'] ?? 'pending');
$filter = in_array($requestedFilter, ['pending','history','all'], true) ? $requestedFilter : 'pending';
$conditions = ['t.vendor_id=?'];
$params = [(int)$vendor['id']];
if ($filter === 'pending') $conditions[] = "t.status IN ('dispatched','disputed')";
if ($filter === 'history') $conditions[] = "t.status IN ('received','rejected','cancelled')";
$statement = db()->prepare("SELECT t.*,COALESCE(SUM(i.box_quantity),0) box_count,COUNT(i.id) product_count FROM pos_transfers t LEFT JOIN pos_transfer_items i ON i.transfer_id=t.id WHERE ".implode(' AND ',$conditions).' GROUP BY t.id ORDER BY t.transfer_date DESC,t.id DESC');
$statement->execute($params);
$rows = $statement->fetchAll();
$pageTitle = 'Incoming Transfers';
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'POS','url'=>app_url('pos.php')],['label'=>'Incoming Transfers']];
$internalBackUrl = app_url('pos.php');
require_once __DIR__ . '/../includes/header.php';
?>
<section class="content-panel pos-report-page">
    <div class="management-heading"><div><span class="section-kicker">POS Transfers</span><h1>Incoming Transfers</h1><p>Review goods sent to <?=e((string)$vendor['vendor_name'])?>.</p></div><div class="management-icon"><i class="fa-solid fa-truck-ramp-box"></i></div></div>
    <div class="report-mode-actions pos-transfer-tabs"><a class="report-mode-button <?=$filter==='pending'?'is-active':''?>" href="<?=e(app_url('pos-incoming-transfers.php?status=pending'))?>">Pending</a><a class="report-mode-button <?=$filter==='history'?'is-active':''?>" href="<?=e(app_url('pos-incoming-transfers.php?status=history'))?>">History</a><a class="report-mode-button <?=$filter==='all'?'is-active':''?>" href="<?=e(app_url('pos-incoming-transfers.php?status=all'))?>">All</a></div>
    <p class="report-result-count pos-report-result-count"><?=number_format(count($rows))?> transfer<?=count($rows)===1?'':'s'?> found</p>
    <div class="table-wrap pos-report-results"><table class="data-table data-table--reports"><thead><tr><th>Date</th><th>Transfer</th><th>Products</th><th>Boxes</th><th>Total</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?=e(date('d M Y',strtotime((string)$row['transfer_date'])))?></td><td><a href="<?=e(app_url('pos-transfer-receipt.php?id='.(int)$row['id']))?>"><strong><?=e((string)$row['transfer_ref'])?></strong></a></td><td><?=number_format((int)$row['product_count'])?></td><td><?=number_format((int)$row['box_count'])?></td><td>GHS <?=number_format((float)$row['total_amount'],2)?></td><td><span class="status-badge <?= $row['status']==='received'?'is-active':(in_array($row['status'],['rejected','cancelled'],true)?'is-inactive':'') ?>"><?=e(ucfirst((string)$row['status']))?></span></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="6" class="empty-state">No <?=e($filter)?> transfers found.</td></tr><?php endif;?></tbody></table></div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
