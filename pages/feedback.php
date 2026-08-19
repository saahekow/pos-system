<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('feedback');
ensure_destination_visit_schema();
$pageTitle = 'Feedback';
$breadcrumbs = [['label' => 'Home', 'url' => app_url('index.php')], ['label' => 'Feedback']];
$internalBackUrl = requested_return_url(app_url('reports.php'));
$destinationId = max(0, (int) ($_GET['destination_id'] ?? 0));
$destinations = db()->query('SELECT id,destination_name,destination_key FROM destinations WHERE is_active=1 ORDER BY is_default DESC,destination_name')->fetchAll();
$selectedDestination = null;
foreach ($destinations as $destination) {
    if ((int) $destination['id'] === $destinationId) {
        $selectedDestination = $destination;
        break;
    }
}
$rows = [];
if ($selectedDestination) {
    $sql = 'SELECT dv.id,dv.company_name,dv.owner_name,dv.phone,dv.area,dv.feedback,dv.note,dv.created_at,d.destination_name,COALESCE(u.full_name,s.full_name,\'Unknown staff\') AS recorded_by FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id LEFT JOIN users u ON u.id=dv.recorded_by_user_id LEFT JOIN staff s ON s.id=dv.staff_id WHERE dv.record_status=\'completed\' AND dv.destination_id=? AND (dv.feedback IS NOT NULL OR dv.note IS NOT NULL)';
    $params = [$destinationId];
    if (!in_array(current_user_role(), ['super_admin', 'admin'], true)) {
        $sql .= ' AND (dv.recorded_by_user_id=? OR dv.staff_id=?)';
        array_push($params, current_user_id(), current_staff_id());
    }
    $sql .= ' ORDER BY dv.created_at DESC,dv.id DESC';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
}
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker">Field Notes</span><h1>Feedback</h1><p><?= $selectedDestination ? 'Review feedback recorded for ' . e((string)$selectedDestination['destination_name']) . ' visits.' : 'Choose a destination to review its feedback.' ?></p></div><div class="management-icon"><i class="fa-solid fa-comments"></i></div></div>
    <?php if (!$selectedDestination): ?>
    <div class="report-destination-grid">
        <?php foreach($destinations as $destination): ?>
        <a class="report-destination-card" href="<?= e(app_url('feedback.php?destination_id='.(int)$destination['id'].'&return_to='.rawurlencode($internalBackUrl))) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-message"></i></span><strong><?= e((string)$destination['destination_name']) ?></strong><span>View feedback <i class="fa-solid fa-arrow-right"></i></span></a>
        <?php endforeach; ?>
    </div>
    <?php else: ?><div class="report-subnav"><a class="secondary-button" href="<?= e(app_url('feedback.php?return_to='.rawurlencode($internalBackUrl))) ?>"><i class="fa-solid fa-arrow-left"></i><span>All feedback menus</span></a></div><?php endif; ?>
</section>
<?php if ($selectedDestination): ?>
<section class="management-panel management-panel--table"><div class="management-heading management-heading--compact"><div><span class="section-kicker"><?= e((string)$selectedDestination['destination_name']) ?></span><h2>Feedback Records</h2><p><?= number_format(count($rows)) ?> records found</p></div></div>
<?php if(!$rows):?><p class="empty-state">No feedback records found for this destination.</p><?php else:?><div class="table-wrap"><table class="data-table data-table--reports"><thead><tr><th>Name</th><th>Contact</th><th>Feedback</th><th>Note</th><th>Date</th></tr></thead><tbody><?php foreach($rows as $visit):?><tr data-clickable-listing data-feedback-view data-feedback-name="<?=e((string)($visit['company_name']?:$visit['owner_name']?:'Feedback'))?>" data-feedback-number="<?=e((string)($visit['phone']??''))?>" data-feedback-text="<?=e((string)($visit['feedback']??''))?>" data-feedback-note="<?=e((string)($visit['note']??''))?>" data-feedback-date="<?=e(date('d M Y, H:i',strtotime((string)$visit['created_at'])))?>" data-feedback-staff="<?=e((string)$visit['recorded_by'])?>" data-feedback-destination="<?=e((string)$visit['destination_name'])?>"><td><strong><?=e((string)($visit['company_name']?:$visit['owner_name']))?></strong><span class="muted-text"><?=e((string)($visit['area']??''))?></span></td><td><?=e((string)($visit['owner_name']??''))?><span class="muted-text"><?=e((string)($visit['phone']??''))?></span></td><td><span class="status-badge is-active"><?=e((string)($visit['feedback']??''))?></span></td><td class="feedback-note-cell"><?=e((string)($visit['note']??''))?></td><td><?=e(date('d M Y',strtotime((string)$visit['created_at'])))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
