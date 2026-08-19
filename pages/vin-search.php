<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/vin.php';

require_module_access('vin_search');

$reportMode=(string)($_GET['view']??'')==='report';
if($reportMode&&!can_access_menu_item('data_reports')){header('Location: '.app_url('data-management.php'));exit;}
$pageTitle = $reportMode?'VIN Search Report':'VIN Search';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Data Management', 'url' => app_url('data-management.php')],
    ['label' => $pageTitle],
];
$internalBackUrl=requested_return_url(app_url('data-management.php'));

function ensure_vin_decode_schema(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS vin_decodes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vin CHAR(17) NOT NULL,
            make VARCHAR(160) NULL,
            model VARCHAR(160) NULL,
            model_year VARCHAR(20) NULL,
            body_style VARCHAR(120) NULL,
            engine VARCHAR(180) NULL,
            transmission VARCHAR(255) NULL,
            trim_name VARCHAR(160) NULL,
            fuel_type VARCHAR(120) NULL,
            country VARCHAR(120) NULL,
            drive_type VARCHAR(120) NULL,
            horsepower VARCHAR(60) NULL,
            power_kw VARCHAR(60) NULL,
            doors VARCHAR(60) NULL,
            seats VARCHAR(60) NULL,
            reference_price VARCHAR(100) NULL,
            model_description VARCHAR(255) NULL,
            remote_image_url VARCHAR(500) NULL,
            local_image_path VARCHAR(255) NULL,
            api_response LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_vin_decodes_vin (vin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $additionalColumns = [
        'horsepower' => 'VARCHAR(60) NULL',
        'power_kw' => 'VARCHAR(60) NULL',
        'doors' => 'VARCHAR(60) NULL',
        'seats' => 'VARCHAR(60) NULL',
        'reference_price' => 'VARCHAR(100) NULL',
    ];

    foreach ($additionalColumns as $column => $definition) {
        if (!db_column_exists('vin_decodes', $column)) {
            db()->exec("ALTER TABLE vin_decodes ADD COLUMN `{$column}` {$definition}");
        }
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS vin_decode_images (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vin_decode_id BIGINT UNSIGNED NOT NULL,
            remote_url VARCHAR(500) NULL,
            local_path VARCHAR(255) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_vin_decode_image_order (vin_decode_id, sort_order),
            CONSTRAINT fk_vin_decode_images_decode
                FOREIGN KEY (vin_decode_id) REFERENCES vin_decodes(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS vin_decode_models (
            record_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vin_decode_id BIGINT UNSIGNED NOT NULL,
            source_index INT UNSIGNED NOT NULL DEFAULT 0,
            `Id` VARCHAR(100) NULL,
            `Js_id` VARCHAR(100) NULL,
            `Model_detail_key` VARCHAR(255) NULL,
            `Gonggao_no` VARCHAR(255) NULL,
            `Group_id` VARCHAR(100) NULL,
            `UrlMake` VARCHAR(500) NULL,
            `Epc` VARCHAR(255) NULL,
            `Epc_id` VARCHAR(100) NULL,
            `Chassis_code` VARCHAR(255) NULL,
            `Model_year` VARCHAR(50) NULL,
            `Model_detail` TEXT NULL,
            `Model_detail_en` TEXT NULL,
            `Factory` VARCHAR(255) NULL,
            `Factory_en` VARCHAR(255) NULL,
            `Brand` VARCHAR(255) NULL,
            `Brand_en` VARCHAR(255) NULL,
            `Series` VARCHAR(255) NULL,
            `Series_en` VARCHAR(255) NULL,
            `Model` VARCHAR(255) NULL,
            `Model_en` VARCHAR(255) NULL,
            `Sales_version` VARCHAR(255) NULL,
            `Sales_version_en` VARCHAR(255) NULL,
            `Cc` VARCHAR(100) NULL,
            `Cc_en` VARCHAR(100) NULL,
            `Engine_no` VARCHAR(255) NULL,
            `Engine_no_en` VARCHAR(255) NULL,
            `Kw` VARCHAR(100) NULL,
            `Hp` VARCHAR(100) NULL,
            `Air_intake` VARCHAR(255) NULL,
            `Air_intake_en` VARCHAR(255) NULL,
            `Fuel_type` VARCHAR(255) NULL,
            `Fuel_type_en` VARCHAR(255) NULL,
            `Effluent_standard` VARCHAR(255) NULL,
            `Effluent_standard_en` VARCHAR(255) NULL,
            `Transmission_detail` VARCHAR(500) NULL,
            `Transmission_detail_en` VARCHAR(500) NULL,
            `Gear_num` VARCHAR(100) NULL,
            `Gear_num_en` VARCHAR(100) NULL,
            `Trans_code` VARCHAR(255) NULL,
            `Driving_mode` VARCHAR(255) NULL,
            `Driving_mode_en` VARCHAR(255) NULL,
            `Door_num` VARCHAR(100) NULL,
            `Door_num_en` VARCHAR(100) NULL,
            `Seat_num` VARCHAR(100) NULL,
            `Body_type` VARCHAR(255) NULL,
            `Body_type_en` VARCHAR(255) NULL,
            `Date_begin` VARCHAR(100) NULL,
            `Date_end` VARCHAR(100) NULL,
            `Price` VARCHAR(100) NULL,
            `Price_unit` VARCHAR(100) NULL,
            `Autohome_id` VARCHAR(100) NULL,
            `Img_adress` TEXT NULL,
            `Xs_id` VARCHAR(100) NULL,
            `Sales_name` VARCHAR(255) NULL,
            `Sales_name_en` VARCHAR(255) NULL,
            `Series_zh` VARCHAR(255) NULL,
            `Model_zh` VARCHAR(255) NULL,
            `model_gonggao_list` LONGTEXT NULL,
            `model_import_list` LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (record_id),
            UNIQUE KEY uq_vin_decode_model_index (vin_decode_id, source_index),
            CONSTRAINT fk_vin_decode_models_decode
                FOREIGN KEY (vin_decode_id) REFERENCES vin_decodes(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!db_column_exists('vin_decode_images', 'vin_decode_model_id')) {
        db()->exec(
            'ALTER TABLE vin_decode_images
             ADD COLUMN vin_decode_model_id BIGINT UNSIGNED NULL AFTER vin_decode_id,
             ADD KEY idx_vin_decode_images_model (vin_decode_model_id),
             ADD CONSTRAINT fk_vin_decode_images_model
                FOREIGN KEY (vin_decode_model_id) REFERENCES vin_decode_models(record_id)
                ON DELETE SET NULL'
        );
    }

    if (!db_column_exists('vin_decode_models', 'vin')) {
        db()->exec('ALTER TABLE vin_decode_models ADD COLUMN vin CHAR(17) NULL AFTER record_id');
        db()->exec(
            'UPDATE vin_decode_models AS vdm
             INNER JOIN vin_decodes AS vd ON vd.id = vdm.vin_decode_id
             SET vdm.vin = vd.vin
             WHERE vdm.vin IS NULL'
        );
    }

    db()->exec('ALTER TABLE vin_decode_models MODIFY vin_decode_id BIGINT UNSIGNED NULL');
    db()->exec('ALTER TABLE vin_decode_images MODIFY vin_decode_id BIGINT UNSIGNED NULL');

    $vinModelIndex = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'vin_decode_models'
           AND INDEX_NAME = 'uq_vin_decode_model_vin_index'"
    );
    $vinModelIndex->execute();
    if ((int) $vinModelIndex->fetchColumn() === 0) {
        db()->exec(
            'ALTER TABLE vin_decode_models
             ADD UNIQUE KEY uq_vin_decode_model_vin_index (vin, source_index)'
        );
    }

    $imageModelIndex = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'vin_decode_images'
           AND INDEX_NAME = 'uq_vin_decode_image_model_order'"
    );
    $imageModelIndex->execute();
    if ((int) $imageModelIndex->fetchColumn() === 0) {
        db()->exec(
            'ALTER TABLE vin_decode_images
             ADD UNIQUE KEY uq_vin_decode_image_model_order (vin_decode_model_id, sort_order)'
        );
    }

    db()->exec(
        "INSERT IGNORE INTO vin_decodes
         (vin,make,model,model_year,body_style,fuel_type,drive_type,horsepower,power_kw,doors,seats)
         SELECT vin,COALESCE(NULLIF(Brand_en,''),Factory_en),COALESCE(NULLIF(Model_en,''),Series_en),
                Model_year,Body_type_en,Fuel_type_en,Driving_mode_en,Hp,Kw,Door_num_en,Seat_num
         FROM vin_decode_models
         WHERE source_index=0 AND vin IS NOT NULL"
    );

}

function vin_model_columns(): array
{
    return [
        'Id', 'Js_id', 'Model_detail_key', 'Gonggao_no', 'Group_id', 'UrlMake',
        'Epc', 'Epc_id', 'Chassis_code', 'Model_year', 'Model_detail',
        'Model_detail_en', 'Factory', 'Factory_en', 'Brand', 'Brand_en',
        'Series', 'Series_en', 'Model', 'Model_en', 'Sales_version',
        'Sales_version_en', 'Cc', 'Cc_en', 'Engine_no', 'Engine_no_en', 'Kw',
        'Hp', 'Air_intake', 'Air_intake_en', 'Fuel_type', 'Fuel_type_en',
        'Effluent_standard', 'Effluent_standard_en', 'Transmission_detail',
        'Transmission_detail_en', 'Gear_num', 'Gear_num_en', 'Trans_code',
        'Driving_mode', 'Driving_mode_en', 'Door_num', 'Door_num_en',
        'Seat_num', 'Body_type', 'Body_type_en', 'Date_begin', 'Date_end',
        'Price', 'Price_unit', 'Autohome_id', 'Img_adress', 'Xs_id',
        'Sales_name', 'Sales_name_en', 'Series_zh', 'Model_zh',
        'model_gonggao_list', 'model_import_list',
    ];
}

function vin_database_value($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    return (string) $value;
}

function vin_value($value, string $fallback = 'Not available'): string
{
    return isset($value) && $value !== '' && $value !== null
        ? (string) $value
        : $fallback;
}

function vin_has_valid_required_check_digit(string $vin): bool
{
    $vin = strtoupper($vin);

    // VINs beginning with 1-5 are assigned to North America, where position 9
    // is a mandatory checksum digit.
    if (!preg_match('/^[1-5]/', $vin)) {
        return true;
    }

    $values = [
        'A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'F'=>6,'G'=>7,'H'=>8,
        'J'=>1,'K'=>2,'L'=>3,'M'=>4,'N'=>5,'P'=>7,'R'=>9,
        'S'=>2,'T'=>3,'U'=>4,'V'=>5,'W'=>6,'X'=>7,'Y'=>8,'Z'=>9,
    ];
    $weights = [8,7,6,5,4,3,2,10,0,9,8,7,6,5,4,3,2];
    $total = 0;

    for ($index = 0; $index < 17; $index++) {
        $character = $vin[$index];
        $value = ctype_digit($character) ? (int)$character : ($values[$character] ?? -1);
        if ($value < 0) {
            return false;
        }
        $total += $value * $weights[$index];
    }

    $remainder = $total % 11;
    $expected = $remainder === 10 ? 'X' : (string)$remainder;

    return $vin[8] === $expected;
}

function vin_remote_image_urls(array $modelData): array
{
    $addresses = preg_split(
        '/\s*,\s*/',
        (string) ($modelData['Img_adress'] ?? ''),
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if (!$addresses) {
        return [];
    }

    $urls = [];
    foreach ($addresses as $address) {
        $path = ltrim(str_replace('\\', '/', (string) $address), '/');

        if ($path === '' || !preg_match('/^[A-Za-z0-9._\/-]+$/', $path)) {
            continue;
        }

        $urls[] = 'https://images.17vin.com/img/car/all/' . strtolower($path);
    }

    return array_values(array_unique($urls));
}

function cache_vin_vehicle_image(string $imageUrl, string $vin, int $imageNumber = 1): ?string
{
    $safeVin = preg_replace('/[^A-HJ-NPR-Z0-9]/', '', strtoupper($vin));

    if ($imageUrl === '' || strlen($safeVin) !== 17 || $imageNumber < 1) {
        return null;
    }

    $relativeDirectory = 'assets/uploads/vin-vehicles';
    $absoluteDirectory = __DIR__ . '/../assets/uploads/vin-vehicles';

    if (!is_dir($absoluteDirectory)
        && !mkdir($absoluteDirectory, 0755, true)
        && !is_dir($absoluteDirectory)) {
        return null;
    }

    foreach (['jpg', 'png', 'webp'] as $extension) {
        $existing = $absoluteDirectory . '/' . $safeVin . '-' . $imageNumber . '.' . $extension;
        if (is_file($existing) && filesize($existing) > 0) {
            return $relativeDirectory . '/' . basename($existing);
        }
    }

    $temporaryPath = $absoluteDirectory . '/' . $safeVin . '-' . $imageNumber . '.download';
    $stream = fopen($temporaryPath, 'wb');
    if ($stream === false) {
        return null;
    }

    $downloadedBytes = 0;
    $maximumBytes = 8 * 1024 * 1024;
    $curl = curl_init($imageUrl);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Automasters VIN Image Cache/1.0',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (
            $stream,
            &$downloadedBytes,
            $maximumBytes
        ): int {
            $length = strlen($data);
            $downloadedBytes += $length;
            if ($downloadedBytes > $maximumBytes) {
                return 0;
            }
            $written = fwrite($stream, $data);
            return $written === false ? 0 : $written;
        },
    ]);

    $caBundle = __DIR__ . '/../config/certs/cacert.pem';
    if (is_file($caBundle)) {
        curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
    }

    $success = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    fclose($stream);

    if ($success === false
        || $httpCode !== 200
        || $downloadedBytes === 0
        || $downloadedBytes > $maximumBytes) {
        error_log(sprintf(
            'VIN image cache failed for %s: HTTP %d, bytes %d, cURL error: %s',
            $safeVin,
            $httpCode,
            $downloadedBytes,
            $curlError !== '' ? $curlError : 'none'
        ));
        @unlink($temporaryPath);
        return null;
    }

    $imageInfo = @getimagesize($temporaryPath);
    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    $imageType = $imageInfo[2] ?? null;

    if (!isset($extensions[$imageType])) {
        @unlink($temporaryPath);
        return null;
    }

    $finalPath = $absoluteDirectory . '/' . $safeVin . '-' . $imageNumber . '.' . $extensions[$imageType];
    if (!rename($temporaryPath, $finalPath)) {
        @unlink($temporaryPath);
        return null;
    }

    return $relativeDirectory . '/' . basename($finalPath);
}

function vin_saved_images(string $vin): array
{
    $statement = db()->prepare(
        'SELECT vdi.remote_url, vdi.local_path
         FROM vin_decode_images AS vdi
         INNER JOIN vin_decode_models AS vdm ON vdm.record_id = vdi.vin_decode_model_id
         WHERE vdm.vin = ?
         ORDER BY vdi.sort_order, vdi.id'
    );
    $statement->execute([$vin]);

    return $statement->fetchAll();
}

function save_vin_models(string $vin, array $modelList): array
{
    $columns = vin_model_columns();
    $quotedColumns = array_map(static fn (string $column): string => "`{$column}`", $columns);
    $assignments = array_map(
        static fn (string $column): string => "`{$column}` = VALUES(`{$column}`)",
        $columns
    );
    $sql = 'INSERT INTO vin_decode_models (
                vin, source_index, ' . implode(', ', $quotedColumns) . '
            ) VALUES (' . implode(', ', array_fill(0, count($columns) + 2, '?')) . ')
            ON DUPLICATE KEY UPDATE
                record_id = LAST_INSERT_ID(record_id),
                ' . implode(', ', $assignments);
    $statement = db()->prepare($sql);
    $modelIds = [];

    foreach (array_values($modelList) as $index => $modelData) {
        if (!is_array($modelData)) {
            continue;
        }

        $values = [$vin, $index];
        foreach ($columns as $column) {
            $values[] = vin_database_value($modelData[$column] ?? null);
        }
        $statement->execute($values);
        $modelIds[$index] = (int) db()->lastInsertId();
    }

    return $modelIds;
}

