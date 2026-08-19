<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('admin');
ensure_destination_visit_schema();
ensure_vendor_type_schema();
ensure_user_phone_schema();
$vendorProvisioning=provision_unlinked_vendor_accounts();

$pageTitle = 'Vendor Setup';
$breadcrumbs = [['label' => 'Home', 'url' => app_url('index.php')], ['label' => 'Setup', 'url' => app_url('setup.php')], ['label' => 'Vendor Setup']];
$internalBackUrl=requested_return_url(app_url('marketing.php?view=setup'));
$message = $error = '';
if ((string)($_GET['updated'] ?? '') === '1') $message = 'Vendor and login account updated successfully.';
elseif ((string)($_GET['created'] ?? '') === '1') $message = 'Vendor account created. Default login password: ' . VENDOR_DEFAULT_PASSWORD;
if((int)$vendorProvisioning['created']>0)$message=number_format((int)$vendorProvisioning['created']).' imported vendor login account(s) created. Default password: '.VENDOR_DEFAULT_PASSWORD;
$editId = max(0, (int) ($_GET['edit'] ?? 0));
$form = ['vendor_name' => '', 'contact_name' => '', 'phone' => '', 'other_phone' => '', 'email' => '', 'location_id' => '', 'area' => '', 'vendor_type' => 'regular', 'notes' => '', 'status' => '1', 'profile_image' => '', 'user_id' => ''];

