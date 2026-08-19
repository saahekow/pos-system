<?php
require_once __DIR__ . '/../config/app.php';
ensure_customer_promo_plug_schema();
require_module_access('vendor_reports');
ensure_places_management_schema();

$vendor = current_vendor_profile();
if (!$vendor || !(int) ($vendor['location_id'] ?? 0)) {
    http_response_code(403);
    exit('Your vendor account needs an active town before reports can be viewed.');
}

$managedTowns = assigned_towns_for_vendor((int) $vendor['id']);
if (!$managedTowns) {
    http_response_code(403);
    exit('Your vendor account has no assigned towns.');
}

$customerTownStatement = db()->prepare(
    "SELECT DISTINCT l.id,l.id AS location_id,l.town_name,l.is_capital,l.region_code,l.region_name,l.mmda_name,l.mmda_name AS district_name
     FROM visits v
     INNER JOIN business_locations p ON p.id=v.bus_loc_id
     INNER JOIN locations l ON l.id=p.location_id AND l.is_active=1
     LEFT JOIN sales_trips st ON st.id=v.sales_trip_id
     WHERE v.record_status='completed' AND v.visit_type='registration'
       AND (v.vendor_id=? OR st.vendor_id=? OR EXISTS (
            SELECT 1 FROM sales_trip_vendor_assignments sta
            WHERE sta.sales_trip_id=v.sales_trip_id AND sta.vendor_id=?
       ))"
);
$customerTownStatement->execute(array_fill(0, 3, (int)$vendor['id']));
foreach ($customerTownStatement->fetchAll() as $customerTown) {
    $managedTowns[] = $customerTown;
}
$managedTowns = array_values(array_reduce($managedTowns, static function (array $towns, array $town): array {
    $towns[(int) $town['id']] = $town;
    return $towns;
}, []));
usort($managedTowns, static function (array $a, array $b): int { return strcmp((string) $a['town_name'], (string) $b['town_name']); });

$reportSection = (string) ($_GET['report'] ?? '');
$reportSection = $reportSection === 'customers' ? 'customers' : '';
$mode = (string) ($_GET['mode'] ?? '');
$mode = in_array($mode, ['type', 'lookup'], true) ? $mode : '';

