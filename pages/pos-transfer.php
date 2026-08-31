<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('pos');
if(current_vendor_personnel()&&!can_access_menu_item('pos_transfer')){http_response_code(403);exit('Transfer access has not been assigned to your account.');}
$transferPersonnel=current_vendor_personnel();
if (current_user_role() === 'vendor' || ($transferPersonnel&&(int)$transferPersonnel['can_transfer']===1)) {
    header('Location: ' . app_url('pos-incoming-transfers.php'));
    exit;
}
ensure_pos_transfer_schema();

$plugBrands = db()->query(
    "SELECT DISTINCT brand_name FROM spark_plugs WHERE is_active=1 AND TRIM(brand_name)<>'' ORDER BY brand_name"
)->fetchAll();
$sparkPlugs = db()->query(
    "SELECT sp.id,sp.brand_name,sp.plug_number,
            (SELECT ph.id FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) AS price_history_id,
            (SELECT COALESCE(NULLIF(ph.wholesale,0),ROUND(ph.price*0.75,2)) FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) AS current_price
     FROM spark_plugs sp
     WHERE sp.is_active=1
     ORDER BY sp.brand_name,sp.plug_number"
)->fetchAll();
$vendors = db()->query(
    "SELECT v.id,v.vendor_name,v.phone,l.town_name
     FROM vendors v
     LEFT JOIN locations l ON l.id=v.location_id
     WHERE v.is_active=1
     ORDER BY v.vendor_name,v.id"
)->fetchAll();

