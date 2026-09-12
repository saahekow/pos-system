<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('pos');
ensure_job_type_schema();
ensure_pos_sales_schema();
ensure_vendor_receipt_branding_schema();
ensure_pos_sale_edit_audit_schema();

$saleId=max(0,(int)($_GET['id']??0));
$statement=db()->prepare(
    "SELECT s.*,s.customer_name receipt_customer_name,s.customer_phone receipt_customer_phone,
            s.customer_type receipt_customer_type,s.area receipt_area,
            COALESCE(c.customer_status,CASE WHEN s.customer_mode='temp' THEN 'temporary' ELSE 'registered' END) receipt_customer_status,
            l.town_name,l.mmda_name,l.region_name,u.full_name recorded_by,u.role recorder_role,
            v.id recorder_vendor_id,v.vendor_name recorder_vendor_name,v.phone recorder_vendor_phone,
            v.email recorder_vendor_email,v.area recorder_vendor_area,v.profile_image recorder_vendor_logo,
            vl.town_name recorder_vendor_town,vl.region_name recorder_vendor_region,
            rb.logo_path receipt_logo_path,rb.signature_path receipt_signature_path,rb.show_signature,
            (SELECT COUNT(*) FROM pos_sale_edit_audit sea WHERE sea.sale_id=s.id) revision_count
     FROM pos_sales s
     LEFT JOIN customers c ON c.id=s.customer_id
     LEFT JOIN locations l ON l.id=s.location_id
     LEFT JOIN users u ON u.id=s.recorded_by_user_id
     LEFT JOIN vendors v ON v.id=s.vendor_id OR (s.vendor_id IS NULL AND v.user_id=s.recorded_by_user_id)
     LEFT JOIN locations vl ON vl.id=v.location_id
     LEFT JOIN vendor_receipt_branding rb ON rb.vendor_id=v.id
     WHERE s.id=? LIMIT 1"
);
$statement->execute([$saleId]);
$sale=$statement->fetch();
if(!$sale){http_response_code(404);exit('Receipt not found.');}
$receiptPersonnel=current_vendor_personnel();
$receiptVendor=current_vendor_profile();
$canViewVendorSales=current_user_role()==='vendor'||($receiptPersonnel&&(int)($receiptPersonnel['can_reports']??0)===1);
$canViewSale=is_admin_user()||current_user_role()==='staff'||(int)$sale['recorded_by_user_id']===(int)current_user_id()||($canViewVendorSales&&$receiptVendor&&(int)($sale['vendor_id']??0)===(int)$receiptVendor['id']);
if(!$canViewSale){http_response_code(404);exit('Receipt not found.');}
$canEditReceipt=is_admin_user()||current_user_role()==='staff'||($receiptVendor&&(int)($sale['vendor_id']??0)===(int)$receiptVendor['id']);
$receiptEdits=[];
if($canEditReceipt&&(int)($sale['revision_count']??0)>0){$editHistoryStatement=db()->prepare('SELECT sea.edit_reason,sea.edited_at,u.full_name edited_by FROM pos_sale_edit_audit sea LEFT JOIN users u ON u.id=sea.edited_by_user_id WHERE sea.sale_id=? ORDER BY sea.id DESC');$editHistoryStatement->execute([$saleId]);$receiptEdits=$editHistoryStatement->fetchAll();}

$itemStatement=db()->prepare('SELECT * FROM pos_sale_items WHERE sale_id=? ORDER BY id');
$itemStatement->execute([$saleId]);
$items=$itemStatement->fetchAll();
$vinsByItem=[];
if($items){
    $itemIds=array_map('intval',array_column($items,'id'));
    $marks=implode(',',array_fill(0,count($itemIds),'?'));
    $vinStatement=db()->prepare("SELECT sale_item_id,vin_number FROM pos_sale_vins WHERE sale_item_id IN ($marks) ORDER BY id");
    $vinStatement->execute($itemIds);
    foreach($vinStatement->fetchAll() as $vinRow)$vinsByItem[(int)$vinRow['sale_item_id']][]=(string)$vinRow['vin_number'];
}
$receiptTotal=$items?array_sum(array_map(static fn(array $item):float=>(float)$item['total_amount'],$items)):(float)$sale['subtotal'];
$receiptDiscount=$items?array_sum(array_map(static fn(array $item):float=>(float)($item['customer_discount_amount']??0),$items)):(float)($sale['customer_discount_amount']??0);
$isVendorReceipt=(int)($sale['recorder_vendor_id']??0)>0;
$issuerName=$isVendorReceipt?(string)$sale['recorder_vendor_name']:COMPANY_NAME;
$issuerPhone=$isVendorReceipt?trim((string)($sale['recorder_vendor_phone']??'')):'';
$issuerEmail=$isVendorReceipt?trim((string)($sale['recorder_vendor_email']??'')):'';
$issuerAddress=$isVendorReceipt?implode(', ',array_filter([(string)($sale['recorder_vendor_area']??''),(string)($sale['recorder_vendor_town']??''),(string)($sale['recorder_vendor_region']??'')])):'';
$configuredLogoPath=trim((string)($sale['receipt_logo_path']??''));
$issuerLogoPath=$isVendorReceipt&&$configuredLogoPath!==''&&is_file(__DIR__.'/../'.$configuredLogoPath)?$configuredLogoPath:'assets/images/autoplus-logo-receipt.jpg';
$issuerLogo=app_url($issuerLogoPath);
$configuredSignaturePath=trim((string)($sale['receipt_signature_path']??''));
$issuerSignature=$isVendorReceipt&&!empty($sale['show_signature'])&&$configuredSignaturePath!==''&&is_file(__DIR__.'/../'.$configuredSignaturePath)?app_url($configuredSignaturePath):'';
$customerLocation=implode(', ',array_filter([(string)($sale['receipt_area']??''),(string)($sale['town_name']??''),(string)($sale['region_name']??'')]));