function save_vendor_picture(): ?string
{
    if (!isset($_FILES['profile_pic']) || !is_array($_FILES['profile_pic']) || ($_FILES['profile_pic']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $upload = $_FILES['profile_pic'];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The vendor picture could not be uploaded.');
    if (($upload['size'] ?? 0) > APP_IMAGE_UPLOAD_MAX_BYTES) throw new RuntimeException('Choose a vendor picture smaller than ' . APP_IMAGE_UPLOAD_MAX_LABEL . '.');
    $imageInfo = @getimagesize((string) $upload['tmp_name']);
    $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/heic'=>'heic', 'image/heif'=>'heif'];
    $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($types[$mime])) $mime = ['heic'=>'image/heic','heif'=>'image/heif'][$extension] ?? $mime;
    if (!isset($types[$mime])) throw new RuntimeException('Upload a JPG, PNG, WEBP, GIF, HEIC, or HEIF image.');
    $directory = __DIR__ . '/../assets/uploads/vendors';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('The vendor upload folder could not be created.');
    $compressible = in_array($mime, ['image/jpeg','image/png','image/webp'], true);
    $name = 'vendor-' . bin2hex(random_bytes(10)) . '.' . ($compressible ? 'jpg' : $types[$mime]);
    $target = $directory . '/' . $name;
    if ($compressible && compress_uploaded_image((string)$upload['tmp_name'], $mime, $target)) return 'assets/uploads/vendors/' . $name;
    if ($compressible) { $name = 'vendor-' . bin2hex(random_bytes(10)) . '.' . $types[$mime]; $target = $directory . '/' . $name; }
    if (!move_uploaded_file((string) $upload['tmp_name'], $target)) throw new RuntimeException('The vendor picture could not be saved.');
    return 'assets/uploads/vendors/' . $name;
}

if ($editId) {
    $statement = db()->prepare('SELECT * FROM vendors WHERE id=? LIMIT 1');
    $statement->execute([$editId]);
    if ($vendor = $statement->fetch()) {
        foreach (array_keys($form) as $key) $form[$key] = (string) ($vendor[$key] ?? '');
        $form['status'] = (string) (int) $vendor['is_active'];
    } else $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['form_action'] ?? 'save');
    $postedId = max(0, (int) ($_POST['vendor_id'] ?? 0));
    foreach (['vendor_name','contact_name','phone','other_phone','email','location_id','area','vendor_type','notes','status'] as $key) $form[$key] = trim((string) ($_POST[$key] ?? ''));
    if (!in_array($form['vendor_type'], ['sor','regular'], true)) $form['vendor_type'] = 'regular';

    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete') {
        $statement = db()->prepare('SELECT user_id FROM vendors WHERE id=? LIMIT 1');
        $statement->execute([$postedId]);
        $linkedUserId = (int) ($statement->fetchColumn() ?: 0);
        db()->beginTransaction();
        try {
            db()->prepare('DELETE FROM vendor_customers WHERE vendor_id=?')->execute([$postedId]);
            ensure_vendor_module_assignments_schema();
            db()->prepare('DELETE FROM vendor_module_assignments WHERE vendor_id=?')->execute([$postedId]);
            ensure_vendor_town_assignments_schema();
            db()->prepare('DELETE FROM vendor_town_assignments WHERE vendor_id=?')->execute([$postedId]);
            db()->prepare('DELETE FROM vendors WHERE id=?')->execute([$postedId]);
            if ($linkedUserId) db()->prepare("DELETE FROM users WHERE id=? AND role='vendor'")->execute([$linkedUserId]);
            db()->commit();
            $message = 'Vendor and linked account deleted successfully.';
            $editId = 0;
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $error = 'The vendor account could not be deleted.';
        }
    } elseif ($form['vendor_name'] === '') {
        $error = 'Vendor name is required.';
    } elseif ($form['email'] !== '' && !is_valid_email_address($form['email'])) {
        $error = 'Enter a valid email address or leave the email field empty.';
    } elseif ($form['phone'] !== '' && !is_valid_phone_number($form['phone'])) {
        $error = 'Enter a valid Ghana phone number.';
    } elseif ($form['email'] === '' && $form['phone'] === '') {
        $error = 'Enter a phone number or email address for vendor login.';
    } elseif (!location_by_id((int)$form['location_id'])) {
        $error = 'Select a valid active vendor location.';
    } else {
        $location=location_by_id((int)$form['location_id']);
    }

    if ($error === '' && $action !== 'delete') {
        try {
            $picture = save_vendor_picture();
            $existingStatement = db()->prepare('SELECT user_id,profile_image FROM vendors WHERE id=? LIMIT 1');
            $existingStatement->execute([$postedId]);
            $existing = $existingStatement->fetch() ?: [];
            $linkedUserId = (int) ($existing['user_id'] ?? 0);
            $profileImage = $picture ?: ((string) ($existing['profile_image'] ?? '') ?: null);
            $accountName = $form['contact_name'] ?: $form['vendor_name'];
            $active = $form['status'] === '0' ? 0 : 1;
            $normalizedPhone = normalize_phone_number($form['phone']) ?: null;
            $vendorEmail = $form['email'] !== '' ? strtolower($form['email']) : null;
            $loginEmail = $vendorEmail ?? ('vendor.' . $normalizedPhone . '@phone.local');

            db()->beginTransaction();
            if ($linkedUserId) {
                $userSql = "UPDATE users SET full_name=?,email=?,phone=?,role='vendor',is_active=?";
                $userParams = [$accountName, $loginEmail, $normalizedPhone, $active];
                if ($picture) { $userSql .= ',profile_image=?'; $userParams[] = $profileImage; }
                $userSql .= ' WHERE id=?'; $userParams[] = $linkedUserId;
                db()->prepare($userSql)->execute($userParams);
            } else {
                $statement = db()->prepare("INSERT INTO users (full_name,email,phone,password_hash,profile_image,role,force_password_change,is_active) VALUES (?,?,?,?,?, 'vendor',1,?)");
                $statement->execute([$accountName, $loginEmail, $normalizedPhone, password_hash(VENDOR_DEFAULT_PASSWORD, PASSWORD_DEFAULT), $profileImage, $active]);
                $linkedUserId = (int) db()->lastInsertId();
            }

            $params = [$linkedUserId,$form['vendor_name'],$form['contact_name']?:null,$normalizedPhone,normalize_phone_number($form['other_phone'])?:null,$vendorEmail,$profileImage,(int)$form['location_id'],$form['area']?:null,$form['vendor_type'],$form['notes']?:null,$active];
            if ($postedId) {
                $params[] = $postedId;
                db()->prepare('UPDATE vendors SET user_id=?,vendor_name=?,contact_name=?,phone=?,other_phone=?,email=?,profile_image=?,location_id=?,area=?,vendor_type=?,notes=?,is_active=? WHERE id=?')->execute($params);
                $message = 'Vendor and login account updated successfully.';
            } else {
                $params[] = current_user_id();
                db()->prepare('INSERT INTO vendors (user_id,vendor_name,contact_name,phone,other_phone,email,profile_image,location_id,area,vendor_type,notes,is_active,created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($params);
                $message = 'Vendor account created. Default login password: ' . VENDOR_DEFAULT_PASSWORD;
            }
            db()->commit();
            header('Location: '.($postedId ? $internalBackUrl : app_url('vendor-setup.php?created=1')));
            exit;
        } catch (PDOException $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $error = $exception->getCode() === '23000' ? 'That vendor phone number or email is already used by another account.' : 'The vendor account could not be saved.';
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            $error = $exception->getMessage();
        }
    }
}

