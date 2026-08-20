<?php
declare(strict_types=1);

ini_set('default_charset', 'utf-8');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Atlantic/Reykjavik');

require_once __DIR__ . '/database.php';

const APP_NAME = 'SPW Sales';
const COMPANY_NAME = 'Spark Plug Warehouse';
const STAFF_DEFAULT_PASSWORD = '123456';
const VENDOR_DEFAULT_PASSWORD = '123456';
const REMEMBER_LOGIN_COOKIE = 'spwsales_remember_login';
const REMEMBER_LOGIN_DAYS = 400;
const APP_IMAGE_UPLOAD_MAX_BYTES = 50 * 1024 * 1024;
const APP_IMAGE_UPLOAD_MAX_LABEL = '50MB';

ini_set('upload_max_filesize', APP_IMAGE_UPLOAD_MAX_LABEL);
ini_set('post_max_size', '220M');
ini_set('max_input_time', '180');
ini_set('max_execution_time', '180');

function ensure_remember_login_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260806_create_remember_login_tokens', function (): void {
    db()->exec("CREATE TABLE IF NOT EXISTS user_remember_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        selector VARCHAR(32) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_remember_selector (selector),
        KEY idx_user_remember_user (user_id),
        KEY idx_user_remember_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    });
    $schemaReady = true;
}

function remember_login_cookie_path(): string
{
    $path=(string)(parse_url(app_url('index.php'),PHP_URL_PATH)?:'/');
    $directory=rtrim(str_replace('\\','/',dirname($path)),'/');
    return $directory===''||$directory==='.'?'/':$directory;
}

function clear_remember_login_cookie(): void
{
    setcookie(REMEMBER_LOGIN_COOKIE,'',[
        'expires'=>time()-3600,'path'=>remember_login_cookie_path(),
        'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off',
        'httponly'=>true,'samesite'=>'Lax',
    ]);
    unset($_COOKIE[REMEMBER_LOGIN_COOKIE]);
}

function issue_remember_login_token(int $userId): void
{
    ensure_remember_login_schema();
    $selector=bin2hex(random_bytes(12));
    $token=bin2hex(random_bytes(32));
    $expires=time()+(REMEMBER_LOGIN_DAYS*86400);
    db()->prepare('DELETE FROM user_remember_tokens WHERE user_id=? OR expires_at<NOW()')->execute([$userId]);
    db()->prepare('INSERT INTO user_remember_tokens(user_id,selector,token_hash,expires_at) VALUES(?,?,?,FROM_UNIXTIME(?))')
        ->execute([$userId,$selector,hash('sha256',$token),$expires]);
    setcookie(REMEMBER_LOGIN_COOKIE,$selector.'.'.$token,[
        'expires'=>$expires,'path'=>remember_login_cookie_path(),
        'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off',
        'httponly'=>true,'samesite'=>'Lax',
    ]);
    $_COOKIE[REMEMBER_LOGIN_COOKIE]=$selector.'.'.$token;
}

function restore_remembered_login(): void
{
    if(isset($_SESSION['user_id'])||empty($_COOKIE[REMEMBER_LOGIN_COOKIE]))return;
    $parts=explode('.',(string)$_COOKIE[REMEMBER_LOGIN_COOKIE],2);
    if(count($parts)!==2||!ctype_xdigit($parts[0])||!ctype_xdigit($parts[1])){clear_remember_login_cookie();return;}
    try{
        ensure_remember_login_schema();
        $statement=db()->prepare('SELECT t.id,t.user_id,t.token_hash,u.full_name,u.email,u.role,u.profile_image,u.force_password_change FROM user_remember_tokens t INNER JOIN users u ON u.id=t.user_id WHERE t.selector=? AND t.expires_at>NOW() AND u.is_active=1 LIMIT 1');
        $statement->execute([$parts[0]]);$record=$statement->fetch();
        if(!$record||!hash_equals((string)$record['token_hash'],hash('sha256',$parts[1]))){clear_remember_login_cookie();return;}
        session_regenerate_id(true);
        $_SESSION['user_id']=(int)$record['user_id'];$_SESSION['user_name']=(string)$record['full_name'];
        $_SESSION['user_email']=(string)$record['email'];$_SESSION['user_role']=(string)$record['role'];
        $_SESSION['profile_image']=(string)($record['profile_image']??'');$_SESSION['force_password_change']=(int)($record['force_password_change']??0);
        db()->prepare('DELETE FROM user_remember_tokens WHERE id=?')->execute([(int)$record['id']]);
        issue_remember_login_token((int)$record['user_id']);
    }catch(Throwable $exception){clear_remember_login_cookie();}
}

restore_remembered_login();

function follow_up_visits_enabled(): bool
{
    return false;
}

function taxi_rank_destination_key(): string
{
    return 'taxi_rank';
}

function db_table_exists(string $tableName): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$tableName]);

    return (int) $statement->fetchColumn() > 0;
}

function db_column_exists(string $tableName, string $columnName): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([$tableName, $columnName]);

    return (int) $statement->fetchColumn() > 0;
}

function db_column_metadata(string $tableName, string $columnName): ?array
{
    $statement = db()->prepare(
        'SELECT IS_NULLABLE, COLUMN_TYPE, DATA_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $statement->execute([$tableName, $columnName]);
    $metadata = $statement->fetch();

    return $metadata ?: null;
}

function run_app_migration(string $migrationKey, callable $migration): void
{
    static $applied = null;
    static $depth = 0;
    static $tableReady = false;

    if (!$tableReady) {
        db()->exec("CREATE TABLE IF NOT EXISTS app_migrations (
            migration_key VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $tableReady = true;
    }

    if ($applied === null) {
        $applied = array_fill_keys(array_map('strval', db()->query('SELECT migration_key FROM app_migrations')->fetchAll(PDO::FETCH_COLUMN)), true);
    }
    if (isset($applied[$migrationKey])) return;

    $ownsLock = $depth === 0;
    if ($ownsLock) {
        $lock = db()->query("SELECT GET_LOCK('spw_app_migrations',15)")->fetchColumn();
        if ((int)$lock !== 1) throw new RuntimeException('The database upgrade is busy. Please try again.');
        $check = db()->prepare('SELECT COUNT(*) FROM app_migrations WHERE migration_key=?');
        $check->execute([$migrationKey]);
        if ((int)$check->fetchColumn() > 0) {
            $applied[$migrationKey] = true;
            db()->query("SELECT RELEASE_LOCK('spw_app_migrations')");
            return;
        }
    }

    $depth++;
    try {
        $migration();
        db()->prepare('INSERT IGNORE INTO app_migrations(migration_key) VALUES(?)')->execute([$migrationKey]);
        $applied[$migrationKey] = true;
    } finally {
        $depth--;
        if ($ownsLock) db()->query("SELECT RELEASE_LOCK('spw_app_migrations')");
    }
}

function ensure_location_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260805_create_and_normalize_locations', function (): void {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS locations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            region_code VARCHAR(30) NOT NULL,
            region_name VARCHAR(120) NOT NULL,
            mmda_code VARCHAR(30) NOT NULL,
            mmda_name VARCHAR(160) NOT NULL,
            town_name VARCHAR(160) NOT NULL,
            is_capital TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_locations_path (region_code,mmda_code,town_name),
            KEY idx_locations_region (region_name),
            KEY idx_locations_mmda (mmda_name),
            KEY idx_locations_town (town_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!db_column_exists('locations','entry_type')) {
        db()->exec("ALTER TABLE locations ADD COLUMN entry_type VARCHAR(12) NOT NULL DEFAULT 'town' AFTER id, ADD INDEX idx_locations_entry_type (entry_type)");
    }
    foreach (['mmda_code' => 'INT UNSIGNED NULL', 'mmda_name' => 'VARCHAR(160) NULL', 'town_name' => 'VARCHAR(160) NULL'] as $column => $definition) {
        $metadata = db_column_metadata('locations',$column);
        if ($metadata && (string)$metadata['IS_NULLABLE'] !== 'YES') db()->exec("ALTER TABLE locations MODIFY `$column` $definition");
    }
    db()->exec("UPDATE locations SET entry_type='town' WHERE entry_type NOT IN ('region','mmda','town') OR entry_type IS NULL OR entry_type=''");
    $canonical=db()->query(
        "SELECT id FROM locations WHERE UPPER(TRIM(region_name))='GREATER ACCRA'
         AND UPPER(TRIM(mmda_name))='AYAWASO WEST MUNICIPAL'
         AND LOWER(REPLACE(TRIM(town_name),' ',''))='bawulashi' LIMIT 1"
    )->fetchColumn();
    if (!$canonical) {
        db()->exec(
            "UPDATE locations SET town_name='Bawulashi'
             WHERE UPPER(TRIM(region_name))='GREATER ACCRA'
               AND UPPER(TRIM(mmda_name))='AYAWASO WEST MUNICIPAL'
               AND LOWER(REPLACE(TRIM(town_name),' ','')) IN ('bawulashie','bawaleshie') LIMIT 1"
        );
        $canonical=db()->query(
            "SELECT id FROM locations WHERE UPPER(TRIM(region_name))='GREATER ACCRA'
             AND UPPER(TRIM(mmda_name))='AYAWASO WEST MUNICIPAL'
             AND LOWER(REPLACE(TRIM(town_name),' ',''))='bawulashi' LIMIT 1"
        )->fetchColumn();
    }
    if ($canonical) {
        $aliases=db()->query(
            "SELECT id FROM locations WHERE UPPER(TRIM(region_name))='GREATER ACCRA'
             AND UPPER(TRIM(mmda_name))='AYAWASO WEST MUNICIPAL'
             AND LOWER(REPLACE(TRIM(town_name),' ','')) IN ('bawulashie','bawaleshie')"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($aliases as $alias) {
            foreach (['vendors','business_locations','vendor_customers','destination_visits'] as $table) {
                if (db_table_exists($table) && db_column_exists($table,'location_id')) {
                    db()->prepare("UPDATE `$table` SET location_id=? WHERE location_id=?")->execute([(int)$canonical,(int)$alias]);
                }
            }
            if (db_table_exists('vendor_town_assignments') && db_column_exists('vendor_town_assignments','location_id')) {
                db()->prepare('UPDATE IGNORE vendor_town_assignments SET location_id=? WHERE location_id=?')->execute([(int)$canonical,(int)$alias]);
            }
            db()->prepare('UPDATE locations SET is_active=0 WHERE id=?')->execute([(int)$alias]);
        }
    }
    foreach (['vendors','business_locations','vendor_customers','destination_visits'] as $table) {
        if (!db_table_exists($table)) continue;
        if (!db_column_exists($table, 'location_id')) {
            db()->exec("ALTER TABLE `$table` ADD COLUMN location_id INT UNSIGNED NULL");
            db()->exec("ALTER TABLE `$table` ADD INDEX `idx_{$table}_location` (location_id)");
        }
        if (db_column_exists($table,'town_id') && db_table_exists('towns')) {
            db()->exec(
                "UPDATE `$table` x
                 INNER JOIN towns t ON t.id=x.town_id
                 LEFT JOIN regions r ON r.id=t.region_id
                 LEFT JOIN districts d ON d.id=t.district_id
                 INNER JOIN locations l ON UPPER(TRIM(l.region_name))=UPPER(TRIM(r.region_name))
                   AND UPPER(TRIM(l.mmda_name))=UPPER(TRIM(d.district_name))
                   AND LOWER(TRIM(l.town_name))=LOWER(TRIM(CASE WHEN LOWER(REPLACE(t.town_name,' ','')) IN ('bawulashie','bawaleshie') THEN 'Bawulashi' ELSE t.town_name END))
                 SET x.location_id=l.id WHERE x.location_id IS NULL"
            );
        }
    }
    if (db_table_exists('vendor_town_assignments')) {
        $townMetadata=db_column_metadata('vendor_town_assignments','town_id');
        if ($townMetadata && (string)$townMetadata['IS_NULLABLE']!=='YES') {
            db()->exec('ALTER TABLE vendor_town_assignments MODIFY town_id INT UNSIGNED NULL');
        }
        if (!db_column_exists('vendor_town_assignments','location_id')) {
            db()->exec('ALTER TABLE vendor_town_assignments ADD COLUMN location_id INT UNSIGNED NULL AFTER town_id');
            db()->exec('ALTER TABLE vendor_town_assignments ADD INDEX idx_vendor_town_assignments_location (location_id)');
        }
        if (db_table_exists('towns')) {
            db()->exec(
                "UPDATE vendor_town_assignments vta
                 INNER JOIN towns t ON t.id=vta.town_id
                 LEFT JOIN regions r ON r.id=t.region_id LEFT JOIN districts d ON d.id=t.district_id
                 INNER JOIN locations l ON UPPER(TRIM(l.region_name))=UPPER(TRIM(r.region_name))
                   AND UPPER(TRIM(l.mmda_name))=UPPER(TRIM(d.district_name))
                   AND LOWER(TRIM(l.town_name))=LOWER(TRIM(CASE WHEN LOWER(REPLACE(t.town_name,' ','')) IN ('bawulashie','bawaleshie') THEN 'Bawulashi' ELSE t.town_name END))
                 SET vta.location_id=l.id WHERE vta.location_id IS NULL"
            );
        }
    }
    });
    $schemaReady = true;
}

function active_locations(): array
{
    ensure_location_schema();
    return db()->query("SELECT id,region_code,region_name,mmda_code,mmda_name,town_name,is_capital FROM locations WHERE is_active=1 AND entry_type='town' AND town_name IS NOT NULL AND town_name<>'' ORDER BY town_name,region_name,mmda_name")->fetchAll();
}

function location_by_id(int $locationId, bool $activeOnly = true): ?array
{
    ensure_location_schema();
    $statement=db()->prepare("SELECT id,region_code,region_name,mmda_code,mmda_name,town_name,is_capital,is_active FROM locations WHERE id=? AND entry_type='town'".($activeOnly?' AND is_active=1':'').' LIMIT 1');
    $statement->execute([$locationId]);
    return $statement->fetch() ?: null;
}

