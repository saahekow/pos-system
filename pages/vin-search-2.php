<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/vin2.php';
require_module_access('vin_search');

$vin114AppKey = defined('VIN114_APP_KEY') ? (string)VIN114_APP_KEY : '';
$vin114AppSecret = defined('VIN114_APP_SECRET') ? (string)VIN114_APP_SECRET : '';
$vin114ApiUrl = defined('VIN114_API_URL') ? (string)VIN114_API_URL : 'https://interface.dat881.com/api/car/getvehiclebyvin';

db()->exec("CREATE TABLE IF NOT EXISTS vin114_decodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vin CHAR(17) NOT NULL,
    source_index INT UNSIGNED NOT NULL DEFAULT 0,
    level_id VARCHAR(100) NULL,
    brand VARCHAR(160) NULL,
    manufacturer VARCHAR(200) NULL,
    series_name VARCHAR(160) NULL,
    model_name VARCHAR(200) NULL,
    model_year VARCHAR(20) NULL,
    sales_name VARCHAR(255) NULL,
    sales_version VARCHAR(255) NULL,
    vehicle_type VARCHAR(160) NULL,
    vehicle_size VARCHAR(160) NULL,
    chassis_code VARCHAR(160) NULL,
    emission_standard VARCHAR(160) NULL,
    guiding_price VARCHAR(100) NULL,
    api_response LONGTEXT NULL,
    searched_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vin114_decode_model (vin,source_index),
    KEY idx_vin114_decode_vin (vin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$vin = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', (string)($_GET['vin'] ?? '')) ?? '');
$error = '';
$results = [];
$additional = [];
$apiInfo = [];

if ($vin !== '') {
    if (strlen($vin) !== 17) {
        $error = 'Enter a valid 17-character VIN.';
    } elseif ($vin114AppKey === '' || $vin114AppSecret === '') {
        $error = 'VIN Search 2 credentials have not been configured yet.';
    } elseif (!function_exists('curl_init')) {
        $error = 'The server cURL extension is not available.';
    } else {
        $jsonInput = json_encode([
            'vin' => $vin,
            'appkey' => $vin114AppKey,
            'appsecret' => $vin114AppSecret,
        ], JSON_UNESCAPED_SLASHES);
        $curl = curl_init($vin114ApiUrl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['jsonInput' => $jsonInput]),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        $caBundle = __DIR__ . '/../config/certs/cacert.pem';
        if (is_file($caBundle)) curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $curlError !== '') {
            $error = 'VIN Search 2 could not contact the vehicle service.';
        } elseif ($httpCode < 200 || $httpCode >= 300) {
            $error = 'The vehicle service returned HTTP '.$httpCode.'.';
        } else {
            $payload = json_decode((string)$response, true);
            if (!is_array($payload)) {
                $error = 'The vehicle service returned an invalid response.';
            } else {
                $apiInfo = is_array($payload['Info'] ?? $payload['info'] ?? null) ? ($payload['Info'] ?? $payload['info']) : [];
                $dataPayload = is_array($payload['data'] ?? $payload['Data'] ?? null) ? ($payload['data'] ?? $payload['Data']) : [];
                $additional = is_array($payload['Additional'] ?? $payload['additional'] ?? null) ? ($payload['Additional'] ?? $payload['additional']) : [];
                if ($dataPayload !== []) {
                    $additional['orderNo'] = (string)($dataPayload['order_no'] ?? '');
                }
                $resultPayload = $dataPayload['result'] ?? $dataPayload['Result'] ?? $payload['Result'] ?? $payload['result'] ?? [];
                if (is_array($resultPayload) && array_is_list($resultPayload)) {
                    $results = array_values(array_filter($resultPayload, 'is_array'));
                } elseif (is_array($resultPayload) && $resultPayload !== []) {
                    $results = [$resultPayload];
                } else {
                    $results = [];
                }
                foreach ($results as &$record) {
                    $dimensions = implode(' × ', array_filter([(string)($record['len'] ?? ''),(string)($record['width'] ?? ''),(string)($record['height'] ?? '')]));
                    $record['series'] = (string)($record['series'] ?? $record['groupname'] ?? '');
                    $record['models'] = (string)($record['models'] ?? $record['model'] ?? '');
                    $record['year'] = (string)($record['year'] ?? $record['yeartype'] ?? '');
                    $record['salesname'] = (string)($record['salesname'] ?? $record['name'] ?? '');
                    $record['salesversion'] = (string)($record['salesversion'] ?? $record['name'] ?? '');
                    $record['vehicletype'] = (string)($record['vehicletype'] ?? $record['bodytype'] ?? '');
                    $record['vehiclesize'] = (string)($record['vehiclesize'] ?? $dimensions);
                    $record['emissionstandard'] = (string)($record['emissionstandard'] ?? $record['environmentalstandards'] ?? '');
                    $record['guidingprice'] = (string)($record['guidingprice'] ?? $record['price'] ?? '');
                }
                unset($record);
                $success = filter_var((string)($payload['success'] ?? $apiInfo['success'] ?? $apiInfo['Success'] ?? ''), FILTER_VALIDATE_BOOLEAN);
                if (!$success && !$results) {
                    $providerMessage = $apiInfo['desc'] ?? $apiInfo['message'] ?? $apiInfo['msg'] ?? $apiInfo['details'] ?? $apiInfo['Details'] ?? $apiInfo['error']
                        ?? $payload['desc'] ?? $payload['message'] ?? $payload['msg'] ?? $payload['error'] ?? '';
                    if (is_array($providerMessage)) $providerMessage = json_encode($providerMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $providerCode = trim((string)($payload['code'] ?? $apiInfo['code'] ?? $apiInfo['error'] ?? $payload['status'] ?? ''));
                    $error = trim((string)$providerMessage);
                    if ($providerCode === 'E2' && ($error === '' || str_contains($error, '接口已停用'))) {
                        $error = 'This VIN API interface has been disabled by the provider. Contact VIN114 to reactivate this interface for your AppKey.';
                    }
                    if ($error === '') $error = 'No vehicle information was returned for this VIN.';
                    if ($providerCode !== '' && !str_contains($error, $providerCode)) $error .= ' (Code '.$providerCode.')';
                } elseif (!$results) {
                    $error = 'No vehicle model matched this VIN.';
                } else {
                    $save = db()->prepare("INSERT INTO vin114_decodes
                        (vin,source_index,level_id,brand,manufacturer,series_name,model_name,model_year,sales_name,sales_version,vehicle_type,vehicle_size,chassis_code,emission_standard,guiding_price,api_response,searched_by_user_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE level_id=VALUES(level_id),brand=VALUES(brand),manufacturer=VALUES(manufacturer),series_name=VALUES(series_name),model_name=VALUES(model_name),model_year=VALUES(model_year),sales_name=VALUES(sales_name),sales_version=VALUES(sales_version),vehicle_type=VALUES(vehicle_type),vehicle_size=VALUES(vehicle_size),chassis_code=VALUES(chassis_code),emission_standard=VALUES(emission_standard),guiding_price=VALUES(guiding_price),api_response=VALUES(api_response),searched_by_user_id=VALUES(searched_by_user_id),updated_at=NOW()");
                    foreach ($results as $index => $record) {
                        $save->execute([$vin,$index,(string)($record['levelid']??''),(string)($record['brand']??''),(string)($record['manufacturers']??''),(string)($record['series']??''),(string)($record['models']??''),(string)($record['year']??''),(string)($record['salesname']??''),(string)($record['salesversion']??''),(string)($record['vehicletype']??''),(string)($record['vehiclesize']??''),(string)($record['chassiscode']??''),(string)($record['emissionstandard']??''),(string)($record['guidingprice']??''),(string)$response,current_user_id()]);
                    }
                }
            }
        }
    }
}

$pageTitle = 'VIN Search 2';
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Data Management','url'=>app_url('data-management.php')],['label'=>'VIN Search 2']];
$internalBackUrl = app_url('data-management.php');
$primaryVehicle = $results[0] ?? null;
$savedVins = db()->query("SELECT vin,brand,model_name,model_year,COALESCE(updated_at,created_at) saved_at FROM vin114_decodes WHERE source_index=0 AND DATE(COALESCE(updated_at,created_at))=CURDATE() ORDER BY saved_at DESC")->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<section class="vin-search-panel" aria-labelledby="vin-search-2-title">
    <div class="vin-search-hero">
        <div><span class="section-kicker vin-search-kicker">Vehicle identification</span><h1 id="vin-search-2-title">VIN Intelligence</h1><p>Enter a 17-character VIN to retrieve and save accurate vehicle model information.</p></div>
        <form class="vin-search-form" method="get" action="<?=e(app_url('vin-search-2.php'))?>"><label class="vin-search-label" for="vin2">Enter VIN <span>(Accurate analysis)</span></label><input id="vin2" name="vin" minlength="17" maxlength="17" value="<?=e($vin)?>" placeholder="Example: LVYPDL1D4MP235576" autocomplete="off" required><button type="submit"><span>Decode VIN</span><i class="fa-solid fa-magnifying-glass"></i></button></form>
    </div>
    <?php if($error!==''):?><div class="vin-alert vin-alert--error vin-search-message" role="alert"><span class="vin-alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span><div><strong>VIN could not be verified</strong><p><?=e($error)?></p></div></div><?php endif;?>
    <?php if($primaryVehicle):?>
    <section class="vin-result" aria-labelledby="vin2-result-title">
        <div class="vin-result-heading"><div><span class="section-kicker">Vehicle report · <?=number_format(count($results))?> match<?=count($results)===1?'':'es'?></span><h2 id="vin2-result-title"><?=e(trim((string)($primaryVehicle['brand']??'').' '.(string)($primaryVehicle['models']??''))?:$vin)?></h2></div></div>
        <div class="vin-result-grid">
            <article class="vin-vehicle-card"><div class="vin-image-wrap vin-image-placeholder"><i class="fa-solid fa-car-side" aria-hidden="true"></i><?php if(($primaryVehicle['year']??'')!==''):?><span class="vin-year-badge"><?=e((string)$primaryVehicle['year'])?></span><?php endif;?></div><div class="vin-vehicle-summary"><strong><?=e((string)($primaryVehicle['salesname']??$primaryVehicle['salesversion']??'Vehicle model'))?></strong><div class="vin-quick-stats"><span><small>Type</small><?=e((string)($primaryVehicle['vehicletype']??'Not available'))?></span><span><small>Size</small><?=e((string)($primaryVehicle['vehiclesize']??'Not available'))?></span><span><small>Emission</small><?=e((string)($primaryVehicle['emissionstandard']??'Not available'))?></span></div></div></article>
            <article class="vin-details-card"><?php foreach(['Brand'=>$primaryVehicle['brand']??'','Model'=>$primaryVehicle['models']??'','Model year'=>$primaryVehicle['year']??'','Manufacturer'=>$primaryVehicle['manufacturers']??'','Series'=>$primaryVehicle['series']??'','Sales version'=>$primaryVehicle['salesversion']??'','Chassis code'=>$primaryVehicle['chassiscode']??'','Guide price'=>$primaryVehicle['guidingprice']??'','Level ID'=>$primaryVehicle['levelid']??'','VIN year'=>$additional['vinYear']??''] as $label=>$value):?><div class="vin-detail-item"><span><?=e($label)?></span><strong><?=e(trim((string)$value)?:'Not available')?></strong></div><?php endforeach;?></article>
        </div>
    </section>
    <?php if(count($results)>1):?><section class="management-panel management-panel--table vin-match-panel"><div class="management-heading management-heading--compact"><div><span class="section-kicker">Possible models</span><h2>All matching versions</h2></div></div><div class="table-wrap"><table class="data-table data-table--compact"><thead><tr><th>Model</th><th>Year</th><th>Sales Version</th><th>Type</th><th>Guide Price</th><th>Level ID</th></tr></thead><tbody><?php foreach($results as $record):?><tr><td><strong><?=e(trim((string)($record['brand']??'').' '.(string)($record['models']??'')))?></strong></td><td><?=e((string)($record['year']??'—'))?></td><td><?=e((string)($record['salesversion']??'—'))?></td><td><?=e((string)($record['vehicletype']??'—'))?></td><td><?=e((string)($record['guidingprice']??'—'))?></td><td><?=e((string)($record['levelid']??'—'))?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
    <?php endif;?>
    <section class="management-panel vin-history-panel"><div class="management-heading management-heading--compact"><div><span class="section-kicker">Saved searches</span><h2>Today's Searches</h2></div></div><div class="table-wrap vin-history-wrap"><table class="data-table data-table--compact vin-history-table"><thead><tr><th>VIN</th><th>Vehicle</th><th>Year</th><th>Saved</th><th>Action</th></tr></thead><tbody><?php foreach($savedVins as $savedVin):?><tr><td><strong><?=e((string)$savedVin['vin'])?></strong></td><td><?=e(trim((string)$savedVin['brand'].' '.(string)$savedVin['model_name'])?:'Vehicle details saved')?></td><td><?=e((string)($savedVin['model_year']?:'—'))?></td><td><?=e(date('d M Y',strtotime((string)$savedVin['saved_at'])))?></td><td><a class="secondary-button secondary-button--small" href="<?=e(app_url('vin-search-2.php?vin='.rawurlencode((string)$savedVin['vin'])))?>">Open</a></td></tr><?php endforeach;?><?php if(!$savedVins):?><tr><td colspan="5" class="empty-state">No VIN Search 2 records have been saved today.</td></tr><?php endif;?></tbody></table></div></section>
</section>
<?php require_once __DIR__ . '/../includes/footer.php';?>