function save_vin_images(
    string $vin,
    array $imageEntries
): array
{
    $savedImages = [];
    $upsert = db()->prepare(
        'INSERT INTO vin_decode_images (
            vin_decode_model_id, remote_url, local_path, sort_order
         ) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            vin_decode_model_id = VALUES(vin_decode_model_id),
            remote_url = VALUES(remote_url),
            local_path = COALESCE(VALUES(local_path), local_path)'
    );

    $seenUrls = [];
    $index = 0;
    foreach ($imageEntries as $entry) {
        $remoteUrl = (string) ($entry['remote_url'] ?? '');
        if ($remoteUrl === '' || isset($seenUrls[$remoteUrl])) {
            continue;
        }
        $seenUrls[$remoteUrl] = true;
        $sortOrder = $index + 1;
        $localPath = cache_vin_vehicle_image((string) $remoteUrl, $vin, $sortOrder);
        $upsert->execute([
            ($entry['vin_decode_model_id'] ?? null) ?: null,
            $remoteUrl,
            $localPath,
            $sortOrder,
        ]);
        $savedImages[] = [
            'remote_url' => (string) $remoteUrl,
            'local_path' => (string) ($localPath ?? ''),
        ];
        $index = $sortOrder;
    }

    return $savedImages;
}

function fetch_vin_api(string $vin): array
{
    $urlParameters = '/?vin=' . rawurlencode($vin);
    $token = md5(md5(VIN_API_USERNAME) . md5(VIN_API_PASSWORD) . $urlParameters);
    $requestUrl = VIN_API_BASE_URL
        . $urlParameters
        . '&user=' . rawurlencode(VIN_API_USERNAME)
        . '&token=' . rawurlencode($token);
    $curl = curl_init($requestUrl);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Request error: ' . $curlError);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('The VIN service returned HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('The VIN service returned an invalid response.');
    }
    if ((int)($decoded['code'] ?? 0) !== 1) {
        $message = (string) ($decoded['msg'] ?? 'The VIN service could not complete the request.');
        $partialData = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $hasPartialData = array_filter([
            $partialData['full_vin'] ?? null,
            $partialData['model_year_from_vin'] ?? null,
            $partialData['epc'] ?? null,
            $partialData['epc_cn'] ?? null,
            $partialData['made_in_en'] ?? null,
            $partialData['made_in_cn'] ?? null,
        ], static fn($value): bool => $value !== null && trim((string)$value) !== '');
        $message = preg_replace('/please contact customer service at\s+\S+/i', 'please contact Automasters at ' . VIN_SUPPORT_PHONE . '.', $message);
        if ($hasPartialData) {
            $decoded['_partial_result'] = true;
            $decoded['_partial_message'] = $message;
            return $decoded;
        }
        throw new RuntimeException($message);
    }
    return $decoded;
}

