<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_promo_plug_schema();
require_module_access('reports');
ensure_places_management_schema();

$places = db()->query(
    "SELECT p.*,t.town_name,
            (SELECT COUNT(*) FROM customers c WHERE c.bus_loc_id=p.id AND c.master_customer_id IS NULL) AS customer_count,
            (SELECT COUNT(*) FROM place_visit_sessions ps WHERE ps.bus_loc_id=p.id) AS place_visit_count,
            (SELECT COUNT(*) FROM visits a WHERE a.bus_loc_id=p.id) AS activity_count
     FROM business_locations p
     LEFT JOIN locations t ON t.id=p.location_id
     WHERE p.is_legacy_placeholder=0
     ORDER BY p.id DESC"
)->fetchAll();

$customers = db()->query(
    "SELECT c.*,p.bus_loc_ref,p.business_name,p.is_legacy_placeholder,
            (SELECT COUNT(*) FROM visits a WHERE a.customer_id=c.id) AS activity_count
     FROM customers c
     LEFT JOIN job_types jt ON jt.id=c.job_type_id
     INNER JOIN business_locations p ON p.id=c.bus_loc_id
     WHERE c.master_customer_id IS NULL
       AND COALESCE(LOWER(NULLIF(jt.job_type_name,'')),LOWER(NULLIF(c.job_type,'')),'')<>'apprentice'
     ORDER BY c.id DESC"
)->fetchAll();

$placeVisits = db()->query(
    "SELECT ps.*,p.bus_loc_ref,p.business_name,t.town_name,st.trip_code,
            COUNT(a.id) AS activity_count,
            SUM(a.visit_type='registration') AS registration_count,
            SUM(a.visit_type='follow_up') AS follow_up_count
     FROM place_visit_sessions ps
     INNER JOIN business_locations p ON p.id=ps.bus_loc_id
     LEFT JOIN locations t ON t.id=p.location_id
     LEFT JOIN sales_trips st ON st.id=ps.sales_trip_id
     LEFT JOIN visits a ON a.place_session_id=ps.id
     GROUP BY ps.id
     ORDER BY ps.id DESC"
)->fetchAll();

$activities = db()->query(
    "SELECT a.*,ps.session_ref,p.bus_loc_ref,p.business_name,c.customer_ref,c.customer_name,
            st.trip_code,cs.sale_record_ref,cs.sales_ref,cs.sale_confirmed,
            (SELECT n.feedback FROM visit_notes n WHERE n.visit_id=a.id ORDER BY n.id DESC LIMIT 1) AS feedback
     FROM visits a
     INNER JOIN business_locations p ON p.id=a.bus_loc_id
     INNER JOIN customers c ON c.id=a.customer_id
     LEFT JOIN place_visit_sessions ps ON ps.id=a.place_session_id
     LEFT JOIN sales_trips st ON st.id=a.sales_trip_id
     LEFT JOIN customer_sales cs ON cs.visit_id=a.id
     ORDER BY a.id DESC"
)->fetchAll();

$tab = (string)($_GET['tab'] ?? 'place-visits');
$tab = in_array($tab, ['places','customers','place-visits','activities'], true) ? $tab : 'place-visits';
$rows = match ($tab) {
    'places' => $places,
    'customers' => $customers,
    'activities' => $activities,
    default => $placeVisits,
};

$pageTitle = 'Location Visits & Customer Activities';
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Reports','url'=>app_url('reports.php')],['label'=>'Separated Records']];
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel management-panel--table">
    <div class="management-heading">
<div><span class="section-kicker">Separated workflow</span><h1>Location Visits & Customer Activities</h1><p>Each physical stop is one location visit containing every registration and follow-up completed there.</p></div>
        <div class="management-icon"><i class="fa-solid fa-diagram-project"></i></div>
    </div>
    <div class="report-mode-actions">
<a class="report-mode-button <?= $tab==='place-visits'?'is-active':'' ?>" href="?tab=place-visits"><span>Location Visits</span></a>
        <a class="report-mode-button <?= $tab==='activities'?'is-active':'' ?>" href="?tab=activities"><span>Customer Activities</span></a>
