<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('pos');
ensure_pos_referral_source_schema();
ensure_job_type_schema();
ensure_places_management_schema();
ensure_pos_sales_schema();
ensure_vendor_personnel_schema();
$saleSource=(string)($_GET['source']??$_POST['sale_source']??'pos')==='sor'?'sor':'pos';
$isSorSale=$saleSource==='sor';
$saleVendor=current_vendor_profile();
if($saleVendor&&current_vendor_is_sor()!==$isSorSale){header('Location: '.app_url('pos-sales.php'.(current_vendor_is_sor()?'?source=sor':'')));exit;}
$salePersonnel=current_vendor_personnel();
if($salePersonnel&&(($isSorSale&&(int)$salePersonnel['can_sor']!==1)||(!$isSorSale&&(int)$salePersonnel['can_make_sales']!==1))){http_response_code(403);exit('This sales role has not been assigned to your account.');}
$saleVendorId=(int)($saleVendor['id']??0);
$activeDayClosure=$saleVendorId?vendor_day_is_closed($saleVendorId):null;
$referralSources = db()->query("SELECT source_name FROM pos_referral_sources WHERE is_active=1 ORDER BY source_name")->fetchAll();
$vendors = db()->query("SELECT id,vendor_name,phone FROM vendors WHERE is_active=1 ORDER BY vendor_name")->fetchAll();
$locations = active_locations();
$locationRegions = [];
foreach($locations as $location){$regionKey=(string)($location['region_code']?:$location['region_name']);$locationRegions[$regionKey]=(string)$location['region_name'];}
asort($locationRegions);
$plugBrands = db()->query("SELECT DISTINCT brand_name FROM spark_plugs WHERE is_active=1 ORDER BY brand_name")->fetchAll();
$jobTypes = db()->query("SELECT id,job_type_name FROM job_types WHERE is_active=1 ORDER BY job_type_name")->fetchAll();
$sparkPlugs = db()->query(
    "SELECT sp.id,sp.brand_name,sp.plug_number,
            (SELECT ph.id FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) current_price_id,
            (SELECT ph.price FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) current_price,
            (SELECT ph.effective_at FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) current_effective_at,
            (SELECT ph.price FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1 OFFSET 1) previous_price,
            (SELECT ph.effective_at FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1 OFFSET 1) previous_effective_at
            ,COALESCE((SELECT pc.commission_percentage FROM plug_commissions pc WHERE pc.spark_plug_id=sp.id AND pc.is_active=1 LIMIT 1),0) commission_percentage
     FROM spark_plugs sp
     WHERE sp.is_active=1 ORDER BY sp.brand_name,sp.plug_number"
)->fetchAll();
$customers = db()->query(
    "SELECT c.id,c.customer_ref,c.customer_name,c.phone,c.job_type_id,c.customer_status,p.business_name,p.bus_loc_ref,p.location_id,p.area,
            COALESCE(NULLIF(jt.job_type_name,''),NULLIF(c.job_type,'')) AS job_type_name
     FROM customers c
     LEFT JOIN business_locations p ON p.id=c.bus_loc_id
     LEFT JOIN job_types jt ON jt.id=c.job_type_id
     WHERE c.is_active=1 AND c.record_status='completed'
     ORDER BY c.customer_name,c.customer_ref,c.id"
)->fetchAll();
$message=(string)($_GET['saved']??'')!==''?'Sale '.trim((string)$_GET['saved']).' saved successfully.':'';
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    elseif($saleVendorId&&vendor_day_is_closed($saleVendorId))$error='Sales are closed for this vendor today. New sales will be available on the next calendar day.';
    else{
        $saleDate=trim((string)($_POST['sale_date']??''));
        $customerMode=(string)($_POST['customer_mode']??'registered');
        $postedProducts=is_array($_POST['products']??null)?$_POST['products']:[];
        $referralName=trim((string)($_POST['referral_source']??''));
        $comment=trim((string)($_POST['comment']??''));
        $salesType=(string)($_POST['sales_type']??'direct');$recipientVendorId=max(0,(int)($_POST['recipient_vendor_id']??0));$recipientVendorName=null;$deliveryCharge=max(0,round((float)($_POST['delivery_charge']??0),2));
        if(!in_array($salesType,['direct','indirect'],true))$salesType='direct';
        $commissionApplies=$isSorSale||$salesType==='indirect';
        if($salesType==='indirect'){$vendorCheck=db()->prepare('SELECT vendor_name FROM vendors WHERE id=? AND is_active=1');$vendorCheck->execute([$recipientVendorId]);$recipientVendorName=$vendorCheck->fetchColumn()?:null;if(!$recipientVendorName)$error='Select the vendor who referred the customer.';}else{$recipientVendorId=0;}
        $customerId=null;$customerName='';$customerPhone=null;$jobTypeId=null;$customerType=null;$locationId=null;$area=null;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$saleDate))$error='Select a valid sale date.';
        elseif(!in_array($customerMode,['registered','temp'],true))$error='Select a valid customer mode.';
        else{
            if($customerMode==='registered'){
                $referralName='';
                $customerId=max(0,(int)($_POST['customer_id']??0));
                $customerStatement=db()->prepare("SELECT c.id,c.customer_name,c.phone,c.job_type_id,c.customer_status,COALESCE(NULLIF(jt.job_type_name,''),NULLIF(c.job_type,'')) customer_type,p.location_id,p.area FROM customers c LEFT JOIN job_types jt ON jt.id=c.job_type_id LEFT JOIN business_locations p ON p.id=c.bus_loc_id WHERE c.id=? AND c.is_active=1 LIMIT 1");
                $customerStatement->execute([$customerId]);$selectedCustomer=$customerStatement->fetch();
                if(!$selectedCustomer)$error='Select a registered customer.';
                else{$customerName=(string)$selectedCustomer['customer_name'];$customerPhone=(string)($selectedCustomer['phone']??'')?:null;$jobTypeId=(int)($selectedCustomer['job_type_id']??0)?:null;$customerType=(string)($selectedCustomer['customer_type']??'')?:null;$locationId=(int)($selectedCustomer['location_id']??0)?:null;$area=(string)($selectedCustomer['area']??'')?:null;}
            }else{
                $customerName=trim((string)($_POST['temp_customer_name']??''));
                $customerPhone=normalize_phone_number((string)($_POST['temp_phone']??''));
                $jobTypeId=max(0,(int)($_POST['job_type_id']??0));
                $jobTypeStatement=db()->prepare('SELECT job_type_name FROM job_types WHERE id=? AND is_active=1');
                $jobTypeStatement->execute([$jobTypeId]);
                $customerType=(string)($jobTypeStatement->fetchColumn()?:'');
                $locationId=max(0,(int)($_POST['temp_location_id']??0));
                $area=trim((string)($_POST['temp_area']??''));
                if($customerName==='')$error='Enter the temporary customer name.';
                elseif(!is_valid_phone_number($customerPhone))$error='Enter a valid temporary customer phone number.';
                elseif(!$locationId||!location_by_id($locationId))$error='Select the temporary customer town.';
                elseif($area==='')$error='Enter the temporary customer area.';
                elseif($customerType==='')$error='Select the temporary customer type.';
            }
        }
        $saleProducts=[];
        if($error===''){
            $brandNames=is_array($postedProducts['brand_name']??null)?$postedProducts['brand_name']:[];
            $sparkPlugIds=is_array($postedProducts['spark_plug_id']??null)?$postedProducts['spark_plug_id']:[];
            $prices=is_array($postedProducts['price']??null)?$postedProducts['price']:[];
            $quantities=is_array($postedProducts['quantity']??null)?$postedProducts['quantity']:[];
            $priceHistoryIds=is_array($postedProducts['price_history_id']??null)?$postedProducts['price_history_id']:[];
            $vins=is_array($postedProducts['vin_number']??null)?$postedProducts['vin_number']:[];
            if(!$sparkPlugIds)$error='Add at least one product.';
            $plugStatement=db()->prepare("SELECT sp.id,sp.plug_number,sp.brand_name,(SELECT ph.id FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) current_price_id,(SELECT ph.price FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) current_price FROM spark_plugs sp WHERE sp.id=? AND LOWER(TRIM(sp.brand_name))=LOWER(TRIM(?)) AND sp.is_active=1");
            foreach($sparkPlugIds as $index=>$postedPlugId){
                if($error!=='')break;
                $sparkPlugId=max(0,(int)$postedPlugId);
                $brandName=trim((string)($brandNames[$index]??''));
                $price=round((float)($prices[$index]??0),2);
                $quantity=max(0,(int)($quantities[$index]??1));
                $priceHistoryId=max(0,(int)($priceHistoryIds[$index]??0));
                $vin=strtoupper(trim((string)($vins[$index]??'')));
                $plugStatement->execute([$sparkPlugId,$brandName]);
                $plug=$plugStatement->fetch();
                if(!$plug){$error='Select a valid brand and plug number for product '.($index+1).'.';break;}
                if($price<=0){$error='Enter a valid price for product '.($index+1).'.';break;}
                if($quantity<1){$error='Enter a valid quantity for product '.($index+1).'.';break;}
                $currentPrice=(float)($plug['current_price']??0);$priceHistoryId=(int)($plug['current_price_id']??0);
                $isNewCurrentPrice=$currentPrice<=0||$price>$currentPrice;
                $listUnitPrice=$currentPrice>0?$currentPrice:$price;
                if($isNewCurrentPrice)$listUnitPrice=$price;
                $customerDiscountAmount=$currentPrice>0&&$price<$currentPrice?round(($currentPrice-$price)*$quantity,2):0.0;
                $commissionStatement=db()->prepare('SELECT commission_percentage FROM plug_commissions WHERE spark_plug_id=? AND is_active=1 LIMIT 1');$commissionStatement->execute([$sparkPlugId]);$commissionPercentage=$commissionApplies?(float)($commissionStatement->fetchColumn()?:0):null;
                $lineTotal=round($price*$quantity,2);
                $saleProducts[]=['plug'=>$plug,'spark_plug_id'=>$sparkPlugId,'price'=>$lineTotal,'unit_price'=>$price,'list_unit_price'=>$listUnitPrice,'customer_discount_amount'=>$customerDiscountAmount,'is_new_current_price'=>$isNewCurrentPrice,'quantity'=>$quantity,'price_history_id'=>$priceHistoryId?:null,'vin'=>$vin,'commission_percentage'=>$commissionPercentage,'commission_amount'=>$commissionApplies?round($lineTotal*(float)$commissionPercentage/100,2):null];
            }
        }
        $saleSubtotal=array_sum(array_column($saleProducts,'price'));
        $customerDiscountAmount=array_sum(array_column($saleProducts,'customer_discount_amount'));
        if($deliveryCharge>$saleSubtotal)$error='Delivery charges cannot be greater than the sales amount.';
        $netSales=max(0,round($saleSubtotal-$deliveryCharge,2));
        $rawCommission=$commissionApplies?array_sum(array_map(static fn(array $product):float=>(float)($product['commission_amount']??0),$saleProducts)):0;
        $commissionAmount=$commissionApplies&&$saleSubtotal>0?round($rawCommission*($netSales/$saleSubtotal),2):null;
        $amountLessCommission=max(0,round($netSales-(float)($commissionAmount??0),2));
        $referralSourceId=null;
        if($error===''&&$referralName!==''){$source=db()->prepare('SELECT id,source_name FROM pos_referral_sources WHERE LOWER(TRIM(source_name))=LOWER(TRIM(?)) AND is_active=1 LIMIT 1');$source->execute([$referralName]);if($matched=$source->fetch()){$referralSourceId=(int)$matched['id'];$referralName=(string)$matched['source_name'];}}
        if($error===''){
            try{
                db()->beginTransaction();
                if($saleVendorId){
                    $lockStatement=db()->prepare('SELECT GET_LOCK(?,10)');$lockStatement->execute([vendor_day_lock_name($saleVendorId,app_business_date())]);
                    if((int)$lockStatement->fetchColumn()!==1)throw new RuntimeException('The sales-day lock could not be acquired.');
                    if(vendor_day_is_closed($saleVendorId))throw new DomainException('Sales are closed for this vendor today.');
                }
                if($customerMode==='temp'){
                    $existingCustomerStatement=db()->prepare("SELECT c.id,c.customer_name,c.phone,c.job_type_id,COALESCE(NULLIF(jt.job_type_name,''),NULLIF(c.job_type,'')) AS job_type,c.customer_status,c.bus_loc_id,p.location_id,p.area FROM customers c LEFT JOIN job_types jt ON jt.id=c.job_type_id LEFT JOIN business_locations p ON p.id=c.bus_loc_id WHERE c.is_active=1 AND (c.phone=? OR c.other_phone=?) ORDER BY c.id LIMIT 1 FOR UPDATE");
                    $existingCustomerStatement->execute([$customerPhone,$customerPhone]);
                    $existingCustomer=$existingCustomerStatement->fetch();
                    if($existingCustomer){
                        $customerId=(int)$existingCustomer['id'];
                        if((string)$existingCustomer['customer_status']==='temporary'){
                            db()->prepare("UPDATE customers SET customer_name=?,job_type=?,job_type_id=?,customer_status='temporary',record_status='completed' WHERE id=?")->execute([$customerName,$customerType,$jobTypeId,$customerId]);
                            db()->prepare('UPDATE business_locations SET business_name=?,location_id=?,area=? WHERE id=?')->execute([$customerName,$locationId,$area,(int)$existingCustomer['bus_loc_id']]);
                        }else{
                            $customerMode='registered';
                            $customerName=(string)$existingCustomer['customer_name'];
                            $customerPhone=(string)($existingCustomer['phone']??'')?:$customerPhone;
                            $jobTypeId=(int)($existingCustomer['job_type_id']??0)?:null;
                            $customerType=(string)($existingCustomer['job_type']??'')?:$customerType;
                            $locationId=(int)($existingCustomer['location_id']??0)?:$locationId;
                            $area=(string)($existingCustomer['area']??'')?:$area;
                        }
                    }else{
                        db()->prepare("INSERT INTO business_locations(bus_loc_ref,business_name,location_id,area,created_by_user_id,is_legacy_placeholder) VALUES(?,?,?,?,?,1)")
                            ->execute([next_project_reference('place'),$customerName,$locationId,$area,current_user_id()]);
                        $temporaryPlaceId=(int)db()->lastInsertId();
                        db()->prepare("INSERT INTO customers(customer_ref,bus_loc_id,vendor_id,customer_name,job_type,job_type_id,phone,created_by_user_id,record_status,customer_status) VALUES(?,?,?,?,?,?,?,?,'completed','temporary')")
                            ->execute([next_project_reference('customer'),$temporaryPlaceId,(int)(current_vendor_profile()['id']??0)?:null,$customerName,$customerType,$jobTypeId,$customerPhone,current_user_id()]);
                        $customerId=(int)db()->lastInsertId();
                    }
                }
                $saleRef=next_project_reference($isSorSale?'sor_sale':'pos_sale');
                $saleVendor=current_vendor_profile();$salePersonnel=current_vendor_personnel();
                $salePersonnelName=$salePersonnel?trim(current_user_name()):'';
                $newPriceIds=[];
                $insertPriceHistory=db()->prepare('INSERT INTO plug_price_history(spark_plug_id,price,effective_at,note,recorded_by_user_id) VALUES(?,?,NOW(),?,?)');
                foreach($saleProducts as &$saleProduct){if(!$saleProduct['is_new_current_price'])continue;$priceKey=$saleProduct['spark_plug_id'].'|'.number_format((float)$saleProduct['unit_price'],2,'.','');if(!isset($newPriceIds[$priceKey])){$insertPriceHistory->execute([$saleProduct['spark_plug_id'],$saleProduct['unit_price'],'Updated automatically from POS sale '.$saleRef,current_user_id()]);$newPriceIds[$priceKey]=(int)db()->lastInsertId();}$saleProduct['price_history_id']=$newPriceIds[$priceKey];}unset($saleProduct);
                db()->prepare('INSERT INTO pos_sales(sale_ref,sale_date,sale_source,vendor_id,vendor_name,vendor_personnel_id,vendor_personnel_name,customer_mode,customer_id,customer_name,customer_phone,job_type_id,customer_type,location_id,area,referral_source_id,referral_source,comment,subtotal,customer_discount_amount,sales_type,recipient_vendor_id,recipient_vendor_name,delivery_charge,net_sales,commission_amount,amount_less_commission,status,recorded_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$saleRef,$saleDate,$saleSource,(int)($saleVendor['id']??0)?:null,trim((string)($saleVendor['vendor_name']??''))?:null,(int)($salePersonnel['id']??0)?:null,$salePersonnelName?:null,$customerMode,$customerId,$customerName,$customerPhone,$jobTypeId,$customerType,$locationId,$area,$referralSourceId,$referralName?:null,$comment?:null,$saleSubtotal,$customerDiscountAmount,$salesType,$recipientVendorId?:null,$recipientVendorName,$deliveryCharge,$netSales,$commissionAmount,$amountLessCommission,'completed',current_user_id()]);
                $saleId=(int)db()->lastInsertId();
                $insertItem=db()->prepare('INSERT INTO pos_sale_items(sale_id,spark_plug_id,price_history_id,brand_name,plug_number,quantity,unit_price,list_unit_price,total_amount,customer_discount_amount,commission_percentage,commission_amount) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                $insertVin=db()->prepare('INSERT INTO pos_sale_vins(sale_item_id,vin_number) VALUES(?,?)');
                foreach($saleProducts as $saleProduct){
                    $plug=$saleProduct['plug'];$lineTotal=(float)$saleProduct['price'];$unitPrice=(float)$saleProduct['unit_price'];
                    $insertItem->execute([$saleId,$saleProduct['spark_plug_id'],$saleProduct['price_history_id'],$plug['brand_name'],$plug['plug_number'],$saleProduct['quantity'],$unitPrice,$saleProduct['list_unit_price'],$lineTotal,$saleProduct['customer_discount_amount'],$saleProduct['commission_percentage'],$saleProduct['commission_amount']]);
                    if($saleProduct['vin']!=='')$insertVin->execute([(int)db()->lastInsertId(),$saleProduct['vin']]);
                }
                db()->commit();
                $salesMenuReturn=requested_return_url(app_url($isSorSale?'pos.php?view=sor':'pos.php?view=sales'));
                header('Location: '.app_url('pos-sale-receipt.php?id='.$saleId.'&sales_return_to='.rawurlencode($salesMenuReturn)));exit;
            }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error=$exception instanceof DomainException?$exception->getMessage():($exception instanceof PDOException&&$exception->getCode()==='23000'?'That VIN or sale record already exists.':'The sale could not be saved.');}
            finally{if($saleVendorId){$release=db()->prepare('SELECT RELEASE_LOCK(?)');$release->execute([vendor_day_lock_name($saleVendorId,app_business_date())]);}}
        }
    }
}

