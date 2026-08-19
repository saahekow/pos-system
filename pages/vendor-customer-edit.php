<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('vendor_customers');

$vendor = current_vendor_profile();
if (!$vendor) { http_response_code(403); exit('A valid vendor account is required.'); }
$requestedReturnTo=trim((string)($_GET['return_to']??$_POST['return_to']??''));
$returnParts=$requestedReturnTo!==''?parse_url($requestedReturnTo):false;
$allowedReturnPaths=array_map(static fn(string $url):string=>(string)(parse_url($url,PHP_URL_PATH)?:$url),[app_url('registration-records.php'),app_url('customers.php'),app_url('vendor-reports.php')]);
$returnTo=is_array($returnParts)&&!isset($returnParts['scheme'],$returnParts['host'])&&in_array((string)($returnParts['path']??''),$allowedReturnPaths,true)?$requestedReturnTo:app_url('vendor-reports.php?report=customers&mode=lookup');
$customerId = max(0, (int)($_GET['id'] ?? $_POST['customer_id'] ?? 0));
$statement = db()->prepare('SELECT * FROM vendor_customers WHERE id=? AND vendor_id=? LIMIT 1');
$statement->execute([$customerId, (int)$vendor['id']]);
$customer = $statement->fetch();
if (!$customer) { http_response_code(404); exit('Customer not found.'); }

$managedTowns = assigned_towns_for_vendor((int)$vendor['id']);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action']??'save') === 'delete') {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) $error = 'Your session expired. Please try again.';
    else {
        db()->beginTransaction();
        try {
            db()->prepare("DELETE FROM customer_pos_sale_vins WHERE customer_source='vendor_customer' AND record_id=?")->execute([$customerId]);
            db()->prepare('DELETE FROM vendor_customers WHERE id=? AND vendor_id=?')->execute([$customerId,(int)$vendor['id']]);
            db()->commit();
            header('Location: '.$returnTo.(str_contains($returnTo,'?')?'&':'?').'deleted=1'); exit;
        } catch(Throwable $exception) { if(db()->inTransaction())db()->rollBack();$error='The customer could not be deleted.'; }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $originalPhone=normalize_phone_number((string)($customer['phone']??''));
    foreach (['customer_name','contact_name','phone','other_phone','area','notes'] as $key) $customer[$key] = trim((string)($_POST[$key] ?? ''));
    $customer['location_id'] = max(0, (int)($_POST['location_id'] ?? 0));
    $customer['phone'] = normalize_phone_number((string)$customer['phone']);
    $customer['other_phone'] = normalize_phone_number((string)$customer['other_phone']);
    $selectedTown = null;
    foreach ($managedTowns as $town) if ((int)$town['id'] === (int)$customer['location_id']) { $selectedTown = $town; break; }
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) $error = 'Your session expired. Please try again.';
    elseif ($customer['customer_name'] === '') $error = 'Enter the customer or company name.';
    elseif ($customer['phone'] === '' || !is_valid_phone_number((string)$customer['phone'])) $error = 'Enter a valid customer phone number.';
    elseif ($customer['other_phone'] !== '' && !is_valid_phone_number((string)$customer['other_phone'])) $error = 'Enter a valid other phone number.';
    elseif (!$selectedTown) $error = 'Select one of your assigned towns.';
    else {
        $duplicateFound=false;
        if($customer['phone']!==$originalPhone){$duplicate = db()->prepare('SELECT id FROM vendor_customers WHERE vendor_id=? AND phone=? AND id<>? LIMIT 1');$duplicate->execute([(int)$vendor['id'], $customer['phone'], $customerId]);$duplicateFound=(bool)$duplicate->fetchColumn();}
        if ($duplicateFound) $error = 'This phone number is already used by another customer.';
        else {
            $update = db()->prepare('UPDATE vendor_customers SET customer_name=?,contact_name=?,phone=?,other_phone=?,location_id=?,town_id=?,area=?,notes=? WHERE id=? AND vendor_id=?');
            $update->execute([$customer['customer_name'],$customer['contact_name']?:null,$customer['phone'],$customer['other_phone']?:null,(int)$selectedTown['id'],(int)$selectedTown['id'],$customer['area']?:null,$customer['notes']?:null,$customerId,(int)$vendor['id']]);
            header('Location: '.$returnTo.(str_contains($returnTo,'?')?'&':'?').'updated=1'); exit;
        }
    }
}

