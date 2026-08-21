<?php
$pageTitle = 'Dashboard';
$breadcrumbs = [];
require_once __DIR__ . '/includes/header.php';

$modules = visible_application_modules();
$homePersonnel = current_vendor_personnel();
$modules=$homePersonnel&&current_user_role()!=='staff'&&isset($modules['pos'])?['pos'=>$modules['pos']]:($homePersonnel&&current_user_role()!=='staff'?[]:$modules);
$homeVendor = current_user_role() === 'vendor' || $homePersonnel ? current_vendor_profile() : null;
$menuGroups = [
    'marketing' => [
        'title' => 'Marketing',
        'description' => 'Open trip, reporting, and marketing administration menus.',
        'icon' => 'fa-solid fa-bullhorn',
        'url' => app_url('marketing.php'),
        'module_keys' => ['customer_visit', 'sales_trip', 'reports', 'create_customer', 'vendor_customers', 'vendor_reports'],
    ],
    'pos' => [
        'title' => 'POS',
        'description' => 'Open sales, transfers, refunds, audit, reports, and POS administration.',
        'icon' => 'fa-solid fa-receipt',
        'url' => app_url('pos.php'),
        'module_keys' => ['pos'],
    ],
    'customer_followup' => [
        'title' => 'Customer Follow-up',
        'description' => 'Find an accessible customer and record a phone or physical follow-up.',
        'icon' => 'fa-solid fa-clipboard-check',
        'url' => app_url('followup.php'),
        'module_keys' => ['customer_followup'],
    ],
    'admin' => [
        'title' => 'Admin',
        'description' => 'Open attendance, vehicle log, administration, and reports.',
        'icon' => 'fa-solid fa-user-gear',
        'url' => app_url('admin.php'),
        'module_keys' => ['attendance', 'vehicle_log', 'admin'],
    ],
];
$visibleMenuGroups = array_filter($menuGroups, static function (array $group, string $key) use ($modules): bool {
    if($key==='marketing')return can_access_menu_item('marketing_trip_registration')||can_access_menu_item('marketing_location_registration')||can_access_menu_item('marketing_customer')||can_access_menu_item('marketing_sales')||can_access_menu_item('marketing_promo_plug')||can_access_menu_item('marketing_report_trip')||can_access_menu_item('marketing_report_location')||can_access_menu_item('marketing_report_customer')||can_access_menu_item('marketing_report_notes')||can_access_menu_item('marketing_report_promo')||can_access_menu_item('marketing_report_vendors')||can_access_module('admin')||can_access_module('vendor_customers');
    if($key==='pos')return can_access_menu_item('pos_shop_sales')||can_access_menu_item('pos_trip_sales')||can_access_menu_item('pos_promo')||can_access_menu_item('pos_transfer')||can_access_menu_item('pos_refund')||can_access_menu_item('pos_audit')||can_access_menu_item('pos_reports')||is_admin_user();
    if($key==='admin')return can_access_module('attendance')||can_access_menu_item('admin_vehicle_log')||can_access_menu_item('admin_reports')||can_access_module('admin');
    return (bool)array_intersect(array_keys($modules),$group['module_keys']);
},ARRAY_FILTER_USE_BOTH);
$groupedModuleKeys = [];
foreach ($menuGroups as $group) {
    $groupedModuleKeys = array_merge($groupedModuleKeys, $group['module_keys']);
}
$nestedModuleKeys = ['registration_records', 'activity_log', 'vin_search'];
$ungroupedModules = array_diff_key(
    $modules,
    array_flip(array_unique(array_merge($groupedModuleKeys, $nestedModuleKeys)))
);
?>
<section class="dashboard">
    <section class="spwsales-welcome" aria-labelledby="spwsales-welcome-title">
        <span class="spwsales-welcome__icon" aria-hidden="true"><i class="fa-solid fa-hand-sparkles"></i></span>
        <div><small>Welcome to SPW Sales</small><h1 id="spwsales-welcome-title">Hello, <?=e(current_user_name())?></h1><?php if(!$homeVendor):?><p>Choose a menu to get started.</p><?php endif;?></div>
        <?php if($homeVendor):?><b class="spwsales-welcome__type <?=($homeVendor['vendor_type']??'regular')==='sor'?'is-sor':'is-regular'?>"><?=($homeVendor['vendor_type']??'regular')==='sor'?'SoR Vendor':'Regular Vendor'?></b><?php endif;?>
    </section>
    <?php if (!$visibleMenuGroups && !$ungroupedModules): ?>
        <p class="empty-state">No menus have been assigned to your account yet.</p>
    <?php else: ?>
        <div class="tile-grid tile-grid--compact">
        <?php foreach ($visibleMenuGroups as $module): ?>
            <a class="module-card" href="<?=e((string)$module['url'])?>">
                <span class="module-card__icon" aria-hidden="true"><i class="<?=e((string)$module['icon'])?>"></i></span>
                <span class="module-card__content"><h2><?=e((string)$module['title'])?></h2><p><?=e((string)$module['description'])?></p></span>
                <span class="module-card__arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
            </a>
        <?php endforeach; ?>
        <?php foreach ($ungroupedModules as $module): ?>
            <a class="module-card" href="<?=e((string)$module['url'])?>">
                <span class="module-card__icon" aria-hidden="true"><i class="<?=e((string)$module['icon'])?>"></i></span>
                <span class="module-card__content"><h2><?=e((string)$module['title'])?></h2><p><?=e((string)$module['description'])?></p></span>
                <span class="module-card__arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
