<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('vehicle_log');

$pageTitle = 'Log Book';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Vehicle Log', 'url' => app_url('vehicles.php')],
    ['label' => 'Log Book'],
];
$internalBackUrl=requested_return_url(app_url('vehicles.php'));
$panelIcon = 'fa-solid fa-book-open';
require_once __DIR__ . '/page-template.php';
