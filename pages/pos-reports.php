<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('pos');
ensure_pos_sales_schema();
ensure_pos_transfer_schema();
ensure_vendor_personnel_schema();

$view = (string)($_GET['view'] ?? 'menu');
$allowedViews = ['menu', 'sales', 'daily', 'history', 'notes', 'refunds', 'transfers'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'menu';
}
if ($view === 'menu' && !in_array((string)($_GET['source'] ?? ''), ['pos', 'sor'], true)) {
    header('Location: ' . app_url('pos.php?view=reports'));
    exit;
}
$reportViews = ['daily', 'history', 'notes', 'refunds', 'transfers'];
$mode = in_array((string)($_GET['mode'] ?? ''), ['lookup', 'type'], true) ? (string)$_GET['mode'] : '';
$source = in_array((string)($_GET['source'] ?? ''), ['pos', 'sor'], true) ? (string)$_GET['source'] : '';
if(current_vendor_is_sor()&&$source==='pos'){header('Location: '.app_url('pos-reports.php?view=menu&source=sor'));exit;}
$sourceQuery = $source !== '' ? '&source=' . rawurlencode($source) : '';

$userId = (int)(current_user_id() ?? 0);
$isManagement = is_admin_user();
$reportPersonnel = current_vendor_personnel();
$hasAssignedReports = $reportPersonnel && (int)($reportPersonnel['can_reports'] ?? 0) === 1;
$reportVendor = current_vendor_profile();
$isVendorOwner = current_user_role()==='vendor' && $reportVendor && (int)$reportVendor['user_id']===$userId;
$recorderType = in_array((string)($_GET['recorder_type'] ?? ''), ['vendor', 'staff'], true) ? (string)$_GET['recorder_type'] : '';
$recorderUserId = max(0, (int)($_GET['recorder_user_id'] ?? 0));
$recorderOptions = [];
if ($isManagement && $recorderType === 'vendor') {
    $recorderOptions = db()->query("SELECT v.user_id,CONCAT(COALESCE(NULLIF(v.vendor_name,''),u.full_name),CASE WHEN COALESCE(v.phone,'')<>'' THEN CONCAT(' · ',v.phone) ELSE '' END) AS display_name FROM vendors v INNER JOIN users u ON u.id=v.user_id WHERE v.is_active=1 AND u.is_active=1 ORDER BY v.vendor_name")->fetchAll();
} elseif ($isManagement && $recorderType === 'staff') {
    $recorderOptions = db()->query("SELECT s.user_id,COALESCE(NULLIF(s.full_name,''),u.full_name) AS display_name FROM staff s INNER JOIN users u ON u.id=s.user_id WHERE s.is_active=1 AND u.is_active=1 ORDER BY display_name")->fetchAll();
}
$date = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to = (string)($_GET['to'] ?? date('Y-m-d'));
$search = trim((string)($_GET['q'] ?? ''));