$normalizedVisitStatement = db()->prepare(
    "SELECT v.id,v.customer_id,c.customer_name,c.customer_name AS contact_name,c.phone,c.other_phone,
            p.location_id,p.location_id AS town_id,p.area,p.google_location,
            (SELECT vn.note FROM visit_notes vn WHERE vn.visit_id=v.id ORDER BY vn.id DESC LIMIT 1) AS notes,
            v.recorded_by_user_id AS created_by_user_id,v.created_at,cs.sales_ref,
            COALESCE(cs.sale_confirmed,0) AS sale_confirmed,'normalized_visit' AS sales_source,
            u.full_name AS recorded_by,l.town_name,l.is_capital,
            CASE WHEN v.vendor_id IS NOT NULL THEN 'Vendor registration' ELSE 'Staff registration' END AS customer_source,
            d.destination_name
     FROM visits v
     INNER JOIN customers c ON c.id=v.customer_id
     INNER JOIN business_locations p ON p.id=v.bus_loc_id
     LEFT JOIN destinations d ON d.id=p.destination_id
     LEFT JOIN locations l ON l.id=p.location_id
     LEFT JOIN users u ON u.id=v.recorded_by_user_id
     LEFT JOIN customer_sales cs ON cs.id=(
          SELECT cs_latest.id
          FROM customer_sales cs_latest
          WHERE cs_latest.visit_id=v.id
          ORDER BY cs_latest.id DESC
          LIMIT 1
     )
     LEFT JOIN sales_trips st ON st.id=v.sales_trip_id
     WHERE v.record_status='completed'
       AND (v.vendor_id=? OR st.vendor_id=? OR EXISTS (
            SELECT 1 FROM sales_trip_vendor_assignments sta
            WHERE sta.sales_trip_id=v.sales_trip_id AND sta.vendor_id=?
       ))
     ORDER BY v.created_at DESC,v.id DESC"
);
$normalizedVisitStatement->execute(array_fill(0,3,(int)$vendor['id']));
$customers = $normalizedVisitStatement->fetchAll();
$legacyVisitStatement=db()->prepare("SELECT dv.id,0 AS customer_id,COALESCE(NULLIF(dv.owner_name,''),dv.company_name) customer_name,dv.owner_name contact_name,dv.phone,dv.other_phone,dv.location_id,dv.location_id town_id,dv.area,dv.google_location,dv.note notes,dv.recorded_by_user_id created_by_user_id,dv.created_at,NULL sales_ref,0 sale_confirmed,'destination_visit' sales_source,u.full_name recorded_by,l.town_name,l.is_capital,'Legacy registration' customer_source,d.destination_name FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id LEFT JOIN users u ON u.id=dv.recorded_by_user_id LEFT JOIN locations l ON l.id=dv.location_id LEFT JOIN sales_trips st ON st.id=dv.sales_trip_id WHERE dv.visit_type='registration' AND dv.record_status='completed' AND dv.normalized_customer_id IS NULL AND NOT EXISTS(SELECT 1 FROM customers nc WHERE COALESCE(NULLIF(nc.phone,''),NULLIF(nc.other_phone,''),'#') IN (COALESCE(NULLIF(dv.phone,''),'!'),COALESCE(NULLIF(dv.other_phone,''),'?'))) AND (dv.vendor_id=? OR st.vendor_id=? OR EXISTS(SELECT 1 FROM sales_trip_vendor_assignments a WHERE a.sales_trip_id=dv.sales_trip_id AND a.vendor_id=?))");
$legacyVisitStatement->execute(array_fill(0,3,(int)$vendor['id']));$customers=array_merge($customers,$legacyVisitStatement->fetchAll());
$legacyCustomerStatement=db()->prepare("SELECT vc.id,0 customer_id,vc.customer_name,vc.contact_name,vc.phone,vc.other_phone,vc.location_id,vc.location_id town_id,vc.area,NULL google_location,vc.notes,vc.created_by_user_id,vc.created_at,NULL sales_ref,0 sale_confirmed,'vendor_customer' sales_source,u.full_name recorded_by,l.town_name,l.is_capital,'Legacy vendor customer' customer_source,NULL destination_name FROM vendor_customers vc LEFT JOIN users u ON u.id=vc.created_by_user_id LEFT JOIN locations l ON l.id=vc.location_id WHERE vc.vendor_id=? AND vc.normalized_customer_id IS NULL AND NOT EXISTS(SELECT 1 FROM customers nc WHERE COALESCE(NULLIF(nc.phone,''),NULLIF(nc.other_phone,''),'#') IN (COALESCE(NULLIF(vc.phone,''),'!'),COALESCE(NULLIF(vc.other_phone,''),'?')))");
$legacyCustomerStatement->execute([(int)$vendor['id']]);$customers=array_merge($customers,$legacyCustomerStatement->fetchAll());
usort($customers, static function (array $a, array $b): int { return strcmp((string) $b['created_at'], (string) $a['created_at']); });

$pageTitle = 'Customer Reports';
$breadcrumbs = [['label' => 'Home', 'url' => app_url('index.php')], ['label' => 'Reports']];
$internalBackUrl=requested_return_url(app_url('marketing.php?view=reports'));
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($reportSection === ''): ?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker">Report Center</span><h1>Reports</h1><p>Choose the report category you want to open.</p></div><div class="management-icon"><i class="fa-solid fa-chart-line"></i></div></div>
    <div class="report-subnav"><a class="secondary-button" href="<?= e(app_url('vendor-customers.php')) ?>"><i class="fa-solid fa-user-plus"></i><span>Create Customer</span></a></div>
    <div class="report-destination-grid"><a class="report-destination-card" href="<?= e(app_url('vendor-reports.php?report=customers')) ?>"><span class="report-destination-card__icon"><i class="fa-solid fa-users"></i></span><strong>Customers</strong><span>Open reports <i class="fa-solid fa-arrow-right"></i></span></a></div>