$locations=active_locations();
$locationRegions=[];foreach($locations as $location){$key=(string)($location['region_code']?:$location['region_name']);$locationRegions[$key]=(string)$location['region_name'];}asort($locationRegions);
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">Setup</span><h1>Vendor Setup</h1><p>Create vendors with linked login accounts and system locations.</p></div><div class="management-icon"><i class="fa-solid fa-truck-field"></i></div></div>
<?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
<form class="record-form mobile-line-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="save"><input type="hidden" name="vendor_id" value="<?=$editId?>"><div class="form-grid">
<div class="form-field"><label for="profile_pic">Vendor picture</label><input id="profile_pic" name="profile_pic" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif" data-photo-source-choice></div>
<?php foreach(['vendor_name'=>'Vendor name','contact_name'=>'Contact name','phone'=>'Phone','other_phone'=>'Other phone','email'=>'Login email (optional)'] as $key=>$label):?><div class="form-field"><label for="<?=$key?>"><?=$label?></label><input id="<?=$key?>" name="<?=$key?>" type="<?=$key==='email'?'email':'text'?>" value="<?=e($form[$key])?>" <?=$key==='vendor_name'?'required':''?> <?=$key==='email'?'data-email-input':''?> <?=$key==='phone'||$key==='other_phone'?'data-phone-input':''?>></div><?php endforeach;?>
<?php $selectedLocationRegion='';foreach($locations as $location){if($form['location_id']===(string)$location['id']){$selectedLocationRegion=(string)($location['region_code']?:$location['region_name']);break;}} ?>
<div class="form-field"><label for="vendor_region">Region</label><select id="vendor_region" data-location-region-select required><option value="">Select region</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e((string)$regionKey)?>" <?=$selectedLocationRegion===(string)$regionKey?'selected':''?>><?=e($regionName)?></option><?php endforeach;?></select></div>
<div class="form-field"><label for="location_id">Town</label><select id="location_id" name="location_id" data-location-town-select required><option value="">Select town</option><?php foreach($locations as $location):?><option value="<?=(int)$location['id']?>" data-region-key="<?=e((string)($location['region_code']?:$location['region_name']))?>" data-mmda-name="<?=e((string)$location['mmda_name'])?>" <?=$form['location_id']===(string)$location['id']?'selected':''?>><?=e((string)$location['town_name'])?><?= (int)$location['is_capital']===1?' *':'' ?></option><?php endforeach;?></select><small data-location-mmda-output></small></div>
<div class="form-field"><label for="area">Area</label><input id="area" name="area" type="text" value="<?=e($form['area'])?>" placeholder="Enter area"></div>
<div class="form-field"><label for="vendor_type">Vendor Type</label><select id="vendor_type" name="vendor_type" required><option value="regular" <?=$form['vendor_type']==='regular'?'selected':''?>>Regular</option><option value="sor" <?=$form['vendor_type']==='sor'?'selected':''?>>SoR</option></select></div>
<div class="form-field"><label for="status">Status</label><select id="status" name="status"><option value="1" <?=$form['status']==='1'?'selected':''?>>Active</option><option value="0" <?=$form['status']==='0'?'selected':''?>>Inactive</option></select></div><div class="form-field form-field--wide"><label for="notes">Notes</label><textarea id="notes" name="notes"><?=e($form['notes'])?></textarea></div></div><div class="form-actions"><button class="login-button"><i class="fa-solid fa-floppy-disk"></i> <?=$editId?'Update vendor':'Create vendor account'?></button><?php if($editId):?><a class="secondary-button" href="<?=e($internalBackUrl)?>">Cancel</a><?php endif;?></div></form></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