<a class="report-mode-button <?= $tab==='places'?'is-active':'' ?>" href="?tab=places"><span>Locations</span></a>
        <a class="report-mode-button <?= $tab==='customers'?'is-active':'' ?>" href="?tab=customers"><span>Customers</span></a>
    </div>
    <div class="table-wrap"><table class="data-table"><thead><tr>
        <?php if ($tab === 'place-visits'): ?>
<th>Location Visit</th><th>Trip</th><th>Location</th><th>Arrival</th><th>Departure</th><th>Registrations</th><th>Follow-ups</th><th>Total Activities</th><th>Status</th>
        <?php elseif ($tab === 'activities'): ?>
<th>Activity Ref</th><th>Location Visit</th><th>Trip</th><th>Customer</th><th>Location</th><th>Activity Type</th><th>Sales</th><th>Feedback</th>
        <?php elseif ($tab === 'places'): ?>
<th>Location Ref</th><th>Business</th><th>Town / Area</th><th>Customers</th><th>Location Visits</th><th>Activities</th>
        <?php else: ?>
<th>Customer Ref</th><th>Customer</th><th>Permanent Location</th><th>Phone</th><th>Activities</th>
        <?php endif; ?>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr>
        <?php if ($tab === 'place-visits'): ?>
            <td><strong><?= e($row['session_ref']) ?></strong></td><td><?= e((string)$row['trip_code']) ?></td><td><?= e($row['bus_loc_ref'].' / '.$row['business_name']) ?></td><td><?= e(substr((string)$row['arrival_time'],0,5) ?: '—') ?></td><td><?= e(substr((string)$row['departure_time'],0,5) ?: '—') ?></td><td><?= (int)$row['registration_count'] ?></td><td><?= (int)$row['follow_up_count'] ?></td><td><strong><?= (int)$row['activity_count'] ?></strong></td><td><span class="status-badge <?= $row['status']==='active'?'is-warning':'is-success' ?>"><?= e(ucfirst($row['status'])) ?></span></td>
        <?php elseif ($tab === 'activities'): ?>
            <td><strong><?= e($row['visit_ref']) ?></strong></td><td><?= e((string)($row['session_ref'] ?? 'Phone activity')) ?></td><td><?= e((string)$row['trip_code']) ?></td><td><?= e($row['customer_ref'].' / '.$row['customer_name']) ?></td><td><?= e($row['bus_loc_ref'].' / '.$row['business_name']) ?></td><td><?= e(ucwords(str_replace('_',' ',$row['visit_type']))) ?></td><td><?= e((string)($row['sale_record_ref'] ?: '—')) ?><span class="muted-text"><?= e((string)$row['sales_ref']) ?></span></td><td><?= e((string)$row['feedback']) ?></td>
        <?php elseif ($tab === 'places'): ?>
            <td><?= e($row['bus_loc_ref']) ?></td><td><?= e($row['business_name']) ?></td><td><?= e(trim(($row['town_name'] ?? '').' / '.$row['area'],' /')) ?></td><td><?= (int)$row['customer_count'] ?></td><td><?= (int)$row['place_visit_count'] ?></td><td><?= (int)$row['activity_count'] ?></td>
        <?php else: ?>
            <td><?= e($row['customer_ref']) ?></td><td><?= e($row['customer_name']) ?><?php if((int)$row['is_legacy_placeholder']===1):?><span class="muted-text">Legacy customer</span><?php endif;?></td><td><?= (int)$row['is_legacy_placeholder']===1 ? 'No location assigned' : e($row['bus_loc_ref'].' / '.$row['business_name']) ?></td><td><?= e((string)$row['phone']) ?></td><td><?= (int)$row['activity_count'] ?></td>
        <?php endif; ?>
    </tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td class="empty-state" colspan="9">No <?= e(str_replace('-', ' ', $tab)) ?> records are available yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php';