$pageTitle = $isSorSale?'SoR Sale':'POS Sales';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'POS', 'url' => app_url('pos.php')],
    ['label' => $isSorSale?'SoR Sale':'Sales'],
];
$internalBackUrl=requested_return_url(app_url($isSorSale?'pos.php?view=sor':'pos.php?view=sales'));
require_once __DIR__ . '/../includes/header.php';
?>
<section class="content-panel pos-sales-panel" aria-labelledby="pos-sales-title">
    <div class="management-heading pos-sales-heading">
        <div><span class="section-kicker"><?=$isSorSale?'SoR':'POS'?></span><h1 id="pos-sales-title"><?=$isSorSale?'SoR Sale':'Sales'?></h1></div>
        <div class="management-icon"><i class="fa-solid fa-cart-shopping"></i></div>
    </div>
    <?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?>
    <?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>

    <?php if($activeDayClosure):?><div class="profile-message is-error"><strong>Sales Closed</strong> at <?=e(date('H:i',strtotime((string)$activeDayClosure['closed_at'])))?>. Reports and receipts remain available.</div><?php else:?><form class="pos-sales-form pos-sales-form--sectioned" method="post" autocomplete="off" novalidate><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="sale_source" value="<?=e($saleSource)?>">
        <div class="pos-customer-mode" role="group" aria-label="Customer mode"><input type="hidden" name="customer_mode" value="registered" data-pos-customer-mode><button class="is-active" type="button" data-pos-customer-mode-button="registered">Registered</button><button type="button" data-pos-customer-mode-button="temp">Temp</button></div>
        <div class="profile-message is-error pos-sales-validation-message" data-pos-validation-message role="alert" hidden></div>
        <nav class="pos-sales-section-menu" aria-label="Sales entry sections"><button type="button" class="is-active" data-pos-section-button="date"><i class="fa-solid fa-calendar-day"></i><span>Date</span></button><button type="button" data-pos-section-button="customer"><i class="fa-solid fa-user"></i><span>Customer</span></button><button type="button" data-pos-section-button="customer-type" data-pos-temp-only hidden><i class="fa-solid fa-id-badge"></i><span>Customer Type</span></button><button type="button" data-pos-section-button="sales-type"><i class="fa-solid fa-right-left"></i><span>Sale Type</span></button><button type="button" data-pos-section-button="product"><i class="fa-solid fa-box"></i><span>Product</span></button><button type="button" data-pos-section-button="delivery"><i class="fa-solid fa-truck"></i><span>Delivery Charges</span></button><button type="button" data-pos-section-button="referral" data-pos-temp-only hidden><i class="fa-solid fa-bullhorn"></i><span>How Did You Know Us?</span></button><button type="button" data-pos-section-button="comment"><i class="fa-solid fa-note-sticky"></i><span>Comment</span></button></nav>
        <details class="pos-sales-field is-active-section" data-pos-section="date" open>
            <summary><strong>Date</strong><span><?=e(date('d/m/Y'))?></span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body"><label for="pos_sale_date">Sale date <span class="required-asterisk" aria-hidden="true">*</span></label><input id="pos_sale_date" name="sale_date" type="date" value="<?=e(date('Y-m-d'))?>" required></div>
        </details>

        <details class="pos-sales-field" data-pos-section="sales-type" open>
            <summary><strong>Sales Type</strong><span data-pos-sales-type-summary>Direct</span><i class="fa-solid fa-caret-down"></i></summary>
