<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('pos');
if(current_vendor_personnel()&&!can_access_menu_item('pos_promo')){http_response_code(403);exit('Sales access has not been assigned to your account.');}
if(!can_access_menu_item('pos_promo')){header('Location: '.app_url('pos.php?view=sales'));exit;}
ensure_customer_promo_plug_schema();

$vendorId=(int)(current_vendor_profile()['id']??0);
$sql="SELECT c.id,c.customer_ref,c.customer_name,c.phone,c.other_phone,c.bus_loc_id,p.business_name,
    (SELECT v.id FROM visits v WHERE v.customer_id=c.id AND v.record_status='completed' ORDER BY v.visit_date DESC,v.id DESC LIMIT 1) latest_visit_id
    FROM customers c INNER JOIN business_locations p ON p.id=c.bus_loc_id
    WHERE c.is_active=1 AND c.record_status='completed'";
$params=[];
if(current_user_role()==='vendor'){$sql.=' AND c.vendor_id=?';$params=[$vendorId];}
elseif(!is_admin_user()){$sql.=' AND (c.created_by_user_id=? OR EXISTS(SELECT 1 FROM visits av WHERE av.customer_id=c.id AND av.recorded_by_user_id=?))';$params=[current_user_id(),current_user_id()];}
$sql.=' ORDER BY c.customer_name,c.id';
$statement=db()->prepare($sql);$statement->execute($params);
$customers=array_values(array_filter($statement->fetchAll(),static fn(array $row):bool=>(int)$row['latest_visit_id']>0));
$error='';$saved=(string)($_GET['saved']??'')==='1';
$customerId=max(0,(int)($_POST['customer_id']??0));$promoEnabled=isset($_POST['promo_enabled']);$plugNumber=trim((string)($_POST['plug_number']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    $selected=null;foreach($customers as $customer){if((int)$customer['id']===$customerId){$selected=$customer;break;}}
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    elseif(!$selected)$error='Select a customer.';
    elseif($promoEnabled&&$plugNumber==='')$error='Enter the plug number.';
    else{try{
        $promoValue=$promoEnabled?$plugNumber:'No';
        db()->prepare("INSERT INTO customer_promo_plugs(visit_id,customer_id,bus_loc_id,promo_plug,recorded_by_user_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),bus_loc_id=VALUES(bus_loc_id),promo_plug=VALUES(promo_plug),recorded_by_user_id=VALUES(recorded_by_user_id),updated_at=NOW()")
            ->execute([(int)$selected['latest_visit_id'],(int)$selected['id'],(int)$selected['bus_loc_id'],$promoValue,current_user_id()]);
        header('Location: '.app_url('pos-promo.php?saved=1'));exit;
    }catch(Throwable $exception){$error='The Promo record could not be saved.';}}
}
$pageTitle='POS Promo';$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'POS','url'=>app_url('pos.php')],['label'=>'Direct Sales','url'=>app_url('pos.php?view=sales')],['label'=>'Promo']];$internalBackUrl=app_url('pos.php?view=sales');require __DIR__.'/../includes/header.php';
?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">POS Sales</span><h1>Promo</h1><p>Select a customer and record their Promo Plug response.</p></div><div class="management-icon"><i class="fa-solid fa-bullhorn"></i></div></div>
<?php if($saved):?><div class="profile-message is-success">Promo saved successfully.</div><?php endif;?><?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
<form class="record-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<div class="form-grid"><div class="form-field form-field--wide"><label for="promo_customer">Customer</label><select id="promo_customer" name="customer_id" data-popup-select data-popup-search required><option value="">Search or select customer</option><?php foreach($customers as $customer):?><option value="<?=(int)$customer['id']?>" <?=$customerId===(int)$customer['id']?'selected':''?>><?=e(implode(' · ',array_filter([(string)$customer['customer_name'],(string)$customer['customer_ref'],(string)$customer['phone'],(string)$customer['business_name']])))?></option><?php endforeach;?></select></div></div>
<label class="standalone-promo-choice"><input type="checkbox" name="promo_enabled" value="1" data-pos-promo-toggle <?=$promoEnabled?'checked':''?>><span><strong>Promo Plug</strong><small data-pos-promo-state><?=$promoEnabled?'Yes':'No'?></small></span></label>
<div class="form-field" data-pos-promo-number-wrap <?=$promoEnabled?'':'hidden'?>><label for="promo_plug_number">Plug Number</label><input id="promo_plug_number" name="plug_number" value="<?=e($plugNumber)?>" data-pos-promo-number <?=$promoEnabled?'required':''?>></div>
<div class="form-actions"><a class="secondary-button" href="<?=e(app_url('pos.php?view=sales'))?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>Save Promo</span></button></div></form></section>
<script>document.addEventListener('DOMContentLoaded',()=>{const toggle=document.querySelector('[data-pos-promo-toggle]'),state=document.querySelector('[data-pos-promo-state]'),wrap=document.querySelector('[data-pos-promo-number-wrap]'),number=document.querySelector('[data-pos-promo-number]');const sync=()=>{const yes=!!toggle?.checked;if(state)state.textContent=yes?'Yes':'No';if(wrap)wrap.hidden=!yes;if(number)number.required=yes;};toggle?.addEventListener('change',sync);sync();});</script>
<?php require __DIR__.'/../includes/footer.php'; ?>