function ensure_places_management_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }
    run_app_migration('20260806_normalize_business_locations_and_customers', function (): void {

    if (!db_table_exists('business_locations') && db_table_exists('places')) {
        db()->exec('RENAME TABLE places TO business_locations');
    }

    foreach (['customers','place_visit_sessions','visits','customer_promo_plugs'] as $table) {
        if (db_table_exists($table) && db_column_exists($table, 'place_id') && !db_column_exists($table, 'bus_loc_id')) {
            db()->exec("ALTER TABLE `$table` CHANGE COLUMN place_id bus_loc_id BIGINT UNSIGNED NOT NULL");
        }
    }

    if (db_table_exists('business_locations')
        && db_column_exists('business_locations', 'place_ref')
        && !db_column_exists('business_locations', 'bus_loc_ref')) {
        db()->exec('ALTER TABLE business_locations CHANGE COLUMN place_ref bus_loc_ref VARCHAR(30) NOT NULL');
    }

    if (!db_table_exists('business_locations')) {
        return;
    }

    if (!db_column_exists('business_locations', 'shop_picture_2')) {
        db()->exec('ALTER TABLE business_locations ADD COLUMN shop_picture_2 VARCHAR(255) NULL AFTER shop_picture');
    }

    $nullableColumns = [
        'business_name' => 'VARCHAR(160) NULL',
        'destination_id' => 'INT UNSIGNED NULL',
        'region_id' => 'INT UNSIGNED NULL',
        'town_id' => 'INT UNSIGNED NULL',
        'area' => 'VARCHAR(160) NULL',
        'google_location' => 'VARCHAR(255) NULL',
    ];
    foreach ($nullableColumns as $column => $definition) {
        $metadata = db_column_metadata('business_locations', $column);
        if ($metadata && (string)$metadata['IS_NULLABLE'] !== 'YES') {
            db()->exec("ALTER TABLE business_locations MODIFY `$column` $definition");
        }
    }

    if (db_table_exists('customers') && !db_column_exists('customers', 'vendor_id')) {
        db()->exec('ALTER TABLE customers ADD COLUMN vendor_id INT UNSIGNED NULL AFTER bus_loc_id, ADD INDEX idx_customers_vendor (vendor_id)');
    }

    if (db_table_exists('customers') && !db_column_exists('customers', 'record_status')) {
        db()->exec("ALTER TABLE customers ADD COLUMN record_status ENUM('draft','completed') NOT NULL DEFAULT 'completed' AFTER is_active, ADD INDEX idx_customers_record_status (record_status)");
        if (db_table_exists('customer_visit_drafts') && db_table_exists('place_visit_sessions')) {
            db()->exec("INSERT IGNORE INTO customers (customer_ref,bus_loc_id,customer_name,phone,other_phone,supervisor_name,supervisor_phone,created_by_user_id,record_status,created_at)
                SELECT CONCAT('DCS-',LPAD(d.id,6,'0')),ps.bus_loc_id,COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.draft_payload,'$.customer_name')),''),'Incomplete customer'),NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.draft_payload,'$.phone')),''),NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.draft_payload,'$.other_phone')),''),NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.draft_payload,'$.supervisor_name')),''),NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.draft_payload,'$.supervisor_phone')),''),d.recorded_by_user_id,'draft',d.created_at FROM customer_visit_drafts d INNER JOIN place_visit_sessions ps ON ps.id=d.place_session_id WHERE d.customer_id IS NULL");
            db()->exec("UPDATE customer_visit_drafts d INNER JOIN customers c ON c.customer_ref=CONCAT('DCS-',LPAD(d.id,6,'0')) SET d.customer_id=c.id WHERE d.customer_id IS NULL");
        }
    }

    // Keep legacy registrations readable until they are linked to the normalized
    // customer model. The source row remains as an audit record after linking.
    foreach (['destination_visits','vendor_customers'] as $legacyTable) {
        if (!db_table_exists($legacyTable)) continue;
        if (!db_column_exists($legacyTable, 'normalized_customer_id')) {
            db()->exec("ALTER TABLE `$legacyTable` ADD COLUMN normalized_customer_id BIGINT UNSIGNED NULL, ADD INDEX `idx_{$legacyTable}_normalized_customer` (normalized_customer_id)");
        }
        if (!db_column_exists($legacyTable, 'normalized_visit_id')) {
            db()->exec("ALTER TABLE `$legacyTable` ADD COLUMN normalized_visit_id BIGINT UNSIGNED NULL, ADD INDEX `idx_{$legacyTable}_normalized_visit` (normalized_visit_id)");
        }
        if (!db_column_exists($legacyTable, 'migrated_at')) {
            db()->exec("ALTER TABLE `$legacyTable` ADD COLUMN migrated_at DATETIME NULL");
        }
    }

    if (!db_column_exists('business_locations', 'is_legacy_placeholder')) {
        db()->exec(
            'ALTER TABLE business_locations
             ADD COLUMN is_legacy_placeholder TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
             ADD INDEX idx_places_legacy_placeholder (is_legacy_placeholder)'
        );

        // Classify imported one-place-per-customer records once. A confirmed legacy
        // location must never be changed back into a placeholder on later requests.
        if (db_table_exists('destination_visits')) {
            db()->exec(
                "UPDATE business_locations p
                 INNER JOIN (
                     SELECT id,ROW_NUMBER() OVER (ORDER BY id) AS legacy_place_sequence,created_at
                     FROM destination_visits
                 ) legacy
                    ON p.bus_loc_ref=CONCAT('LOC-',LPAD(legacy.legacy_place_sequence,3,'0'))
                   AND p.created_at=legacy.created_at
                 SET p.is_legacy_placeholder=1
                 WHERE p.is_legacy_placeholder=0"
            );
        }
    }
    });
    run_app_migration('20260811_add_second_location_picture', function (): void {
        if (db_table_exists('business_locations') && !db_column_exists('business_locations', 'shop_picture_2')) {
            db()->exec('ALTER TABLE business_locations ADD COLUMN shop_picture_2 VARCHAR(255) NULL AFTER shop_picture');
        }
    });
    $schemaReady = true;
}

function ensure_addendum_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady || !db_table_exists('place_visit_sessions')) return;
    run_app_migration('20260805_add_addendum_sessions', function (): void {

    $sessionTrip = db_column_metadata('place_visit_sessions', 'sales_trip_id');
    if ($sessionTrip && (string)$sessionTrip['IS_NULLABLE'] !== 'YES') {
        db()->exec('ALTER TABLE place_visit_sessions MODIFY sales_trip_id INT UNSIGNED NULL');
    }
    if (!db_column_exists('place_visit_sessions', 'session_type')) {
        db()->exec("ALTER TABLE place_visit_sessions ADD COLUMN session_type ENUM('trip','addendum') NOT NULL DEFAULT 'trip' AFTER bus_loc_id, ADD INDEX idx_place_sessions_type_user_status (session_type,recorded_by_user_id,status)");
    }
    if (!db_column_exists('place_visit_sessions', 'activity_date')) {
        db()->exec('ALTER TABLE place_visit_sessions ADD COLUMN activity_date DATE NULL AFTER session_type');
        db()->exec('UPDATE place_visit_sessions ps INNER JOIN sales_trips st ON st.id=ps.sales_trip_id SET ps.activity_date=st.trip_date WHERE ps.activity_date IS NULL');
    }
    if (db_table_exists('visits')) {
        $visitTrip = db_column_metadata('visits', 'sales_trip_id');
        if ($visitTrip && (string)$visitTrip['IS_NULLABLE'] !== 'YES') {
            db()->exec('ALTER TABLE visits MODIFY sales_trip_id INT UNSIGNED NULL');
        }
    }
    });
    $schemaReady = true;
}

function ensure_job_type_schema(): void{
    static $schemaReady = false;
    if ($schemaReady || !db_table_exists('customers')) {
        return;
    }
    run_app_migration('20260805_create_job_types', function (): void {

    db()->exec(
        "CREATE TABLE IF NOT EXISTS job_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_type_name VARCHAR(120) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!db_column_exists('customers', 'job_type_id')) {
        db()->exec('ALTER TABLE customers ADD COLUMN job_type_id INT UNSIGNED NULL AFTER job_type');
        db()->exec('ALTER TABLE customers ADD INDEX idx_customers_job_type_id (job_type_id)');
    }

    db()->exec("INSERT IGNORE INTO job_types (job_type_name,is_active) VALUES ('Apprentice',1)");

    if (!db_column_exists('customers', 'master_customer_id')) {
        db()->exec('ALTER TABLE customers ADD COLUMN master_customer_id BIGINT UNSIGNED NULL AFTER job_type_id, ADD INDEX idx_customers_master (master_customer_id)');
    }

    db()->exec(
        "INSERT IGNORE INTO job_types (job_type_name)
         SELECT DISTINCT TRIM(job_type)
         FROM customers
         WHERE job_type IS NOT NULL AND TRIM(job_type) <> ''"
    );
    db()->exec(
        "UPDATE customers c
         INNER JOIN job_types jt ON jt.job_type_name = TRIM(c.job_type)
         SET c.job_type_id = jt.id
         WHERE c.job_type_id IS NULL AND c.job_type IS NOT NULL AND TRIM(c.job_type) <> ''"
    );

    $constraint = db()->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA=DATABASE()
           AND TABLE_NAME='customers'
           AND CONSTRAINT_NAME='fk_customers_job_type'"
    );
    if ((int)$constraint->fetchColumn() === 0) {
        db()->exec(
            'ALTER TABLE customers
             ADD CONSTRAINT fk_customers_job_type
             FOREIGN KEY (job_type_id) REFERENCES job_types(id)'
        );
    }
    });

    run_app_migration('20260812_add_customer_apprentices', function (): void {
        db()->exec("INSERT IGNORE INTO job_types (job_type_name,is_active) VALUES ('Apprentice',1)");
        if (!db_column_exists('customers', 'master_customer_id')) {
            db()->exec('ALTER TABLE customers ADD COLUMN master_customer_id BIGINT UNSIGNED NULL AFTER job_type_id, ADD INDEX idx_customers_master (master_customer_id)');
        }
    });

    $schemaReady = true;
}

function customer_master_id_for_job(int $jobTypeId, int $requestedMasterId, int $excludeCustomerId = 0): ?int
{
    if ($jobTypeId <= 0) return null;
    $job = db()->prepare('SELECT job_type_name FROM job_types WHERE id=? AND is_active=1');
    $job->execute([$jobTypeId]);
    if (strcasecmp((string)$job->fetchColumn(), 'Apprentice') !== 0) return null;
    if ($requestedMasterId <= 0 || $requestedMasterId === $excludeCustomerId) throw new RuntimeException('Select a valid master for the apprentice.');
    $master = db()->prepare("SELECT COUNT(*) FROM customers c LEFT JOIN job_types jt ON jt.id=c.job_type_id WHERE c.id=? AND c.is_active=1 AND c.record_status='completed' AND COALESCE(LOWER(jt.job_type_name),'')<>'apprentice'");
    $master->execute([$requestedMasterId]);
    if (!(int)$master->fetchColumn()) throw new RuntimeException('Select a customer who is not an apprentice as the master.');
    return $requestedMasterId;
}

function ensure_pos_referral_source_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260807_create_pos_referral_sources', function (): void {
    if (db_table_exists('sor_referral_sources') && !db_table_exists('pos_referral_sources')) {
        db()->exec('RENAME TABLE sor_referral_sources TO pos_referral_sources');
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS pos_referral_sources (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            source_name VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pos_referral_source_name (source_name),
            KEY idx_pos_referral_source_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    });
    $schemaReady = true;
}

function ensure_pos_plug_commission_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260809_create_pos_plug_discounts', function (): void {
    db()->exec("CREATE TABLE IF NOT EXISTS plug_commissions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        spark_plug_id BIGINT UNSIGNED NOT NULL,
        commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_plug_commissions_plug (spark_plug_id),
        KEY idx_plug_commissions_active (is_active),
        CONSTRAINT fk_plug_commissions_plug FOREIGN KEY (spark_plug_id) REFERENCES spark_plugs(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("INSERT IGNORE INTO plug_commissions(spark_plug_id,commission_percentage,is_active)
        SELECT id,20.00,1 FROM spark_plugs WHERE is_active=1");
    });
    run_app_migration('20260820_rename_plug_discounts_to_commissions', function (): void {
        $constraintExists=db()->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'");
        if (db_table_exists('pos_transfer_items')) {
            $constraintExists->execute(['pos_transfer_items','fk_pos_transfer_items_discount']);
            if ((int)$constraintExists->fetchColumn()) db()->exec('ALTER TABLE pos_transfer_items DROP FOREIGN KEY fk_pos_transfer_items_discount');
        }
        if (db_table_exists('plug_discounts') && !db_table_exists('plug_commissions')) db()->exec('RENAME TABLE plug_discounts TO plug_commissions');
        if (db_table_exists('plug_commissions') && db_column_exists('plug_commissions','discount_percentage')) db()->exec('ALTER TABLE plug_commissions CHANGE discount_percentage commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 20.00');
        if (db_table_exists('pos_transfers') && db_column_exists('pos_transfers','discount_amount')) db()->exec('ALTER TABLE pos_transfers CHANGE discount_amount commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00');
        if (db_table_exists('pos_transfer_items')) {
            if (db_column_exists('pos_transfer_items','discount_id')) db()->exec('ALTER TABLE pos_transfer_items CHANGE discount_id commission_id BIGINT UNSIGNED NULL');
            if (db_column_exists('pos_transfer_items','discount_percentage')) db()->exec('ALTER TABLE pos_transfer_items CHANGE discount_percentage commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00');
            if (db_column_exists('pos_transfer_items','discount_amount')) db()->exec('ALTER TABLE pos_transfer_items CHANGE discount_amount commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00');
            $constraintExists->execute(['pos_transfer_items','fk_pos_transfer_items_commission']);
            if (!(int)$constraintExists->fetchColumn()) db()->exec('ALTER TABLE pos_transfer_items ADD CONSTRAINT fk_pos_transfer_items_commission FOREIGN KEY (commission_id) REFERENCES plug_commissions(id) ON DELETE SET NULL ON UPDATE CASCADE');
        }
    });
    $schemaReady = true;
}

function ensure_pos_transfer_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    ensure_pos_sales_schema();
    run_app_migration('20260809_create_pos_transfers', function (): void {
    db()->exec("CREATE TABLE IF NOT EXISTS pos_transfers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        transfer_ref VARCHAR(30) NOT NULL,
        transfer_date DATE NOT NULL,
        vendor_id INT UNSIGNED NOT NULL,
        vendor_name VARCHAR(160) NOT NULL,
        vendor_phone VARCHAR(40) NULL,
        vendor_email VARCHAR(160) NULL,
        vendor_location VARCHAR(255) NULL,
        gross_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft','dispatched','received','disputed','rejected','cancelled') NOT NULL DEFAULT 'dispatched',
        note TEXT NULL,
        recorded_by_user_id INT UNSIGNED NULL,
        responded_by_user_id INT UNSIGNED NULL,
        response_note TEXT NULL,
        responded_at DATETIME NULL,
        dispatched_at DATETIME NULL,
        received_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pos_transfers_ref (transfer_ref),
        KEY idx_pos_transfers_date (transfer_date),
        KEY idx_pos_transfers_vendor (vendor_id),
        KEY idx_pos_transfers_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS pos_transfer_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        transfer_id BIGINT UNSIGNED NOT NULL,
        spark_plug_id BIGINT UNSIGNED NOT NULL,
        price_history_id BIGINT UNSIGNED NULL,
        commission_id BIGINT UNSIGNED NULL,
        brand_name VARCHAR(64) NOT NULL,
        plug_number VARCHAR(80) NOT NULL,
        box_quantity INT UNSIGNED NOT NULL,
        pieces_per_box INT UNSIGNED NOT NULL DEFAULT 4,
        total_pieces INT UNSIGNED NOT NULL,
        unit_price DECIMAL(14,2) NOT NULL,
        gross_amount DECIMAL(14,2) NOT NULL,
        commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(14,2) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_pos_transfer_items_transfer (transfer_id),
        KEY idx_pos_transfer_items_plug (spark_plug_id),
        CONSTRAINT fk_pos_transfer_items_transfer FOREIGN KEY (transfer_id) REFERENCES pos_transfers(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_pos_transfer_items_plug FOREIGN KEY (spark_plug_id) REFERENCES spark_plugs(id) ON UPDATE CASCADE,
        CONSTRAINT fk_pos_transfer_items_price FOREIGN KEY (price_history_id) REFERENCES plug_price_history(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_pos_transfer_items_commission FOREIGN KEY (commission_id) REFERENCES plug_commissions(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    });
    run_app_migration('20260810_add_pos_transfer_acceptance', function (): void {
    db()->exec("ALTER TABLE pos_transfers MODIFY COLUMN status ENUM('draft','dispatched','received','disputed','rejected','cancelled') NOT NULL DEFAULT 'dispatched'");
    if (!db_column_exists('pos_transfers', 'responded_by_user_id')) db()->exec('ALTER TABLE pos_transfers ADD COLUMN responded_by_user_id INT UNSIGNED NULL AFTER recorded_by_user_id');
    if (!db_column_exists('pos_transfers', 'response_note')) db()->exec('ALTER TABLE pos_transfers ADD COLUMN response_note TEXT NULL AFTER responded_by_user_id');
    if (!db_column_exists('pos_transfers', 'responded_at')) db()->exec('ALTER TABLE pos_transfers ADD COLUMN responded_at DATETIME NULL AFTER response_note');
    if (!db_column_exists('pos_transfer_items', 'received_box_quantity')) db()->exec('ALTER TABLE pos_transfer_items ADD COLUMN received_box_quantity INT UNSIGNED NULL AFTER box_quantity');
    if (!db_column_exists('pos_transfer_items', 'discrepancy_note')) db()->exec('ALTER TABLE pos_transfer_items ADD COLUMN discrepancy_note VARCHAR(255) NULL AFTER received_box_quantity');
    });
    $schemaReady = true;
}

function ensure_customer_status_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady || !db_table_exists('customers')) return;
    run_app_migration('20260808_add_customer_record_and_temp_status', function (): void {
    if (!db_column_exists('customers', 'record_status')) {
        db()->exec("ALTER TABLE customers ADD COLUMN record_status ENUM('draft','completed') NOT NULL DEFAULT 'completed' AFTER is_active");
    }
    if (!db_column_exists('customers', 'customer_status')) {
        db()->exec("ALTER TABLE customers ADD COLUMN customer_status ENUM('temporary','registered') NOT NULL DEFAULT 'registered' AFTER record_status");
        db()->exec('ALTER TABLE customers ADD INDEX idx_customers_customer_status (customer_status)');
    }
    });
    $schemaReady = true;
}

function ensure_customer_promo_plug_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260808_convert_customer_sales_to_promo_plugs', function (): void {

    if (db_table_exists('customer_sales') && !db_table_exists('customer_promo_plugs')) {
        $constraint = db()->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pos_sales' AND CONSTRAINT_NAME='fk_sales_legacy_customer_sale'");
        if ((int)$constraint->fetchColumn() > 0) db()->exec('ALTER TABLE pos_sales DROP FOREIGN KEY fk_sales_legacy_customer_sale');
        if (db_column_exists('pos_sales','legacy_customer_sale_id')) {
            $index = db()->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_sales' AND INDEX_NAME='uq_sales_legacy_customer_sale'");
            if ((int)$index->fetchColumn() > 0) db()->exec('ALTER TABLE pos_sales DROP INDEX uq_sales_legacy_customer_sale');
            db()->exec('ALTER TABLE pos_sales DROP COLUMN legacy_customer_sale_id');
        }
        db()->exec('RENAME TABLE customer_sales TO customer_promo_plugs');
    }

    db()->exec("CREATE TABLE IF NOT EXISTS customer_promo_plugs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        visit_id BIGINT UNSIGNED NOT NULL,
        customer_id BIGINT UNSIGNED NOT NULL,
        bus_loc_id BIGINT UNSIGNED NOT NULL,
        promo_plug VARCHAR(160) NOT NULL,
        recorded_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_customer_promo_visit (visit_id),
        KEY idx_customer_promo_customer (customer_id),
        KEY idx_customer_promo_place (bus_loc_id),
        CONSTRAINT fk_customer_promo_visit FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_customer_promo_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON UPDATE CASCADE,
        CONSTRAINT fk_customer_promo_place FOREIGN KEY (bus_loc_id) REFERENCES business_locations(id) ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach (['sale_record_ref','sales_ref','sale_confirmed','car_picture'] as $column) {
        if (db_column_exists('customer_promo_plugs',$column)) db()->exec('ALTER TABLE customer_promo_plugs DROP COLUMN '.$column);
    }
    db()->exec("DELETE FROM customer_promo_plugs WHERE promo_plug IS NULL OR TRIM(promo_plug)=''");
    db()->exec('ALTER TABLE customer_promo_plugs MODIFY promo_plug VARCHAR(160) NOT NULL');
    db()->exec("CREATE OR REPLACE VIEW customer_sales AS
        SELECT id,CONCAT('PROMO-',id) AS sale_record_ref,visit_id,customer_id,bus_loc_id,
               NULL AS sales_ref,promo_plug,0 AS sale_confirmed,NULL AS car_picture,
               recorded_by_user_id,created_at,updated_at
        FROM customer_promo_plugs");
    });
    $schemaReady = true;
}

