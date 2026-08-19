<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$canTrip = can_access_menu_item('marketing_trip_registration')||can_access_menu_item('marketing_location_registration')||can_access_menu_item('marketing_customer')||can_access_menu_item('marketing_sales')||can_access_menu_item('marketing_promo_plug');
$canReports = can_access_menu_item('marketing_report_trip')||can_access_menu_item('marketing_report_location')||can_access_menu_item('marketing_report_customer')||can_access_menu_item('marketing_report_notes')||can_access_menu_item('marketing_report_promo')||can_access_menu_item('marketing_report_vendors');
$canMarketingAdmin = can_access_module('admin') || can_access_module('create_customer') || can_access_module('vendor_customers');
if (!$canTrip && !$canReports && !$canMarketingAdmin) {
    header('Location: ' . app_url('index.php'));
    exit;
}

$view = (string)($_GET['view'] ?? 'menu');
if (!in_array($view, ['menu', 'trip', 'reports', 'admin', 'setup', 'assignment'], true)) $view = 'menu';
if (($view === 'trip' && !$canTrip) || ($view === 'reports' && !$canReports) || (in_array($view, ['admin','setup','assignment'], true) && !$canMarketingAdmin)) {
    $view = 'menu';
}

$pageTitle = match ($view) {
    'trip' => 'Marketing Trip',
    'reports' => 'Marketing Reports',
    'admin' => 'Marketing Admin',
    'setup' => 'Marketing Setup',
    'assignment' => 'Marketing Assignment',
    default => 'Marketing',
};
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Marketing','url'=>app_url('marketing.php')]];
if ($view !== 'menu') $breadcrumbs[] = ['label'=>$pageTitle];
$internalBackUrl = $view === 'menu' ? app_url('index.php') : (in_array($view,['setup','assignment'],true) ? app_url('marketing.php?view=admin') : app_url('marketing.php'));
require_once __DIR__ . '/../includes/header.php';