$message=(string)($_GET['saved']??'')!==''?'Transfer '.trim((string)$_GET['saved']).' dispatched successfully.':'';
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $transferDate=trim((string)($_POST['transfer_date']??''));
    $vendorId=max(0,(int)($_POST['vendor_id']??0));
    $note=trim((string)($_POST['note']??''));
    $postedProducts=is_array($_POST['products']??null)?$_POST['products']:[];
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$transferDate))$error='Select a valid transfer date.';
    else{
        $vendorStatement=db()->prepare("SELECT v.id,v.vendor_name,v.phone,v.email,v.area,l.town_name,l.region_name FROM vendors v LEFT JOIN locations l ON l.id=v.location_id WHERE v.id=? AND v.is_active=1 LIMIT 1");
        $vendorStatement->execute([$vendorId]);$selectedVendor=$vendorStatement->fetch();
        if(!$selectedVendor)$error='Select a valid active vendor.';
    }
    $transferProducts=[];$transferTotal=0.0;
    if($error===''){
        $brands=is_array($postedProducts['brand']??null)?$postedProducts['brand']:[];
        $plugIds=is_array($postedProducts['spark_plug_id']??null)?$postedProducts['spark_plug_id']:[];
        $boxQuantities=is_array($postedProducts['box_quantity']??null)?$postedProducts['box_quantity']:[];
        if(!$plugIds)$error='Add at least one product.';
        $productStatement=db()->prepare("SELECT sp.id,sp.brand_name,sp.plug_number,
            (SELECT ph.id FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) price_history_id,
            (SELECT COALESCE(NULLIF(ph.wholesale,0),ROUND(ph.price*0.75,2)) FROM plug_price_history ph WHERE ph.spark_plug_id=sp.id AND ph.effective_at<=NOW() ORDER BY ph.effective_at DESC,ph.id DESC LIMIT 1) unit_price
            FROM spark_plugs sp
            WHERE sp.id=? AND sp.brand_name=? AND sp.is_active=1 LIMIT 1");
        foreach($plugIds as $index=>$plugId){
            if($error!=='')break;
            $brand=trim((string)($brands[$index]??''));$boxes=max(0,(int)($boxQuantities[$index]??0));
            $productStatement->execute([max(0,(int)$plugId),$brand]);$product=$productStatement->fetch();
            if(!$product){$error='Select a valid brand and plug number for product '.($index+1).'.';break;}
            if($boxes<1){$error='Enter the number of boxes for product '.($index+1).'.';break;}
            $unitPrice=round((float)($product['unit_price']??0),2);
            if($unitPrice<=0){$error='The selected plug for product '.($index+1).' has no current price.';break;}
            $piecesPerBox=4;$totalPieces=$boxes*$piecesPerBox;$lineTotal=round($totalPieces*$unitPrice,2);
            $transferTotal+=$lineTotal;
            $transferProducts[]=['product'=>$product,'boxes'=>$boxes,'pieces_per_box'=>$piecesPerBox,'total_pieces'=>$totalPieces,'unit_price'=>$unitPrice,'total'=>$lineTotal];
        }
    }
    if($error===''){
        try{
            db()->beginTransaction();
            $transferRef=next_project_reference('pos_transfer');
            $vendorLocation=implode(', ',array_filter([(string)($selectedVendor['area']??''),(string)($selectedVendor['town_name']??''),(string)($selectedVendor['region_name']??'')]));
            db()->prepare("INSERT INTO pos_transfers(transfer_ref,transfer_date,vendor_id,vendor_name,vendor_phone,vendor_email,vendor_location,gross_amount,commission_amount,total_amount,status,note,recorded_by_user_id,dispatched_at) VALUES(?,?,?,?,?,?,?,?,?,?,'dispatched',?,?,NOW())")
                ->execute([$transferRef,$transferDate,$vendorId,(string)$selectedVendor['vendor_name'],(string)($selectedVendor['phone']??'')?:null,(string)($selectedVendor['email']??'')?:null,$vendorLocation?:null,round($transferTotal,2),0.00,round($transferTotal,2),$note?:null,current_user_id()]);
            $transferId=(int)db()->lastInsertId();
            $insertItem=db()->prepare('INSERT INTO pos_transfer_items(transfer_id,spark_plug_id,price_history_id,commission_id,brand_name,plug_number,box_quantity,pieces_per_box,total_pieces,unit_price,gross_amount,commission_percentage,commission_amount,total_amount) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($transferProducts as $line){$product=$line['product'];$insertItem->execute([$transferId,(int)$product['id'],(int)($product['price_history_id']??0)?:null,null,(string)$product['brand_name'],(string)$product['plug_number'],$line['boxes'],$line['pieces_per_box'],$line['total_pieces'],$line['unit_price'],$line['total'],0.00,0.00,$line['total']]);}
            db()->commit();header('Location: '.app_url('pos-transfer.php?saved='.rawurlencode($transferRef)));exit;
        }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error='The transfer could not be saved.';}
    }
}