function vin_display_record(array $record): array
{
    return [
        'vin' => vin_value($record['vin'] ?? null),
        'make' => vin_value($record['make'] ?? null),
        'model' => vin_value($record['model'] ?? null),
        'year' => vin_value($record['model_year'] ?? null),
        'body_style' => vin_value($record['body_style'] ?? null),
        'engine' => vin_value($record['engine'] ?? null),
        'transmission' => vin_value($record['transmission'] ?? null),
        'trim' => vin_value($record['trim_name'] ?? null),
        'fuel_type' => vin_value($record['fuel_type'] ?? null),
        'country' => vin_value($record['country'] ?? null),
        'drive_type' => vin_value($record['drive_type'] ?? null),
        'horsepower' => vin_value($record['horsepower'] ?? null),
        'power_kw' => vin_value($record['power_kw'] ?? null),
        'doors' => vin_value($record['doors'] ?? null),
        'seats' => vin_value($record['seats'] ?? null),
        'reference_price' => vin_value($record['reference_price'] ?? null),
        'model_description' => vin_value($record['model_description'] ?? null),
        'remote_image_url' => (string) ($record['remote_image_url'] ?? ''),
        'local_image_path' => (string) ($record['local_image_path'] ?? ''),
    ];
}

function vin_display_model_record(array $modelData, string $vin, string $country = 'Not available'): array
{
    $engine = trim(
        vin_value($modelData['Cc_en'] ?? null, '') . ' '
        . vin_value($modelData['Engine_no_en'] ?? null, '')
    );
    $remoteUrls = vin_remote_image_urls($modelData);

    return [
        'vin' => $vin,
        'make' => vin_value($modelData['Brand_en'] ?? $modelData['Factory_en'] ?? null),
        'model' => vin_value($modelData['Model_en'] ?? $modelData['Series_en'] ?? null),
        'year' => vin_value($modelData['Model_year'] ?? null),
        'body_style' => vin_value($modelData['Body_type_en'] ?? null),
        'engine' => $engine !== '' ? $engine : 'Not available',
        'transmission' => vin_value($modelData['Transmission_detail_en'] ?? null),
        'trim' => vin_value($modelData['Sales_version_en'] ?? null),
        'fuel_type' => vin_value($modelData['Fuel_type_en'] ?? null),
        'country' => vin_value($country),
        'drive_type' => vin_value($modelData['Driving_mode_en'] ?? null),
        'horsepower' => isset($modelData['Hp']) && $modelData['Hp'] !== ''
            ? $modelData['Hp'] . ' HP'
            : 'Not available',
        'power_kw' => isset($modelData['Kw']) && $modelData['Kw'] !== ''
            ? $modelData['Kw'] . ' kW'
            : 'Not available',
        'doors' => vin_value($modelData['Door_num_en'] ?? $modelData['Door_num'] ?? null),
        'seats' => vin_value($modelData['Seat_num'] ?? null),
        'reference_price' => isset($modelData['Price']) && $modelData['Price'] !== ''
            ? trim((string) ($modelData['Price_unit'] ?? '') . ' ' . number_format((float) $modelData['Price']))
            : 'Not available',
        'model_description' => vin_value($modelData['Model_detail_en'] ?? null),
        'remote_image_url' => (string) ($remoteUrls[0] ?? ''),
        'local_image_path' => '',
    ];
}

