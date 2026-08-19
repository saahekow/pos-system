<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('reports');
ensure_destination_visit_schema();

$requestedId = max(0, (int) ($_GET['id'] ?? 0));
$returnMode = (string) ($_GET['mode'] ?? 'type');
$returnMode = in_array($returnMode, ['type', 'lookup'], true) ? $returnMode : 'type';

$rootStatement = db()->prepare('SELECT CASE WHEN visit_type=? THEN id ELSE parent_visit_id END FROM destination_visits WHERE id=? LIMIT 1');
$rootStatement->execute(['registration', $requestedId]);
$registrationId = (int) ($rootStatement->fetchColumn() ?: 0);

$statement = db()->prepare(
    "SELECT registration.*,d.destination_name,l.region_name,l.town_name,l.is_capital,st.shop_type_name,
            u.full_name AS recorded_by,s.full_name AS staff_name
     FROM destination_visits registration
     INNER JOIN destinations d ON d.id=registration.destination_id
     LEFT JOIN locations l ON l.id=registration.location_id
     LEFT JOIN shop_types st ON st.id=registration.shop_type_id
     LEFT JOIN users u ON u.id=registration.recorded_by_user_id
     LEFT JOIN staff s ON s.id=registration.staff_id
     WHERE registration.id=? AND registration.visit_type='registration'
     LIMIT 1"
);
$statement->execute([$registrationId]);
$registration = $statement->fetch();

if (!$registration) {
    http_response_code(404);
    $pageTitle = 'Follow-up Report Not Found';
    $breadcrumbs = [['label' => 'Home', 'url' => app_url('index.php')], ['label' => 'Reports', 'url' => app_url('reports.php')], ['label' => 'Not Found']];
    require_once __DIR__ . '/../includes/header.php';
    echo '<section class="management-panel"><h1>Follow-up report not found</h1><p>The requested registration or its follow-up history is unavailable.</p></section>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$followupStatement = db()->prepare(
    "SELECT followup.*,u.full_name AS recorded_by,s.full_name AS staff_name,tr.trip_code,tr.trip_date
     FROM destination_visits followup
     LEFT JOIN users u ON u.id=followup.recorded_by_user_id
     LEFT JOIN staff s ON s.id=followup.staff_id
     LEFT JOIN sales_trips tr ON tr.id=followup.sales_trip_id
     WHERE followup.parent_visit_id=? AND followup.visit_type='follow_up'
     ORDER BY COALESCE(followup.follow_up_at,followup.created_at) DESC,followup.id DESC"
);
$followupStatement->execute([$registrationId]);
$followups = $followupStatement->fetchAll();

$displayName = (string) ($registration['company_name'] ?: $registration['owner_name'] ?: $registration['destination_name']);
$registeredBy = (string) ($registration['recorded_by'] ?: $registration['staff_name'] ?: '');
$locationSuffix = implode(', ', array_filter([(string) ($registration['area'] ?? ''), (string) ($registration['region_name'] ?? '')]));
$salesReference=trim((string)($registration['sales_ref']??''));
$isSold=customer_has_completed_pos_sale((int)($registration['customer_id']??0));
$saleVins=customer_pos_sale_vins('visit',$registrationId);
$pageTitle = $displayName . ' Follow-ups';
$breadcrumbs = [['label' => 'Home', 'url' => app_url('index.php')], ['label' => 'Reports', 'url' => app_url('reports.php')], ['label' => 'Follow-up Reports', 'url' => app_url('reports.php?report=followup')], ['label' => $displayName]];
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel retailer-visit-panel followup-profile-compact">
    <div class="management-heading retailer-detail-heading">
        <div><span class="section-kicker">Follow-up Profile</span><h1><?= e($displayName) ?></h1><p><?= e((string) $registration['destination_name']) ?><?= $registration['area'] ? ' · ' . e((string) $registration['area']) : '' ?></p></div>
        <div class="retailer-heading-actions"><span class="status-badge is-active"><?= number_format(count($followups)) ?> Follow-up<?= count($followups) === 1 ? '' : 's' ?></span><a class="secondary-button" href="<?= e(app_url('reports.php?report=followup&mode=' . $returnMode)) ?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a></div>
    </div>
    <div class="sales-status-editor <?=$isSold?'is-sold':'is-unsold'?>" data-customer-sales-row><div><span class="section-kicker">POS Sales Status</span><strong><?=$isSold?'Yes — Purchased':'No — Not purchased'?></strong><small><?=$isSold?'A completed POS sale is linked to this customer.':'No completed POS sale is linked to this customer.'?></small></div></div>
    <div class="detail-grid detail-grid--plain retailer-profile-grid"><dl>
        <div><dt>Contact</dt><dd><?= e((string) ($registration['owner_name'] ?: 'Not provided')) ?></dd></div>
        <div><dt>Phone</dt><dd><?= e((string) ($registration['phone'] ?: 'Not provided')) ?></dd></div>
        <div><dt>Location</dt><dd><?= town_name_html((string) ($registration['town_name'] ?? ''),$registration['is_capital']??0) ?><?= $locationSuffix !== '' ? ', ' . e($locationSuffix) : '' ?></dd></div>
        <div><dt>Registered</dt><dd><?= e(date('d M Y', strtotime((string) $registration['created_at']))) ?><?= $registeredBy !== '' ? ' by ' . e($registeredBy) : '' ?></dd></div>
        <div class="detail-item--wide"><dt>Purchased VINs</dt><dd data-sales-vin-list><?=e($saleVins?implode(', ',$saleVins):'None recorded')?></dd></div>
    </dl></div>