$pageTitle = 'POS Transfer';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'POS', 'url' => app_url('pos.php')],
    ['label' => 'Transfer'],
];
require_once __DIR__ . '/../includes/header.php';
?>
<section class="content-panel pos-transfer-panel" aria-labelledby="pos-transfer-title">
    <div class="management-heading pos-transfer-heading">
        <div>
            <span class="section-kicker">POS</span>
            <h1 id="pos-transfer-title">To Vendor</h1>
            <p>Select a vendor, brand, plug number, and quantity. The receipt fills in as you go.</p>
        </div>
        <div class="management-icon"><i class="fa-solid fa-right-left"></i></div>
    </div>
    <?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?>
    <?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>

    <form class="pos-transfer-form pos-transfer-form--invoice" method="post" autocomplete="off" data-pos-transfer-form>
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <div class="pos-transfer-workspace">
        <div class="pos-transfer-entry">
        <div class="pos-transfer-grid is-active-section" data-transfer-section="date">
            <label class="pos-transfer-field pos-transfer-field--wide" for="transfer_date">
                <input id="transfer_date" name="transfer_date" type="date" value="<?=e(date('Y-m-d'))?>" aria-label="Transfer date" required>
            </label>
        </div>
        <div class="pos-transfer-grid" data-transfer-section="vendor">
            <label class="pos-transfer-field pos-transfer-field--wide" for="transfer_vendor">
                <select id="transfer_vendor" name="vendor_id" aria-label="Vendor" data-vendor-selector data-popup-select data-popup-search data-popup-hide-empty required>
                    <option value="">Search or select vendor</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?= (int) $vendor['id'] ?>"><?= e(implode(' · ', array_filter([(string) $vendor['vendor_name'], (string) ($vendor['phone'] ?? '')]))) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="pos-transfer-products" data-transfer-section="product">
            <div class="pos-transfer-products__heading">
                <button class="secondary-button pos-transfer-add" type="button" data-transfer-add><i class="fa-solid fa-plus"></i><span>Add Product</span></button>
            </div>
            <div class="pos-transfer-product-list" data-transfer-product-list></div>
        </div>

        <template data-transfer-product-template>
            <article class="pos-transfer-product" data-transfer-product>
                <div class="pos-transfer-product__head">
                    <div class="pos-transfer-product__identity">
                        <strong>Product <span data-product-number></span></strong>
                        <small data-transfer-product-summary>Not selected</small>
                    </div>
                    <div class="pos-transfer-product__head-actions">
                        <button type="button" class="pos-transfer-edit" data-transfer-edit aria-label="Edit product"><i class="fa-solid fa-pen"></i><span>Edit</span></button>
                        <button type="button" class="pos-transfer-remove" data-transfer-remove aria-label="Remove product"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                </div>
                <div class="pos-transfer-product__fields">
                    <label class="pos-transfer-field pos-transfer-field--wide">
                        <select name="products[brand][]" aria-label="Spark plug brand" data-transfer-brand data-popup-select data-popup-search data-popup-hide-empty required>
                            <option value="">Search or select brand</option>
                            <?php foreach ($plugBrands as $brand): ?>
                                <option value="<?= e((string) $brand['brand_name']) ?>"><?= e((string) $brand['brand_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="pos-transfer-field pos-transfer-field--wide">
                        <select name="products[spark_plug_id][]" aria-label="Plug number" data-transfer-plug data-popup-select data-popup-search data-popup-hide-empty data-popup-empty-text="No plug numbers are available for the selected brand." required>
                            <option value="">Search or select plug number</option>
                            <?php foreach ($sparkPlugs as $plug): ?>
                                <option value="<?= (int) $plug['id'] ?>" data-brand="<?= e(strtolower(trim((string) $plug['brand_name']))) ?>" data-current-price="<?= e((string) ($plug['current_price'] ?? '')) ?>" data-price-history-id="<?= e((string) ($plug['price_history_id'] ?? '')) ?>" hidden disabled><?= e((string) $plug['plug_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="pos-transfer-field">
                        <input name="products[box_quantity][]" type="number" min="1" step="1" inputmode="numeric" placeholder="Quantity (boxes)" aria-label="Quantity in boxes" data-transfer-quantity required>
                    </label>
                    <label class="pos-transfer-field">
                        <div class="pos-transfer-money"><span>GH&#8373;</span><input name="products[unit_price][]" type="number" min="0" step="0.01" placeholder="Wholesale price per piece" aria-label="Wholesale price per piece" data-transfer-unit-price readonly><input name="products[price_history_id][]" type="hidden" data-transfer-price-history></div>
                    </label>
                </div>
                <div class="pos-transfer-line-total"><span><small>Pieces</small><strong data-transfer-piece-count>0 pieces</strong><em>4 per box</em></span><span><small>Line total</small><strong data-transfer-line-total>GH&#8373; 0.00</strong><em>At wholesale price</em></span></div>
            </article>
        </template>

        <div class="pos-transfer-note" data-transfer-section="comment"><textarea id="transfer_note" name="note" rows="3" placeholder="Add a transfer note (optional)" aria-label="Transfer note"></textarea></div>

        <div class="form-actions pos-transfer-actions">
            <a class="secondary-button" href="<?= e(app_url('pos.php')) ?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>
            <button class="login-button" type="submit"><i class="fa-solid fa-paper-plane"></i><span>Send Transfer</span></button>
        </div>
        </div>
        <aside class="pos-transfer-live-receipt" aria-live="polite" aria-labelledby="transfer-live-receipt-title">
            <div class="pos-transfer-live-receipt__head"><div><span class="section-kicker">Live receipt</span><strong id="transfer-live-receipt-title">Transfer Invoice</strong></div><strong data-transfer-receipt-total>GH₵ 0.00</strong></div>
            <div class="pos-transfer-live-receipt__meta"><span><small>Transfer date</small><strong data-transfer-receipt-date>Not selected</strong></span><span><small>Vendor</small><strong data-transfer-receipt-vendor>Vendor not selected</strong></span></div>
            <div class="pos-transfer-live-receipt__table"><table><thead><tr><th>Brand / Plug</th><th>Boxes</th><th>Pieces</th><th>Price</th><th>Amount</th></tr></thead><tbody data-transfer-receipt-items><tr><td colspan="5">Select a product to build the receipt.</td></tr></tbody></table></div>
            <div class="pos-transfer-live-receipt__note" data-transfer-receipt-note-row hidden><small>Note</small><p data-transfer-receipt-note></p></div>
            <dl class="pos-transfer-live-receipt__totals"><div class="is-net"><dt>Total</dt><dd data-transfer-receipt-net>GH₵ 0.00</dd></div></dl>
        </aside>
        </div>
    </form>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.querySelector('[data-transfer-product-list]');
    const template = document.querySelector('[data-transfer-product-template]');
    const addButton = document.querySelector('[data-transfer-add]');
    const count = document.querySelector('[data-transfer-product-count]');
    const total = document.querySelector('[data-transfer-total]');
    const form = document.querySelector('[data-pos-transfer-form]');
    const sectionButtons = Array.from(document.querySelectorAll('[data-transfer-section-button]'));
    const sectionPanels = Array.from(document.querySelectorAll('[data-transfer-section]'));
    const showSection = function (section) {
        sectionButtons.forEach(function (button) { button.classList.toggle('is-active', button.dataset.transferSectionButton === section); });
        sectionPanels.forEach(function (panel) { panel.classList.toggle('is-active-section', panel.dataset.transferSection === section); });
    };
    sectionButtons.forEach(function (button) { button.addEventListener('click', function () { showSection(button.dataset.transferSectionButton); }); });
    const receiptItems = document.querySelector('[data-transfer-receipt-items]');
    const money = function (amount) {
        return 'GH\u20B5 ' + amount.toLocaleString('en-GH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };
    const updateTotal = function () {
        let transferTotal = 0;
        const receiptRows = [];
        list?.querySelectorAll('[data-transfer-product]').forEach(function (product) {
            const boxes = Math.max(0, Number(product.querySelector('[data-transfer-quantity]')?.value || 0));
            const pieces = boxes * 4;
            const unitPrice = Math.max(0, Number(product.querySelector('[data-transfer-unit-price]')?.value || 0));
            const lineTotal = pieces * unitPrice;
            transferTotal += lineTotal;
            const output = product.querySelector('[data-transfer-line-total]');
            const pieceOutput = product.querySelector('[data-transfer-piece-count]');
            const summary = product.querySelector('[data-transfer-product-summary]');
            const brandSelect = product.querySelector('[data-transfer-brand]');
            const plugSelect = product.querySelector('[data-transfer-plug]');
            const brand = brandSelect?.value ? brandSelect.selectedOptions[0]?.textContent.trim() || '' : '';
            const plug = plugSelect?.value ? plugSelect.selectedOptions[0]?.textContent.trim() || '' : '';
            if (brand || plug || boxes > 0 || unitPrice > 0) receiptRows.push({product:[brand,plug].filter(Boolean).join(' ')||'Product not selected',boxes:boxes,pieces:pieces,unitPrice:unitPrice,amount:lineTotal});
            if (output) output.textContent = money(lineTotal);
            if (pieceOutput) pieceOutput.textContent = pieces.toLocaleString('en-GH') + (pieces === 1 ? ' piece' : ' pieces');
            if (summary) {
                const productName = [brand, plug].filter(Boolean).join(' · ') || 'Not selected';
                summary.textContent = productName + (boxes > 0 ? ' · ' + boxes + (boxes === 1 ? ' box' : ' boxes') : '') + (lineTotal > 0 ? ' · ' + money(lineTotal) : '');
            }
        });
        if (total) total.textContent = money(transferTotal);
        document.querySelectorAll('[data-transfer-receipt-total],[data-transfer-receipt-net]').forEach(function (element) { element.textContent = money(transferTotal); });
        if (receiptItems) {
            receiptItems.replaceChildren();
            if (!receiptRows.length) {
                const row=document.createElement('tr');const cell=document.createElement('td');cell.colSpan=5;cell.textContent='Select a product to build the receipt.';row.appendChild(cell);receiptItems.appendChild(row);
            } else receiptRows.forEach(function (item) {
                const row=document.createElement('tr');[item.product,item.boxes.toLocaleString('en-GH'),item.pieces.toLocaleString('en-GH'),money(item.unitPrice),money(item.amount)].forEach(function(value){const cell=document.createElement('td');cell.textContent=value;row.appendChild(cell);});receiptItems.appendChild(row);
            });
        }
        const dateInput = document.querySelector('#transfer_date');
        const dateOutput = document.querySelector('[data-transfer-receipt-date]');
        if (dateOutput) { const parsed=dateInput?.value ? new Date(dateInput.value+'T00:00:00') : null; dateOutput.textContent=parsed&&!Number.isNaN(parsed.getTime())?parsed.toLocaleDateString('en-GH',{day:'2-digit',month:'short',year:'numeric'}):'Not selected'; }
        const vendorSelect = document.querySelector('#transfer_vendor');
        const vendorOutput = document.querySelector('[data-transfer-receipt-vendor]');
        if (vendorOutput) vendorOutput.textContent=vendorSelect?.value?(vendorSelect.selectedOptions[0]?.textContent.trim()||'Vendor selected'):'Vendor not selected';
        const note = document.querySelector('#transfer_note')?.value.trim() || '';
        const noteRow = document.querySelector('[data-transfer-receipt-note-row]');
        const noteOutput = document.querySelector('[data-transfer-receipt-note]');
        if (noteRow) noteRow.hidden=note==='';
        if (noteOutput) noteOutput.textContent=note;
    };
    const refreshProducts = function () {
        const products = Array.from(list?.querySelectorAll('[data-transfer-product]') || []);
        products.forEach(function (product, index) {
            const number = product.querySelector('[data-product-number]');
            const remove = product.querySelector('[data-transfer-remove]');
            if (number) number.textContent = String(index + 1);
            if (remove) remove.hidden = products.length === 1;
        });
        if (count) count.textContent = products.length + (products.length === 1 ? ' product' : ' products');
        updateTotal();
    };
    const addProduct = function () {
        if (!list || !template) return;
        list.querySelectorAll('[data-transfer-product]').forEach(function (existingProduct) {
            existingProduct.classList.add('is-collapsed');
        });
        const fragment = template.content.cloneNode(true);
        const product = fragment.querySelector('[data-transfer-product]');
        list.appendChild(fragment);
        product?.querySelectorAll('select[data-popup-select]').forEach(function (select) {
            if (typeof createLookupButton === 'function') {
                createLookupButton(select, {
                    buttonClass: 'form-lookup-button',
                    emptyText: select.options[0]?.textContent.trim() || '',
                });
                select.addEventListener('change', function () { updateLookupButton(select); });
            }
        });
        const plugSelect = product?.querySelector('[data-transfer-plug]');
        const plugButton = plugSelect ? product.querySelector('[data-lookup-button="' + plugSelect.id + '"]') : null;
        if (plugButton) {
            plugButton.disabled = true;
            plugButton.classList.add('is-disabled');
        }
        refreshProducts();
    };
    const syncBrand = function (product) {
        const brandSelect = product.querySelector('[data-transfer-brand]');
        const plugSelect = product.querySelector('[data-transfer-plug]');
        if (!brandSelect || !plugSelect) return;
        const brand = brandSelect.value.trim().toLocaleLowerCase();
        plugSelect.value = '';
        Array.from(plugSelect.options).forEach(function (option) {
            if (!option.value) {
                option.hidden = false;
                option.disabled = false;
                return;
            }
            const matches = brand !== '' && option.dataset.brand === brand;
            option.hidden = !matches;
            option.disabled = !matches;
        });
        const button = product.querySelector('[data-lookup-button="' + plugSelect.id + '"]');
        if (button) {
            button.disabled = brand === '';
            button.classList.toggle('is-disabled', brand === '');
        }
        const price = product.querySelector('[data-transfer-unit-price]');
        const priceHistory = product.querySelector('[data-transfer-price-history]');
        if (price) price.value = '';
        if (priceHistory) priceHistory.value = '';
        if (typeof updateLookupButton === 'function') updateLookupButton(plugSelect);
        updateTotal();
    };
    const syncPlug = function (product) {
        const plugSelect = product.querySelector('[data-transfer-plug]');
        const selected = plugSelect?.selectedOptions[0];
        const price = product.querySelector('[data-transfer-unit-price]');
        const priceHistory = product.querySelector('[data-transfer-price-history]');
        if (price) price.value = selected?.dataset.currentPrice || '';
        if (priceHistory) priceHistory.value = selected?.dataset.priceHistoryId || '';
        updateTotal();
    };
    addButton?.addEventListener('click', addProduct);
    list?.addEventListener('change', function (event) {
        const product = event.target.closest('[data-transfer-product]');
        if (!product) return;
        if (event.target.matches('[data-transfer-brand]')) syncBrand(product);
        if (event.target.matches('[data-transfer-plug]')) syncPlug(product);
    });
    list?.addEventListener('input', updateTotal);
    form?.addEventListener('input', function(event){if(!event.target.closest('[data-transfer-product-list]'))updateTotal();});
    form?.addEventListener('change', function(event){if(!event.target.closest('[data-transfer-product-list]'))updateTotal();});
    list?.addEventListener('click', function (event) {
        const edit = event.target.closest('[data-transfer-edit]');
        if (edit) {
            const selectedProduct = edit.closest('[data-transfer-product]');
            list.querySelectorAll('[data-transfer-product]').forEach(function (product) {
                product.classList.toggle('is-collapsed', product !== selectedProduct);
            });
            selectedProduct?.querySelector('[data-lookup-button]')?.focus();
            return;
        }
        const remove = event.target.closest('[data-transfer-remove]');
        if (!remove) return;
        remove.closest('[data-transfer-product]')?.remove();
        const remainingProducts = Array.from(list.querySelectorAll('[data-transfer-product]'));
        if (remainingProducts.length && remainingProducts.every(function (product) { return product.classList.contains('is-collapsed'); })) {
            remainingProducts[remainingProducts.length - 1].classList.remove('is-collapsed');
        }
        refreshProducts();
    });
    document.querySelector('[data-pos-transfer-form]')?.addEventListener('invalid', function (event) {
        const section = event.target.closest('[data-transfer-section]');
        if (section) showSection(section.dataset.transferSection);
        const product = event.target.closest('[data-transfer-product]');
        if (!product) return;
        list.querySelectorAll('[data-transfer-product]').forEach(function (item) {
            item.classList.toggle('is-collapsed', item !== product);
        });
    }, true);
    addProduct();
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