</section>

<?php elseif ($mode === ''): ?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker">Report Center</span><h1>Customer Reports</h1><p>Choose how you want to open this report.</p></div><div class="management-icon"><i class="fa-solid fa-users"></i></div></div>
    <div class="report-subnav"><a class="secondary-button" href="<?= e(app_url('vendor-reports.php')) ?>"><i class="fa-solid fa-arrow-left"></i><span>Reports</span></a></div>
    <div class="report-mode-actions report-mode-actions--menu">
        <a class="report-mode-button" href="<?= e(app_url('vendor-reports.php?report=customers&mode=lookup')) ?>"><i class="fa-solid fa-list-check"></i><span>Lookup</span></a>
        <a class="report-mode-button" href="<?= e(app_url('vendor-reports.php?report=customers&mode=type')) ?>"><i class="fa-solid fa-keyboard"></i><span>Quick Search</span></a>
    </div>
</section>

<?php else: ?>
<section class="management-panel management-panel--table" data-report-listing>
    <div class="management-heading"><div><span class="section-kicker">Vendor Reports</span><h1>Customer Reports</h1><p>Customers managed by <?= e((string) $vendor['vendor_name']) ?> across <?= number_format(count($managedTowns)) ?> assigned town<?= count($managedTowns) === 1 ? '' : 's' ?>.</p></div><div class="management-icon"><i class="fa-solid fa-chart-column"></i></div></div>
    <div class="report-subnav"><a class="secondary-button" href="<?= e(app_url('vendor-reports.php?report=customers')) ?>"><i class="fa-solid fa-arrow-left"></i><span>Customer Reports</span></a></div>
    <form class="report-filter-form" data-report-filter-form <?= $mode === 'type' ? 'data-report-require-filter' : '' ?>>
        <?php if ($mode === 'type'): ?>
        <label class="report-search-field" for="vendor_report_search"><i class="fa-solid fa-magnifying-glass"></i><input id="vendor_report_search" type="search" placeholder="Type customer, contact, town, area, or source..." autocomplete="off" data-report-search></label>
        <?php else: ?>
        <div class="report-filter-panel">
            <?php $vendorReportRegions=[];foreach($managedTowns as $town){$key=(string)($town['region_code']??$town['region_name']??'');$vendorReportRegions[$key]=(string)($town['region_name']??'');}asort($vendorReportRegions); ?>
            <div class="form-field"><label for="vendor_report_region">Region</label><select id="vendor_report_region" data-location-region-select><option value="">All regions</option><?php foreach($vendorReportRegions as $regionKey=>$regionName):?><option value="<?=e($regionKey)?>"><?=e($regionName)?></option><?php endforeach;?></select></div>
            <div class="form-field"><label for="location_id">Town</label><select id="location_id" name="location_id" data-location-town-select data-report-lookup><option value="">All assigned towns</option><?php foreach($managedTowns as $town):?><option value="<?=(int)$town['id']?>" data-region-key="<?=e((string)($town['region_code']??$town['region_name']??''))?>" data-mmda-name="<?=e((string)($town['mmda_name']??''))?>"><?=e((string)$town['town_name'])?></option><?php endforeach;?></select><small data-location-mmda-output></small></div>
            <div class="form-field report-date-field"><label for="date_from">Date From</label><input id="date_from" name="date_from" type="date" data-report-date-filter></div>
            <div class="form-field report-date-field"><label for="date_to">Date To</label><input id="date_to" name="date_to" type="date" data-report-date-filter></div>
        </div>
        <?php endif; ?>
        <button class="report-filter-clear is-hidden" type="button" data-report-filter-clear>Clear</button>
    </form>
    <p class="report-result-count" data-report-results-count>0 results found</p>
    <?php if (!$customers): ?>
        <p class="empty-state" data-report-empty-state data-initial-message="No customers have been created in your assigned towns yet." data-no-match-message="No customers match these filters.">No customers have been created in your assigned towns yet.</p>
    <?php else: ?>
        <div class="table-wrap is-hidden" data-report-results><table class="data-table data-table--reports vendor-customer-report-table"><thead><tr><th>Customer</th><th>Contact</th><th>Town</th><th>Area</th><th class="compact-location-cell">Location</th><th>Source</th><th>Sales Status</th><th>Date Added</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($customers as $customer): $search = strtolower(implode(' ', [$customer['customer_name'], $customer['contact_name'], $customer['phone'], $customer['other_phone'], $customer['area'], $customer['town_name'], $customer['customer_source'], $customer['destination_name']])); $sold=customer_has_completed_pos_sale((int)($customer['customer_id']??0)); ?>
            <tr class="customer-sales-row <?=$sold?'is-sold':'is-unsold'?>" data-customer-sales-row data-report-filter-item data-report-search="<?= e($search) ?>" data-town-id="<?= (int) $customer['town_id'] ?>" data-report-date="<?= e(substr((string) $customer['created_at'], 0, 10)) ?>"><td><strong><?= e((string) ($customer['customer_name'] ?: $customer['contact_name'] ?: 'Customer')) ?></strong><span class="muted-text"><?= e((string) ($customer['contact_name'] ?? '')) ?></span></td><td><?= e((string) $customer['phone']) ?><span class="muted-text"><?= e((string) ($customer['other_phone'] ?? '')) ?></span></td><td><?= town_name_html((string) $customer['town_name'], $customer['is_capital'] ?? 0) ?></td><td><?= e((string) ($customer['area'] ?? '')) ?></td><td class="compact-location-cell"><?php if (!empty($customer['google_location'])): ?><a class="compact-location-link" href="<?= e((string) $customer['google_location']) ?>" target="_blank" rel="noopener" title="Open Google location" aria-label="Open Google location for <?= e((string) ($customer['customer_name'] ?: $customer['contact_name'] ?: 'customer')) ?>"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></a><?php else: ?><span class="muted-text" aria-label="No Google location">-</span><?php endif; ?></td><td><span class="status-badge <?= str_starts_with((string)$customer['customer_source'], 'Staff') ? 'is-warning' : 'is-active' ?>"><?= e((string) $customer['customer_source']) ?></span><?php if ($customer['destination_name']): ?><span class="muted-text"><?= e((string) $customer['destination_name']) ?></span><?php endif; ?></td><td><span class="status-badge <?=$sold?'is-success':'is-warning'?>"><?=$sold?'Yes':'No'?></span></td><td><?= e(date('d M Y', strtotime((string) $customer['created_at']))) ?></td><td><?php $legacySource=in_array((string)$customer['sales_source'],['destination_visit','vendor_customer'],true)?(string)$customer['sales_source']:'';?><a class="secondary-button secondary-button--small" href="<?=e($legacySource!==''?app_url('legacy-customer-location.php?source='.$legacySource.'&id='.(int)$customer['id'].'&return_to='.rawurlencode(app_url('vendor-reports.php?report=customers&mode=lookup'))):app_url('registration-edit.php?type=completed&id='.(int)$customer['id'].'&return_to='.rawurlencode(app_url('vendor-reports.php?report=customers&mode=lookup'))))?>"><i class="fa-solid <?=$legacySource!==''?'fa-location-dot':'fa-pen'?>"></i><span><?=$legacySource!==''?'Assign':'Edit'?></span></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <p class="empty-state" data-report-empty-state data-initial-message="Search or select a filter to view records." data-no-match-message="No customers match these filters.">Search or select a filter to view records.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