</section>

<section class="management-panel management-panel--table retailer-visit-panel">
    <div class="management-heading management-heading--compact"><div><span class="section-kicker">Follow-up History</span><h2>All Follow-ups</h2><p><?= number_format(count($followups)) ?> <?= count($followups) === 1 ? 'record' : 'records' ?> in newest-first order.</p></div><div class="management-icon"><i class="fa-solid fa-timeline"></i></div></div>
    <?php if (!$followups): ?><p class="empty-state">No follow-ups have been recorded for this registration.</p><?php else: ?>
    <div class="retailer-visit-stack">
        <?php foreach ($followups as $index => $followup):
            $method = (string) ($followup['follow_up_method'] ?: 'physical_visit');
            $isCall = $method === 'phone_call';
            $methodLabel = $isCall ? 'Phone Call' : 'Physical Visit';
            $followupDate = $followup['follow_up_at'] ?: $followup['created_at'];
            $recordedBy = (string) ($followup['recorded_by'] ?: $followup['staff_name'] ?: '');
        ?>
        <article class="retailer-visit-card followup-history-card">
            <div class="retailer-visit-card__header"><span class="retailer-visit-card__marker"><?= $index + 1 ?></span><div><span class="status-badge <?= $isCall ? 'is-warning' : 'is-active' ?>"><i class="fa-solid <?= $isCall ? 'fa-phone' : 'fa-location-dot' ?>"></i> <?= e($methodLabel) ?></span><h3><?= e(date('d M Y, H:i', strtotime((string) $followupDate))) ?></h3><p><?= e($recordedBy) ?></p></div><span class="muted-text">#<?= e((string) $followup['id']) ?></span></div>
            <dl class="retailer-visit-summary">
                <?php if ($isCall): ?><div><dt>Called Number</dt><dd><?= e((string) ($followup['phone'] ?? '')) ?></dd></div><?php else: ?><div><dt>Trip</dt><dd><?= e((string) ($followup['trip_code'] ?? '')) ?></dd></div><div><dt>Arrival</dt><dd><?= e(substr((string) ($followup['shop_arrival_time'] ?? ''), 0, 5)) ?></dd></div><div><dt>Departure</dt><dd><?= e(substr((string) ($followup['shop_departure_time'] ?? ''), 0, 5)) ?></dd></div><?php endif; ?>
                <div><dt>Feedback</dt><dd><?= e((string) ($followup['feedback'] ?? '')) ?></dd></div>
            </dl>
            <div class="retailer-visit-notes"><div><span>Note</span><p><?= nl2br(e((string) ($followup['note'] ?? ''))) ?></p></div><div><span>Date Recorded</span><p><?= e(date('d M Y, H:i', strtotime((string) $followup['created_at']))) ?></p></div></div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
