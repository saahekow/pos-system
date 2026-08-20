<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('admin');
ensure_pos_sales_schema();

$pageTitle='Plug Commission Setup';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Admin','url'=>app_url('admin.php')],['label'=>'Setup','url'=>app_url('setup.php')],['label'=>'Plug Commissions']];
$internalBackUrl=requested_return_url(app_url('pos.php?view=setup'));
$message='';$error='';$editId=max(0,(int)($_GET['edit']??0));
$form=['brand_name'=>'','spark_plug_id'=>'','discount_percentage'=>'20.00','is_active'=>'1'];
if($editId){$edit=db()->prepare('SELECT d.*,sp.brand_name FROM plug_commissions d INNER JOIN spark_plugs sp ON sp.id=d.spark_plug_id WHERE d.id=?');$edit->execute([$editId]);if($row=$edit->fetch())$form=['brand_name'=>(string)$row['brand_name'],'spark_plug_id'=>(string)$row['spark_plug_id'],'discount_percentage'=>(string)$row['commission_percentage'],'is_active'=>(string)(int)$row['is_active']];else $editId=0;}
if($_SERVER['REQUEST_METHOD']==='POST'){
    $editId=max(0,(int)($_POST['discount_id']??0));
    $form=['brand_name'=>trim((string)($_POST['brand_name']??'')),'spark_plug_id'=>(string)max(0,(int)($_POST['spark_plug_id']??0)),'discount_percentage'=>trim((string)($_POST['discount_percentage']??'')),'is_active'=>(string)($_POST['is_active']??'1')];
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    else{
        $plugCheck=db()->prepare('SELECT id,brand_name FROM spark_plugs WHERE id=? AND brand_name=? AND is_active=1');$plugCheck->execute([(int)$form['spark_plug_id'],$form['brand_name']]);$plug=$plugCheck->fetch();
        $percentage=(float)$form['discount_percentage'];
        if(!$plug)$error='Select a valid brand and plug number.';
        elseif($percentage<0||$percentage>100)$error='Commission percentage must be between 0 and 100.';
        else{
            try{
                if($editId){db()->prepare('UPDATE plug_commissions SET spark_plug_id=?,commission_percentage=?,is_active=? WHERE id=?')->execute([(int)$form['spark_plug_id'],$percentage,$form['is_active']==='0'?0:1,$editId]);$message='Plug commission updated successfully.';}
                else{db()->prepare('INSERT INTO plug_commissions(spark_plug_id,commission_percentage,is_active,created_by_user_id) VALUES(?,?,?,?)')->execute([(int)$form['spark_plug_id'],$percentage,$form['is_active']==='0'?0:1,current_user_id()]);$message='Plug commission added successfully.';}
                $editId=0;$form=['brand_name'=>'','spark_plug_id'=>'','discount_percentage'=>'20.00','is_active'=>'1'];
            }catch(PDOException $exception){$error=(string)$exception->getCode()==='23000'?'That plug number already has a commission. Edit its existing commission instead.':'The commission could not be saved.';}
        }
    }
}
$brands=db()->query("SELECT DISTINCT brand_name FROM spark_plugs WHERE is_active=1 ORDER BY brand_name")->fetchAll();
$plugs=db()->query("SELECT id,brand_name,plug_number FROM spark_plugs WHERE is_active=1 ORDER BY brand_name,plug_number")->fetchAll();
$discounts=db()->query("SELECT d.id,d.commission_percentage AS discount_percentage,d.is_active,d.created_at,sp.brand_name,sp.plug_number FROM plug_commissions d INNER JOIN spark_plugs sp ON sp.id=d.spark_plug_id ORDER BY sp.brand_name,sp.plug_number")->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker">POS Setup</span><h1>Plug Commissions</h1><p>Assign the commission percentage earned on each complete box of a plug number.</p></div><div class="management-icon"><i class="fa-solid fa-percent"></i></div></div>
<?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
<form class="record-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="discount_id" value="<?=$editId?>"><div class="form-grid"><div class="form-field"><label for="discount_brand">Brand</label><select id="discount_brand" name="brand_name" data-popup-select data-popup-search data-popup-hide-empty required><option value="">Search or select brand</option><?php foreach($brands as $brand):?><option value="<?=e((string)$brand['brand_name'])?>" <?=$form['brand_name']===(string)$brand['brand_name']?'selected':''?>><?=e((string)$brand['brand_name'])?></option><?php endforeach;?></select></div><div class="form-field"><label for="discount_plug">Plug number</label><select id="discount_plug" name="spark_plug_id" data-popup-select data-popup-search data-popup-hide-empty required><option value="">Search or select plug number</option><?php foreach($plugs as $plug):?><option value="<?=(int)$plug['id']?>" data-brand="<?=e((string)$plug['brand_name'])?>" <?=$form['spark_plug_id']===(string)$plug['id']?'selected':''?>><?=e((string)$plug['plug_number'])?></option><?php endforeach;?></select></div><div class="form-field"><label for="discount_percentage">Commission per box (%)</label><input id="discount_percentage" name="discount_percentage" type="number" min="0" max="100" step="0.01" value="<?=e($form['discount_percentage'])?>" required></div><div class="form-field"><label for="discount_status">Status</label><select id="discount_status" name="is_active"><option value="1" <?=$form['is_active']==='1'?'selected':''?>>Active</option><option value="0" <?=$form['is_active']==='0'?'selected':''?>>Inactive</option></select></div></div><div class="form-actions"><button class="login-button"><i class="fa-solid fa-floppy-disk"></i><span><?=$editId?'Update Commission':'Save Commission'?></span></button><?php if($editId):?><a class="secondary-button" href="<?=e(app_url('pos-discount-setup.php'))?>">Cancel</a><?php endif;?></div></form></section>
<section class="management-panel management-panel--table"><div class="management-heading management-heading--compact"><div><span class="section-kicker">Current Rules</span><h2>Box Commissions</h2></div></div><?php if(!$discounts):?><p class="empty-state">No plug commissions have been added.</p><?php else:?><div class="table-wrap"><table class="data-table data-table--compact"><thead><tr><th>Brand</th><th>Plug Number</th><th>Commission</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($discounts as $discount):?><tr><td><?=e((string)$discount['brand_name'])?></td><td><?=e((string)$discount['plug_number'])?></td><td><?=e(number_format((float)$discount['discount_percentage'],2))?>%</td><td><span class="status-badge <?=(int)$discount['is_active']?'is-active':'is-inactive'?>"><?=(int)$discount['is_active']?'Active':'Inactive'?></span></td><td><a class="action-button" href="<?=e(app_url('pos-discount-setup.php?edit='.(int)$discount['id']))?>"><i class="fa-solid fa-pen"></i><span>Edit</span></a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script>document.addEventListener('DOMContentLoaded',function(){const brand=document.querySelector('#discount_brand');const plug=document.querySelector('#discount_plug');const options=Array.from(plug?.options||[]);const sync=function(){const value=brand?.value||'';if(plug)plug.value='';options.forEach(function(option){if(!option.value)return;const match=value!==''&&option.dataset.brand===value;option.hidden=!match;option.disabled=!match;});if(typeof updateLookupButton==='function'&&plug)updateLookupButton(plug);};brand?.addEventListener('change',sync);if(brand?.value){const selected='<?=e($form['spark_plug_id'])?>';options.forEach(function(option){if(!option.value)return;const match=option.dataset.brand===brand.value;option.hidden=!match;option.disabled=!match;});if(plug)plug.value=selected;}else sync();});</script>
<?php require_once __DIR__ . '/../includes/footer.php';?>
