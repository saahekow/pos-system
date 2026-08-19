<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
ensure_destination_visit_schema();

$requestedId = max(0, (int) ($_GET['id'] ?? $_POST['visit_id'] ?? 0));
$returnTo = trim((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
$returnParts = $returnTo !== '' ? parse_url($returnTo) : false;
$allowedReturnPaths = array_map(static function (string $url): string { return (string) (parse_url($url, PHP_URL_PATH) ?: $url); }, [app_url('reports.php'), app_url('sales-trip.php'), app_url('registration-records.php'), app_url('customers.php')]);
if (!is_array($returnParts) || isset($returnParts['scheme']) || isset($returnParts['host']) || !in_array((string) ($returnParts['path'] ?? ''), $allowedReturnPaths, true)) {
    $returnTo = '';
}
$rootStatement = db()->prepare('SELECT COALESCE(parent_visit_id,id) FROM destination_visits WHERE id=?');
$rootStatement->execute([$requestedId]);
$rootId = (int) ($rootStatement->fetchColumn() ?: 0);
$isAdmin = in_array(current_user_role(), ['super_admin', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['form_action'] ?? '') === 'delete_visit') {
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $deleteError = 'Your session expired. Please try again.';
    } elseif (!$isAdmin || $rootId <= 0) {
        $deleteError = 'You are not allowed to delete this visit.';
    } else {
        db()->beginTransaction();
        try {
            db()->prepare('DELETE FROM destination_visits WHERE parent_visit_id=?')->execute([$rootId]);
            db()->prepare('DELETE FROM destination_visits WHERE id=?')->execute([$rootId]);
            db()->commit();
            $deleteReturnUrl = $returnTo !== '' ? $returnTo : app_url('reports.php?report=visits');
            $deleteReturnUrl .= str_contains($deleteReturnUrl, '?') ? '&deleted=1' : '?deleted=1';
            header('Location: ' . $deleteReturnUrl);
            exit;
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $deleteError = 'The visit could not be deleted.';
        }
    }
}

$statement = db()->prepare('SELECT dv.*,d.destination_name,d.destination_key,st.shop_type_name,l.region_name,l.mmda_name AS district_name,l.town_name,l.is_capital,u.full_name AS recorded_by,s.full_name AS staff_name,tr.trip_code,tr.trip_date FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id LEFT JOIN shop_types st ON st.id=dv.shop_type_id LEFT JOIN locations l ON l.id=dv.location_id LEFT JOIN users u ON u.id=dv.recorded_by_user_id LEFT JOIN staff s ON s.id=dv.staff_id LEFT JOIN sales_trips tr ON tr.id=dv.sales_trip_id WHERE dv.id=?');
$statement->execute([$rootId]);
$visit = $statement->fetch();
if (!$visit) {
    http_response_code(404); $pageTitle='Visit Not Found'; $breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Visit Not Found']];
    require __DIR__.'/../includes/header.php'; echo '<section class="management-panel"><p class="empty-state">The destination visit was not found.</p></section>'; require __DIR__.'/../includes/footer.php'; exit;
}

$historyStatement = db()->prepare('SELECT dv.*,u.full_name AS recorded_by,s.full_name AS staff_name,tr.trip_code,tr.trip_date FROM destination_visits dv LEFT JOIN users u ON u.id=dv.recorded_by_user_id LEFT JOIN staff s ON s.id=dv.staff_id LEFT JOIN sales_trips tr ON tr.id=dv.sales_trip_id WHERE dv.id=? OR dv.parent_visit_id=? ORDER BY dv.created_at,dv.id');
$historyStatement->execute([$rootId,$rootId]);
$history = $historyStatement->fetchAll();
$isTaxi = (string)($visit['destination_key']??'') === taxi_rank_destination_key();
$staffOwnsVisit=current_user_role()==='staff'&&(
    (int)($visit['recorded_by_user_id']??0)===(int)current_user_id()
    || (current_staff_id()!==null&&(int)($visit['staff_id']??0)===(int)current_staff_id())
);
$vendorId=(int)(current_vendor_profile()['id']??0);
$vendorOwnsVisit=current_user_role()==='vendor'&&$vendorId>0&&(int)($visit['vendor_id']??0)===$vendorId;
$tripCanManage=(int)($visit['sales_trip_id']??0)>0&&can_access_registration_trip((int)$visit['sales_trip_id']);
$canEdit=$isAdmin||$staffOwnsVisit||$vendorOwnsVisit||$tripCanManage;
$salesReference=trim((string)($visit['sales_ref']??''));
$isSold=customer_has_completed_pos_sale((int)($visit['customer_id']??0));
$saleVins=customer_pos_sale_vins('visit',$rootId);
$displayName = (string)($visit['company_name'] ?: $visit['owner_name'] ?: $visit['destination_name'].' Visit');
$pageTitle = $displayName;
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Reports','url'=>app_url('reports.php')],['label'=>'Visits','url'=>app_url('reports.php?report=visits')],['label'=>'Visit Details']];
$backUrl = $returnTo !== '' ? $returnTo : app_url('reports.php?report=visits&destination_id='.(int)$visit['destination_id'].'&mode=type');
$internalBackUrl = $backUrl;
require __DIR__.'/../includes/header.php';
?>
<section class="management-panel retailer-profile-panel">
    <div class="management-heading">
        <div class="retailer-profile-title"><span class="section-kicker"><?=e((string)$visit['destination_name'])?></span><h1><?=e($displayName)?></h1><p><?=e(implode(', ', array_filter([(string)($visit['town_name']??''),(string)($visit['district_name']??''),(string)($visit['region_name']??'')])))?></p></div>
        <div class="table-actions retailer-heading-actions customer-detail-action-bar">
            <a class="secondary-button" href="<?=e($backUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>
            <?php if(empty($visit['normalized_customer_id'])):?><a class="secondary-button" href="<?=e(app_url('legacy-customer-location.php?source=destination_visit&id='.$rootId.'&return_to='.rawurlencode($backUrl)))?>"><i class="fa-solid fa-location-dot"></i><span>Assign Location</span></a><?php endif;?><?php if($canEdit): ?><a class="action-button" href="<?=e(app_url('visit-edit.php?id='.$rootId.($returnTo!==''?'&return_to='.rawurlencode($returnTo):'')))?>"><i class="fa-solid fa-pen"></i><span>Edit</span></a><?php endif;?><?php if($isAdmin): ?><form method="post" data-confirm-title="Delete destination visit?" data-confirm-message="This will permanently delete the registration and all of its followups."><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="delete_visit"><input type="hidden" name="visit_id" value="<?=$rootId?>"><input type="hidden" name="return_to" value="<?=e($backUrl)?>"><button class="action-button is-danger"><i class="fa-solid fa-trash"></i><span>Delete</span></button></form><?php endif; ?>
            <span class="management-icon"><i class="fa-solid <?=$isTaxi?'fa-taxi':'fa-store'?>"></i></span>
        </div>
    </div>
    <?php if(!empty($deleteError)):?><div class="profile-message is-error"><?=e($deleteError)?></div><?php endif;?>
    <div class="detail-grid detail-grid--plain retailer-profile-grid"><dl>
        <?php foreach(['owner_name'=>($isTaxi?'Driver':"Owner's Name"),'phone'=>'Phone','other_phone'=>'Other Phone','region_name'=>'Region','district_name'=>'District','town_name'=>'Town','area'=>'Area','shop_type_name'=>'Shop Type'] as $key=>$label): ?><div><dt><?=$label?></dt><dd><?= $key==='town_name' ? town_name_html((string)($visit[$key]??''),$visit['is_capital']??0) : e((string)($visit[$key]??'')) ?></dd></div><?php endforeach; ?>
        <div><dt>Date Added</dt><dd><?=e(date('d M Y',strtotime((string)$visit['created_at'])))?></dd></div>
        <div class="detail-item--wide"><dt>Purchased VINs</dt><dd data-sales-vin-list><?=e($saleVins?implode(', ',$saleVins):'None recorded')?></dd></div>
        <div><dt>Recorded By</dt><dd><?=e((string)($visit['recorded_by']?:$visit['staff_name']?:''))?></dd></div>
        <div class="detail-item--wide"><dt>Google Location</dt><dd><?php if($visit['google_location']):?><a href="<?=e((string)$visit['google_location'])?>" target="_blank" rel="noopener">Open location</a><?php endif;?></dd></div>
    </dl></div>
</section>

<section class="management-panel management-panel--table retailer-visit-panel">
    <div class="management-heading management-heading--compact"><div><span class="section-kicker">Media</span><h2><?=e((string)$visit['destination_name'])?> Media</h2></div></div>
    <div class="retailer-media-grid">
        <?php $media=[['owner_pic',$isTaxi?'Driver Pic':"Owner's Pic",'No owner pic'],['shop_pic',$isTaxi?'Station Pic':'Shop Pic','No shop pic'],['shop_video','Shop Vid','No shop video']]; foreach($media as [$key,$label,$empty]): ?>
        <?php $mediaPath=(string)($visit[$key]??''); $mediaUrl=$mediaPath!==''?app_url($mediaPath):''; $isVideo=$key==='shop_video'; ?>
        <div class="retailer-media-item <?=$isVideo?'retailer-media-item--video':''?>">
            <span><?=e($label)?></span>
            <?php if($mediaUrl!==''): ?>
                <button class="media-video-trigger" type="button" data-media-viewer="<?=$isVideo?'video':'image'?>" data-media-src="<?=e($mediaUrl)?>" data-media-title="<?=e($label)?>" aria-label="View <?=e(strtolower($label))?>">
                    <?php if($isVideo): ?>
                        <video preload="metadata" muted playsinline><source src="<?=e($mediaUrl)?>"></video>
                        <span class="media-play-badge" aria-hidden="true"><i class="fa-solid fa-play"></i></span>
                    <?php else: ?>
                        <img src="<?=e($mediaUrl)?>" alt="<?=e($label)?>">
                    <?php endif; ?>
                </button>
            <?php else: ?>
                <div class="media-empty-state"><?=e($empty)?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="management-panel management-panel--table retailer-visit-panel">
    <div class="management-heading management-heading--compact"><div><span class="section-kicker">Visit History</span><h2>Visits</h2></div></div>
    <div class="retailer-visit-stack">
    <?php foreach($history as $index=>$item): ?>
        <?php $historyLabel=$item['visit_type']==='registration'?'Registration':ucwords(str_replace('_',' ',(string)($item['follow_up_method']?:'physical_visit')));$noteText=trim((string)($item['note']??''));$notePreview=strlen($noteText)>90?substr($noteText,0,87).'...':$noteText; ?>
        <article class="retailer-visit-card"><div class="retailer-visit-card__header"><span class="retailer-visit-card__marker"><?=$index+1?></span><div><span class="status-badge <?=$item['visit_type']==='registration'?'is-active':'is-warning'?>"><?=e($historyLabel)?></span><h3><?=e((string)($item['trip_code']??''))?></h3><p><?=e((string)($item['recorded_by']?:$item['staff_name']?:''))?></p></div><span class="muted-text"><?=e(date('d M Y',strtotime((string)$item['created_at'])) )?></span></div>
        <dl class="retailer-visit-summary"><div><dt><?= $item['follow_up_method']==='phone_call'?'Call Date':'Trip Date' ?></dt><dd><?=e($item['follow_up_method']==='phone_call'&&$item['follow_up_at']?date('d M Y H:i',strtotime((string)$item['follow_up_at'])):($item['trip_date']?date('d M Y',strtotime((string)$item['trip_date'])):''))?></dd></div><div><dt>Arrival</dt><dd><?=e(substr((string)$item['shop_arrival_time'],0,5))?></dd></div><div><dt>Departure</dt><dd><?=e(substr((string)$item['shop_departure_time'],0,5))?></dd></div><?php if($item['visit_type']!=='follow_up'):?><div><dt>Promo Plug</dt><dd><?=e((string)($item['promo_plug']??''))?></dd></div><?php endif;?><div><dt>Recorded</dt><dd><?=e(date('d M Y',strtotime((string)$item['created_at'])))?></dd></div></dl>
        <div class="retailer-visit-notes"><div><span>Feedback</span><p><?=e((string)($item['feedback']??''))?></p></div><div><span>Note</span><?php if($noteText!==''):?><button class="visit-note-preview" type="button" data-note-view data-note-text="<?=e($noteText)?>"><?=e($notePreview)?></button><?php else:?><p class="muted-text">No note</p><?php endif;?></div></div></article>
    <?php endforeach; ?>
    </div>
</section>
<?php require __DIR__.'/../includes/footer.php'; ?>