function ensure_pos_sales_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    ensure_pos_plug_commission_schema();
    run_app_migration('20260808_create_and_normalize_pos_sales', function (): void {
    ensure_pos_referral_source_schema();
    ensure_customer_status_schema();
    if (db_table_exists('sales') && !db_table_exists('pos_sales')) {
        db()->exec('RENAME TABLE sales TO pos_sales');
    }
    if (db_table_exists('sale_items') && !db_table_exists('pos_sale_items')) {
        db()->exec('RENAME TABLE sale_items TO pos_sale_items');
    }
    if (db_table_exists('sale_vins') && !db_table_exists('pos_sale_vins')) {
        db()->exec('RENAME TABLE sale_vins TO pos_sale_vins');
    }
    if (db_table_exists('sor_temp_customers')) db()->exec('DROP TABLE sor_temp_customers');
    if (db_table_exists('pos_temp_customers')) db()->exec('DROP TABLE pos_temp_customers');
    if (db_table_exists('pos_sales') && db_column_exists('pos_sales','sale_source')) {
        db()->exec("ALTER TABLE pos_sales MODIFY sale_source ENUM('sor','pos','visit','vendor') NOT NULL DEFAULT 'pos'");
        db()->exec("UPDATE pos_sales SET sale_source='pos' WHERE sale_source='sor'");
        db()->exec("ALTER TABLE pos_sales MODIFY sale_source ENUM('pos','visit','vendor') NOT NULL DEFAULT 'pos'");
        db()->exec("UPDATE pos_sales old_sale LEFT JOIN pos_sales pos_sale ON pos_sale.sale_ref=CONCAT('POS-',SUBSTRING(old_sale.sale_ref,5)) SET old_sale.sale_ref=CONCAT('POS-',SUBSTRING(old_sale.sale_ref,5)) WHERE old_sale.sale_ref LIKE 'SOR-%' AND pos_sale.id IS NULL");
    }
    ensure_customer_promo_plug_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS spark_plugs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        brand_name VARCHAR(64) NOT NULL,
        plug_number VARCHAR(80) NOT NULL,
        note TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_spark_plugs_brand_number (brand_name,plug_number),
        KEY idx_spark_plugs_number (plug_number),
        KEY idx_spark_plugs_brand (brand_name),
        KEY idx_spark_plugs_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!db_column_exists('spark_plugs','brand_name')) {
        db()->exec('ALTER TABLE spark_plugs ADD COLUMN brand_name VARCHAR(64) NULL AFTER id');
        if (db_table_exists('plug_brands') && db_column_exists('spark_plugs','brand_id')) {
            db()->exec('UPDATE spark_plugs sp INNER JOIN plug_brands b ON b.id=sp.brand_id SET sp.brand_name=b.brand_name');
        }
    }
    $foreignKeyCheck=db()->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $foreignKeyCheck->execute(['spark_plugs','fk_spark_plugs_brand']);
    if ((int)$foreignKeyCheck->fetchColumn()) db()->exec('ALTER TABLE spark_plugs DROP FOREIGN KEY fk_spark_plugs_brand');
    $indexCheck=db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $indexCheck->execute(['spark_plugs','uq_spark_plugs_brand_number']);
    if ((int)$indexCheck->fetchColumn()) db()->exec('ALTER TABLE spark_plugs DROP INDEX uq_spark_plugs_brand_number');
    if (db_column_exists('spark_plugs','brand_id')) db()->exec('ALTER TABLE spark_plugs DROP COLUMN brand_id');
    db()->exec("ALTER TABLE spark_plugs MODIFY brand_name VARCHAR(64) NOT NULL");
    $indexCheck->execute(['spark_plugs','uq_spark_plugs_brand_number']);
    if (!(int)$indexCheck->fetchColumn()) db()->exec('ALTER TABLE spark_plugs ADD UNIQUE KEY uq_spark_plugs_brand_number (brand_name,plug_number)');
    $indexCheck->execute(['spark_plugs','idx_spark_plugs_brand']);
    if (!(int)$indexCheck->fetchColumn()) db()->exec('ALTER TABLE spark_plugs ADD KEY idx_spark_plugs_brand (brand_name)');
    ensure_pos_plug_commission_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS plug_price_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        spark_plug_id BIGINT UNSIGNED NOT NULL,
        price DECIMAL(14,2) NOT NULL,
        effective_at DATETIME NOT NULL,
        note TEXT NULL,
        recorded_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_plug_prices_plug_effective (spark_plug_id,effective_at,id),
        CONSTRAINT fk_plug_prices_plug FOREIGN KEY (spark_plug_id) REFERENCES spark_plugs(id) ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS pos_sales (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_ref VARCHAR(30) NOT NULL,
        sale_date DATE NOT NULL,
        sale_source ENUM('sor','pos','visit','vendor') NOT NULL DEFAULT 'pos',
        vendor_name VARCHAR(160) NULL,
        source_record_id BIGINT UNSIGNED NULL,
        customer_mode ENUM('registered','temp') NOT NULL DEFAULT 'registered',
        customer_id BIGINT UNSIGNED NULL,
        customer_name VARCHAR(160) NOT NULL,
        customer_phone VARCHAR(40) NULL,
        job_type_id INT UNSIGNED NULL,
        customer_type VARCHAR(120) NULL,
        location_id INT UNSIGNED NULL,
        area VARCHAR(160) NULL,
        referral_source_id INT UNSIGNED NULL,
        referral_source VARCHAR(120) NULL,
        comment TEXT NULL,
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        sales_type ENUM('direct','indirect') NOT NULL DEFAULT 'direct',
        recipient_vendor_id BIGINT UNSIGNED NULL,
        recipient_vendor_name VARCHAR(160) NULL,
        delivery_charge DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        net_sales DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        commission_amount DECIMAL(14,2) NULL,
        amount_less_commission DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft','completed','cancelled') NOT NULL DEFAULT 'completed',
        recorded_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sales_ref (sale_ref),
        KEY idx_sales_date (sale_date),
        KEY idx_sales_customer (customer_id),
        KEY idx_sales_location (location_id),
        KEY idx_sales_source (sale_source,source_record_id),
        KEY idx_sales_status (status),
        CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_sales_job_type FOREIGN KEY (job_type_id) REFERENCES job_types(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_sales_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_sales_referral FOREIGN KEY (referral_source_id) REFERENCES pos_referral_sources(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS pos_sale_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_id BIGINT UNSIGNED NOT NULL,
        spark_plug_id BIGINT UNSIGNED NOT NULL,
        price_history_id BIGINT UNSIGNED NULL,
        brand_name VARCHAR(64) NOT NULL,
        plug_number VARCHAR(80) NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        unit_price DECIMAL(14,2) NOT NULL,
        total_amount DECIMAL(14,2) NOT NULL,
        commission_percentage DECIMAL(5,2) NULL,
        commission_amount DECIMAL(14,2) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_pos_sale_items_sale (sale_id),
        KEY idx_pos_sale_items_plug (spark_plug_id),
        CONSTRAINT fk_pos_sale_items_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_pos_sale_items_plug FOREIGN KEY (spark_plug_id) REFERENCES spark_plugs(id) ON UPDATE CASCADE,
        CONSTRAINT fk_pos_sale_items_price FOREIGN KEY (price_history_id) REFERENCES plug_price_history(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['fk_sale_items_brand','fk_pos_sale_items_brand'] as $brandForeignKey) {
        $foreignKeyCheck->execute(['pos_sale_items',$brandForeignKey]);
        if ((int)$foreignKeyCheck->fetchColumn()) db()->exec('ALTER TABLE pos_sale_items DROP FOREIGN KEY '.$brandForeignKey);
    }
    if (db_column_exists('pos_sale_items','brand_id')) db()->exec('ALTER TABLE pos_sale_items DROP COLUMN brand_id');
    db()->exec("CREATE TABLE IF NOT EXISTS pos_sale_vins (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_item_id BIGINT UNSIGNED NOT NULL,
        vin_number VARCHAR(80) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pos_sale_vins_number (vin_number),
        KEY idx_pos_sale_vins_item (sale_item_id),
        CONSTRAINT fk_pos_sale_vins_item FOREIGN KEY (sale_item_id) REFERENCES pos_sale_items(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (db_table_exists('plug_brands')) db()->exec('DROP TABLE plug_brands');
    });
    run_app_migration('20260811_enable_sor_pos_sales', function (): void {
        if (db_table_exists('pos_sales') && db_column_exists('pos_sales','sale_source')) {
            db()->exec("ALTER TABLE pos_sales MODIFY sale_source ENUM('sor','pos','visit','vendor') NOT NULL DEFAULT 'pos'");
        }
    });
    run_app_migration('20260811_add_pos_sale_commission_fields', function (): void {
        if (!db_column_exists('pos_sales','sales_type')) db()->exec("ALTER TABLE pos_sales ADD COLUMN sales_type ENUM('direct','indirect') NOT NULL DEFAULT 'direct' AFTER subtotal");
        if (!db_column_exists('pos_sales','recipient_vendor_id')) db()->exec('ALTER TABLE pos_sales ADD COLUMN recipient_vendor_id BIGINT UNSIGNED NULL AFTER sales_type');
        if (!db_column_exists('pos_sales','recipient_vendor_name')) db()->exec('ALTER TABLE pos_sales ADD COLUMN recipient_vendor_name VARCHAR(160) NULL AFTER recipient_vendor_id');
        if (!db_column_exists('pos_sales','delivery_charge')) db()->exec('ALTER TABLE pos_sales ADD COLUMN delivery_charge DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER recipient_vendor_name');
        if (!db_column_exists('pos_sales','net_sales')) db()->exec('ALTER TABLE pos_sales ADD COLUMN net_sales DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER delivery_charge');
        if (!db_column_exists('pos_sales','commission_amount')) db()->exec('ALTER TABLE pos_sales ADD COLUMN commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER net_sales');
        if (!db_column_exists('pos_sales','amount_less_commission')) db()->exec('ALTER TABLE pos_sales ADD COLUMN amount_less_commission DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER commission_amount');
        if (!db_column_exists('pos_sale_items','commission_percentage')) db()->exec('ALTER TABLE pos_sale_items ADD COLUMN commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER total_amount');
        if (!db_column_exists('pos_sale_items','commission_amount')) db()->exec('ALTER TABLE pos_sale_items ADD COLUMN commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER commission_percentage');
    });
    run_app_migration('20260812_make_direct_sale_commission_nullable', function (): void {
        if (db_column_exists('pos_sales','commission_amount')) db()->exec('ALTER TABLE pos_sales MODIFY commission_amount DECIMAL(14,2) NULL DEFAULT NULL');
        if (db_column_exists('pos_sale_items','commission_percentage')) db()->exec('ALTER TABLE pos_sale_items MODIFY commission_percentage DECIMAL(5,2) NULL DEFAULT NULL');
        if (db_column_exists('pos_sale_items','commission_amount')) db()->exec('ALTER TABLE pos_sale_items MODIFY commission_amount DECIMAL(14,2) NULL DEFAULT NULL');
        db()->exec("UPDATE pos_sales SET commission_amount=NULL,amount_less_commission=net_sales WHERE sale_source='pos' AND sales_type='direct'");
        db()->exec("UPDATE pos_sale_items si INNER JOIN pos_sales s ON s.id=si.sale_id SET si.commission_percentage=NULL,si.commission_amount=NULL WHERE s.sale_source='pos' AND s.sales_type='direct'");
    });
    run_app_migration('20260820_add_customer_price_discounts', function (): void {
        if (!db_column_exists('pos_sales','customer_discount_amount')) db()->exec('ALTER TABLE pos_sales ADD COLUMN customer_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER subtotal');
        if (!db_column_exists('pos_sale_items','list_unit_price')) db()->exec('ALTER TABLE pos_sale_items ADD COLUMN list_unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER unit_price');
        if (!db_column_exists('pos_sale_items','customer_discount_amount')) db()->exec('ALTER TABLE pos_sale_items ADD COLUMN customer_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER total_amount');
        db()->exec('UPDATE pos_sale_items SET list_unit_price=unit_price WHERE list_unit_price=0');
    });
    $schemaReady = true;
}

function customer_has_completed_pos_sale(int $customerId): bool
{
    static $resultCache = [];
    if ($customerId <= 0 || !db_table_exists('pos_sales')) return false;
    if (!array_key_exists($customerId, $resultCache)) {
        $statement = db()->prepare("SELECT COUNT(*) FROM pos_sales WHERE customer_id=? AND status='completed'");
        $statement->execute([$customerId]);
        $resultCache[$customerId] = (int)$statement->fetchColumn() > 0;
    }
    return $resultCache[$customerId];
}

function orient_uploaded_jpeg($image, string $sourcePath, string $mimeType)
{
    if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $image;
    }

    $metadata = @exif_read_data($sourcePath, 'IFD0', true);
    $metadata = is_array($metadata) ? $metadata : [];
    $orientation = (int) ($metadata['IFD0']['Orientation'] ?? $metadata['Orientation'] ?? 1);

    if ($orientation === 2 && function_exists('imageflip')) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
    } elseif ($orientation === 3) {
        $rotated = imagerotate($image, 180, 0);
    } elseif ($orientation === 4 && function_exists('imageflip')) {
        imageflip($image, IMG_FLIP_VERTICAL);
    } elseif ($orientation === 5) {
        if (function_exists('imageflip')) imageflip($image, IMG_FLIP_HORIZONTAL);
        $rotated = imagerotate($image, 90, 0);
    } elseif ($orientation === 6) {
        $rotated = imagerotate($image, -90, 0);
    } elseif ($orientation === 7) {
        if (function_exists('imageflip')) imageflip($image, IMG_FLIP_HORIZONTAL);
        $rotated = imagerotate($image, -90, 0);
    } elseif ($orientation === 8) {
        $rotated = imagerotate($image, 90, 0);
    }

    if (isset($rotated) && $rotated !== false) {
        imagedestroy($image);
        return $rotated;
    }

    return $image;
}

function ensure_sales_trip_assignment_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }
    run_app_migration('20260805_create_sales_trip_assignments', function (): void {

    db()->exec(
        'CREATE TABLE IF NOT EXISTS sales_trip_vendor_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sales_trip_id INT UNSIGNED NOT NULL,
            vendor_id INT UNSIGNED NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sales_trip_vendor_assignments_trip_vendor (sales_trip_id,vendor_id),
            KEY idx_sales_trip_vendor_assignments_vendor (vendor_id)
        )'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS sales_trip_staff_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sales_trip_id INT UNSIGNED NOT NULL,
            staff_id INT UNSIGNED NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sales_trip_staff_assignments_trip_staff (sales_trip_id,staff_id),
            KEY idx_sales_trip_staff_assignments_staff (staff_id)
        )'
    );
    });

    $schemaReady = true;
}

