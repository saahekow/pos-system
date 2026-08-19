<?php
require_once __DIR__ . '/config/app.php';
require_auth();

$source = (string) ($_GET['source'] ?? 'normalized_visit');
$id = max(0, (int) ($_GET['id'] ?? 0));
$returnTo = trim((string) ($_GET['return_to'] ?? ''));
if ($returnTo !== '') {
    $returnParts = parse_url($returnTo);
    $recordsPath = (string) (parse_url(app_url('registration-records.php'), PHP_URL_PATH) ?: app_url('registration-records.php'));
    if (is_array($returnParts) && (string) ($returnParts['path'] ?? '') === $recordsPath) {
        $returnTo = $recordsPath . (isset($returnParts['query']) ? '?' . $returnParts['query'] : '');
    }
}
$customersUrl = app_url('customers.php?return_to=' . rawurlencode(app_url('normalized-customer.php?stage=new-place&menu=customer')));
if ($returnTo === '') $returnTo = $customersUrl;

if ($id < 1 || !in_array($source, ['normalized_visit', 'destination_visit', 'vendor_customer'], true)) {
    header('Location: ' . $customersUrl);
    exit;
}

$target = match ($source) {
    'destination_visit' => 'visit-details.php',
    'vendor_customer' => 'vendor-customer-edit.php',
    default => 'normalized-visit-details.php',
};
$parameters = ['id' => $id];
if ($returnTo !== '') $parameters['return_to'] = $returnTo;
header('Location: ' . app_url($target . '?' . http_build_query($parameters)));
exit;