$pageTitle='Receipt '.(string)$sale['sale_ref'];
$isSorReceipt=(string)($sale['sale_source']??'pos')==='sor';
$salesMenuUrl=app_url($isSorReceipt?'pos.php?view=sor':'pos.php?view=sales');
$recordAnotherUrl=app_url('pos-sales.php'.($isSorReceipt?'?source=sor&return_to='.rawurlencode($salesMenuUrl):'?return_to='.rawurlencode($salesMenuUrl)));
$receiptBackUrl=$salesMenuUrl;
$receiptBackLabel='Back to Sales';
$salesMenuReturn=trim((string)($_GET['sales_return_to']??''));
if($salesMenuReturn!=='')$receiptBackUrl=safe_app_return_url($salesMenuReturn,$salesMenuUrl);
$requestedReturn=trim((string)($_GET['return_to']??''));
if($requestedReturn!==''){
    $returnParts=parse_url($requestedReturn);
    $reportsPath=(string)(parse_url(app_url('pos-reports.php'),PHP_URL_PATH)?:'');
    if(is_array($returnParts)&&!isset($returnParts['scheme'])&&!isset($returnParts['host'])&&(string)($returnParts['path']??'')===$reportsPath){$receiptBackUrl=$requestedReturn;$receiptBackLabel='Back to Reports';}
}
$internalBackUrl=$receiptBackUrl;
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'POS','url'=>app_url('pos.php')],['label'=>$isSorReceipt?'SoR':'Direct Sales','url'=>$salesMenuUrl],['label'=>(string)$sale['sale_ref']]];
require_once __DIR__.'/../includes/header.php';
?>
<section class="pos-receipt-page">
    <div class="pos-receipt-actions" aria-label="Receipt actions">
        <a class="secondary-button pos-receipt-action pos-receipt-action--back" href="<?=e($receiptBackUrl)?>"><i class="fa-solid fa-arrow-left"></i><span><?=e($receiptBackLabel)?></span></a>
        <a class="secondary-button pos-receipt-action pos-receipt-action--new" href="<?=e($recordAnotherUrl)?>"><i class="fa-solid fa-plus"></i><span>Record Another Sale</span></a>
        <?php if($canEditReceipt):?><a class="secondary-button pos-receipt-action pos-receipt-action--edit" href="<?=e(app_url('pos-sales.php?edit='.$saleId.'&return_to='.rawurlencode(app_url('pos-sale-receipt.php?id='.$saleId))))?>"><i class="fa-solid fa-pen-to-square"></i><span>Edit Receipt</span></a><?php endif;?>
        <a class="secondary-button pos-receipt-action pos-receipt-action--download" href="<?=e(app_url('pos-sale-receipt-pdf.php?id='.$saleId.'&download=1'))?>"><i class="fa-solid fa-file-arrow-down"></i><span>Download PDF</span></a>
        <button class="login-button pos-receipt-action pos-receipt-action--share" type="button" data-share-receipt data-pdf-url="<?=e(app_url('pos-sale-receipt-pdf.php?id='.$saleId))?>" data-pdf-name="<?=e((string)$sale['sale_ref'].'-receipt.pdf')?>"><i class="fa-solid fa-share-nodes"></i><span>Share Receipt</span></button>
    </div>
    <?php if($receiptEdits):?><details class="pos-receipt-revisions"><summary><i class="fa-solid fa-clock-rotate-left"></i><span>Edit history</span><b><?=count($receiptEdits)?></b><i class="fa-solid fa-chevron-down pos-receipt-revisions__chevron"></i></summary><div><?php foreach($receiptEdits as $revision):?><p><strong><?=e(date('d M Y H:i',strtotime((string)$revision['edited_at'])))?> · <?=e((string)($revision['edited_by']?:'System'))?></strong><span><?=e((string)$revision['edit_reason'])?></span></p><?php endforeach;?></div></details><?php endif;?>
    <article class="pos-receipt" aria-label="Sales receipt">
        <header class="pos-receipt__header">
            <div class="pos-receipt__issuer">
                <span class="pos-receipt__logo"><?php if($issuerLogo!==''):?><img src="<?=e($issuerLogo)?>" alt="<?=e($issuerName)?> logo"><?php else:?><i class="fa-solid fa-bolt"></i><?php endif;?></span>
                <div class="pos-receipt__issuer-details">
                    <h1><?=e($issuerName)?></h1>
                    <?php if($issuerAddress!==''):?><p><?=e($issuerAddress)?></p><?php endif;?>
                    <?php if($issuerPhone!==''):?><p><b>Phone:</b> <?=e($issuerPhone)?></p><?php endif;?>
                    <?php if($issuerEmail!==''):?><p><?=e($issuerEmail)?></p><?php endif;?>
                </div>
            </div>
            <div class="pos-receipt__document-title"><strong>Sales</strong><span>Receipt</span></div>
        </header>

        <section class="pos-receipt__parties">
            <div class="pos-receipt__bill-to"><span>Bill To:</span><strong><?=e((string)$sale['receipt_customer_name'])?></strong><?php if($sale['receipt_customer_phone']):?><p><?=e((string)$sale['receipt_customer_phone'])?></p><?php endif;?><?php if($customerLocation!==''):?><p><?=e($customerLocation)?></p><?php endif;?></div>
            <dl class="pos-receipt__reference"><div><dt>Receipt #:</dt><dd><?=e((string)$sale['sale_ref'])?></dd></div><div><dt>Date:</dt><dd><?=e(date('j M Y',strtotime((string)$sale['sale_date'])))?></dd></div><div><dt>Time:</dt><dd><?=e(date('H:i',strtotime((string)$sale['created_at'])))?></dd></div></dl>
        </section>

        <section class="pos-receipt__items"><div class="pos-receipt__table"><table><thead><tr><th>Description</th><th>Qty</th><th>Unit</th><th>Amount</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><strong><?=e(trim((string)$item['brand_name'].' '.(string)$item['plug_number']))?></strong><?php if((float)($item['customer_discount_amount']??0)>0):?><small class="pos-receipt__discount-note">Regular GHS <?=e(number_format((float)$item['list_unit_price'],2))?><?php if((float)($item['customer_discount_percentage']??0)>0):?> · <?=e(number_format((float)$item['customer_discount_percentage'],2))?>% off<?php endif;?> · Discount GHS <?=e(number_format((float)$item['customer_discount_amount'],2))?></small><?php endif;?><?php if(!empty($vinsByItem[(int)$item['id']])):?><small>VIN: <?=e(implode(', ',$vinsByItem[(int)$item['id']]))?></small><?php endif;?></td><td><?=(int)$item['quantity']?></td><td><?=e(number_format((float)$item['unit_price'],2))?></td><td><?=e(number_format((float)$item['total_amount'],2))?></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="4">Item details are not available for this sale.</td></tr><?php endif;?></tbody></table></div></section>

        <?php if($receiptDiscount>0):?><dl class="pos-receipt__discount-summary"><div><dt>Regular total</dt><dd>GHS <?=e(number_format($receiptTotal+$receiptDiscount,2))?></dd></div><div><dt>Customer discount</dt><dd>− GHS <?=e(number_format($receiptDiscount,2))?></dd></div></dl><?php endif;?>
        <div class="pos-receipt__total"><span>Total</span><strong>GHS <?=e(number_format($receiptTotal,2))?></strong></div>
        <footer class="pos-receipt__footer"><p>Thank you for your purchase.</p><?php if($issuerSignature!==''):?><div class="pos-receipt__signature"><img src="<?=e($issuerSignature)?>" alt="Authorized signature"></div><?php endif;?><small>Recorded by <?=e((string)($sale['recorded_by']?:'System'))?> · <?=e(date('d M Y H:i',strtotime((string)$sale['created_at'])))?></small></footer>
    </article>
</section>
<script>document.querySelector('[data-share-receipt]')?.addEventListener('click',async function(){const button=this;const original=button.innerHTML;button.disabled=true;button.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i><span>Preparing...</span>';try{const response=await fetch(button.dataset.pdfUrl,{credentials:'same-origin'});if(!response.ok)throw new Error('Receipt PDF could not be generated.');const blob=await response.blob();const file=new File([blob],button.dataset.pdfName,{type:'application/pdf'});if(navigator.canShare&&navigator.canShare({files:[file]})){await navigator.share({title:'Sales Receipt',text:'Your SPW Sales receipt',files:[file]});}else{const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=button.dataset.pdfName;document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}}catch(error){if(error.name!=='AbortError')alert(error.message||'The receipt could not be shared.');}finally{button.disabled=false;button.innerHTML=original;}});</script>
<?php require_once __DIR__.'/../includes/footer.php';?>