function ensure_destination_visit_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }
    run_app_migration('20260807_create_and_normalize_destination_visits', function (): void {

    ensure_location_schema();
    db()->exec(
        "CREATE TABLE IF NOT EXISTS shop_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_type_name VARCHAR(100) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS visit_feedback_options (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feedback_label VARCHAR(140) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS districts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            region_id INT UNSIGNED NOT NULL,
            district_name VARCHAR(160) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_districts_region_name (region_id, district_name),
            CONSTRAINT fk_districts_region FOREIGN KEY (region_id) REFERENCES regions(id)
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS vendors (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL UNIQUE,
            vendor_name VARCHAR(160) NOT NULL,
            contact_name VARCHAR(140) NULL,
            phone VARCHAR(40) NULL,
            other_phone VARCHAR(40) NULL,
            email VARCHAR(160) NULL,
            profile_image VARCHAR(255) NULL,
            location_id INT UNSIGNED NULL,
            region_id INT UNSIGNED NULL,
            district_id INT UNSIGNED NULL,
            town_id INT UNSIGNED NULL,
            area VARCHAR(160) NULL,
            vendor_type ENUM('sor','regular') NOT NULL DEFAULT 'regular',
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_vendors_name (vendor_name),
            CONSTRAINT fk_vendors_user FOREIGN KEY (user_id) REFERENCES users(id),
            CONSTRAINT fk_vendors_location FOREIGN KEY (location_id) REFERENCES locations(id),
            CONSTRAINT fk_vendors_region FOREIGN KEY (region_id) REFERENCES regions(id),
            CONSTRAINT fk_vendors_district FOREIGN KEY (district_id) REFERENCES districts(id),
            CONSTRAINT fk_vendors_town FOREIGN KEY (town_id) REFERENCES towns(id),
            CONSTRAINT fk_vendors_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        )"
    );
    if (!db_column_exists('vendors', 'vendor_type')) {
        db()->exec("ALTER TABLE vendors ADD COLUMN vendor_type ENUM('sor','regular') NOT NULL DEFAULT 'regular' AFTER area");
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS vendor_customers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT UNSIGNED NOT NULL,
            customer_name VARCHAR(160) NOT NULL,
            contact_name VARCHAR(140) NULL,
            phone VARCHAR(40) NOT NULL,
            other_phone VARCHAR(40) NULL,
            location_id INT UNSIGNED NULL,
            region_id INT UNSIGNED NOT NULL,
            district_id INT UNSIGNED NOT NULL,
            town_id INT UNSIGNED NULL,
            area VARCHAR(160) NULL,
            notes TEXT NULL,
            sale_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            created_by_user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_customers_phone (vendor_id, phone),
            KEY idx_vendor_customers_town (town_id),
            KEY idx_vendor_customers_name (customer_name),
            KEY idx_vendor_customers_region (region_id),
            KEY idx_vendor_customers_district (district_id),
            KEY idx_vendor_customers_created_by (created_by_user_id)
            ,CONSTRAINT fk_vendor_customers_location FOREIGN KEY (location_id) REFERENCES locations(id)
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS destination_visits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sales_trip_id INT UNSIGNED NULL,
            destination_id INT UNSIGNED NOT NULL,
            vendor_id INT UNSIGNED NULL,
            parent_visit_id BIGINT UNSIGNED NULL,
            staff_id INT UNSIGNED NULL,
            recorded_by_user_id INT UNSIGNED NULL,
            visit_type ENUM('registration', 'follow_up') NOT NULL DEFAULT 'registration',
            sales_ref TEXT NULL,
            sale_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            promo_plug VARCHAR(160) NULL,
            shop_arrival_time TIME NULL,
            shop_departure_time TIME NULL,
            follow_up_method ENUM('phone_call', 'physical_visit') NULL,
            follow_up_at DATETIME NULL,
            company_name VARCHAR(160) NULL,
            owner_name VARCHAR(140) NULL,
            phone VARCHAR(40) NULL,
            other_phone VARCHAR(40) NULL,
            location_id INT UNSIGNED NULL,
            region_id INT UNSIGNED NULL,
            district_id INT UNSIGNED NULL,
            town_id INT UNSIGNED NULL,
            area VARCHAR(160) NULL,
            google_location VARCHAR(255) NULL,
            shop_type_id INT UNSIGNED NULL,
            vehicle_registration_no VARCHAR(80) NULL,
            supervisor_name VARCHAR(140) NULL,
            supervisor_phone VARCHAR(40) NULL,
            vin_no VARCHAR(80) NULL,
            owner_pic VARCHAR(255) NULL,
            shop_pic VARCHAR(255) NULL,
            car_pic VARCHAR(255) NULL,
            shop_video VARCHAR(255) NULL,
            feedback VARCHAR(140) NULL,
            note TEXT NULL,
            record_status ENUM('draft','completed') NOT NULL DEFAULT 'completed',
            legacy_source_id INT UNSIGNED NULL,
            legacy_visit_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_destination_visits_legacy_visit (legacy_visit_id),
            KEY idx_destination_visits_trip (sales_trip_id),
            KEY idx_destination_visits_destination (destination_id),
            KEY idx_destination_visits_vendor (vendor_id),
            KEY idx_destination_visits_parent (parent_visit_id),
            KEY idx_destination_visits_staff (staff_id),
            CONSTRAINT fk_destination_visits_trip FOREIGN KEY (sales_trip_id) REFERENCES sales_trips(id),
            CONSTRAINT fk_destination_visits_destination FOREIGN KEY (destination_id) REFERENCES destinations(id),
            CONSTRAINT fk_destination_visits_parent FOREIGN KEY (parent_visit_id) REFERENCES destination_visits(id),
            CONSTRAINT fk_destination_visits_staff FOREIGN KEY (staff_id) REFERENCES staff(id),
            CONSTRAINT fk_destination_visits_recorded_by FOREIGN KEY (recorded_by_user_id) REFERENCES users(id),
            CONSTRAINT fk_destination_visits_location FOREIGN KEY (location_id) REFERENCES locations(id),
            CONSTRAINT fk_destination_visits_region FOREIGN KEY (region_id) REFERENCES regions(id),
            CONSTRAINT fk_destination_visits_district FOREIGN KEY (district_id) REFERENCES districts(id),
            CONSTRAINT fk_destination_visits_town FOREIGN KEY (town_id) REFERENCES towns(id),
            CONSTRAINT fk_destination_visits_shop_type FOREIGN KEY (shop_type_id) REFERENCES shop_types(id)
        )"
    );

    if (!db_column_exists('destination_visits', 'record_status')) {
        db()->exec("ALTER TABLE destination_visits ADD COLUMN record_status ENUM('draft','completed') NOT NULL DEFAULT 'completed' AFTER note");
    }
    if (!db_column_exists('destination_visits', 'sale_confirmed')) {
        db()->exec('ALTER TABLE destination_visits ADD COLUMN sale_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER sales_ref');
    }
    if (!db_column_exists('vendor_customers', 'sale_confirmed')) {
        db()->exec('ALTER TABLE vendor_customers ADD COLUMN sale_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER notes');
    }
    if (db_table_exists('customer_sale_vins') && !db_table_exists('customer_pos_sale_vins')) {
        db()->exec('RENAME TABLE customer_sale_vins TO customer_pos_sale_vins');
    }
    db()->exec("CREATE TABLE IF NOT EXISTS customer_pos_sale_vins (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_source ENUM('visit','vendor_customer') NOT NULL DEFAULT 'visit',
        record_id BIGINT UNSIGNED NOT NULL,
        vin_no VARCHAR(80) NOT NULL,
        amount DECIMAL(14,2) NULL,
        created_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_customer_pos_sale_vins_vin (vin_no),
        KEY idx_customer_pos_sale_vins_customer (customer_source,record_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $saleVinMetadata=db_column_metadata('customer_pos_sale_vins','vin_no');
    if($saleVinMetadata&&strtolower((string)($saleVinMetadata['COLUMN_TYPE']??''))!=='varchar(80)'){
        db()->exec('ALTER TABLE customer_pos_sale_vins MODIFY vin_no VARCHAR(80) NOT NULL');
    }
    if (!db_column_exists('customer_pos_sale_vins', 'amount')) {
        db()->exec('ALTER TABLE customer_pos_sale_vins ADD COLUMN amount DECIMAL(14,2) NULL AFTER vin_no');
    }
    db()->exec("UPDATE destination_visits dv SET dv.sale_confirmed=1 WHERE dv.sale_confirmed=0 AND EXISTS (SELECT 1 FROM customer_pos_sale_vins csv WHERE csv.customer_source='visit' AND csv.record_id=dv.id AND csv.amount>0)");
    db()->exec("UPDATE vendor_customers vc SET vc.sale_confirmed=1 WHERE vc.sale_confirmed=0 AND EXISTS (SELECT 1 FROM customer_pos_sale_vins csv WHERE csv.customer_source='vendor_customer' AND csv.record_id=vc.id AND csv.amount>0)");

    if (db_column_exists('destination_visits', 'legacy_retailer_id') && !db_column_exists('destination_visits', 'legacy_source_id')) {
        db()->exec('ALTER TABLE destination_visits CHANGE legacy_retailer_id legacy_source_id INT UNSIGNED NULL');
    }
    if (!db_column_exists('sales_trips', 'vendor_id')) {
        db()->exec('ALTER TABLE sales_trips ADD COLUMN vendor_id INT UNSIGNED NULL AFTER vehicle_id');
    }
    foreach (['staff_id', 'companion_staff_id', 'vendor_id'] as $salesTripColumn) {
        $salesTripColumnMetadata = db_column_metadata('sales_trips', $salesTripColumn);
        if ($salesTripColumnMetadata && (string) ($salesTripColumnMetadata['IS_NULLABLE'] ?? 'NO') !== 'YES') {
            db()->exec("ALTER TABLE sales_trips MODIFY `$salesTripColumn` INT UNSIGNED NULL");
        }
    }
    if (!db_column_exists('sales_trips', 'journey_start_kilometer_photo')) {
        db()->exec('ALTER TABLE sales_trips ADD COLUMN journey_start_kilometer_photo VARCHAR(255) NULL AFTER journey_start_kilometers');
    }
    if (!db_column_exists('sales_trips', 'journey_end_kilometer_photo')) {
        db()->exec('ALTER TABLE sales_trips ADD COLUMN journey_end_kilometer_photo VARCHAR(255) NULL AFTER journey_end_kilometers');
    }
    if (!db_column_exists('destination_visits', 'vendor_id')) {
        db()->exec('ALTER TABLE destination_visits ADD COLUMN vendor_id INT UNSIGNED NULL AFTER destination_id');
    }
    if (!db_column_exists('destination_visits', 'place_group_key')) {
        db()->exec('ALTER TABLE destination_visits ADD COLUMN place_group_key CHAR(32) NULL AFTER vendor_id, ADD KEY idx_destination_visits_place_group (place_group_key)');
    }
    if (!db_column_exists('destination_visits', 'sale_vins_json')) {
        db()->exec('ALTER TABLE destination_visits ADD COLUMN sale_vins_json JSON NULL AFTER sales_ref');
    }
    if (!db_column_exists('destination_visits', 'sale_amounts_json')) {
        db()->exec('ALTER TABLE destination_visits ADD COLUMN sale_amounts_json JSON NULL AFTER sale_vins_json');
    }
    $salesTripMetadata = db_column_metadata('destination_visits', 'sales_trip_id');
    if ($salesTripMetadata && (string) ($salesTripMetadata['IS_NULLABLE'] ?? 'NO') !== 'YES') {
        db()->exec('ALTER TABLE destination_visits MODIFY sales_trip_id INT UNSIGNED NULL');
    }
    ensure_sales_trip_assignment_schema();
    });

    $schemaReady = true;
}

function ensure_fuel_log_upload_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260806_add_fuel_log_photos', function (): void {
    db()->exec("CREATE TABLE IF NOT EXISTS fuel_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT UNSIGNED NOT NULL,
        staff_id INT UNSIGNED NULL,
        fuel_date DATE NOT NULL,
        liters DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        odometer_reading INT UNSIGNED NULL,
        odometer_picture VARCHAR(255) NULL,
        odometer_picture_2 VARCHAR(255) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_fuel_logs_vehicle (vehicle_id),
        KEY idx_fuel_logs_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!db_column_exists('fuel_logs','odometer_picture')) {
        db()->exec('ALTER TABLE fuel_logs ADD COLUMN odometer_picture VARCHAR(255) NULL AFTER odometer_reading');
    }
    if (!db_column_exists('fuel_logs','odometer_picture_2')) {
        db()->exec('ALTER TABLE fuel_logs ADD COLUMN odometer_picture_2 VARCHAR(255) NULL AFTER odometer_picture');
    }
    });
    $schemaReady = true;
}

function customer_pos_sale_vins(string $source,int $recordId): array
{
    ensure_destination_visit_schema();
    $statement=db()->prepare('SELECT vin_no FROM customer_pos_sale_vins WHERE customer_source=? AND record_id=? ORDER BY id');
    $statement->execute([$source,$recordId]);
    return array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN));
}

function ensure_attendance_schema(): void
{
    static $schemaReady = false;

    if ($schemaReady) {
        return;
    }
    run_app_migration('20260806_create_attendance_tables', function (): void {

    db()->exec(
        "CREATE TABLE IF NOT EXISTS attendance_services (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_name VARCHAR(140) NOT NULL,
            service_type VARCHAR(80) NOT NULL DEFAULT 'General',
            service_date DATE NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendance_service_date_type (service_date, service_type),
            KEY idx_attendance_services_date (service_date),
            KEY idx_attendance_services_status (status),
            CONSTRAINT fk_attendance_services_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        )"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS attendance_records (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_id INT UNSIGNED NOT NULL,
            staff_id INT UNSIGNED NOT NULL,
            status ENUM('present', 'absent', 'late', 'excused') NOT NULL DEFAULT 'present',
            note TEXT NULL,
            marked_by_user_id INT UNSIGNED NULL,
            latitude DECIMAL(10, 7) NULL,
            longitude DECIMAL(10, 7) NULL,
            location_accuracy DECIMAL(10, 2) NULL,
            distance_meters DECIMAL(10, 2) NULL,
            location_verified TINYINT(1) NOT NULL DEFAULT 0,
            marked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendance_service_staff (service_id, staff_id),
            KEY idx_attendance_records_service_id (service_id),
            KEY idx_attendance_records_staff_id (staff_id),
            KEY idx_attendance_records_status (status),
            CONSTRAINT fk_attendance_records_service FOREIGN KEY (service_id) REFERENCES attendance_services(id) ON DELETE CASCADE,
            CONSTRAINT fk_attendance_records_staff FOREIGN KEY (staff_id) REFERENCES staff(id),
            CONSTRAINT fk_attendance_records_marked_by FOREIGN KEY (marked_by_user_id) REFERENCES users(id)
        )"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS attendance_location_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            latitude DECIMAL(10, 7) NULL,
            longitude DECIMAL(10, 7) NULL,
            attendance_radius INT NOT NULL DEFAULT 100,
            location_accuracy DECIMAL(10, 2) NULL,
            location_updated_by_user_id INT UNSIGNED NULL,
            location_updated_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_attendance_location_updated_by FOREIGN KEY (location_updated_by_user_id) REFERENCES users(id)
        )"
    );

    if (!db()->query('SELECT id FROM attendance_location_settings ORDER BY id ASC LIMIT 1')->fetchColumn()) {
        db()->exec('INSERT INTO attendance_location_settings (attendance_radius) VALUES (100)');
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS attendance_weekday_schedule (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_name VARCHAR(140) NOT NULL DEFAULT 'Daily Attendance',
            service_type VARCHAR(80) NOT NULL DEFAULT 'Workday',
            start_time TIME NULL,
            end_time TIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_attendance_weekday_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        )"
    );
    });

    $schemaReady = true;
}