$cards = [];
if ($view === 'menu') {
    if ($canTrip) $cards[]=['title'=>'Trip','description'=>'Trip registration, locations, customers, sales, and promotional plugs.','icon'=>'fa-solid fa-route','url'=>app_url('marketing.php?view=trip')];
    if ($canReports) $cards[]=['title'=>'Reports','description'=>'Review trip, location, customer, notes, and promotional activity.','icon'=>'fa-solid fa-chart-column','url'=>app_url('marketing.php?view=reports')];
    if ($canMarketingAdmin) $cards[]=['title'=>'Admin','description'=>'Open marketing setup and assignment tools.','icon'=>'fa-solid fa-gears','url'=>app_url('marketing.php?view=admin')];
} elseif ($view === 'trip') {
    if (can_access_menu_item('marketing_trip_registration')) $cards[]=['title'=>'Trip Registration','description'=>'Register, start, review, and complete a marketing trip.','icon'=>'fa-solid fa-route','url'=>app_url('sales-trip.php?section=trip')];
    if (can_access_menu_item('marketing_location_registration'))
        $cards[]=['title'=>'Location Registration','description'=>'Register a new location or continue at an existing location.','icon'=>'fa-solid fa-location-dot','url'=>app_url('normalized-customer.php?stage=new-place&menu=location')];
    if (can_access_menu_item('marketing_customer'))
        $cards[]=['title'=>'Customer','description'=>'Register or manage customers within the current marketing trip.','icon'=>'fa-solid fa-user-plus','url'=>app_url('normalized-customer.php?stage=new-place&menu=customer')];
    if(can_access_menu_item('marketing_sales')) $cards[]=['title'=>'Sales','description'=>'Record sales using the existing POS Sales workflow.','icon'=>'fa-solid fa-cart-shopping','url'=>app_url('pos-sales.php?return_to='.rawurlencode(app_url('marketing.php?view=trip')))];
    if(can_access_menu_item('marketing_promo_plug'))
        $cards[]=['title'=>'Promo Plug','description'=>'Select a customer and record Promo Plug as Yes or No.','icon'=>'fa-solid fa-bullhorn','url'=>app_url('normalized-customer.php?stage=new-place&menu=promo')];
} elseif ($view === 'reports') {
    $reportUrl = can_access_module('reports') ? 'reports.php' : 'vendor-reports.php';
    foreach ([['Trip','fa-solid fa-route'],['Location','fa-solid fa-location-dot'],['Customer','fa-solid fa-users'],['Notes','fa-solid fa-note-sticky'],['Promo Plug','fa-solid fa-bullhorn'],['Vendors','fa-solid fa-store']] as [$title,$icon]) {
        $permission=['Trip'=>'marketing_report_trip','Location'=>'marketing_report_location','Customer'=>'marketing_report_customer','Notes'=>'marketing_report_notes','Promo Plug'=>'marketing_report_promo','Vendors'=>'marketing_report_vendors'][$title];
        if(!can_access_menu_item($permission))continue;
        $url=match(true){
            $title==='Trip'&&can_access_module('reports')=>app_url('reports.php?report=visit-summary&return_to='.rawurlencode(app_url('marketing.php?view=reports'))),
            $title==='Location'=>app_url('registration-records.php?view=locations&return_to='.rawurlencode(app_url('marketing.php?view=reports'))),
            $title==='Customer'=>app_url('registration-records.php?view=customers&return_to='.rawurlencode(app_url('marketing.php?view=reports'))),
            $title==='Notes'=>app_url('marketing-notes-report.php'),
            $title==='Promo Plug'=>app_url('marketing-promo-report.php'),
            $title==='Vendors'=>app_url('marketing-vendors-report.php'),
            default=>app_url($reportUrl),
        };
        $cards[]=['title'=>$title,'description'=>'Open the available '.strtolower($title).' records and reporting tools.','icon'=>$icon,'url'=>$url];
    }
} elseif ($view === 'admin') {
    $cards[]=['title'=>'Setup','description'=>'Manage locations, vendors, customers, and related marketing setup.','icon'=>'fa-solid fa-sliders','url'=>app_url('marketing.php?view=setup')];
    if (can_access_module('admin')) $cards[]=['title'=>'Assignment','description'=>'Open staff and administrative assignment areas.','icon'=>'fa-solid fa-clipboard-check','url'=>app_url('marketing.php?view=assignment')];
} elseif ($view === 'assignment') {
    $cards[]=['title'=>'Staff','description'=>'Assign Marketing and other operational menu access to staff accounts.','icon'=>'fa-solid fa-id-card-clip','url'=>app_url('assignments.php?return_to='.rawurlencode(app_url('marketing.php?view=assignment')))];
    $cards[]=['title'=>'Admin','description'=>'Open administrative staff, vendor, town, and customer assignments.','icon'=>'fa-solid fa-user-gear','url'=>app_url('admin.php?view=assignment&return_to='.rawurlencode(app_url('marketing.php?view=assignment')))];
} else {
    if (can_access_module('admin')) {
        $marketingSetupReturn=rawurlencode(app_url('marketing.php?view=setup'));
        $cards[]=['title'=>'Location Setup','description'=>'Manage the Region → MMDA → Town hierarchy.','icon'=>'fa-solid fa-map-location-dot','url'=>app_url('location-setup.php?return_to='.$marketingSetupReturn)];
        $cards[]=['title'=>'Vendor','description'=>'Create and manage vendor accounts.','icon'=>'fa-solid fa-store','url'=>app_url('vendor-setup.php?return_to='.$marketingSetupReturn)];
        $cards[]=['title'=>'Customer','description'=>'Create customers outside a marketing trip.','icon'=>'fa-solid fa-user-plus','url'=>app_url('admin-customers.php?return_to='.$marketingSetupReturn)];
        $cards[]=['title'=>'Assign Customer to Vendor','description'=>'Assign existing customer records to vendors.','icon'=>'fa-solid fa-user-tag','url'=>app_url('customer-vendor-assignments.php?return_to='.$marketingSetupReturn)];
    } elseif (can_access_module('vendor_customers')) {
        $cards[]=['title'=>'Customer','description'=>'Create customers within your assigned town.','icon'=>'fa-solid fa-user-plus','url'=>app_url('vendor-customers.php')];
    }
}
?>
<section class="dashboard"><div class="tile-grid tile-grid--compact">
<?php foreach($cards as $card): ?><a class="module-card" href="<?=e($card['url'])?>"><span class="module-card__icon"><i class="<?=e($card['icon'])?>"></i></span><span class="module-card__content"><h2><?=e($card['title'])?></h2><p><?=e($card['description'])?></p></span><span class="module-card__arrow"><i class="fa-solid fa-arrow-right"></i></span></a><?php endforeach; ?>
</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