$regions=[];foreach($managedTowns as $town){$key=(string)($town['region_code']??$town['region_name']??'');$regions[$key]=(string)($town['region_name']??'');}asort($regions);
$selectedRegion='';$selectedTownName='';foreach($managedTowns as $town){if((int)$customer['location_id']===(int)$town['id']){$selectedRegion=(string)($town['region_code']??$town['region_name']??'');$selectedTownName=(string)($town['town_name']??'');break;}}
$viewMode=$_SERVER['REQUEST_METHOD']==='GET'&&(string)($_GET['edit']??'')!=='1';
$pageTitle = $viewMode?'Customer Details':'Edit Customer';
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'Customers','url'=>$returnTo],['label'=>$pageTitle]];
$internalBackUrl=$returnTo;
require __DIR__ . '/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading"><div><span class="section-kicker">Vendor Workspace</span><h1><?=e($viewMode?(string)$customer['customer_name']:'Edit Customer')?></h1><p><?=$viewMode?'View the complete saved customer details.':'Update this customer within your assigned towns.'?></p></div><div class="table-actions<?=$viewMode?' customer-detail-action-bar':''?>"><?php if($viewMode):?><?php if(empty($customer['normalized_customer_id'])):?><a class="secondary-button" href="<?=e(app_url('legacy-customer-location.php?source=vendor_customer&id='.$customerId.'&return_to='.rawurlencode($returnTo)))?>"><i class="fa-solid fa-location-dot"></i><span>Assign Location</span></a><?php endif;?><a class="action-button" href="<?=e(app_url('vendor-customer-edit.php?id='.$customerId.'&edit=1&return_to='.rawurlencode($returnTo)))?>"><i class="fa-solid fa-pen"></i><span>Edit</span></a><form method="post" data-confirm-title="Delete customer?" data-confirm-message="This permanently deletes this customer and their recorded VIN details."><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="customer_id" value="<?=$customerId?>"><input type="hidden" name="return_to" value="<?=e($returnTo)?>"><button class="action-button is-danger" type="submit" name="form_action" value="delete"><i class="fa-solid fa-trash"></i><span>Delete</span></button></form><?php endif;?><div class="management-icon"><i class="fa-solid <?=$viewMode?'fa-user':'fa-user-pen'?>"></i></div></div></div>
    <?php if($error):?><div class="profile-message is-error" role="alert"><?=e($error)?></div><?php endif;?>
    <?php if($viewMode):?><div class="detail-grid detail-grid--plain retailer-profile-grid"><dl><?php foreach(['customer_name'=>'Customer / Company','contact_name'=>'Contact Name','phone'=>'Phone','other_phone'=>'Other Phone'] as $key=>$label):?><div><dt><?=e($label)?></dt><dd><?=e((string)($customer[$key]??''))?></dd></div><?php endforeach;?><div><dt>Region</dt><dd><?=e((string)($regions[$selectedRegion]??''))?></dd></div><div><dt>Town</dt><dd><?=e($selectedTownName)?></dd></div><div><dt>Area</dt><dd><?=e((string)($customer['area']??''))?></dd></div><div class="detail-item--wide"><dt>Notes</dt><dd><?=nl2br(e((string)($customer['notes']??'')))?></dd></div><div><dt>Date Added</dt><dd><?=e(!empty($customer['created_at'])?date('d M Y',strtotime((string)$customer['created_at'])):'')?></dd></div></dl></div><?php else:?>
    <form class="record-form mobile-line-form" method="post">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="customer_id" value="<?=$customerId?>"><input type="hidden" name="return_to" value="<?=e($returnTo)?>">
        <div class="form-grid">
            <div class="form-field"><label for="customer_name">Customer / Company</label><input id="customer_name" name="customer_name" value="<?=e((string)$customer['customer_name'])?>" required></div>
            <div class="form-field"><label for="contact_name">Contact Name</label><input id="contact_name" name="contact_name" value="<?=e((string)($customer['contact_name']??''))?>"></div>
            <div class="form-field"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?=e((string)$customer['phone'])?>" data-phone-input required></div>
            <div class="form-field"><label for="other_phone">Other Phone</label><input id="other_phone" name="other_phone" type="tel" value="<?=e((string)($customer['other_phone']??''))?>" data-phone-input></div>
            <div class="form-field"><label for="customer_region">Region</label><select id="customer_region" data-location-region-select required><option value="">Select region</option><?php foreach($regions as $key=>$name):?><option value="<?=e($key)?>" <?=$selectedRegion===$key?'selected':''?>><?=e($name)?></option><?php endforeach;?></select></div>
            <div class="form-field"><label for="location_id">Town</label><select id="location_id" name="location_id" data-location-town-select required><option value="">Select assigned town</option><?php foreach($managedTowns as $town):?><option value="<?=(int)$town['id']?>" data-region-key="<?=e((string)($town['region_code']??$town['region_name']??''))?>" data-mmda-name="<?=e((string)($town['mmda_name']??''))?>" <?=(int)$customer['location_id']===(int)$town['id']?'selected':''?>><?=e((string)$town['town_name'])?></option><?php endforeach;?></select><small data-location-mmda-output></small></div>
            <div class="form-field"><label for="area">Area</label><input id="area" name="area" value="<?=e((string)($customer['area']??''))?>"></div>
            <div class="form-field form-field--wide"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="4"><?=e((string)($customer['notes']??''))?></textarea></div>
        </div>
        <div class="form-actions"><a class="secondary-button" href="<?=e($returnTo)?>"><i class="fa-solid fa-arrow-left"></i><span>Cancel</span></a><button class="danger-button" type="submit" name="form_action" value="delete" formnovalidate data-confirm-title="Delete customer?" data-confirm-message="This permanently deletes this customer and their recorded VIN details."><i class="fa-solid fa-trash"></i><span>Delete</span></button><button class="login-button" type="submit" name="form_action" value="save"><span>Update customer</span><i class="fa-solid fa-floppy-disk"></i></button></div>
    </form>
    <?php endif;?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