<div class="pos-sales-field__body"><label for="pos_sales_type">Sales type <span class="required-asterisk" aria-hidden="true">*</span></label><select id="pos_sales_type" name="sales_type" data-popup-select data-popup-search required><option value="direct">Direct</option><option value="indirect">Indirect</option></select><div data-pos-recipient-vendor hidden><label for="pos_recipient_vendor">Recipient / Referring vendor <span class="required-asterisk" aria-hidden="true">*</span></label><select id="pos_recipient_vendor" name="recipient_vendor_id" data-vendor-selector data-popup-select data-popup-search data-popup-hide-empty><option value="">Search or select vendor</option><?php foreach($vendors as $vendor):?><option value="<?=(int)$vendor['id']?>"><?=e(implode(' · ',array_filter([(string)$vendor['vendor_name'],(string)($vendor['phone']??'')])))?></option><?php endforeach;?></select></div></div>
        </details>

        <details class="pos-sales-field" data-pos-section="customer" data-pos-registered-field open>
            <summary><strong>Customer</strong><span>Select</span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body"><label for="pos_customer">Customer</label><select id="pos_customer" name="customer_id" data-popup-select data-popup-search data-popup-hide-empty data-pos-auto-picker required><option value="">Search or select customer</option><?php foreach($customers as $customer):?><option value="<?=(int)$customer['id']?>" data-job-type="<?=e((string)($customer['job_type_name']??''))?>"><?=e(implode(' · ',array_filter([(string)$customer['customer_name'],(string)$customer['phone']])))?></option><?php endforeach;?></select></div>
        </details>

        <details class="pos-sales-field" data-pos-section="customer" data-pos-temp-field hidden open>
            <summary><strong>Customer Name</strong><span>Enter</span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body"><label for="pos_temp_customer_name">Customer name</label><input id="pos_temp_customer_name" name="temp_customer_name" placeholder="Enter temporary customer name" data-required-when-temp disabled></div>
        </details>

        <details class="pos-sales-field" data-pos-section="customer" data-pos-temp-field hidden open>
            <summary><strong>Phone Number</strong><span>Enter</span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body"><label for="pos_temp_phone">Phone number</label><input id="pos_temp_phone" name="temp_phone" type="tel" placeholder="Enter phone number" data-required-when-temp disabled></div>
        </details>

        <details class="pos-sales-field" data-pos-section="customer" data-pos-temp-field hidden open>
            <summary><strong>Location</strong><span>Select</span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body pos-temp-location-fields"><div><label for="pos_temp_region">Region</label><select id="pos_temp_region" data-location-region-select data-popup-select data-popup-search data-popup-hide-empty data-required-when-temp disabled><option value="">Select region</option><?php foreach($locationRegions as $regionKey=>$regionName):?><option value="<?=e((string)$regionKey)?>"><?=e($regionName)?></option><?php endforeach;?></select></div><div><label for="pos_temp_town">Town</label><select id="pos_temp_town" name="temp_location_id" data-location-town-select data-popup-select data-popup-search data-popup-hide-empty data-required-when-temp disabled><option value="">Select town</option><option value="__other__" data-add-town-option="true">Other — add a new town</option><?php foreach($locations as $location):?><option value="<?=(int)$location['id']?>" data-region-key="<?=e((string)($location['region_code']?:$location['region_name']))?>" data-mmda-name="<?=e((string)$location['mmda_name'])?>"><?=e((string)$location['town_name'])?><?= (int)$location['is_capital']===1?' *':'' ?></option><?php endforeach;?></select></div><div><label for="pos_temp_area">Area</label><input id="pos_temp_area" name="temp_area" placeholder="Enter area" data-required-when-temp disabled></div></div>
        </details>

        <details class="pos-sales-field pos-sales-derived-field" data-pos-section="customer-type" data-pos-customer-type-field data-pos-temp-only hidden open>
            <summary><strong>Customer Type</strong><span data-pos-customer-type-summary>Not available</span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body"><span class="pos-sales-derived-value" data-pos-customer-type-value>Not available</span><label for="pos_customer_type">Customer type</label><select id="pos_customer_type" name="job_type_id" data-pos-customer-type-input data-required-when-temp data-popup-select data-popup-search data-popup-hide-empty disabled><option value="">Select customer type</option><?php foreach($jobTypes as $jobType):?><option value="<?=(int)$jobType['id']?>"><?=e((string)$jobType['job_type_name'])?></option><?php endforeach;?></select></div>
        </details>

        <section class="pos-sale-products" data-pos-section="product" aria-labelledby="pos-sale-products-title">
            <div class="pos-sale-products__heading"><div><span class="section-kicker">Products</span><strong id="pos-sale-products-title" data-pos-product-count>1 product</strong></div><button class="secondary-button pos-sale-product-add" type="button" data-pos-product-add><i class="fa-solid fa-plus"></i><span>Add Product</span></button></div>
            <div class="pos-sale-product-list" data-pos-product-list></div>
        </section>

        <details class="pos-sales-field" data-pos-section="delivery" open>
            <summary><strong>Delivery Charges</strong><span>GHS 0.00</span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body"><label for="pos_delivery_charge">Delivery charges</label><input id="pos_delivery_charge" name="delivery_charge" type="number" min="0" step="0.01" value="0.00"></div>
        </details>

        <template data-pos-product-template>
            <article class="pos-transfer-product pos-sale-product" data-pos-product>
                <div class="pos-transfer-product__head"><div class="pos-transfer-product__identity"><strong>Product <span data-pos-product-number></span></strong><small data-pos-product-summary>Not selected</small></div><div class="pos-transfer-product__head-actions"><button type="button" class="pos-transfer-edit" data-pos-product-edit aria-label="Edit product"><i class="fa-solid fa-pen"></i><span>Edit</span></button><button type="button" class="pos-transfer-remove" data-pos-product-remove aria-label="Remove product"><i class="fa-solid fa-trash-can"></i></button></div></div>
                <div class="pos-sale-product__fields">
                    <label class="pos-transfer-field pos-transfer-field--wide"><span>Brand</span><select name="products[brand_name][]" data-pos-product-brand data-popup-select data-popup-search data-popup-hide-empty required><option value="">Search or select brand</option><?php foreach($plugBrands as $brand):?><option value="<?=e((string)$brand['brand_name'])?>"><?=e((string)$brand['brand_name'])?></option><?php endforeach;?></select></label>
                    <label class="pos-transfer-field pos-transfer-field--wide"><span>Plug number</span><select name="products[spark_plug_id][]" data-pos-product-plug data-popup-select data-popup-search data-popup-hide-empty data-popup-empty-text="No plug numbers are available for the selected brand." required><option value="">Search or select plug number</option><?php foreach($sparkPlugs as $plug):?><option value="<?=(int)$plug['id']?>" data-brand-name="<?=e(strtolower(trim((string)$plug['brand_name'])))?>" data-current-price="<?=e((string)($plug['current_price']??''))?>" data-current-effective="<?=e((string)($plug['current_effective_at']??''))?>" data-previous-price="<?=e((string)($plug['previous_price']??''))?>" data-previous-effective="<?=e((string)($plug['previous_effective_at']??''))?>" data-price-history-id="<?=e((string)($plug['current_price_id']??''))?>" hidden disabled><?=e((string)$plug['plug_number'])?></option><?php endforeach;?></select></label>
                    <label class="pos-transfer-field"><span>Price</span><input name="products[price][]" type="number" min="0" step="0.01" placeholder="0.00" data-pos-product-price required><input type="hidden" name="products[price_history_id][]" data-pos-product-price-history></label>
                    <label class="pos-transfer-field"><span>Quantity</span><input name="products[quantity][]" type="number" min="1" step="1" value="4" data-pos-product-quantity required></label>
                    <label class="pos-transfer-field"><span>VIN number</span><input name="products[vin_number][]" maxlength="17" placeholder="Enter VIN number" data-pos-product-vin></label>
                    <small class="pos-price-history pos-sale-product__history" data-pos-product-history>No price history available.</small>
                </div>
            </article>
        </template>

        <details class="pos-sales-field" data-pos-section="referral" data-pos-temp-only hidden open>
            <summary><strong>How Did You Know Us?</strong><span>Select</span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body"><label for="pos_referral">Referral source</label><select id="pos_referral" name="referral_source" data-popup-select data-popup-search data-popup-hide-empty data-pos-auto-picker><option value="">Search or select referral source</option><?php foreach($referralSources as $source):?><option value="<?=e((string)$source['source_name'])?>"><?=e((string)$source['source_name'])?></option><?php endforeach;?></select></div>
        </details>

        <details class="pos-sales-field" data-pos-section="comment" open>
            <summary><strong>Comment</strong><span>Optional</span><i class="fa-solid fa-caret-down"></i></summary>
            <div class="pos-sales-field__body"><label for="pos_comment">Comment</label><textarea id="pos_comment" name="comment" rows="3" placeholder="Enter comment"></textarea></div>
        </details>

        <aside class="pos-sales-live-receipt" aria-live="polite" aria-labelledby="pos-live-receipt-title">
            <div class="pos-sales-live-receipt__head"><div><span class="section-kicker">Live receipt</span><strong id="pos-live-receipt-title">Sale total</strong></div><strong data-pos-live-total>GHS 0.00</strong></div>
            <div class="pos-sales-live-receipt__customer"><span>Bill to</span><strong data-pos-live-customer>Customer not selected</strong></div>
            <div class="pos-sales-live-receipt__table"><table><thead><tr><th>Product</th><th>Qty</th><th>Unit price</th><th>Amount</th></tr></thead><tbody data-pos-live-items><tr><td colspan="4">Add a product to build the receipt.</td></tr></tbody></table></div>
            <dl class="pos-sales-live-receipt__totals"><div><dt>Sales</dt><dd data-pos-live-subtotal>GHS 0.00</dd></div><div data-pos-live-discount-row hidden><dt>Customer discount</dt><dd data-pos-live-discount>− GHS 0.00</dd></div><div><dt>Delivery charges</dt><dd data-pos-live-delivery>GHS 0.00</dd></div><div class="is-net"><dt>Net sales</dt><dd data-pos-live-net>GHS 0.00</dd></div></dl>
        </aside>

        <div class="form-actions pos-sales-actions"><a class="secondary-button" href="<?=e($internalBackUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>Save Sale</span></button></div>
    </form><?php endif;?>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const syncRequiredAsterisks = function () {
        document.querySelectorAll('.pos-sales-form label').forEach((label) => {
            const forId=label.getAttribute('for');
            const control=forId?document.getElementById(forId):label.querySelector('input,select,textarea');
            const labelText=label.querySelector(':scope > span:first-child');
            let marker=label.querySelector('.required-asterisk');
            if(control?.required){if(!marker){marker=document.createElement('span');marker.className='required-asterisk';marker.setAttribute('aria-hidden','true');marker.textContent='*';(labelText||label).append(' ',marker);}}
            else marker?.remove();
        });
    };
    const requiredAsteriskRoot=document.querySelector('.pos-sales-form');
    if(requiredAsteriskRoot)new MutationObserver(syncRequiredAsterisks).observe(requiredAsteriskRoot,{childList:true,subtree:true,attributes:true,attributeFilter:['required']});
    const sectionButtons=Array.from(document.querySelectorAll('[data-pos-section-button]'));
    const sectionPanels=Array.from(document.querySelectorAll('[data-pos-section]'));
    const showSection=function(section){sectionButtons.forEach((button)=>button.classList.toggle('is-active',button.dataset.posSectionButton===section));sectionPanels.forEach((panel)=>panel.classList.toggle('is-active-section',panel.dataset.posSection===section));sectionPanels.filter((panel)=>panel.dataset.posSection===section&&!panel.hidden).forEach((panel)=>{if(panel.tagName==='DETAILS')panel.open=true;});};
    sectionButtons.forEach((button)=>button.addEventListener('click',()=>showSection(button.dataset.posSectionButton)));
    const salesType = document.querySelector('#pos_sales_type');
    const recipientWrap = document.querySelector('[data-pos-recipient-vendor]');
    const recipient = document.querySelector('#pos_recipient_vendor');
    const salesTypeSummary = document.querySelector('[data-pos-sales-type-summary]');
    const syncSalesType = function () { const indirect=salesType?.value==='indirect'; if(recipientWrap)recipientWrap.hidden=!indirect; if(recipient){recipient.disabled=!indirect;recipient.required=indirect;} if(salesTypeSummary)salesTypeSummary.textContent=indirect?'Indirect':'Direct'; syncRequiredAsterisks(); };
    salesType?.addEventListener('change',syncSalesType);syncSalesType();
    const fields = Array.from(document.querySelectorAll('.pos-sales-field'));
    const customerSelect = document.querySelector('#pos_customer');
    const modeInput = document.querySelector('[data-pos-customer-mode]');
    const modeButtons = Array.from(document.querySelectorAll('[data-pos-customer-mode-button]'));
    const registeredFields = Array.from(document.querySelectorAll('[data-pos-registered-field]'));
    const tempFields = Array.from(document.querySelectorAll('[data-pos-temp-field]'));
    const tempOnlySections = Array.from(document.querySelectorAll('[data-pos-temp-only]'));
    const customerTypeSummary = document.querySelector('[data-pos-customer-type-summary]');
    const customerTypeValue = document.querySelector('[data-pos-customer-type-value]');
    const customerTypeInput = document.querySelector('[data-pos-customer-type-input]');
    const referralInput = document.querySelector('#pos_referral');
    const syncCustomerType = function () {
        if (modeInput?.value === 'temp') return;
        const jobType = customerSelect?.selectedOptions[0]?.dataset.jobType?.trim() || '';
        const display = jobType || 'Not available';
        if (customerTypeSummary) customerTypeSummary.textContent = display;
        if (customerTypeValue) customerTypeValue.textContent = display;
        if (customerTypeInput) customerTypeInput.value = jobType;
    };
    customerSelect?.addEventListener('change', syncCustomerType);
    const setCustomerMode = function (mode) {
        const temporary = mode === 'temp';
        if (modeInput) modeInput.value = temporary ? 'temp' : 'registered';
        modeButtons.forEach((button) => button.classList.toggle('is-active', button.dataset.posCustomerModeButton === mode));
        registeredFields.forEach((field) => { field.hidden = temporary; field.open = false; field.querySelectorAll('input,select,textarea').forEach((control) => { control.disabled = temporary; }); });
        tempFields.forEach((field,index) => { field.hidden = !temporary; field.open = temporary && index === 0; field.querySelectorAll('input,select,textarea').forEach((control) => { control.disabled = !temporary; }); });
        tempOnlySections.forEach((section) => { section.hidden = !temporary; section.querySelectorAll?.('input,select,textarea').forEach((control) => { control.disabled = !temporary; }); });
        document.querySelectorAll('[data-required-when-temp]').forEach((control)=>{control.required=temporary;});
        [...registeredFields,...tempFields].filter((field)=>!field.hidden).forEach((field)=>{field.open=true;});
        if (customerTypeInput) { customerTypeInput.disabled = !temporary; customerTypeInput.value = temporary ? '' : customerTypeInput.value; if(typeof updateLookupButton==='function')updateLookupButton(customerTypeInput); }
        if (customerTypeValue) customerTypeValue.hidden = temporary;
        if (temporary) { if (customerTypeSummary) customerTypeSummary.textContent = 'Enter'; }
        else { if(referralInput)referralInput.value=''; if(sectionButtons.some((button)=>button.classList.contains('is-active')&&button.hidden))showSection('customer'); syncCustomerType(); }
        syncRequiredAsterisks();
    };
    modeButtons.forEach((button) => button.addEventListener('click', () => { setCustomerMode(button.dataset.posCustomerModeButton); updateProducts(); }));
    customerTypeInput?.addEventListener('change', () => { if (modeInput?.value === 'temp' && customerTypeSummary) customerTypeSummary.textContent = customerTypeInput.selectedOptions?.[0]?.textContent.trim() || 'Select'; });
    setCustomerMode('registered');
    /* Single-product controls replaced by the repeatable product editor.
    const syncPlugOptions = function () {
        const brandName = brandSelect?.value || '';
        plugOptions.forEach((option) => {
            if (!option.value) return;
            const match = brandName !== '' && option.dataset.brandName === brandName;
            option.hidden = !match;
            option.disabled = !match;
        });
        if (plugSelect?.selectedOptions[0]?.disabled) {
            plugSelect.value = '';
            plugSelect.dispatchEvent(new Event('change', {bubbles:true}));
        }
        if (typeof updateLookupButton === 'function' && plugSelect) updateLookupButton(plugSelect);
    };
    const formatPrice = (value) => Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
    const formatEffectiveDate = (value) => value ? new Date(value.replace(' ','T')).toLocaleDateString() : '';
    const syncPlugPrice = function () {
        const option = plugSelect?.selectedOptions[0];
        const current = option?.dataset.currentPrice || '';
        const previous = option?.dataset.previousPrice || '';
        if (priceInput) priceInput.value = current;
        if (priceHistoryInput) priceHistoryInput.value = option?.dataset.priceHistoryId || '';
        if (!priceHistoryText) return;
        if (!current) { priceHistoryText.textContent = 'No price history available. Enter the sale price.'; return; }
        const currentDate = formatEffectiveDate(option.dataset.currentEffective);
        const currentText = `Current: ${formatPrice(current)}${currentDate ? ` from ${currentDate}` : ''}`;
        const previousDate = formatEffectiveDate(option.dataset.previousEffective);
        priceHistoryText.textContent = previous ? `${currentText} · Previous: ${formatPrice(previous)}${previousDate ? ` from ${previousDate}` : ''}` : `${currentText} · No previous price`;
    };
    brandSelect?.addEventListener('change', syncPlugOptions);
    plugSelect?.addEventListener('change', syncPlugPrice);
    syncPlugOptions();
    syncPlugPrice(); */
    const formatPrice = (value) => Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
    const formatEffectiveDate = (value) => value ? new Date(value.replace(' ','T')).toLocaleDateString() : '';
    const productList = document.querySelector('[data-pos-product-list]');
    const productTemplate = document.querySelector('[data-pos-product-template]');
    const addProductButton = document.querySelector('[data-pos-product-add]');
    const productCount = document.querySelector('[data-pos-product-count]');
    const liveItems = document.querySelector('[data-pos-live-items]');
    const liveSubtotal = document.querySelector('[data-pos-live-subtotal]');
    const liveDiscount = document.querySelector('[data-pos-live-discount]');
    const liveDiscountRow = document.querySelector('[data-pos-live-discount-row]');
    const liveDelivery = document.querySelector('[data-pos-live-delivery]');
    const liveNet = document.querySelector('[data-pos-live-net]');
    const liveTotal = document.querySelector('[data-pos-live-total]');
    const liveCustomer = document.querySelector('[data-pos-live-customer]');
    const deliveryInput = document.querySelector('#pos_delivery_charge');
    const money = (value) => 'GHS ' + formatPrice(Math.max(0, Number(value) || 0));
    const updateLiveReceipt = function (products) {
        let subtotal=0,discountTotal=0;
        const rows=products.map(function(product){
            const brand=product.querySelector('[data-pos-product-brand]')?.value.trim()||'';
            const plugSelect=product.querySelector('[data-pos-product-plug]');
            const plug=plugSelect?.value?(plugSelect.selectedOptions[0]?.textContent.trim()||''):'';
            const quantity=Math.max(1,Number(product.querySelector('[data-pos-product-quantity]')?.value||4));
            const unitPrice=Math.max(0,Number(product.querySelector('[data-pos-product-price]')?.value||0));
            const currentPrice=Math.max(0,Number(plugSelect?.selectedOptions[0]?.dataset.currentPrice||0));
            const amount=quantity*unitPrice;subtotal+=amount;
            discountTotal+=currentPrice>unitPrice?quantity*(currentPrice-unitPrice):0;
            if(!brand&&!plug&&unitPrice<=0)return '';
            const row=document.createElement('tr');
            [([brand,plug].filter(Boolean).join(' ')||'Product'),String(quantity),money(unitPrice),money(amount)].forEach(function(value){const cell=document.createElement('td');cell.textContent=value;row.appendChild(cell);});
            return row.outerHTML;
        }).filter(Boolean);
        const delivery=Math.max(0,Number(deliveryInput?.value||0));
        const net=Math.max(0,subtotal-delivery);
        if(liveItems)liveItems.innerHTML=rows.join('')||'<tr><td colspan="4">Add a product to build the receipt.</td></tr>';
        if(liveSubtotal)liveSubtotal.textContent=money(subtotal);
        if(liveDiscount)liveDiscount.textContent='− '+money(discountTotal);
        if(liveDiscountRow)liveDiscountRow.hidden=discountTotal<=0;
        if(liveDelivery)liveDelivery.textContent=money(delivery);
        if(liveNet)liveNet.textContent=money(net);
        if(liveTotal)liveTotal.textContent=money(net);
        let customer='Customer not selected';
        if(modeInput?.value==='temp')customer=document.querySelector('#pos_temp_customer_name')?.value.trim()||customer;
        else if(customerSelect?.value)customer=customerSelect.selectedOptions[0]?.textContent.split(' · ')[0].trim()||customer;
        if(liveCustomer)liveCustomer.textContent=customer;
    };
    const updateProducts = function () {
        const products = Array.from(productList?.querySelectorAll('[data-pos-product]') || []);
        products.forEach(function (product,index) {
            const brandSelect = product.querySelector('[data-pos-product-brand]');
            const plugSelect = product.querySelector('[data-pos-product-plug]');
            const price = Number(product.querySelector('[data-pos-product-price]')?.value || 0);
            const quantity = Math.max(1,Number(product.querySelector('[data-pos-product-quantity]')?.value || 4));
            const brand = brandSelect?.value ? brandSelect.selectedOptions[0]?.textContent.trim() || '' : '';
            const plug = plugSelect?.value ? plugSelect.selectedOptions[0]?.textContent.trim() || '' : '';
            const summary = product.querySelector('[data-pos-product-summary]');
            const number = product.querySelector('[data-pos-product-number]');
            const remove = product.querySelector('[data-pos-product-remove]');
            if(number)number.textContent=String(index+1);
            if(remove)remove.hidden=products.length===1;
            if(summary)summary.textContent=([brand,plug].filter(Boolean).join(' · ')||'Not selected')+(price>0?' · '+quantity+' × GHS '+formatPrice(price):'');
        });
        if(productCount)productCount.textContent=products.length+(products.length===1?' product':' products');
        updateLiveReceipt(products);
    };
    const syncProductBrand = function (product) {
        const brandSelect=product.querySelector('[data-pos-product-brand]');
        const plugSelect=product.querySelector('[data-pos-product-plug]');
        if(!brandSelect||!plugSelect)return;
        plugSelect.value='';
        Array.from(plugSelect.options).forEach(function(option){
            if(!option.value){option.hidden=false;option.disabled=false;return;}
            const selectedBrand=brandSelect.value.trim().toLocaleLowerCase();
            const matches=selectedBrand!==''&&option.dataset.brandName===selectedBrand;
            option.hidden=!matches;option.disabled=!matches;
        });
        const plugButton=product.querySelector('[data-lookup-button="'+plugSelect.id+'"]');
        if(plugButton){plugButton.disabled=brandSelect.value==='';plugButton.classList.toggle('is-disabled',brandSelect.value==='');}
        product.querySelector('[data-pos-product-price]').value='';
        product.querySelector('[data-pos-product-price-history]').value='';
        product.querySelector('[data-pos-product-history]').textContent='No price history available.';
        if(typeof updateLookupButton==='function')updateLookupButton(plugSelect);
        updateProducts();
    };
    const syncProductPlug = function (product) {
        const option=product.querySelector('[data-pos-product-plug]')?.selectedOptions[0];
        const current=option?.dataset.currentPrice||'';
        const previous=option?.dataset.previousPrice||'';
        const priceInput=product.querySelector('[data-pos-product-price]');
        const historyInput=product.querySelector('[data-pos-product-price-history]');
        const historyText=product.querySelector('[data-pos-product-history]');
        if(priceInput)priceInput.value=current;
        if(historyInput)historyInput.value=option?.dataset.priceHistoryId||'';
        if(historyText){
            if(!current)historyText.textContent='No price history available. Enter the sale price.';
            else{
                const currentDate=formatEffectiveDate(option.dataset.currentEffective);
                const currentText=`Current: ${formatPrice(current)}${currentDate?` from ${currentDate}`:''}`;
                const previousDate=formatEffectiveDate(option.dataset.previousEffective);
                historyText.textContent=previous?`${currentText} · Previous: ${formatPrice(previous)}${previousDate?` from ${previousDate}`:''}`:`${currentText} · No previous price`;
            }
        }
        updateProducts();
    };
    const addProduct = function () {
        if(!productList||!productTemplate)return;
        productList.querySelectorAll('[data-pos-product]').forEach((product)=>product.classList.add('is-collapsed'));
        const fragment=productTemplate.content.cloneNode(true);
        const product=fragment.querySelector('[data-pos-product]');
        productList.appendChild(fragment);
        product?.querySelectorAll('select[data-popup-select]').forEach(function(select){
            if(typeof createLookupButton==='function'){
                createLookupButton(select,{buttonClass:'form-lookup-button',emptyText:select.options[0]?.textContent.trim()||''});
                select.addEventListener('change',()=>updateLookupButton(select));
            }
        });
        const plugSelect=product?.querySelector('[data-pos-product-plug]');
        const plugButton=plugSelect?product.querySelector('[data-lookup-button="'+plugSelect.id+'"]'):null;
        if(plugButton){plugButton.disabled=true;plugButton.classList.add('is-disabled');}
        updateProducts();
    };
    addProductButton?.addEventListener('click',addProduct);
    productList?.addEventListener('change',function(event){
        const product=event.target.closest('[data-pos-product]');if(!product)return;
        if(event.target.matches('[data-pos-product-brand]'))syncProductBrand(product);
        if(event.target.matches('[data-pos-product-plug]'))syncProductPlug(product);
    });
    productList?.addEventListener('input',function(event){
        if(event.target.matches('[data-pos-product-vin]'))event.target.value=event.target.value.toUpperCase().replace(/[^A-HJ-NPR-Z0-9]/g,'').slice(0,17);
        updateProducts();
    });
    deliveryInput?.addEventListener('input',updateProducts);
    customerSelect?.addEventListener('change',updateProducts);
    document.querySelector('#pos_temp_customer_name')?.addEventListener('input',updateProducts);
    productList?.addEventListener('click',function(event){
        const edit=event.target.closest('[data-pos-product-edit]');
        if(edit){const selected=edit.closest('[data-pos-product]');productList.querySelectorAll('[data-pos-product]').forEach((product)=>product.classList.toggle('is-collapsed',product!==selected));selected?.querySelector('[data-lookup-button]')?.focus();return;}
        const remove=event.target.closest('[data-pos-product-remove]');if(!remove)return;
        remove.closest('[data-pos-product]')?.remove();
        const remaining=Array.from(productList.querySelectorAll('[data-pos-product]'));
        if(remaining.length&&remaining.every((product)=>product.classList.contains('is-collapsed')))remaining[remaining.length-1].classList.remove('is-collapsed');
        updateProducts();
    });
    const salesForm=document.querySelector('.pos-sales-form');
    const validationMessage=document.querySelector('[data-pos-validation-message]');
    const fieldName=function(control){const label=control.closest('label')?.querySelector('span')?.textContent.trim()||control.closest('.pos-sales-field__body')?.querySelector('label')?.textContent.trim();return label||control.getAttribute('placeholder')||'required field';};
    salesForm?.addEventListener('submit',function(event){
        if(validationMessage){validationMessage.hidden=true;validationMessage.textContent='';}
        const required=Array.from(salesForm.querySelectorAll('[required]')).filter((control)=>!control.disabled&&!control.closest('[hidden]'));
        const invalid=required.find((control)=>!control.checkValidity());
        if(!invalid)return;
        event.preventDefault();
        const section=invalid.closest('[data-pos-section]')?.dataset.posSection||'customer';showSection(section);
        const product=invalid.closest('[data-pos-product]');if(product)productList.querySelectorAll('[data-pos-product]').forEach((item)=>item.classList.toggle('is-collapsed',item!==product));
        if(validationMessage){validationMessage.textContent='Please complete '+fieldName(invalid)+' before saving the sale.';validationMessage.hidden=false;validationMessage.scrollIntoView({behavior:'smooth',block:'center'});}
        window.setTimeout(function(){const lookup=invalid.closest('.pos-transfer-field,.pos-sales-field__body')?.querySelector('[data-lookup-button]');(lookup||invalid).focus({preventScroll:true});},250);
    });
    addProduct();
    fields.forEach(function (field, index) {
        const picker = field.querySelector('[data-pos-auto-picker]');
        if (!picker) return;

        field.addEventListener('toggle', function () {
            if (!field.open) return;
            window.requestAnimationFrame(function () {
                picker.focus({preventScroll: true});
                if (typeof picker.showPicker === 'function') {
                    try { picker.showPicker(); } catch (error) { /* Focus remains as fallback. */ }
                }
            });
        });

        picker.addEventListener('change', function () {
            if (!picker.value) return;
            const summaryValue = field.querySelector('summary span');
            if (summaryValue) summaryValue.textContent = picker.tagName === 'SELECT' ? picker.selectedOptions[0].textContent.trim() : picker.value.trim();
            if (!field.closest('.pos-sales-form--sectioned')) field.open = false;
            let nextField = fields.slice(index + 1).find((candidate) => !candidate.hidden);
            if (nextField?.matches('[data-pos-customer-type-field]') && modeInput?.value === 'registered') {
                const productsPanel = document.querySelector('.pos-sale-products');
                productsPanel?.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                window.setTimeout(() => productsPanel?.querySelector('[data-lookup-button]')?.focus(), 250);
                return;
            }
            if (nextField) {
                nextField.open = true;
                nextField.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }
        });
    });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