function save_vin_decode_record(string $vin, array $modelData, string $country, array $apiResponse = []): void
{
    $vehicle = vin_display_model_record($modelData, $vin, $country);
    db()->prepare(
        'INSERT INTO vin_decodes
         (vin,make,model,model_year,body_style,engine,transmission,trim_name,fuel_type,country,
          drive_type,horsepower,power_kw,doors,seats,reference_price,model_description,
          remote_image_url,api_response)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
          make=VALUES(make),model=VALUES(model),model_year=VALUES(model_year),
          body_style=VALUES(body_style),engine=VALUES(engine),transmission=VALUES(transmission),
          trim_name=VALUES(trim_name),fuel_type=VALUES(fuel_type),country=VALUES(country),
          drive_type=VALUES(drive_type),horsepower=VALUES(horsepower),power_kw=VALUES(power_kw),
          doors=VALUES(doors),seats=VALUES(seats),reference_price=VALUES(reference_price),
          model_description=VALUES(model_description),remote_image_url=VALUES(remote_image_url),
          api_response=COALESCE(VALUES(api_response),api_response)'
    )->execute([
        $vin,$vehicle['make'],$vehicle['model'],$vehicle['year'],$vehicle['body_style'],
        $vehicle['engine'],$vehicle['transmission'],$vehicle['trim'],$vehicle['fuel_type'],
        $vehicle['country'],$vehicle['drive_type'],$vehicle['horsepower'],$vehicle['power_kw'],
        $vehicle['doors'],$vehicle['seats'],$vehicle['reference_price'],
        $vehicle['model_description'],$vehicle['remote_image_url'],
        $apiResponse ? json_encode($apiResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

ensure_vin_decode_schema();

$vin = strtoupper(trim((string) ($_GET['vin'] ?? '')));
$refreshRequested = (string) ($_GET['refresh'] ?? '') === '1';
$error = '';
$vehicle = null;
$vehicleImages = [];
$partialResultMessage = '';

if ($vin !== '') {
    if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
        $error = 'Please enter a valid 17-character VIN.';
    } elseif (!vin_has_valid_required_check_digit($vin)) {
        $error = 'This VIN has an invalid check digit and was not sent to 17VIN.';
    } else {
        $statement = db()->prepare(
            'SELECT * FROM vin_decode_models WHERE vin = ? ORDER BY source_index LIMIT 1'
        );
        $statement->execute([$vin]);
        $savedRecord = $statement->fetch();
        $summaryStatement = db()->prepare('SELECT * FROM vin_decodes WHERE vin=? LIMIT 1');
        $summaryStatement->execute([$vin]);
        $savedSummary = $summaryStatement->fetch();

        try {
            if ($savedRecord && !$refreshRequested) {
                $vehicle = vin_display_model_record($savedRecord, $vin);
                $vehicleImages = vin_saved_images($vin);
            } elseif ($savedSummary && !$refreshRequested) {
                $vehicle = vin_display_record($savedSummary);
                $savedApiResponse = json_decode((string)($savedSummary['api_response'] ?? ''), true);
                if (is_array($savedApiResponse) && !empty($savedApiResponse['_partial_result'])) {
                    $partialResultMessage = (string)($savedApiResponse['_partial_message'] ?? 'Only partial vehicle information was returned.');
                }
            } else {
                $decoded = fetch_vin_api($vin);
                $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
                $modelList = is_array($data['model_list'] ?? null) ? $data['model_list'] : [];
                $modelData = is_array($modelList[0] ?? null) ? $modelList[0] : [];
                $responseVin = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', (string)($data['full_vin'] ?? '')));

                if ($responseVin !== '' && $responseVin !== $vin) {
                    throw new RuntimeException('The VIN service returned details for a different VIN. Nothing was saved.');
                }

                if (!empty($decoded['_partial_result'])) {
                    $partialResultMessage = (string)($decoded['_partial_message'] ?? 'Only partial vehicle information was returned.');
                    $modelData = [
                        'Brand_en' => $data['brand'] ?? null,
                        'Model_year' => $data['model_year_from_vin'] ?? null,
                        'Body_type_en' => $data['epc_cn'] ?? $data['epc'] ?? null,
                        'Model_detail_en' => trim(implode(' · ', array_filter([
                            (string)($data['epc_cn'] ?? $data['epc'] ?? ''),
                            isset($data['matching_mode']) ? 'Match: '.(string)$data['matching_mode'] : '',
                        ]))),
                    ];
                    $country = vin_value($data['made_in_en'] ?? $data['made_in_cn'] ?? null);
                    save_vin_decode_record($vin, $modelData, $country, $decoded);
                    $vehicle = vin_display_model_record($modelData, $vin, $country);
                } else {
                    if (!$modelData) {
                        throw new RuntimeException('No detailed vehicle model was returned for this VIN.');
                    }
                    $hasVehicleIdentity = array_filter([
                        $modelData['Brand_en'] ?? null,
                        $modelData['Brand'] ?? null,
                        $modelData['Factory_en'] ?? null,
                        $modelData['Model_en'] ?? null,
                        $modelData['Model'] ?? null,
                        $modelData['Series_en'] ?? null,
                        $modelData['Series'] ?? null,
                        $modelData['Model_detail_en'] ?? null,
                    ], static fn($value): bool => trim((string)$value) !== '');
                    if (!$hasVehicleIdentity) {
                        throw new RuntimeException('The VIN service did not return enough verified vehicle details. Nothing was saved.');
                    }

                    $savedVin = $vin;
                    db()->beginTransaction();
                    try {
                        save_vin_decode_record(
                            $savedVin,
                            $modelData,
                            vin_value($data['made_in_en'] ?? null),
                            $decoded
                        );
                        $modelIds = save_vin_models($savedVin, $modelList);
                        db()->commit();
                    } catch (Throwable $saveException) {
                        if (db()->inTransaction()) {
                            db()->rollBack();
                        }
                        throw $saveException;
                    }
                    $imageEntries = [];
                    foreach ($modelList as $modelIndex => $apiModel) {
                        if (!is_array($apiModel)) {
                            continue;
                        }
                        foreach (vin_remote_image_urls($apiModel) as $modelImageUrl) {
                            $imageEntries[] = [
                                'vin_decode_model_id' => $modelIds[$modelIndex] ?? null,
                                'remote_url' => $modelImageUrl,
                            ];
                        }
                    }
                    $vehicleImages = save_vin_images($savedVin, $imageEntries);
                    $vehicle = vin_display_model_record(
                        $modelData,
                        $savedVin,
                        vin_value($data['made_in_en'] ?? null)
                    );
                }
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$savedVins = db()->query(
    'SELECT vin,make,model,model_year,updated_at,created_at
     FROM vin_decodes'.($reportMode?'':' WHERE DATE(COALESCE(updated_at,created_at))=CURDATE()').'
     ORDER BY COALESCE(updated_at,created_at) DESC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';

if ($vehicle !== null && !$vehicleImages
    && ($vehicle['local_image_path'] !== '' || $vehicle['remote_image_url'] !== '')) {
    $vehicleImages[] = [
        'local_path' => $vehicle['local_image_path'],
        'remote_url' => $vehicle['remote_image_url'],
    ];
}
?>
<section class="vin-search-panel" aria-labelledby="vin-search-title">
    <?php if(!$reportMode):?>
    <div class="vin-search-hero">
        <div>
            <span class="section-kicker vin-search-kicker">Vehicle identification</span>
            <h1 id="vin-search-title">VIN Intelligence</h1>
            <p>Enter a 17-character VIN to retrieve and save verified vehicle information.</p>
        </div>

        <form class="vin-search-form" method="get" action="<?= e(app_url('vin-search.php')) ?>">
            <label class="vin-search-label" for="vin">Enter VIN <span>(Recommended)</span></label>
            <input
                id="vin"
                name="vin"
                type="text"
                minlength="17"
                maxlength="17"
                value="<?= e($vin) ?>"
                placeholder="Example: LLV2C3A26S0000783"
                autocomplete="off"
                required
            >
            <button type="submit">
                <span>Decode VIN</span>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            </button>
        </form>
    </div>

    <?php if ($error !== ''): ?>
        <div class="vin-alert vin-alert--error vin-search-message" role="alert">
            <span class="vin-alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <div>
                <strong>VIN could not be verified</strong>
                <p><?=e($error)?></p>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($partialResultMessage !== ''): ?>
        <div class="vin-alert vin-alert--partial vin-search-message" role="status">
            <span class="vin-alert__icon"><i class="fa-solid fa-circle-info"></i></span>
            <div>
                <strong>Limited VIN information</strong>
                <p><?=e($partialResultMessage)?></p>
                <small>Available details are shown below and saved as a partial VIN record.</small>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($vehicle !== null): ?>
        <section class="vin-result" aria-labelledby="vin-result-title">
            <div class="vin-result-heading">
                <div>
                    <span class="section-kicker">Vehicle report</span>
                    <h2 id="vin-result-title"><?= e(trim($vehicle['make'] . ' ' . $vehicle['model'])) ?></h2>
                </div>
            </div>

            <div class="vin-result-grid">
                <article class="vin-vehicle-card">
                    <div class="vin-image-wrap">
                        <?php if ($vehicle['year'] !== 'Not available'): ?>
                            <span class="vin-year-badge"><?= e($vehicle['year']) ?></span>
                        <?php endif; ?>
                        <?php if ($vehicleImages): ?>
                            <div class="vin-image-slider" data-vin-slider>
                                <div class="vin-image-slides">
                                    <?php foreach ($vehicleImages as $imageIndex => $image): ?>
                                        <?php
                                        $slideUrl = (string) ($image['local_path'] ?? '') !== ''
                                            ? asset_url((string) $image['local_path'])
                                            : (string) ($image['remote_url'] ?? '');
                                        ?>
                                        <?php if ($slideUrl !== ''): ?>
                                            <figure
                                                class="vin-image-slide<?= $imageIndex === 0 ? ' is-active' : '' ?>"
                                                data-vin-slide
                                            >
                                                <img
                                                    src="<?= e($slideUrl) ?>"
                                                    alt="<?= e(trim($vehicle['make'] . ' ' . $vehicle['model'])) ?> image <?= $imageIndex + 1 ?>"
                                                    loading="<?= $imageIndex === 0 ? 'eager' : 'lazy' ?>"
                                                >
                                            </figure>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($vehicleImages) > 1): ?>
                                    <button class="vin-slider-button vin-slider-button--previous" type="button" data-vin-previous aria-label="Previous image">
                                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                                    </button>
                                    <button class="vin-slider-button vin-slider-button--next" type="button" data-vin-next aria-label="Next image">
                                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                    </button>
                                    <span class="vin-image-counter" aria-live="polite">
                                        <span data-vin-current>1</span> / <?= count($vehicleImages) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="vin-vehicle-summary">
                        <strong><?= e($vehicle['trim']) ?></strong>
                        <div class="vin-quick-stats">
                            <span><small>Body</small><?= e($vehicle['body_style']) ?></span>
                            <span><small>Fuel</small><?= e($vehicle['fuel_type']) ?></span>
                            <span><small>Drive</small><?= e($vehicle['drive_type']) ?></span>
                        </div>
                    </div>
                </article>

                <article class="vin-details-card">
                    <?php
                    $details = [
                        'Make' => $vehicle['make'],
                        'Model' => $vehicle['model'],
                        'Model year' => $vehicle['year'],
                        'Country' => $vehicle['country'],
                        'Engine' => $vehicle['engine'],
                        'Transmission' => $vehicle['transmission'],
                        'Horsepower' => $vehicle['horsepower'],
                        'Power' => $vehicle['power_kw'],
                        'Doors' => $vehicle['doors'],
                        'Seats' => $vehicle['seats'],
                        'Reference price' => $vehicle['reference_price'],
                        'Fuel type' => $vehicle['fuel_type'],
                        'Full model description' => $vehicle['model_description'],
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <div class="vin-detail-item <?= $label === 'Full model description' ? 'vin-detail-item--wide' : '' ?>">
                            <span><?= e($label) ?></span>
                            <strong><?= e($value) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </article>
            </div>
        </section>
    <?php endif; ?>

    <?php endif;?>
    <section class="management-panel vin-history-panel">
        <div class="management-heading management-heading--compact"><div><span class="section-kicker"><?=$reportMode?'Data Management Report':'Saved searches'?></span><h2><?=$reportMode?'All VIN Searches':"Today's Searches"?></h2><?php if($reportMode):?><p><?=number_format(count($savedVins))?> saved VIN<?=count($savedVins)===1?'':'s'?></p><?php endif;?></div></div>
        <div class="table-wrap vin-history-wrap" data-vin-history-scroll><table class="data-table data-table--compact vin-history-table"><thead><tr><th>VIN</th><th>Vehicle</th><th>Year</th><th>Saved</th><th>Action</th></tr></thead><tbody>
        <?php foreach($savedVins as $savedVin): ?>
            <tr><td><strong><?=e($savedVin['vin'])?></strong></td><td><?=e(trim((string)$savedVin['make'].' '.(string)$savedVin['model'])?:'Vehicle details saved')?></td><td><?=e((string)($savedVin['model_year']?:'—'))?></td><td><?=e(date('d M Y',strtotime((string)($savedVin['updated_at']?:$savedVin['created_at']))))?></td><td><a class="secondary-button secondary-button--small" href="<?=e(app_url('vin-search.php?vin='.rawurlencode((string)$savedVin['vin'])))?>">Open</a></td></tr>
        <?php endforeach; ?>
        <?php if(!$savedVins):?><tr><td colspan="5" class="empty-state"><?=$reportMode?'No successful VIN searches have been saved yet.':'No VIN searches have been saved today.'?></td></tr><?php endif;?>
        </tbody></table></div>
    </section>
</section>
<script>
document.querySelectorAll('[data-vin-slider]').forEach((slider) => {
    const slides = Array.from(slider.querySelectorAll('[data-vin-slide]'));
    if (slides.length < 2) return;

    const counter = slider.querySelector('[data-vin-current]');
    let activeIndex = 0;
    let touchStartX = 0;

    const showSlide = (nextIndex) => {
        activeIndex = (nextIndex + slides.length) % slides.length;
        slides.forEach((slide, index) => slide.classList.toggle('is-active', index === activeIndex));
        if (counter) counter.textContent = String(activeIndex + 1);
    };

    slider.querySelector('[data-vin-previous]')?.addEventListener('click', () => showSlide(activeIndex - 1));
    slider.querySelector('[data-vin-next]')?.addEventListener('click', () => showSlide(activeIndex + 1));
    slider.addEventListener('touchstart', (event) => {
        touchStartX = event.changedTouches[0].clientX;
    }, {passive: true});
    slider.addEventListener('touchend', (event) => {
        const distance = event.changedTouches[0].clientX - touchStartX;
        if (Math.abs(distance) > 45) showSlide(activeIndex + (distance < 0 ? 1 : -1));
    }, {passive: true});
});

</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