function attendance_location_settings(): array
{
    ensure_attendance_schema();

    $settings = db()
        ->query('SELECT * FROM attendance_location_settings ORDER BY id ASC LIMIT 1')
        ->fetch();

    return $settings ?: [];
}

function attendance_location_is_configured(array $settings): bool
{
    return ($settings['latitude'] ?? null) !== null && ($settings['longitude'] ?? null) !== null;
}

function attendance_distance_meters(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
{
    $earthRadius = 6371000;
    $fromLatRad = deg2rad($fromLatitude);
    $toLatRad = deg2rad($toLatitude);
    $deltaLat = deg2rad($toLatitude - $fromLatitude);
    $deltaLon = deg2rad($toLongitude - $fromLongitude);

    $a = sin($deltaLat / 2) ** 2 + cos($fromLatRad) * cos($toLatRad) * sin($deltaLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function attendance_active_service_for_today(): ?array
{
    ensure_attendance_schema();

    $statement = db()->prepare(
        "SELECT *
         FROM attendance_services
         WHERE service_date = CURDATE() AND status = 'active'
         ORDER BY is_default DESC, start_time IS NULL ASC, start_time ASC, id DESC
         LIMIT 1"
    );
    $statement->execute();
    $service = $statement->fetch();

    if (!$service && (int) date('N') <= 5) {
        $schedule = db()->query(
            'SELECT * FROM attendance_weekday_schedule WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
        )->fetch();

        if ($schedule) {
            db()->prepare(
                'INSERT IGNORE INTO attendance_services
                    (service_name, service_type, service_date, start_time, end_time, is_default, status, created_by_user_id)
                 VALUES (?, ?, CURDATE(), ?, ?, 1, \'active\', ?)'
            )->execute([
                $schedule['service_name'], $schedule['service_type'], $schedule['start_time'],
                $schedule['end_time'], $schedule['updated_by_user_id'],
            ]);
            $statement->execute();
            $service = $statement->fetch();
        }
    }

    return $service ?: null;
}

function attendance_staff_record_for_service(int $serviceId, int $staffId): ?array
{
    ensure_attendance_schema();

    $statement = db()->prepare(
        'SELECT *
         FROM attendance_records
         WHERE service_id = ? AND staff_id = ?
         LIMIT 1'
    );
    $statement->execute([$serviceId, $staffId]);
    $record = $statement->fetch();

    return $record ?: null;
}

function destination_is_taxi_rank(array $destination): bool
{
    return (string) ($destination['destination_key'] ?? '') === taxi_rank_destination_key();
}

function app_url(string $path = ''): string
{
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    if (str_contains($basePath, '/pages')) {
        $basePath = dirname($basePath);
    }

    return ($basePath === '/' ? '' : $basePath) . '/' . ltrim($path, '/');
}

function safe_app_return_url(string $candidate, string $fallback = ''): string
{
    $candidate = trim($candidate);
    if ($candidate === '' || str_starts_with($candidate, '//')) return $fallback;

    $parts = parse_url($candidate);
    if (!is_array($parts)) return $fallback;
    if (isset($parts['scheme']) || isset($parts['host'])) {
        $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $candidateHost = strtolower((string)($parts['host'] ?? ''));
        if (isset($parts['port'])) $candidateHost .= ':' . (int)$parts['port'];
        if ($requestHost === '' || $candidateHost !== $requestHost) return $fallback;
        $candidate = (string)($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    $appRoot = rtrim(app_url(''), '/') . '/';
    $candidatePath = (string)(parse_url($candidate, PHP_URL_PATH) ?: '');
    if (!str_starts_with($candidatePath . '/', $appRoot) || str_contains(rawurldecode($candidatePath), '..')) return $fallback;
    return $candidate;
}

function requested_return_url(string $fallback): string
{
    return safe_app_return_url((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''), $fallback);
}

function asset_url(string $path): string
{
    $normalizedPath = ltrim($path, '/');
    $filePath = __DIR__ . '/../' . $normalizedPath;
    $version = is_file($filePath) ? (string) filemtime($filePath) : '';
    $url = app_url($normalizedPath);

    return $version !== '' ? $url . '?v=' . rawurlencode($version) : $url;
}

function compress_uploaded_image(string $sourcePath, string $mimeType, string $targetPath, int $maxDimension = 1600, int $quality = 78): bool
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) return false;

    $sourceImage = match ($mimeType) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($sourcePath) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($sourcePath) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };
    if (!$sourceImage) return false;

    if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        $orientation = (int)($exif['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => @imagerotate($sourceImage, 180, 0),
            6 => @imagerotate($sourceImage, -90, 0),
            8 => @imagerotate($sourceImage, 90, 0),
            default => false,
        };
        if ($rotated) {
            imagedestroy($sourceImage);
            $sourceImage = $rotated;
        }
    }

    $width = imagesx($sourceImage);
    $height = imagesy($sourceImage);
    if ($width < 1 || $height < 1) {
        imagedestroy($sourceImage);
        return false;
    }

    $scale = min(1, $maxDimension / max($width, $height));
    $targetWidth = max(1, (int)round($width * $scale));
    $targetHeight = max(1, (int)round($height * $scale));
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$targetImage) {
        imagedestroy($sourceImage);
        return false;
    }

    $white = imagecolorallocate($targetImage, 255, 255, 255);
    imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $white);
    $copied = imagecopyresampled($targetImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    $saved = $copied && imagejpeg($targetImage, $targetPath, max(40, min(95, $quality)));
    imagedestroy($targetImage);
    imagedestroy($sourceImage);
    return $saved;
}

function video_compressor_binary(): ?string
{
    $configured = trim((string) getenv('SPW_FFMPEG_PATH'));
    $packagedBinaries = glob(__DIR__ . '/../tools/ffmpeg-*/bin/ffmpeg.exe') ?: [];
    $candidates = array_filter(array_merge([
        $configured,
        __DIR__ . '/../tools/ffmpeg/bin/ffmpeg.exe',
        __DIR__ . '/../tools/ffmpeg/ffmpeg.exe',
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
        'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
        'ffmpeg',
    ], $packagedBinaries));

    foreach ($candidates as $candidate) {
        if ($candidate === 'ffmpeg') {
            $output = [];
            $exitCode = 1;
            @exec('where ffmpeg 2>NUL', $output, $exitCode);
            if ($exitCode === 0 && !empty($output[0])) return trim((string)$output[0]);
            continue;
        }
        if (is_file($candidate)) return $candidate;
    }

    return null;
}

function compress_uploaded_video(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 1280,
    int $videoBitrateKbps = 1200,
    int $audioBitrateKbps = 96
): bool {
    $binary = video_compressor_binary();
    if ($binary === null || !function_exists('exec')) return false;

    $temporaryTarget = $targetPath . '.processing.mp4';
    $scaleFilter = "scale='min({$maxWidth},iw)':-2";
    $command = escapeshellarg($binary)
        . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($sourcePath)
        . ' -map 0:v:0 -map 0:a? -vf ' . escapeshellarg($scaleFilter)
        . ' -c:v libx264 -preset veryfast -b:v ' . max(350, $videoBitrateKbps) . 'k'
        . ' -maxrate ' . max(450, (int)round($videoBitrateKbps * 1.35)) . 'k'
        . ' -bufsize ' . max(900, $videoBitrateKbps * 2) . 'k'
        . ' -c:a aac -b:a ' . max(48, $audioBitrateKbps) . 'k'
        . ' -movflags +faststart ' . escapeshellarg($temporaryTarget) . ' 2>NUL';
    $output = [];
    $exitCode = 1;
    @exec($command, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($temporaryTarget) || filesize($temporaryTarget) < 1) {
        if (is_file($temporaryTarget)) @unlink($temporaryTarget);
        return false;
    }

    if (!@rename($temporaryTarget, $targetPath)) {
        @unlink($temporaryTarget);
        return false;
    }
    return true;
}

function absolute_app_url(string $path = ''): string
{
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $isSecure ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';

    return $scheme . '://' . $host . app_url($path);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function town_name_html(?string $name, mixed $isCapital = 0): string
{
    $label = e((string) $name);
    return $label . ((int) $isCapital === 1 ? ' <span class="capital-town-marker" aria-label="Capital town">*</span>' : '');
}

function current_user_name(): string
{
    return (string) ($_SESSION['user_name'] ?? 'Guest');
}

function current_user_role(): string
{
    return (string) ($_SESSION['user_role'] ?? '');
}

function is_super_admin(): bool
{
    return current_user_role() === 'super_admin';
}

function is_admin_user(): bool
{
    return in_array(current_user_role(), ['super_admin', 'admin'], true);
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_staff_id(): ?int
{
    $userId = current_user_id();

    if (!$userId) {
        return null;
    }

    $statement = db()->prepare('SELECT id FROM staff WHERE user_id = ? LIMIT 1');
    $statement->execute([$userId]);
    $staffId = (int) ($statement->fetchColumn() ?: 0);

    return $staffId > 0 ? $staffId : null;
}

function ensure_vendor_personnel_schema(): void
{
    static $schemaReady=false;
    if($schemaReady)return;
    run_app_migration('20260813_create_vendor_personnel',function():void{
        db()->exec("CREATE TABLE IF NOT EXISTS vendor_personnel (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            personnel_role ENUM('manager','salesperson') NOT NULL DEFAULT 'salesperson',
            can_make_sales TINYINT(1) NOT NULL DEFAULT 1,
            can_sor TINYINT(1) NOT NULL DEFAULT 0,
            can_transfer TINYINT(1) NOT NULL DEFAULT 0,
            can_refund TINYINT(1) NOT NULL DEFAULT 0,
            can_audit TINYINT(1) NOT NULL DEFAULT 0,
            can_reports TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            added_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_personnel_vendor_user (vendor_id,user_id),
            KEY idx_vendor_personnel_user_active (user_id,is_active),
            KEY idx_vendor_personnel_added_by (added_by_user_id)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if(!db_column_exists('vendor_personnel','can_sor'))db()->exec('ALTER TABLE vendor_personnel ADD COLUMN can_sor TINYINT(1) NOT NULL DEFAULT 0 AFTER can_make_sales');
        if(!db_column_exists('vendor_personnel','can_transfer'))db()->exec('ALTER TABLE vendor_personnel ADD COLUMN can_transfer TINYINT(1) NOT NULL DEFAULT 0 AFTER can_sor');
        if(db_table_exists('pos_sales')){
            if(!db_column_exists('pos_sales','vendor_id'))db()->exec('ALTER TABLE pos_sales ADD COLUMN vendor_id INT UNSIGNED NULL AFTER sale_source, ADD KEY idx_pos_sales_vendor (vendor_id)');
            if(!db_column_exists('pos_sales','vendor_personnel_id'))db()->exec('ALTER TABLE pos_sales ADD COLUMN vendor_personnel_id BIGINT UNSIGNED NULL AFTER vendor_id, ADD KEY idx_pos_sales_vendor_personnel (vendor_personnel_id)');
        }
    });
    run_app_migration('20260813_add_vendor_personnel_pos_roles',function():void{
        if(!db_column_exists('vendor_personnel','can_sor'))db()->exec('ALTER TABLE vendor_personnel ADD COLUMN can_sor TINYINT(1) NOT NULL DEFAULT 0 AFTER can_make_sales');
        if(!db_column_exists('vendor_personnel','can_transfer'))db()->exec('ALTER TABLE vendor_personnel ADD COLUMN can_transfer TINYINT(1) NOT NULL DEFAULT 0 AFTER can_sor');
    });
    run_app_migration('20260814_add_spwsales_personnel_permissions',function():void{
        if(!db_column_exists('vendor_personnel','can_refund'))db()->exec('ALTER TABLE vendor_personnel ADD COLUMN can_refund TINYINT(1) NOT NULL DEFAULT 0 AFTER can_transfer');
        if(!db_column_exists('vendor_personnel','can_audit'))db()->exec('ALTER TABLE vendor_personnel ADD COLUMN can_audit TINYINT(1) NOT NULL DEFAULT 0 AFTER can_refund');
        if(!db_column_exists('vendor_personnel','can_reports'))db()->exec('ALTER TABLE vendor_personnel ADD COLUMN can_reports TINYINT(1) NOT NULL DEFAULT 0 AFTER can_audit');
    });
    run_app_migration('20260814_add_pos_sales_vendor_name',function():void{
        if(!db_table_exists('pos_sales'))return;
        if(!db_column_exists('pos_sales','vendor_name'))db()->exec('ALTER TABLE pos_sales ADD COLUMN vendor_name VARCHAR(160) NULL AFTER vendor_id');
        if(db_column_exists('pos_sales','vendor_id')){
            db()->exec("UPDATE pos_sales s INNER JOIN vendors v ON v.id=s.vendor_id SET s.vendor_name=v.vendor_name WHERE COALESCE(TRIM(s.vendor_name),'')=''");
        }
        db()->exec("UPDATE pos_sales s INNER JOIN vendors v ON v.user_id=s.recorded_by_user_id SET s.vendor_name=v.vendor_name WHERE COALESCE(TRIM(s.vendor_name),'')=''");
    });
    run_app_migration('20260814_add_pos_sales_vendor_personnel_name',function():void{
        if(!db_table_exists('pos_sales'))return;
        if(!db_column_exists('pos_sales','vendor_personnel_name'))db()->exec('ALTER TABLE pos_sales ADD COLUMN vendor_personnel_name VARCHAR(160) NULL AFTER vendor_personnel_id');
        if(db_column_exists('pos_sales','vendor_personnel_id')){
            db()->exec("UPDATE pos_sales s INNER JOIN vendor_personnel vp ON vp.id=s.vendor_personnel_id INNER JOIN users u ON u.id=vp.user_id SET s.vendor_personnel_name=u.full_name WHERE COALESCE(TRIM(s.vendor_personnel_name),'')=''");
        }
    });
    $schemaReady=true;
}

function current_vendor_personnel(): ?array
{
    $userId=current_user_id();
    if(!$userId)return null;
    ensure_vendor_personnel_schema();
    $statement=db()->prepare('SELECT vp.*,v.vendor_name FROM vendor_personnel vp INNER JOIN vendors v ON v.id=vp.vendor_id AND v.is_active=1 WHERE vp.user_id=? AND vp.is_active=1 LIMIT 1');
    $statement->execute([$userId]);
    return $statement->fetch()?:null;
}

function current_vendor_is_sor(): bool
{
    $vendor=current_vendor_profile();
    return $vendor && (string)($vendor['vendor_type']??'regular')==='sor';
}

function current_vendor_profile(): ?array
{
    $userId = current_user_id();
    if (!$userId) {
        return null;
    }

    ensure_location_schema();
    ensure_vendor_type_schema();
    ensure_vendor_personnel_schema();
    $statement = db()->prepare(
        'SELECT v.*,l.region_name,l.mmda_name AS district_name,l.mmda_name,
                l.town_name,l.is_capital
         FROM vendors v
         LEFT JOIN locations l ON l.id=v.location_id
         LEFT JOIN vendor_personnel vp ON vp.vendor_id=v.id AND vp.user_id=? AND vp.is_active=1
         WHERE (v.user_id=? OR vp.id IS NOT NULL) AND v.is_active=1
         LIMIT 1'
    );
    $statement->execute([$userId,$userId]);
    $vendor = $statement->fetch();

    return $vendor ?: null;
}

function app_business_date(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d');
}

function ensure_vendor_day_closure_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260817_create_vendor_day_closures', function (): void {
        db()->exec("CREATE TABLE IF NOT EXISTS vendor_day_closures (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT UNSIGNED NOT NULL,
            business_date DATE NOT NULL,
            closure_sequence INT UNSIGNED NOT NULL DEFAULT 1,
            closed_by_user_id INT UNSIGNED NOT NULL,
            closed_at DATETIME NOT NULL,
            summary_snapshot JSON NOT NULL,
            reopened_by_user_id INT UNSIGNED NULL,
            reopened_at DATETIME NULL,
            reopen_reason TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_day_closure_sequence (vendor_id,business_date,closure_sequence),
            KEY idx_vendor_day_active (vendor_id,business_date,reopened_at),
            KEY idx_vendor_day_closed_at (closed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS vendor_day_closure_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            closure_id BIGINT UNSIGNED NOT NULL,
            action ENUM('reopened') NOT NULL,
            administrator_user_id INT UNSIGNED NOT NULL,
            reason TEXT NOT NULL,
            action_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_closure_audit_closure (closure_id),
            KEY idx_closure_audit_admin (administrator_user_id),
            CONSTRAINT fk_closure_audit_closure FOREIGN KEY (closure_id) REFERENCES vendor_day_closures(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    });
    run_app_migration('20260817_add_vendor_day_closure_names', function (): void {
        if(!db_column_exists('vendor_day_closures','closed_by_user_name'))db()->exec('ALTER TABLE vendor_day_closures ADD COLUMN closed_by_user_name VARCHAR(160) NULL AFTER closed_by_user_id');
        if(!db_column_exists('vendor_day_closures','reopened_by_user_name'))db()->exec('ALTER TABLE vendor_day_closures ADD COLUMN reopened_by_user_name VARCHAR(160) NULL AFTER reopened_by_user_id');
        if(!db_column_exists('vendor_day_closure_audit','administrator_user_name'))db()->exec('ALTER TABLE vendor_day_closure_audit ADD COLUMN administrator_user_name VARCHAR(160) NULL AFTER administrator_user_id');
        db()->exec("UPDATE vendor_day_closures c INNER JOIN users u ON u.id=c.closed_by_user_id SET c.closed_by_user_name=u.full_name WHERE COALESCE(TRIM(c.closed_by_user_name),'')=''");
        db()->exec("UPDATE vendor_day_closures c INNER JOIN users u ON u.id=c.reopened_by_user_id SET c.reopened_by_user_name=u.full_name WHERE c.reopened_by_user_id IS NOT NULL AND COALESCE(TRIM(c.reopened_by_user_name),'')=''");
        db()->exec("UPDATE vendor_day_closure_audit a INNER JOIN users u ON u.id=a.administrator_user_id SET a.administrator_user_name=u.full_name WHERE COALESCE(TRIM(a.administrator_user_name),'')=''");
    });
    $schemaReady = true;
}

function vendor_day_lock_name(int $vendorId, string $businessDate): string
{
    return 'spw_close_day_' . $vendorId . '_' . str_replace('-', '', $businessDate);
}

function vendor_day_is_closed(int $vendorId, ?string $businessDate = null): ?array
{
    if ($vendorId < 1) return null;
    ensure_vendor_day_closure_schema();
    $statement = db()->prepare('SELECT * FROM vendor_day_closures WHERE vendor_id=? AND business_date=? AND reopened_at IS NULL ORDER BY closure_sequence DESC,id DESC LIMIT 1');
    $statement->execute([$vendorId, $businessDate ?: app_business_date()]);
    return $statement->fetch() ?: null;
}

function vendor_day_summary(int $vendorId, string $businessDate): array
{
    ensure_pos_sales_schema();
    $totals = db()->prepare("SELECT COUNT(*) sale_count,COALESCE(SUM(subtotal),0) gross_sales,COALESCE(SUM(delivery_charge),0) delivery_charges,COALESCE(SUM(net_sales),0) net_sales,COALESCE(SUM(commission_amount),0) discounts_commissions,COALESCE(SUM(amount_less_commission),0) amount_less_commission FROM pos_sales WHERE vendor_id=? AND sale_date=? AND status='completed'");
    $totals->execute([$vendorId,$businessDate]);
    $summary = $totals->fetch() ?: [];
    $products = db()->prepare("SELECT si.brand_name,si.plug_number,SUM(si.quantity) quantity,SUM(si.total_amount) gross_amount FROM pos_sale_items si INNER JOIN pos_sales s ON s.id=si.sale_id WHERE s.vendor_id=? AND s.sale_date=? AND s.status='completed' GROUP BY si.brand_name,si.plug_number ORDER BY si.brand_name,si.plug_number");
    $products->execute([$vendorId,$businessDate]);
    $customers = db()->prepare("SELECT s.id sale_id,COALESCE(NULLIF(s.customer_name,''),'Walk-in customer') customer_name,si.brand_name,si.plug_number,si.quantity,si.total_amount amount,s.subtotal grand_total,s.delivery_charge,s.amount_less_commission final_total FROM pos_sales s INNER JOIN pos_sale_items si ON si.sale_id=s.id WHERE s.vendor_id=? AND s.sale_date=? AND s.status='completed' ORDER BY customer_name,s.id,si.id");
    $customers->execute([$vendorId,$businessDate]);
    $breakdown = db()->prepare("SELECT CASE WHEN sale_source='sor' THEN 'SoR' ELSE 'Direct' END sale_channel,COUNT(*) sale_count,COALESCE(SUM(subtotal),0) gross_sales,COALESCE(SUM(delivery_charge),0) delivery_charges,COALESCE(SUM(net_sales),0) net_sales,COALESCE(SUM(commission_amount),0) discounts_commissions,COALESCE(SUM(amount_less_commission),0) total_after_commission FROM pos_sales WHERE vendor_id=? AND sale_date=? AND status='completed' GROUP BY CASE WHEN sale_source='sor' THEN 'SoR' ELSE 'Direct' END ORDER BY sale_channel");
    $breakdown->execute([$vendorId,$businessDate]);
    $personnel = db()->prepare("SELECT COALESCE(NULLIF(vendor_personnel_name,''),u.full_name,'Unknown') personnel_name,recorded_by_user_id,COUNT(*) sale_count,COALESCE(SUM(subtotal),0) gross_sales,COALESCE(SUM(delivery_charge),0) delivery_charges,COALESCE(SUM(net_sales),0) net_sales,COALESCE(SUM(commission_amount),0) discounts_commissions,COALESCE(SUM(amount_less_commission),0) total_after_commission FROM pos_sales s LEFT JOIN users u ON u.id=s.recorded_by_user_id WHERE s.vendor_id=? AND s.sale_date=? AND s.status='completed' GROUP BY recorded_by_user_id,COALESCE(NULLIF(vendor_personnel_name,''),u.full_name,'Unknown') ORDER BY personnel_name");
    $personnel->execute([$vendorId,$businessDate]);
    return [
        'business_date'=>$businessDate,
        'sale_count'=>(int)($summary['sale_count']??0),
        'gross_sales'=>(float)($summary['gross_sales']??0),
        'delivery_charges'=>(float)($summary['delivery_charges']??0),
        'net_sales'=>(float)($summary['net_sales']??0),
        'discounts_commissions'=>(float)($summary['discounts_commissions']??0),
        'amount_less_commission'=>(float)($summary['amount_less_commission']??0),
        'customers'=>$customers->fetchAll(),
        'products'=>$products->fetchAll(),
        'channels'=>$breakdown->fetchAll(),
        'personnel'=>$personnel->fetchAll(),
    ];
}

function ensure_vendor_type_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260808_add_vendor_type', function (): void {
    if (db_table_exists('vendors') && !db_column_exists('vendors', 'vendor_type')) {
        db()->exec("ALTER TABLE vendors ADD COLUMN vendor_type ENUM('sor','regular') NOT NULL DEFAULT 'regular' AFTER area");
    }
    });
    $schemaReady = true;
}

function ensure_user_phone_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    run_app_migration('20260807_add_unique_user_phone', function (): void {
    if (!db_column_exists('users','phone')) {
        db()->exec('ALTER TABLE users ADD COLUMN phone VARCHAR(40) NULL AFTER email');
    }
    $check=db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $check->execute(['users','uq_users_phone']);
    if (!(int)$check->fetchColumn()) db()->exec('ALTER TABLE users ADD UNIQUE INDEX uq_users_phone (phone)');
    });
    $schemaReady = true;
}

function sync_staff_login_phones(): int
{
    ensure_user_phone_schema();
    if (!db_table_exists('staff')) return 0;

    $statement=db()->prepare(
        "UPDATE users u
         INNER JOIN staff s ON s.user_id=u.id
         LEFT JOIN users conflict ON conflict.phone=? AND conflict.id<>u.id
         SET u.phone=?
         WHERE u.id=? AND conflict.id IS NULL"
    );
    $staff=db()->query("SELECT s.user_id,s.phone FROM staff s INNER JOIN users u ON u.id=s.user_id WHERE s.user_id IS NOT NULL AND s.phone IS NOT NULL AND TRIM(s.phone)<>''")->fetchAll();
    $updated=0;
    foreach($staff as $row){
        $phone=normalize_phone_number((string)$row['phone']);
        if(!is_valid_phone_number($phone)) continue;
        $statement->execute([$phone,$phone,(int)$row['user_id']]);
        $updated += $statement->rowCount();
    }
    return $updated;
}

function provision_unlinked_vendor_accounts(): array
{
    ensure_destination_visit_schema();
    ensure_user_phone_schema();
    $result=['created'=>0,'pending'=>0];
    $vendors=db()->query('SELECT id,vendor_name,contact_name,email,phone,profile_image,is_active FROM vendors WHERE user_id IS NULL ORDER BY id')->fetchAll();
    $phoneCheck=db()->prepare('SELECT COUNT(*) FROM users WHERE phone=?');
    $emailCheck=db()->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email)=LOWER(?)');
    $insert=db()->prepare("INSERT INTO users (full_name,email,phone,password_hash,profile_image,role,force_password_change,is_active) VALUES (?,?,?,?,?,'vendor',1,?)");
    $link=db()->prepare('UPDATE vendors SET user_id=? WHERE id=? AND user_id IS NULL');
    foreach($vendors as $vendor){
        $phone=normalize_phone_number((string)($vendor['phone']??''));
        if(!is_valid_phone_number($phone)){$result['pending']++;continue;}
        $phoneCheck->execute([$phone]);
        if((int)$phoneCheck->fetchColumn()){$result['pending']++;continue;}
        $email=trim((string)($vendor['email']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $email='';
        if($email!==''){
            $emailCheck->execute([$email]);
            if((int)$emailCheck->fetchColumn()) $email='';
        }
        if($email==='') $email='vendor.'.(int)$vendor['id'].'.'.$phone.'@phone.local';
        $insert->execute([(string)($vendor['contact_name']?:$vendor['vendor_name']),$email,$phone,password_hash(VENDOR_DEFAULT_PASSWORD,PASSWORD_DEFAULT),(string)($vendor['profile_image']??'')?:null,(int)$vendor['is_active']]);
        $link->execute([(int)db()->lastInsertId(),(int)$vendor['id']]);
        $result['created']++;
    }
    db()->exec("UPDATE users u
        INNER JOIN vendors v ON v.user_id=u.id
        LEFT JOIN users conflict ON LOWER(conflict.email)=LOWER(v.email) AND conflict.id<>u.id
        SET u.email=TRIM(v.email)
        WHERE u.role='vendor' AND u.email LIKE '%@phone.local'
          AND v.email IS NOT NULL AND TRIM(v.email) REGEXP '^[^@[:space:]]+@[^@[:space:]]+\\.[^@[:space:]]+$'
          AND conflict.id IS NULL");
    return $result;
}

function current_user_profile_image(): string
{
    return (string) ($_SESSION['profile_image'] ?? '');
}

function current_user_profile_image_url(): string
{
    $profileImage = current_user_profile_image();

    return $profileImage !== '' ? app_url($profileImage) : '';
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function require_auth(): void
{
    if (!is_logged_in()) {
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? app_url('index.php');
        header('Location: ' . app_url('login.php'));
        exit;
    }

    $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if (must_change_password() && !in_array($currentScript, ['change-password.php', 'logout.php'], true)) {
        header('Location: ' . app_url('change-password.php'));
        exit;
    }
}

function application_modules(): array
{
    migrate_pos_module_identity();
    return [
        'customer_visit' => [
            'title' => 'Marketing Trip',
            'description' => 'Register a new location or continue at a previously visited location.',
            'icon' => 'fa-solid fa-map-location-dot',
            'url' => app_url('marketing-trip.php'),
        ],
        'sales_trip' => [
            'title' => 'Trip Registration',
            'description' => 'Register, view, and complete a marketing trip.',
            'icon' => 'fa-solid fa-route',
            'url' => app_url('sales-trip.php?section=trip'),
        ],
        'customer_followup' => [
            'title' => 'Customer Follow-up',
            'description' => 'Find a registered customer and record a phone follow-up.',
            'icon' => 'fa-solid fa-clipboard-check',
            'url' => app_url('followup.php'),
        ],
        'vehicle_log' => [
            'title' => 'Vehicle Log',
            'description' => 'Monitor fuel activity, log book records, and daily fleet movement.',
            'icon' => 'fa-solid fa-location-crosshairs',
            'url' => app_url('vehicles.php'),
        ],
        'vin_search' => [
            'title' => 'VIN Search',
            'description' => 'Decode a VIN and review its vehicle specifications.',
            'icon' => 'fa-solid fa-barcode',
            'url' => app_url('vin-search.php'),
        ],
        'attendance' => [
            'title' => 'Staff Attendance',
            'description' => 'Use GPS to mark workplace attendance for today.',
            'icon' => 'fa-solid fa-calendar-check',
            'url' => app_url('attendance.php'),
        ],
        'feedback' => [
            'title' => 'Feedback',
            'description' => 'Review driver, staff, and operational feedback records.',
            'icon' => 'fa-solid fa-comments',
            'url' => app_url('feedback.php'),
        ],
        'pos' => [
            'title' => 'POS',
            'description' => 'Open sales, refund, and report menus.',
            'icon' => 'fa-solid fa-receipt',
            'url' => app_url('pos.php'),
        ],
        'reports' => [
            'title' => 'Reports',
            'description' => 'Review operational summaries and performance insights.',
            'icon' => 'fa-solid fa-chart-line',
            'url' => app_url('reports.php'),
        ],
        'registration_records' => [
            'title' => 'Registration Records',
            'description' => 'Continue drafts and review or edit customer and location registrations.',
            'icon' => 'fa-solid fa-address-card',
            'url' => app_url('registration-records.php'),
        ],
        'activity_log' => [
            'title' => 'Activity Log',
            'description' => 'Review completed trips, destination visits, and field activity history.',
            'icon' => 'fa-solid fa-clock-rotate-left',
            'url' => app_url('activity-log.php'),
        ],
        'create_customer' => [
            'title' => 'Create Customer',
            'description' => 'Register a customer for a vendor without starting a marketing trip.',
            'icon' => 'fa-solid fa-user-plus',
            'url' => app_url('admin-customers.php'),
        ],
        'vendor_customers' => [
            'title' => 'Create Customers',
            'description' => 'Register customers within your assigned town. Their location is secured to your vendor account.',
            'icon' => 'fa-solid fa-user-plus',
            'url' => app_url('vendor-customers.php'),
        ],
        'vendor_reports' => [
            'title' => 'Reports',
            'description' => 'Review all customers you registered within your assigned town.',
            'icon' => 'fa-solid fa-chart-column',
            'url' => app_url('vendor-reports.php'),
        ],
        'vendor_personnel' => [
            'title' => 'Vendor Personnel',
            'description' => 'Add and manage people who can record sales for your vendor account.',
            'icon' => 'fa-solid fa-people-group',
            'url' => app_url('vendor-personnel.php'),
        ],
        'admin' => [
            'title' => 'Admin',
            'description' => 'Manage accounts, system setup, staff, and assignments.',
            'icon' => 'fa-solid fa-user-gear',
            'url' => app_url('admin.php'),
        ],
    ];
}

function migrate_pos_module_identity(): void
{
    static $migrated = false;
    if ($migrated) return;
    run_app_migration('20260808_rename_sor_module_to_pos', function (): void {

    if (db_table_exists('module_assignments')) {
        db()->exec("INSERT IGNORE INTO module_assignments (staff_id,module_key,assigned_by_user_id,is_active,created_at,updated_at) SELECT staff_id,'pos',assigned_by_user_id,is_active,created_at,updated_at FROM module_assignments WHERE module_key='sor'");
        db()->exec("DELETE FROM module_assignments WHERE module_key='sor'");
    }
    if (db_table_exists('vendor_module_assignments')) {
        db()->exec("INSERT IGNORE INTO vendor_module_assignments (vendor_id,module_key,assigned_by_user_id,is_active,created_at,updated_at) SELECT vendor_id,'pos',assigned_by_user_id,is_active,created_at,updated_at FROM vendor_module_assignments WHERE module_key='sor'");
        db()->exec("DELETE FROM vendor_module_assignments WHERE module_key='sor'");
    }
    if (db_table_exists('reference_sequences')) {
        db()->exec("INSERT INTO reference_sequences(sequence_key,next_value) SELECT 'pos_sale',rs.next_value FROM reference_sequences rs WHERE rs.sequence_key='sor_sale' ON DUPLICATE KEY UPDATE reference_sequences.next_value=GREATEST(reference_sequences.next_value,VALUES(next_value))");
        db()->exec("DELETE FROM reference_sequences WHERE sequence_key='sor_sale'");
    }
    });
    $migrated = true;
}

function application_module_groups(): array
{
    return [
        'operations' => [
            'title' => 'Operations',
            'description' => 'Trips, customers, follow-ups and field tools.',
            'icon' => 'fa-solid fa-route',
            'modules' => ['customer_visit','sales_trip','customer_followup','vendor_customers','vehicle_log','attendance','vin_search'],
        ],
        'sales' => [
            'title' => 'POS / Sales',
            'description' => 'Record sales and open POS activities.',
            'icon' => 'fa-solid fa-receipt',
            'modules' => ['pos'],
        ],
        'records' => [
            'title' => 'Records & Reports',
            'description' => 'Registration records, reports and activity history.',
            'icon' => 'fa-solid fa-chart-column',
            'modules' => ['reports','registration_records','activity_log','vendor_reports','feedback'],
        ],
        'administration' => [
            'title' => 'Setup & Administration',
            'description' => 'Customer creation, accounts and system setup.',
            'icon' => 'fa-solid fa-user-gear',
            'modules' => ['create_customer','admin'],
        ],
    ];
}

function group_application_modules(array $modules): array
{
    $grouped = [];
    foreach (application_module_groups() as $groupKey => $group) {
        $items = array_intersect_key($modules, array_flip($group['modules']));
        if (!$items) continue;
        $group['key'] = $groupKey;
        $group['items'] = $items;
        $grouped[$groupKey] = $group;
    }
    return $grouped;
}

function assigned_module_keys_for_staff(int $staffId): array
{
    $statement = db()->prepare(
        'SELECT module_key
         FROM module_assignments
         WHERE staff_id = ? AND is_active = 1'
    );
    $statement->execute([$staffId]);

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function ensure_vendor_module_assignments_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    ensure_destination_visit_schema();
    run_app_migration('20260805_create_vendor_module_assignments', function (): void {
    db()->exec(
        'CREATE TABLE IF NOT EXISTS vendor_module_assignments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT UNSIGNED NOT NULL,
            module_key VARCHAR(80) NOT NULL,
            assigned_by_user_id INT UNSIGNED NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_module_assignments_vendor_module (vendor_id,module_key),
            KEY idx_vendor_module_assignments_vendor (vendor_id),
            KEY idx_vendor_module_assignments_assigned_by (assigned_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    });
    $schemaReady = true;
}

function assigned_module_keys_for_vendor(int $vendorId): array
{
    ensure_vendor_module_assignments_schema();
    $statement = db()->prepare(
        'SELECT module_key
         FROM vendor_module_assignments
         WHERE vendor_id = ? AND is_active = 1'
    );
    $statement->execute([$vendorId]);

    return array_values(array_unique(array_merge(
        ['vendor_reports', 'vendor_personnel'],
        array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))
    )));
}

function vendor_assignable_module_keys(): array
{
    return ['customer_visit','customer_followup','vendor_customers','pos','vin_search','registration_records','vendor_reports'];
}

function ensure_vendor_town_assignments_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) return;
    ensure_destination_visit_schema();
    ensure_location_schema();
    run_app_migration('20260805_create_vendor_town_assignments', function (): void {
    db()->exec(
        'CREATE TABLE IF NOT EXISTS vendor_town_assignments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT UNSIGNED NOT NULL,
            town_id INT UNSIGNED NULL,
            location_id INT UNSIGNED NULL,
            assigned_by_user_id INT UNSIGNED NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_town_assignments_vendor_town (vendor_id,town_id),
            UNIQUE KEY uq_vendor_town_assignments_vendor_location (vendor_id,location_id),
            KEY idx_vendor_town_assignments_vendor (vendor_id),
            KEY idx_vendor_town_assignments_town (town_id),
            KEY idx_vendor_town_assignments_location (location_id),
            CONSTRAINT fk_vendor_town_assignments_location FOREIGN KEY (location_id) REFERENCES locations(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    });
    $schemaReady = true;
}

function assigned_towns_for_vendor(int $vendorId): array
{
    ensure_vendor_town_assignments_schema();
    $statement=db()->prepare(
        "SELECT DISTINCT l.id,l.id AS location_id,l.region_code,l.region_name,
                l.mmda_code,l.mmda_name,l.mmda_name AS district_name,l.town_name,l.is_capital
         FROM locations l
         LEFT JOIN vendors v ON v.id=?
         LEFT JOIN vendor_town_assignments vta ON vta.vendor_id=v.id AND vta.location_id=l.id AND vta.is_active=1
         WHERE l.is_active=1 AND l.entry_type='town' AND (l.id=v.location_id OR vta.id IS NOT NULL)
         ORDER BY l.town_name,l.region_name,l.mmda_name"
    );
    $statement->execute([$vendorId]);
    return $statement->fetchAll();
}

function vendor_can_manage_town(int $vendorId,int $locationId): bool
{
    foreach (assigned_towns_for_vendor($vendorId) as $town) {
        if ((int)$town['id']===$locationId) return true;
    }
    return false;
}

function current_user_assigned_module_keys(): array
{
    if (current_user_role() === 'vendor') {
        $vendor = current_vendor_profile();
        return $vendor ? assigned_module_keys_for_vendor((int) $vendor['id']) : [];
    }

    $staffId = current_staff_id();

    return $staffId ? assigned_module_keys_for_staff($staffId) : [];
}

function staff_child_menu_definitions(): array
{
    return [
        'marketing_trip_registration'=>['group'=>'Marketing — Trip','title'=>'Trip Registration','description'=>'Register, start, and complete marketing trips.','icon'=>'fa-solid fa-route'],
        'marketing_location_registration'=>['group'=>'Marketing — Trip','title'=>'Location Registration','description'=>'Create, view, and update marketing locations.','icon'=>'fa-solid fa-location-dot'],
        'marketing_customer'=>['group'=>'Marketing — Trip','title'=>'Customer','description'=>'View and edit marketing customers.','icon'=>'fa-solid fa-user-plus'],
        'marketing_sales'=>['group'=>'Marketing — Trip','title'=>'Sales','description'=>'Open POS Sales from the Marketing flow.','icon'=>'fa-solid fa-cart-shopping'],
        'marketing_promo_plug'=>['group'=>'Marketing — Trip','title'=>'Promo Plug','description'=>'Record Promo Plug responses for customers.','icon'=>'fa-solid fa-bullhorn'],
        'marketing_report_trip'=>['group'=>'Marketing — Reports','title'=>'Trip Report','description'=>'View all marketing trips.','icon'=>'fa-solid fa-route'],
        'marketing_report_location'=>['group'=>'Marketing — Reports','title'=>'Location Report','description'=>'View all accessible locations.','icon'=>'fa-solid fa-location-dot'],
        'marketing_report_customer'=>['group'=>'Marketing — Reports','title'=>'Customer Report','description'=>'View all accessible customers.','icon'=>'fa-solid fa-users'],
        'marketing_report_notes'=>['group'=>'Marketing — Reports','title'=>'Notes Report','description'=>'View marketing notes separately.','icon'=>'fa-solid fa-note-sticky'],
        'marketing_report_promo'=>['group'=>'Marketing — Reports','title'=>'Promo Plug Report','description'=>'View Promo Plug records separately.','icon'=>'fa-solid fa-bullhorn'],
        'marketing_report_vendors'=>['group'=>'Marketing — Reports','title'=>'Vendors','description'=>'View and manage the complete vendor directory.','icon'=>'fa-solid fa-store'],
        'pos_shop_sales'=>['group'=>'POS','title'=>'Shop Sales','description'=>'Record direct shop sales.','icon'=>'fa-solid fa-store'],
        'pos_trip_sales'=>['group'=>'POS','title'=>'Trip Sales','description'=>'Record sales connected to marketing activity.','icon'=>'fa-solid fa-route'],
        'pos_promo'=>['group'=>'POS','title'=>'Promo','description'=>'Record promotional POS sales.','icon'=>'fa-solid fa-bullhorn'],
        'pos_transfer'=>['group'=>'POS','title'=>'Transfer','description'=>'Transfer stock to vendors.','icon'=>'fa-solid fa-right-left'],
        'pos_refund'=>['group'=>'POS','title'=>'Refund','description'=>'Open the POS refund workflow.','icon'=>'fa-solid fa-rotate-left'],
        'pos_audit'=>['group'=>'POS','title'=>'Audit','description'=>'Open adjustment audit menus.','icon'=>'fa-solid fa-clipboard-list'],
        'pos_reports'=>['group'=>'POS','title'=>'Reports','description'=>'View POS sales, transfer, refund, and notes reports.','icon'=>'fa-solid fa-chart-column'],
        'admin_vehicle_log'=>['group'=>'Admin','title'=>'Vehicle Log','description'=>'Open fuel, log book, and vehicle movement records.','icon'=>'fa-solid fa-car-side'],
        'admin_reports'=>['group'=>'Admin','title'=>'Reports','description'=>'Open administrative and operational reports.','icon'=>'fa-solid fa-chart-line'],
        'data_vin_search_1'=>['group'=>'Data Management','title'=>'VIN Search 1','description'=>'Use the current VIN Search service.','icon'=>'fa-solid fa-barcode'],
        'data_reports'=>['group'=>'Data Management','title'=>'Reports','description'=>'Review saved VIN searches.','icon'=>'fa-solid fa-chart-column'],
    ];
}

function current_user_has_child_menu_assignments(): bool
{
    foreach(current_user_assigned_module_keys() as $key){if(isset(staff_child_menu_definitions()[$key]))return true;}
    return false;
}

function can_access_menu_item(string $key): bool
{
    if(is_admin_user())return true;
    $personnel=current_vendor_personnel();
    if($personnel){
        $posKeys=['pos_shop_sales','pos_trip_sales','pos_promo','pos_transfer','pos_refund','pos_audit','pos_reports'];
        if(in_array($key,$posKeys,true)){
            if(in_array($key,['pos_shop_sales','pos_trip_sales','pos_promo'],true))return !current_vendor_is_sor()&&(int)$personnel['can_make_sales']===1&&can_access_module('pos');
            if($key==='pos_transfer')return (int)$personnel['can_transfer']===1&&can_access_module('pos');
            if($key==='pos_refund')return (int)$personnel['can_refund']===1&&can_access_module('pos');
            if($key==='pos_audit')return (int)$personnel['can_audit']===1&&can_access_module('pos');
            if($key==='pos_reports')return can_access_module('pos');
        }
        if(current_user_role()!=='staff')return false;
    }
    $assigned=current_user_assigned_module_keys();
    if(current_user_role()==='staff'&&current_user_has_child_menu_assignments())return in_array($key,$assigned,true);
    $legacy=[
        'marketing_trip_registration'=>['sales_trip'],'marketing_location_registration'=>['sales_trip','customer_visit'],'marketing_customer'=>['sales_trip','customer_visit'],
        'marketing_sales'=>['pos'],'marketing_promo_plug'=>['sales_trip','customer_visit'],
        'marketing_report_trip'=>['reports'],'marketing_report_location'=>['reports'],'marketing_report_customer'=>['reports'],'marketing_report_notes'=>['reports'],'marketing_report_promo'=>['reports'],'marketing_report_vendors'=>['reports'],
        'pos_shop_sales'=>['pos'],'pos_trip_sales'=>['pos'],'pos_promo'=>['pos'],'pos_transfer'=>['pos'],'pos_refund'=>['pos'],'pos_audit'=>['pos'],'pos_reports'=>['pos'],
        'admin_vehicle_log'=>['vehicle_log'],'admin_reports'=>['reports'],'data_vin_search_1'=>['vin_search'],'data_reports'=>['vin_search'],
    ];
    if(current_user_role()==='staff'&&in_array($key,['marketing_report_trip','marketing_report_location','marketing_report_customer','marketing_report_notes','marketing_report_promo','marketing_report_vendors','admin_reports'],true))return true;
    foreach($legacy[$key]??[] as $moduleKey){if(in_array($moduleKey,$assigned,true))return true;}
    if(current_user_role()==='vendor'||(current_vendor_personnel()&&current_user_role()!=='staff')){
        $vendorMap=['marketing_location_registration'=>'customer_visit','marketing_customer'=>'vendor_customers','marketing_promo_plug'=>'customer_visit','marketing_report_customer'=>'vendor_reports','marketing_report_notes'=>'vendor_reports','marketing_report_promo'=>'vendor_reports','pos_shop_sales'=>'pos','pos_transfer'=>'pos','pos_refund'=>'pos','pos_reports'=>'pos','data_vin_search_1'=>'vin_search','data_reports'=>'vin_search'];
        return isset($vendorMap[$key])&&in_array($vendorMap[$key],$assigned,true);
    }
    return false;
}

function can_access_module(string $moduleKey): bool
{
    migrate_pos_module_identity();
    $personnel=current_vendor_personnel();
    if($personnel&&current_user_role()!=='staff'&&$moduleKey!=='pos')return false;
    if($moduleKey==='vendor_personnel'){
        if(is_admin_user())return true;
        $vendor=current_vendor_profile();
        return current_user_role()==='vendor'&&$vendor&&(int)$vendor['user_id']===(int)current_user_id();
    }
    if($moduleKey==='pos'&&!is_admin_user()){
        if(current_user_role()==='vendor'){
            $vendor=current_vendor_profile();
            return $vendor&&in_array('pos',assigned_module_keys_for_vendor((int)$vendor['id']),true);
        }
        $personnel=current_vendor_personnel();
        return $personnel&&((int)$personnel['can_make_sales']===1||(int)$personnel['can_sor']===1||(int)$personnel['can_transfer']===1||(int)$personnel['can_refund']===1||(int)$personnel['can_audit']===1||(int)$personnel['can_reports']===1)&&in_array('pos',assigned_module_keys_for_vendor((int)$personnel['vendor_id']),true);
    }
    if(current_user_role()==='staff'&&current_user_has_child_menu_assignments()){
        $childMap=[
            'sales_trip'=>['marketing_trip_registration'],'customer_visit'=>['marketing_location_registration','marketing_customer','marketing_promo_plug'],
            'pos'=>['marketing_sales','pos_shop_sales','pos_trip_sales','pos_promo','pos_transfer','pos_refund','pos_audit','pos_reports'],
            'reports'=>['marketing_report_trip','marketing_report_location','marketing_report_customer','marketing_report_notes','marketing_report_promo','marketing_report_vendors','admin_reports'],
            'vehicle_log'=>['admin_vehicle_log'],'vin_search'=>['data_vin_search_1','data_reports'],
        ];
        if(isset($childMap[$moduleKey])){foreach($childMap[$moduleKey] as $childKey){if(can_access_menu_item($childKey))return true;}return false;}
    }
    if ($moduleKey === 'create_customer') {
        return is_admin_user();
    }
    if ($moduleKey === 'registration_records') {
        return current_user_id() !== null;
    }

    if (current_user_role() === 'vendor' && $moduleKey === 'customer_visit') {
        $vendor = current_vendor_profile();
        // Assignment controls entry to the customer/location workspace. An
        // active trip must not be required here because vendors also use this
        // workspace to start Addendums outside a trip. Individual trip and
        // customer operations perform their own ownership checks.
        return $vendor
            && in_array($moduleKey, assigned_module_keys_for_vendor((int)$vendor['id']), true);
    }

    if (current_user_role() === 'vendor' && in_array($moduleKey, ['customer_followup','vendor_customers','vendor_reports','pos','vin_search'], true)) {
        $vendor = current_vendor_profile();
        if (!$vendor || !in_array($moduleKey, assigned_module_keys_for_vendor((int)$vendor['id']), true)) return false;
        if (in_array($moduleKey, ['vendor_customers','vendor_reports'], true)) return (int)($vendor['location_id'] ?? 0) > 0;
        return true;
    }

    if (current_user_role() === 'vendor' && $moduleKey === 'sales_trip') {
        return false;
    }

    if (current_user_role() === 'staff' && $moduleKey === 'sales_trip') {
        ensure_destination_visit_schema();
        $staffId=current_staff_id();
        if($staffId){$statement=db()->prepare("SELECT COUNT(*) FROM sales_trips st INNER JOIN sales_trip_staff_assignments stsa ON stsa.sales_trip_id=st.id WHERE st.status='in_progress' AND stsa.staff_id=?");$statement->execute([$staffId]);if((int)$statement->fetchColumn()>0)return true;}
    }

    if (current_user_role() === 'staff' && $moduleKey === 'followup') {
        ensure_destination_visit_schema();
        $staffId=current_staff_id();
        if($staffId){$statement=db()->prepare("SELECT COUNT(*) FROM sales_trips st INNER JOIN sales_trip_staff_assignments stsa ON stsa.sales_trip_id=st.id WHERE st.status='in_progress' AND stsa.staff_id=?");$statement->execute([$staffId]);if((int)$statement->fetchColumn()>0)return true;}
    }

    if (current_user_role() === 'staff' && $moduleKey === 'attendance') {
        return current_staff_id() !== null;
    }

    if (is_admin_user()) {
        return true;
    }

    if (in_array($moduleKey, ['customer_visit', 'customer_followup'], true)) {
        return can_access_module('sales_trip');
    }

    if ($moduleKey === 'admin') {
        return false;
    }

    if (in_array($moduleKey, ['reports', 'activity_log'], true)) {
        return current_user_role() === 'staff';
    }

    if (current_user_role() !== 'staff') {
        return false;
    }

    return in_array($moduleKey, current_user_assigned_module_keys(), true);
}

function visible_application_modules(): array
{
    $vendorOnlyModules = ['vendor_customers', 'vendor_reports', 'vendor_personnel'];
    return array_filter(
        application_modules(),
        static function (array $module, string $moduleKey) use ($vendorOnlyModules): bool {
            if (current_user_role() !== 'vendor' && in_array($moduleKey, $vendorOnlyModules, true)) {
                return false;
            }
            return $moduleKey !== 'feedback' && can_access_module($moduleKey);
        },
        ARRAY_FILTER_USE_BOTH
    );
}

function can_access_registration_trip(int $tripId): bool
{
    if ($tripId <= 0 || current_user_id() === null) {
        return false;
    }
    if (is_admin_user()) {
        return true;
    }

    ensure_sales_trip_assignment_schema();
    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM sales_trips st
         WHERE st.id=? AND (
            st.recorded_by_user_id=?
            OR st.vendor_id=?
            OR EXISTS (
                SELECT 1 FROM sales_trip_staff_assignments a
                WHERE a.sales_trip_id=st.id AND a.staff_id=?
            )
            OR EXISTS (
                SELECT 1 FROM sales_trip_vendor_assignments a
                WHERE a.sales_trip_id=st.id AND a.vendor_id=?
            )
         )'
    );
    $statement->execute([
        $tripId,
        current_user_id(),
        (int)(current_vendor_profile()['id'] ?? 0),
        current_staff_id() ?: 0,
        (int)(current_vendor_profile()['id'] ?? 0),
    ]);
    return (int)$statement->fetchColumn() > 0;
}

function require_module_access(string $moduleKey): void
{
    require_auth();

    if (can_access_module($moduleKey)) {
        return;
    }

    http_response_code(403);
    $pageTitle = 'Access Denied';
    $breadcrumbs = [
        ['label' => 'Home', 'url' => app_url('index.php')],
        ['label' => 'Access Denied'],
    ];
    require __DIR__ . '/../includes/header.php';
    echo '<section class="content-panel" aria-labelledby="page-title">';
    echo '<div class="content-panel__icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></div>';
    echo '<h1 id="page-title">Access Denied</h1>';
    echo '<p class="empty-state">This menu has not been assigned to your account.</p>';
    echo '</section>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

function redirect_if_authenticated(): void
{
    if (!is_logged_in()) {
        return;
    }

    header('Location: ' . app_url(must_change_password() ? 'change-password.php' : 'index.php'));
    exit;
}

function attempt_login(string $identifier, string $password): bool
{
    ensure_user_phone_schema();
    sync_staff_login_phones();
    $identifier=trim($identifier);
    $phone=normalize_phone_number($identifier);
    $statement = db()->prepare(
        'SELECT id, full_name, email, phone, password_hash, role, is_active, profile_image, force_password_change
         FROM users WHERE LOWER(email)=LOWER(?) OR phone=? LIMIT 1'
    );
    $statement->execute([$identifier,$phone]);
    $user = $statement->fetch();

    if (!$user || !(int) $user['is_active'] || !password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['full_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['profile_image'] = (string) ($user['profile_image'] ?? '');
    $_SESSION['force_password_change'] = (int) ($user['force_password_change'] ?? 0);

    return true;
}

function must_change_password(): bool
{
    return (int) ($_SESSION['force_password_change'] ?? 0) === 1;
}

function change_current_user_password(string $newPassword): void
{
    $userId = current_user_id();

    if (!$userId) {
        return;
    }

    $statement = db()->prepare('UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?');
    $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    ensure_remember_login_schema();
    db()->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([$userId]);
    clear_remember_login_cookie();
    $_SESSION['force_password_change'] = 0;
}

function verify_current_user_password(string $password): bool
{
    $userId = current_user_id();
    if (!$userId || $password === '') {
        return false;
    }

    $statement = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);
    $passwordHash = $statement->fetchColumn();

    return is_string($passwordHash) && password_verify($password, $passwordHash);
}

function ensure_password_reset_schema(): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }
    run_app_migration('20260806_create_password_reset_tokens', function (): void {

    db()->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_password_reset_user (user_id),
            KEY idx_password_reset_expiry (expires_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
    });
    $schemaReady = true;
}

function request_password_reset(string $email): void
{
    ensure_password_reset_schema();
    $statement = db()->prepare('SELECT id, full_name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $statement->execute([normalize_email_address($email)]);
    $user = $statement->fetch();
    if (!$user) {
        password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        return;
    }

    $userId = (int) $user['id'];
    $recent = db()->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)');
    $recent->execute([$userId]);
    if ((int) $recent->fetchColumn() > 0) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    db()->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
        ->execute([$userId, hash('sha256', $token)]);

    $resetUrl = absolute_app_url('reset-password.php?token=' . rawurlencode($token));
    $subject = APP_NAME . ' password reset';
    $message = 'Hello ' . (string) $user['full_name'] . ",\n\nUse the link below to reset your password. It expires in one hour and can only be used once.\n\n"
        . $resetUrl . "\n\nIf you did not request this, you can ignore this email.";
    $from = getenv('APP_MAIL_FROM') ?: 'no-reply@localhost';
    $headers = 'From: ' . APP_NAME . ' <' . $from . ">\r\nContent-Type: text/plain; charset=UTF-8";
    if (!@mail((string) $user['email'], $subject, $message, $headers)) {
        db()->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
        error_log('Password reset email could not be sent for user ID ' . $userId);
        throw new RuntimeException('Password reset email delivery failed.');
    }
}

function password_reset_is_valid(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    ensure_password_reset_schema();
    $statement = db()->prepare('SELECT COUNT(*) FROM password_reset_tokens INNER JOIN users ON users.id = password_reset_tokens.user_id WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() AND users.is_active = 1');
    $statement->execute([hash('sha256', $token)]);
    return (int) $statement->fetchColumn() === 1;
}

function reset_password_with_token(string $token, string $newPassword): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    ensure_password_reset_schema();
    ensure_remember_login_schema();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT password_reset_tokens.user_id FROM password_reset_tokens INNER JOIN users ON users.id = password_reset_tokens.user_id WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() AND users.is_active = 1 LIMIT 1 FOR UPDATE');
        $statement->execute([hash('sha256', $token)]);
        $reset = $statement->fetch();
        if (!$reset) {
            $pdo->rollBack();
            return false;
        }
        $userId = (int) $reset['user_id'];
        $pdo->prepare('UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([$userId]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function refresh_current_user(): void
{
    $userId = current_user_id();

    if (!$userId) {
        return;
    }

    $statement = db()->prepare('SELECT full_name, email, role, profile_image, force_password_change FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);
    $user = $statement->fetch();

    if (!$user) {
        logout_user();
        header('Location: ' . app_url('login.php'));
        exit;
    }

    $_SESSION['user_name'] = (string) $user['full_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['profile_image'] = (string) ($user['profile_image'] ?? '');
    $_SESSION['force_password_change'] = (int) ($user['force_password_change'] ?? 0);
}

function logout_user(): void
{
    $rememberCookie=(string)($_COOKIE[REMEMBER_LOGIN_COOKIE]??'');
    if($rememberCookie!==''){
        $selector=explode('.',$rememberCookie,2)[0]??'';
        if($selector!==''&&ctype_xdigit($selector)){
            try{ensure_remember_login_schema();db()->prepare('DELETE FROM user_remember_tokens WHERE selector=?')->execute([$selector]);}catch(Throwable $exception){}
        }
    }
    clear_remember_login_cookie();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verify_csrf_token(string $token): bool
{
    return hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
}

function normalize_ghana_card_no(string $ghanaCardNo): string
{
    $value = strtoupper(trim($ghanaCardNo));
    $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';

    if (preg_match('/^GHA(\d{9})(\d)$/', $value, $matches) === 1) {
        return 'GHA-' . $matches[1] . '-' . $matches[2];
    }

    return strtoupper(trim($ghanaCardNo));
}

function is_valid_ghana_card_no(string $ghanaCardNo): bool
{
    return preg_match('/^GHA-\d{9}-\d$/', $ghanaCardNo) === 1;
}

function normalize_email_address(string $email): string
{
    return strtolower(trim($email));
}

function is_valid_email_address(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function normalize_phone_number(string $phone): string
{
    $value = trim($phone);
    $digits = preg_replace('/\D/', '', $value) ?? '';

    if (str_starts_with($digits, '233') && strlen($digits) === 12) {
        return '0' . substr($digits, 3);
    }

    if (strlen($digits) === 9 && preg_match('/^[235]\d{8}$/', $digits) === 1) {
        return '0' . $digits;
    }

    if (strlen($digits) === 10) {
        return $digits;
    }

    return $value;
}

function is_valid_phone_number(string $phone): bool
{
    return preg_match('/^0[235]\d{8}$/', $phone) === 1;
}

function registered_customer_for_phone(string $phone, int $excludeVisitId = 0): ?array
{
    $normalized = normalize_phone_number($phone);
    if (!is_valid_phone_number($normalized)) return null;

    $lastNine = substr($normalized, -9);
    $sql = "SELECT id,company_name,owner_name,phone,other_phone
            FROM destination_visits
            WHERE visit_type='registration'
              AND (RIGHT(REGEXP_REPLACE(COALESCE(phone,''),'[^0-9]',''),9)=?
                   OR RIGHT(REGEXP_REPLACE(COALESCE(other_phone,''),'[^0-9]',''),9)=?)";
    $params = [$lastNine, $lastNine];
    if ($excludeVisitId > 0) { $sql .= ' AND id<>?'; $params[] = $excludeVisitId; }
    $sql .= ' ORDER BY id LIMIT 1';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetch() ?: null;
}

function next_staff_ref_no(): string
{
    $statement = db()->query(
        "SELECT MAX(CAST(SUBSTRING(staff_code, 5) AS UNSIGNED))
         FROM staff
         WHERE staff_code REGEXP '^STF-[0-9]+$'"
    );
    $nextId = (int) ($statement->fetchColumn() ?: 0) + 1;

    do {
        $staffRefNo = 'STF-' . str_pad((string) $nextId, 4, '0', STR_PAD_LEFT);
        $checkStatement = db()->prepare('SELECT COUNT(*) FROM staff WHERE staff_code = ?');
        $checkStatement->execute([$staffRefNo]);

        if ((int) $checkStatement->fetchColumn() === 0) {
            return $staffRefNo;
        }

        $nextId++;
    } while (true);
}

function next_sales_trip_ref_no(): string
{
    return next_project_reference('trip');
}

function next_project_reference(string $type): string
{
    $definitions = [
        'trip' => ['sequence' => 'sales_trip', 'table' => 'sales_trips', 'column' => 'trip_code', 'prefix' => 'TRIP', 'padding' => 5],
        'place' => ['sequence' => 'place', 'table' => 'business_locations', 'column' => 'bus_loc_ref', 'prefix' => 'LOC'],
        'customer' => ['sequence' => 'customer', 'table' => 'customers', 'column' => 'customer_ref', 'prefix' => 'CS'],
        'place_visit' => ['sequence' => 'place_session', 'table' => 'place_visit_sessions', 'column' => 'session_ref', 'prefix' => 'LV'],
        'visit' => ['sequence' => 'visit', 'table' => 'visits', 'column' => 'visit_ref', 'prefix' => 'VS'],
        'sale' => ['sequence' => 'customer_promo_plug', 'table' => 'customer_promo_plugs', 'column' => 'id', 'prefix' => 'PP'],
        'pos_sale' => ['sequence' => 'pos_sale', 'table' => 'pos_sales', 'column' => 'sale_ref', 'prefix' => 'POS'],
        'sor_sale' => ['sequence' => 'sor_sale', 'table' => 'pos_sales', 'column' => 'sale_ref', 'prefix' => 'SOR'],
        'pos_transfer' => ['sequence' => 'pos_transfer', 'table' => 'pos_transfers', 'column' => 'transfer_ref', 'prefix' => 'TRF'],
        'visit_note' => ['sequence' => 'visit_note', 'table' => 'visit_notes', 'column' => 'note_ref', 'prefix' => 'NT'],
        'visit_draft' => ['sequence' => 'visit_draft', 'table' => 'customer_visit_drafts', 'column' => 'draft_ref', 'prefix' => 'DR'],
    ];

    if (!isset($definitions[$type])) {
        throw new InvalidArgumentException('Unknown reference type: ' . $type);
    }

    $definition = $definitions[$type];
    $connection = db();
    $ownsTransaction = !$connection->inTransaction();

    try {
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        $statement = $connection->prepare(
            'INSERT IGNORE INTO reference_sequences(sequence_key,next_value) VALUES(?,0)'
        );
        $statement->execute([$definition['sequence']]);

        $statement = $connection->prepare(
            'SELECT next_value FROM reference_sequences WHERE sequence_key=? FOR UPDATE'
        );
        $statement->execute([$definition['sequence']]);
        $sequenceValue = (int)($statement->fetchColumn() ?: 0);

        $table = $definition['table'];
        $column = $definition['column'];
        $storedMaximum = (int)($connection->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX($column, '-', -1) AS UNSIGNED))
             FROM $table
             WHERE $column REGEXP '-[0-9]+$'"
        )->fetchColumn() ?: 0);

        $nextValue = max($sequenceValue, $storedMaximum) + 1;
        $statement = $connection->prepare(
            'UPDATE reference_sequences SET next_value=? WHERE sequence_key=?'
        );
        $statement->execute([$nextValue, $definition['sequence']]);

        if ($ownsTransaction) {
            $connection->commit();
        }

        $padding = (int)($definition['padding'] ?? 3);
        return $definition['prefix'] . '-' . str_pad((string)$nextValue, $padding, '0', STR_PAD_LEFT);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function current_display_date(): string
{
    return date('d F, Y');
}

function render_breadcrumb(array $items): void
{
    echo '<nav class="breadcrumb" aria-label="Breadcrumb">';
    $lastIndex = count($items) - 1;

    foreach ($items as $index => $item) {
        $label = e((string) $item['label']);
        $url = $item['url'] ?? null;

        if ($index > 0) {
            echo '<span class="breadcrumb__separator" aria-hidden="true">/</span>';
        }

        if ($url && $index !== $lastIndex) {
            echo '<a href="' . e((string) $url) . '">' . $label . '</a>';
        } else {
            echo '<span aria-current="page">' . $label . '</span>';
        }
    }

    echo '</nav>';
}

function page_meta(string $title, array $breadcrumbs = []): array
{
    return [
        'title' => $title,
        'breadcrumbs' => $breadcrumbs,
    ];
}
