<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('pos');
if(!is_admin_user()){http_response_code(403);exit('Access denied.');}
ensure_pos_credit_payments_schema();

$error='';
$message=(string)($_GET['saved']??'')!==''?'Credit payment saved successfully.':'';
$selectedCustomerId=max(0,(int)($_POST['customer_id']??0));
$selectedSaleId=max(0,(int)($_POST['sale_id']??0));

if($_SERVER['REQUEST_METHOD']==='POST'){
    $paymentDate=trim((string)($_POST['payment_date']??''));
    $amount=round((float)($_POST['amount']??0),2);
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$paymentDate))$error='Select a valid payment date.';
    elseif($selectedCustomerId<1)$error='Select a customer.';
    elseif($selectedSaleId<1)$error='Select a credit invoice.';
    elseif($amount<=0)$error='Enter a payment amount greater than zero.';
    else{
        try{
            db()->beginTransaction();
            $saleStatement=db()->prepare("SELECT id,customer_id,amount_less_commission FROM pos_sales WHERE id=? AND customer_id=? AND payment_type='credit' AND status='completed' FOR UPDATE");
            $saleStatement->execute([$selectedSaleId,$selectedCustomerId]);$sale=$saleStatement->fetch();
            if(!$sale)throw new DomainException('The selected credit invoice is no longer available.');
            $paidStatement=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_credit_payments WHERE sale_id=? AND status='completed'");
            $paidStatement->execute([$selectedSaleId]);
            $balance=round((float)$sale['amount_less_commission']-(float)$paidStatement->fetchColumn(),2);
            if($balance<=0)throw new DomainException('This invoice has already been paid in full.');
            if($amount>$balance)throw new DomainException('Payment cannot exceed the outstanding balance of GHS '.number_format($balance,2).'.');
            $paymentRef='CP-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(2)));
            db()->prepare('INSERT INTO pos_credit_payments(payment_ref,sale_id,customer_id,payment_date,amount,recorded_by_user_id) VALUES(?,?,?,?,?,?)')->execute([$paymentRef,$selectedSaleId,(int)$sale['customer_id'],$paymentDate,$amount,current_user_id()]);
            db()->commit();
            header('Location: '.app_url('credit-payment.php?saved='.rawurlencode($paymentRef)));exit;
        }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error=$exception instanceof DomainException?$exception->getMessage():'The credit payment could not be saved.';}
    }
}

$invoices=db()->query("SELECT s.id,s.sale_ref,s.sale_date,s.customer_id,s.customer_name,s.amount_less_commission invoice_total,COALESCE(SUM(CASE WHEN p.status='completed' THEN p.amount ELSE 0 END),0) amount_paid,s.amount_less_commission-COALESCE(SUM(CASE WHEN p.status='completed' THEN p.amount ELSE 0 END),0) balance FROM pos_sales s LEFT JOIN pos_credit_payments p ON p.sale_id=s.id WHERE s.payment_type='credit' AND s.status='completed' GROUP BY s.id HAVING balance>0 ORDER BY s.sale_date DESC,s.id DESC")->fetchAll();
$customers=[];foreach($invoices as $invoice){$key=(int)($invoice['customer_id']??0);if(!isset($customers[$key]))$customers[$key]=(string)$invoice['customer_name'];}
$payments=db()->query("SELECT p.payment_ref,p.payment_date,p.amount,p.status,s.sale_ref,s.customer_name FROM pos_credit_payments p INNER JOIN pos_sales s ON s.id=p.sale_id ORDER BY p.payment_date DESC,p.id DESC LIMIT 100")->fetchAll();
$pageTitle='Credit Payment';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'POS','url'=>app_url('pos.php')],['label'=>'Admin','url'=>app_url('pos.php?view=admin')],['label'=>'Credit Payment']];
$internalBackUrl=app_url('pos.php?view=admin');
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel credit-payment-simple">
    <div class="management-heading"><div><span class="section-kicker">POS Collections</span><h1>Credit Payment</h1><p>Receive a payment against a customer credit invoice.</p></div><div class="management-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div></div>
    <?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?>
    <?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
    <form class="record-form" method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <div class="form-grid">
            <div class="form-field"><label for="credit_payment_date">Date</label><input id="credit_payment_date" name="payment_date" type="date" value="<?=e((string)($_POST['payment_date']??date('Y-m-d')))?>" required></div>
            <div class="form-field"><label for="credit_customer">Customer</label><select id="credit_customer" name="customer_id" data-popup-select data-popup-search required><option value="">Search or select customer</option><?php foreach($customers as $id=>$name):?><option value="<?=$id?>" <?=$selectedCustomerId===$id?'selected':''?>><?=e($name)?></option><?php endforeach;?></select></div>
            <div class="form-field"><label for="credit_invoice">Invoice number</label><select id="credit_invoice" name="sale_id" data-popup-select data-popup-search required><option value="">Select invoice</option><?php foreach($invoices as $invoice):?><option value="<?=(int)$invoice['id']?>" data-customer-id="<?=(int)($invoice['customer_id']??0)?>" data-balance="<?=e(number_format((float)$invoice['balance'],2,'.',''))?>" <?=$selectedSaleId===(int)$invoice['id']?'selected':''?>><?=e($invoice['sale_ref'].' - GHS '.number_format((float)$invoice['balance'],2))?></option><?php endforeach;?></select></div>
            <div class="form-field"><label for="credit_payment_amount">Amount</label><div class="credit-payment-money"><b>GHS</b><input id="credit_payment_amount" name="amount" type="number" min="0.01" step="0.01" value="<?=e((string)($_POST['amount']??''))?>" placeholder="0.00" required></div></div>
        </div>
        <div class="credit-payment-balance-line"><span>Outstanding balance</span><strong data-credit-balance>GHS 0.00</strong></div>
        <div class="form-actions"><a class="secondary-button" href="<?=e($internalBackUrl)?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>Save Payment</span></button></div>
    </form>
</section>
<section class="management-panel credit-payment-history"><div class="management-heading"><div><span class="section-kicker">Ledger</span><h2>Recent Credit Payments</h2></div></div><?php if(!$payments):?><p class="empty-state">No credit payments recorded yet.</p><?php else:?><div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Payment</th><th>Invoice</th><th>Customer</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php foreach($payments as $payment):?><tr><td><?=e(date('d M Y',strtotime($payment['payment_date'])))?></td><td><?=e($payment['payment_ref'])?></td><td><?=e($payment['sale_ref'])?></td><td><?=e($payment['customer_name'])?></td><td>GHS <?=number_format((float)$payment['amount'],2)?></td><td><span class="status-badge <?=$payment['status']==='completed'?'is-active':'is-inactive'?>"><?=e(ucfirst($payment['status']))?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script>
document.addEventListener('DOMContentLoaded',()=>{const customer=document.getElementById('credit_customer'),invoice=document.getElementById('credit_invoice'),balance=document.querySelector('[data-credit-balance]'),amount=document.getElementById('credit_payment_amount');const options=[...invoice.options].slice(1);function update(){const customerId=customer.value;options.forEach(option=>{option.hidden=!!customerId&&option.dataset.customerId!==customerId;option.disabled=option.hidden;});if(invoice.selectedOptions[0]?.disabled)invoice.value='';const value=invoice.selectedOptions[0]?.dataset.balance||'0.00';balance.textContent='GHS '+Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});amount.max=value;}customer.addEventListener('change',update);invoice.addEventListener('change',update);update();});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