$rows = [];
$summary = ['sales_count' => 0, 'items_count' => 0, 'sales_total' => 0.0];
if (in_array($view, $reportViews, true) && ($mode === 'lookup' || ($mode === 'type' && $search !== ''))) {
    if ($view === 'transfers') {
        $conditions = [];
        $params = [];
        if (!$isManagement) {
            if(($isVendorOwner||$hasAssignedReports)&&$reportVendor){$conditions[]='t.vendor_id = ?';$params[]=(int)$reportVendor['id'];}
            else{$conditions[] = 't.recorded_by_user_id = ?';$params[] = $userId;}
        } elseif ($recorderUserId > 0 && $recorderType !== '') {
            $validRecorderIds = array_map('intval', array_column($recorderOptions, 'user_id'));
            if (in_array($recorderUserId, $validRecorderIds, true)) {
                $conditions[] = 't.recorded_by_user_id = ?';
                $params[] = $recorderUserId;
            }
        } elseif ($recorderType !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM users recorder_user WHERE recorder_user.id=t.recorded_by_user_id AND recorder_user.role=?)';
            $params[] = $recorderType;
        }
        if ($mode === 'lookup') {
            $conditions[] = 't.transfer_date BETWEEN ? AND ?';
            array_push($params, $from, $to);
        } else {
            $term = '%' . $search . '%';
            $conditions[] = '(t.transfer_ref LIKE ? OR t.vendor_name LIKE ? OR t.vendor_phone LIKE ? OR t.note LIKE ? OR EXISTS (SELECT 1 FROM pos_transfer_items search_item WHERE search_item.transfer_id=t.id AND (search_item.brand_name LIKE ? OR search_item.plug_number LIKE ?)))';
            array_push($params, $term, $term, $term, $term, $term, $term);
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $statement = db()->prepare("SELECT t.id,t.transfer_ref,t.transfer_date,t.vendor_name,t.vendor_phone,t.note,t.total_amount,t.status,t.created_at,u.full_name AS recorded_by,COALESCE(SUM(ti.box_quantity),0) AS item_count,GROUP_CONCAT(CONCAT(ti.brand_name,' ',ti.plug_number) ORDER BY ti.id SEPARATOR ', ') AS product_list FROM pos_transfers t LEFT JOIN pos_transfer_items ti ON ti.transfer_id=t.id LEFT JOIN users u ON u.id=t.recorded_by_user_id $where GROUP BY t.id ORDER BY t.transfer_date DESC,t.id DESC");
        $statement->execute($params);
        $rows = $statement->fetchAll();
        foreach ($rows as $row) {
            $summary['sales_count']++;
            $summary['items_count'] += (int)$row['item_count'];
            $summary['sales_total'] += (float)$row['total_amount'];
        }
    } else {
    $conditions = [];
    $params = [];
    if ($source !== '') {
        $conditions[] = 's.sale_source = ?';
        $params[] = $source;
    }
    if (!$isManagement) {
        if(($isVendorOwner||$hasAssignedReports)&&$reportVendor){$conditions[]='(s.vendor_id=? OR (s.vendor_id IS NULL AND s.recorded_by_user_id=?))';array_push($params,(int)$reportVendor['id'],$userId);}
        else{$conditions[] = 's.recorded_by_user_id = ?';$params[] = $userId;}
    } elseif ($recorderUserId > 0 && $recorderType !== '') {
        $validRecorderIds = array_map('intval', array_column($recorderOptions, 'user_id'));
        if (in_array($recorderUserId, $validRecorderIds, true)) {
            $conditions[] = 's.recorded_by_user_id = ?';
            $params[] = $recorderUserId;
        }
    } elseif ($recorderType !== '') {
        $conditions[] = 'EXISTS (SELECT 1 FROM users recorder_user WHERE recorder_user.id=s.recorded_by_user_id AND recorder_user.role=?)';
        $params[] = $recorderType;
    }
    if ($view === 'daily') {
        $conditions[] = "s.status = 'completed'";
        if ($mode === 'lookup') {
            $conditions[] = 's.sale_date = ?';
            $params[] = $date;
        }
    } elseif ($view === 'history') {
        if ($mode === 'lookup') {
            $conditions[] = 's.sale_date BETWEEN ? AND ?';
            array_push($params, $from, $to);
        }
    } elseif ($view === 'notes') {
        $conditions[] = "COALESCE(TRIM(s.comment),'') <> ''";
        if ($mode === 'lookup') {
            $conditions[] = 's.sale_date BETWEEN ? AND ?';
            array_push($params, $from, $to);
        }
    } else {
        $conditions[] = "s.status = 'cancelled'";
        if ($mode === 'lookup') {
            $conditions[] = 's.sale_date BETWEEN ? AND ?';
            array_push($params, $from, $to);
        }
    }
    if ($mode === 'type' && $search !== '') {
        $conditions[] = '(s.sale_ref LIKE ? OR s.customer_name LIKE ? OR s.customer_phone LIKE ? OR s.comment LIKE ? OR s.vendor_name LIKE ? OR v.vendor_name LIKE ? OR u.full_name LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term, $term, $term);
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $sql = "SELECT s.id,s.sale_ref,s.sale_date,s.sale_source,
                   s.customer_name AS customer_name,
                   s.customer_phone AS customer_phone,
                   s.comment,s.subtotal,s.sales_type,s.recipient_vendor_name,s.delivery_charge,s.net_sales,s.commission_amount,s.amount_less_commission,s.status,
                   s.created_at,COALESCE(NULLIF(s.vendor_personnel_name,''),u.full_name) AS recorded_by,
                   COALESCE(NULLIF(s.vendor_name,''),NULLIF(v.vendor_name,''),NULLIF(legacy_vendor.vendor_name,'')) AS vendor_name,
                   COALESCE(SUM(si.quantity),0) AS item_count
            FROM pos_sales s
            LEFT JOIN pos_sale_items si ON si.sale_id=s.id
            LEFT JOIN users u ON u.id=s.recorded_by_user_id
            LEFT JOIN vendors v ON v.id=s.vendor_id
            LEFT JOIN vendors legacy_vendor ON s.vendor_id IS NULL AND legacy_vendor.user_id=s.recorded_by_user_id
            $where
            GROUP BY s.id
            ORDER BY s.sale_date DESC,s.id DESC";
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    foreach ($rows as $row) {
        $summary['sales_count']++;
        $summary['items_count'] += (int)$row['item_count'];
        $summary['sales_total'] += (float)$row['subtotal'];
    }
    }
}

$titles = ['menu'=>'Reports','sales'=>'Sales Reports','daily'=>'Daily Sales','history'=>'Sales History','notes'=>'Sales Notes','refunds'=>'Refunds','transfers'=>'Transfers'];
$pageTitle = 'POS ' . $titles[$view];
$breadcrumbs = [
    ['label'=>'Home','url'=>app_url('index.php')],
    ['label'=>'POS','url'=>app_url('pos.php')],
    ['label'=>'Reports','url'=>app_url('pos.php?view=reports')],
];
if ($view !== 'menu') {
    $breadcrumbs[] = ['label'=>$titles[$view]];
}
$reportParentView = $view === 'daily' || $view === 'history' ? 'sales' : 'menu';
$requestedReportsReturn = requested_return_url('');
$channelHomeUrl = $source === 'sor' ? app_url('pos.php?view=sor') : ($source === 'pos' ? app_url('pos.php?view=direct-reports') : app_url('pos.php?view=reports'));
$internalBackUrl = $requestedReportsReturn !== '' ? $requestedReportsReturn : ($view === 'menu'
    ? app_url('pos.php?view=reports')
    : ($view === 'sales'
        ? $channelHomeUrl
        : ($mode !== ''
            ? app_url('pos-reports.php?view=' . $view . $sourceQuery)
            : ($reportParentView === 'menu'
                ? $channelHomeUrl
                : app_url('pos-reports.php?view=' . $reportParentView . $sourceQuery)))));
$reportReturnParams = $_GET;
$reportReturnUrl = app_url('pos-reports.php' . ($reportReturnParams ? '?' . http_build_query($reportReturnParams) : ''));
require_once __DIR__ . '/../includes/header.php';
?>
<section class="content-panel pos-report-page">
    <div class="management-heading">
        <div><span class="section-kicker"><?=e($source === 'sor' ? 'SoR Reports' : ($source === 'pos' ? 'Direct Sales Reports' : 'POS Reports'))?></span><h1><?=e($titles[$view])?></h1><p><?= $isManagement ? 'Showing records for all users.' : (($isVendorOwner||$hasAssignedReports)?'Showing records for your vendor account.':'Showing only records recorded by you.') ?></p></div>
        <div class="management-icon"><i class="fa-solid fa-chart-column"></i></div>
    </div>

    <?php if ($view === 'menu'): ?>
        <div class="pos-report-menu">
            <a class="pos-report-card" href="<?=e(app_url('pos-reports.php?view=sales'.$sourceQuery))?>"><i class="fa-solid fa-cart-shopping"></i><span><strong>Sales</strong><small>Daily sales and sales history</small></span><i class="fa-solid fa-chevron-right"></i></a>
            <a class="pos-report-card" href="<?=e(app_url('pos-reports.php?view=notes'.$sourceQuery))?>"><i class="fa-solid fa-note-sticky"></i><span><strong>Notes</strong><small>Notes saved with sales</small></span><i class="fa-solid fa-chevron-right"></i></a>
            <a class="pos-report-card" href="<?=e(app_url('pos-reports.php?view=refunds'.$sourceQuery))?>"><i class="fa-solid fa-rotate-left"></i><span><strong>Refunds</strong><small>Cancelled and refunded sales</small></span><i class="fa-solid fa-chevron-right"></i></a>
            <?php if ($source === ''): ?><a class="pos-report-card" href="<?=e(app_url('pos-reports.php?view=transfers'))?>"><i class="fa-solid fa-truck-arrow-right"></i><span><strong>Transfers</strong><small>Goods dispatched to vendors</small></span><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
        </div>
    <?php elseif ($view === 'sales'): ?>
        <div class="pos-report-menu pos-report-menu--two">
            <a class="pos-report-card" href="<?=e(app_url('pos-reports.php?view=daily'.$sourceQuery))?>"><i class="fa-solid fa-calendar-day"></i><span><strong>Daily Sales</strong><small>Review sales for a selected day</small></span><i class="fa-solid fa-chevron-right"></i></a>
            <a class="pos-report-card" href="<?=e(app_url('pos-reports.php?view=history'.$sourceQuery))?>"><i class="fa-solid fa-clock-rotate-left"></i><span><strong>Sales History</strong><small>Search previous POS sales</small></span><i class="fa-solid fa-chevron-right"></i></a>
        </div>
    <?php elseif ($mode === ''): ?>
        <div class="report-mode-actions report-mode-actions--menu pos-report-mode-menu">
            <a class="report-mode-button" href="<?=e(app_url('pos-reports.php?view='.$view.'&mode=lookup'.$sourceQuery))?>"><i class="fa-solid fa-list-check"></i><span>Lookup</span></a>
            <a class="report-mode-button" href="<?=e(app_url('pos-reports.php?view='.$view.'&mode=type'.$sourceQuery))?>"><i class="fa-solid fa-keyboard"></i><span>Quick Search</span></a>
        </div>
    <?php else: ?>
        <?php if ($mode === 'type'): ?>
            <form class="pos-report-filter pos-report-filter--quick" method="get" data-pos-live-filter="search"><input type="hidden" name="view" value="<?=e($view)?>"><input type="hidden" name="mode" value="type"><label class="pos-report-filter__search">Quick Search<input type="search" name="q" value="<?=e($search)?>" placeholder="<?= $view==='transfers'?'Transfer, vendor, phone or product':'Receipt, customer, vendor, recorder or note' ?>" autocomplete="off" autofocus></label><button class="login-button" type="submit"><i class="fa-solid fa-magnifying-glass"></i><span>Search</span></button></form>
        <?php elseif ($view === 'daily'): ?>
            <form class="pos-report-filter" method="get" data-pos-live-filter="date"><input type="hidden" name="view" value="daily"><input type="hidden" name="mode" value="lookup"><div class="pos-report-filter__row pos-report-filter__row--date"><label>Date<input type="date" name="date" value="<?=e($date)?>"></label></div><?php if($isManagement):?><div class="pos-report-filter__row pos-report-filter__row--recorder"><label>Recorder type<select name="recorder_type"><option value="">All recorders</option><option value="vendor" <?=$recorderType==='vendor'?'selected':''?>>Vendors</option><option value="staff" <?=$recorderType==='staff'?'selected':''?>>Staff</option></select></label><?php if($recorderType!==''):?><label><?=e(ucfirst($recorderType))?><select name="recorder_user_id" data-popup-select data-popup-search><option value="0">All <?=e($recorderType)?> records</option><?php foreach($recorderOptions as $recorder):?><option value="<?=(int)$recorder['user_id']?>" <?= (int)$recorder['user_id']===$recorderUserId?'selected':'' ?>><?=e((string)$recorder['display_name'])?></option><?php endforeach;?></select></label><?php endif;?></div><?php endif;?><button class="login-button" type="submit"><i class="fa-solid fa-filter"></i><span>Show</span></button></form>
        <?php else: ?>
            <form class="pos-report-filter" method="get" data-pos-live-filter="date"><input type="hidden" name="view" value="<?=e($view)?>"><input type="hidden" name="mode" value="lookup"><div class="pos-report-filter__row pos-report-filter__row--date"><label>From<input type="date" name="from" value="<?=e($from)?>"></label><label>To<input type="date" name="to" value="<?=e($to)?>"></label></div><?php if($isManagement):?><div class="pos-report-filter__row pos-report-filter__row--recorder"><label>Recorder type<select name="recorder_type"><option value="">All recorders</option><option value="vendor" <?=$recorderType==='vendor'?'selected':''?>>Vendors</option><option value="staff" <?=$recorderType==='staff'?'selected':''?>>Staff</option></select></label><?php if($recorderType!==''):?><label><?=e(ucfirst($recorderType))?><select name="recorder_user_id" data-popup-select data-popup-search><option value="0">All <?=e($recorderType)?> records</option><?php foreach($recorderOptions as $recorder):?><option value="<?=(int)$recorder['user_id']?>" <?= (int)$recorder['user_id']===$recorderUserId?'selected':'' ?>><?=e((string)$recorder['display_name'])?></option><?php endforeach;?></select></label><?php endif;?></div><?php endif;?><button class="login-button" type="submit"><i class="fa-solid fa-filter"></i><span>Show</span></button></form>
        <?php endif; ?>

        <p class="report-result-count pos-report-result-count"><?=number_format(count($rows))?> result<?=count($rows)===1?'':'s'?> found</p>
        <div class="pos-report-summary">
            <div><span><?= $view==='transfers'?'Transfers':'Sales' ?></span><strong><?=number_format($summary['sales_count'])?></strong></div>
            <div><span><?= $view==='transfers'?'Boxes':'Quantity' ?></span><strong><?=number_format($summary['items_count'])?></strong></div>
            <div><span>Total</span><strong>GHS <?=number_format($summary['sales_total'],2)?></strong></div>
        </div>
        <div class="table-wrap pos-report-results">
            <?php if($view==='transfers'): ?>
            <table class="data-table data-table--reports"><thead><tr><th>Date</th><th>Transfer</th><th>Vendor</th><th>Products</th><th>Boxes</th><th>Total</th><th>Status</th><?php if($isManagement):?><th>Recorded By</th><?php endif;?></tr></thead>
            <tbody><?php foreach($rows as $row):?><tr><td><?=e(date('d M Y',strtotime((string)$row['transfer_date'])))?></td><td><a href="<?=e(app_url('pos-transfer-receipt.php?id='.(int)$row['id'].'&return_to='.rawurlencode($reportReturnUrl)))?>"><strong><?=e((string)$row['transfer_ref'])?></strong></a></td><td class="pos-transfer-vendor-cell"><strong><?=e((string)$row['vendor_name'])?></strong><?php if(trim((string)$row['vendor_phone'])!==''):?><span><i class="fa-solid fa-phone" aria-hidden="true"></i><?=e((string)$row['vendor_phone'])?></span><?php endif;?></td><td><?=e((string)($row['product_list']?:'—'))?></td><td><?=number_format((int)$row['item_count'])?></td><td>GHS <?=number_format((float)$row['total_amount'],2)?></td><td><span class="status-badge <?= in_array($row['status'],['rejected','cancelled'],true)?'is-inactive':($row['status']==='received'?'is-active':'') ?>"><?=e(ucfirst((string)$row['status']))?></span></td><?php if($isManagement):?><td><?=e((string)($row['recorded_by']?:'Unknown'))?></td><?php endif;?></tr><?php endforeach;?><?php if(!$rows):?><tr><td class="empty-state" colspan="<?= $isManagement ? 8 : 7 ?>"><?= $mode==='type' && $search==='' ? 'Type a transfer, vendor, phone or product to search.' : 'No transfer records found.' ?></td></tr><?php endif;?></tbody></table>
            <?php else: ?>
            <table class="data-table data-table--reports"><thead><tr><th>Date</th><th>Receipt</th><th>Customer</th><th>Vendor</th><th>Recorded By</th><th>Channel</th><?php if($view==='notes'):?><th>Note</th><?php else:?><th>Qty</th><th>Recipient</th><th>Sales</th><th>Delivery</th><th>Net Sales</th><th>Commission</th><th>Amount Less Commission</th><th>Status</th><?php endif;?></tr></thead>
            <tbody><?php foreach($rows as $row):$isSor=(string)($row['sale_source']??'pos')==='sor';?><tr><td><?=e(date('d M Y',strtotime((string)$row['sale_date'])))?></td><td><a href="<?=e(app_url('pos-sale-receipt.php?id='.(int)$row['id'].'&return_to='.rawurlencode($reportReturnUrl)))?>"><?=e((string)$row['sale_ref'])?></a></td><td><strong><?=e((string)$row['customer_name'])?></strong><span class="muted-text"><?=e((string)$row['customer_phone'])?></span></td><td><?=e((string)($row['vendor_name']?:'—'))?></td><td><?=e((string)($row['recorded_by']?:'Unknown'))?></td><td><span class="status-badge <?=$isSor?'is-warning':'is-active'?>"><?=$isSor?'SoR':'Direct Sales'?></span></td><?php if($view==='notes'):?><td><?=nl2br(e((string)$row['comment']))?></td><?php else:?><td><?=number_format((int)$row['item_count'])?></td><td><?=e((string)($row['sales_type']==='indirect'?($row['recipient_vendor_name']?:'Indirect'):'Direct'))?></td><td>GHS <?=number_format((float)$row['subtotal'],2)?></td><td>GHS <?=number_format((float)$row['delivery_charge'],2)?></td><td>GHS <?=number_format((float)$row['net_sales'],2)?></td><td><?= $row['commission_amount']===null ? '—' : 'GHS '.number_format((float)$row['commission_amount'],2) ?></td><td>GHS <?=number_format((float)$row['amount_less_commission'],2)?></td><td><span class="status-badge <?= $row['status']==='completed'?'is-active':'is-inactive' ?>"><?=e(ucfirst((string)$row['status']))?></span></td><?php endif;?></tr><?php endforeach;?><?php if(!$rows):?><tr><td class="empty-state" colspan="<?=$view==='notes'?7:15?>"><?= $mode==='type' && $search==='' ? 'Type a receipt, customer, phone, vendor, recorder or note to search.' : 'No '.e(strtolower($titles[$view])).' records found.' ?></td></tr><?php endif;?></tbody></table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<script>
<?php if ($source !== ''): ?>
document.querySelectorAll('.pos-report-filter').forEach(function (form) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'source';
    input.value = <?=json_encode($source)?>;
    form.appendChild(input);
});
<?php endif; ?>
document.querySelectorAll('[data-pos-live-filter]').forEach(function (form) {
    var timer;
    if (form.dataset.posLiveFilter === 'search') {
        var search = form.querySelector('input[type="search"]');
        if (!search) return;
        search.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { form.requestSubmit(); }, 450);
        });
    } else {
        form.querySelectorAll('input[type="date"], select').forEach(function (field) {
            field.addEventListener('change', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () { form.requestSubmit(); }, 120);
            });
        });
    }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
