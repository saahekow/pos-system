<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('pos');
if (!is_admin_user()) { http_response_code(403); exit('You do not have access to receipt branding.'); }
ensure_vendor_receipt_branding_schema();

function save_receipt_branding_image(string $field, int $vendorId, string $kind): ?string
{
    $upload = $_FILES[$field] ?? null;
    if (!$upload || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ((int)$upload['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The '.$kind.' image could not be uploaded.');
    if ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Choose a '.$kind.' image smaller than 5MB.');
    $sourcePath = (string)($upload['tmp_name'] ?? '');
    $info = @getimagesize($sourcePath);
    $mime = strtolower((string)($info['mime'] ?? ''));
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) throw new RuntimeException('The '.$kind.' must be a JPG, PNG, or WebP image.');
    if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('Image processing is not available on this server.');
    $source = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($sourcePath),
        'image/png' => @imagecreatefrompng($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };
    if (!$source) throw new RuntimeException('The '.$kind.' image could not be read.');
    $width = imagesx($source); $height = imagesy($source);
    if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) { imagedestroy($source); throw new RuntimeException('The '.$kind.' image dimensions are not supported.'); }
    $canvas = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagealphablending($canvas, true);
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
    $relativeDir = 'assets/uploads/receipt-branding';
    $absoluteDir = __DIR__ . '/../' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) { imagedestroy($source); imagedestroy($canvas); throw new RuntimeException('The receipt branding folder could not be created.'); }
    $filename = 'vendor-'.$vendorId.'-'.$kind.'-'.bin2hex(random_bytes(8)).'.jpg';
    $absolutePath = $absoluteDir.'/'.$filename;
    $saved = imagejpeg($canvas, $absolutePath, 90);
    imagedestroy($source); imagedestroy($canvas);
    if (!$saved) throw new RuntimeException('The '.$kind.' image could not be saved.');
    return $relativeDir.'/'.$filename;
}

function remove_receipt_branding_file(?string $relativePath): void
{
    if (!$relativePath || !str_starts_with($relativePath, 'assets/uploads/receipt-branding/')) return;
    $base = realpath(__DIR__.'/../assets/uploads/receipt-branding');
    $file = realpath(__DIR__.'/../'.$relativePath);
    if ($base && $file && str_starts_with($file, $base.DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
}

$returnTo = requested_return_url(app_url('pos.php?view=setup'));
$vendorId = max(0, (int)($_POST['vendor_id'] ?? $_GET['vendor_id'] ?? 0));
$message = (string)($_GET['saved'] ?? '') === '1' ? 'Receipt branding saved successfully. Every receipt for this vendor now uses these settings.' : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) $error = 'Your session expired. Please try again.';
    elseif ($vendorId <= 0) $error = 'Select a vendor first.';
    else {
        $vendorCheck = db()->prepare('SELECT COUNT(*) FROM vendors WHERE id=?');
        $vendorCheck->execute([$vendorId]);
        if (!(int)$vendorCheck->fetchColumn()) $error = 'The selected vendor could not be found.';
        else {
            $currentStatement = db()->prepare('SELECT logo_path,signature_path FROM vendor_receipt_branding WHERE vendor_id=?');
            $currentStatement->execute([$vendorId]);
            $current = $currentStatement->fetch() ?: ['logo_path'=>null,'signature_path'=>null];
            $newLogo = null; $newSignature = null;
            try {
                $newLogo = save_receipt_branding_image('logo_image', $vendorId, 'logo');
                $newSignature = save_receipt_branding_image('signature_image', $vendorId, 'signature');
                $removeLogo = isset($_POST['remove_logo']);
                $removeSignature = isset($_POST['remove_signature']);
                $logoPath = $removeLogo ? null : ($newLogo ?: $current['logo_path']);
                $signaturePath = $removeSignature ? null : ($newSignature ?: $current['signature_path']);
                $showSignature = $signaturePath && isset($_POST['show_signature']) ? 1 : 0;
                db()->prepare("INSERT INTO vendor_receipt_branding(vendor_id,logo_path,signature_path,show_signature,updated_by_user_id)
                    VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE logo_path=VALUES(logo_path),signature_path=VALUES(signature_path),show_signature=VALUES(show_signature),updated_by_user_id=VALUES(updated_by_user_id)")
                    ->execute([$vendorId,$logoPath,$signaturePath,$showSignature,current_user_id()]);
                if ($removeLogo && $newLogo) remove_receipt_branding_file($newLogo);
                if ($removeSignature && $newSignature) remove_receipt_branding_file($newSignature);
                if (($removeLogo || $newLogo) && $current['logo_path'] !== $logoPath) remove_receipt_branding_file($current['logo_path']);
                if (($removeSignature || $newSignature) && $current['signature_path'] !== $signaturePath) remove_receipt_branding_file($current['signature_path']);
                $query = http_build_query(['vendor_id'=>$vendorId,'saved'=>1,'return_to'=>$returnTo]);
                header('Location: '.app_url('pos-receipt-branding.php?'.$query)); exit;
            } catch (Throwable $exception) {
                if ($newLogo) remove_receipt_branding_file($newLogo);
                if ($newSignature) remove_receipt_branding_file($newSignature);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The receipt branding could not be saved.';
            }
        }
    }
}

$vendors = db()->query('SELECT id,vendor_name FROM vendors WHERE is_active=1 ORDER BY vendor_name')->fetchAll();
$branding = null;
if ($vendorId > 0) {
    $statement = db()->prepare('SELECT rb.*,u.full_name updated_by FROM vendor_receipt_branding rb LEFT JOIN users u ON u.id=rb.updated_by_user_id WHERE rb.vendor_id=?');
    $statement->execute([$vendorId]); $branding = $statement->fetch() ?: null;
}
$pageTitle = 'Receipt Branding';
$breadcrumbs = [['label'=>'Home','url'=>app_url('index.php')],['label'=>'POS','url'=>app_url('pos.php')],['label'=>'Setup','url'=>app_url('pos.php?view=setup')],['label'=>'Receipt Branding']];
$internalBackUrl = $returnTo;
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel receipt-branding-setup">
 <div class="management-heading"><div><span class="section-kicker">POS Setup</span><h1>Vendor Receipt Branding</h1><p>Set the logo and signature image shown on every sales receipt for a vendor, including receipts already issued.</p></div><div class="management-icon"><i class="fa-solid fa-file-signature"></i></div></div>
 <?php if($message!==''):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?>
 <?php if($error!==''):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
 <form class="record-form receipt-branding-picker" method="get"><input type="hidden" name="return_to" value="<?=e($returnTo)?>"><div class="form-field"><label for="receipt_vendor_id">Vendor</label><select id="receipt_vendor_id" name="vendor_id" required data-popup-select data-popup-search><option value="">Select a vendor</option><?php foreach($vendors as $vendor):?><option value="<?=(int)$vendor['id']?>" <?=(int)$vendor['id']===$vendorId?'selected':''?>><?=e((string)$vendor['vendor_name'])?></option><?php endforeach;?></select></div><button class="secondary-button" type="submit"><i class="fa-solid fa-arrow-right"></i><span>Open Branding</span></button></form>
 <?php if($vendorId>0):?>
 <form class="record-form receipt-branding-form" method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="vendor_id" value="<?=$vendorId?>"><input type="hidden" name="return_to" value="<?=e($returnTo)?>">
  <div class="receipt-branding-grid">
   <section class="receipt-branding-card"><div class="receipt-branding-card__heading"><span><i class="fa-regular fa-image"></i></span><div><h2>Receipt Logo</h2><p>Shown at the top of HTML, printed, shared, and PDF receipts.</p></div></div><div class="receipt-branding-preview" data-branding-preview="logo"><?php if(!empty($branding['logo_path'])):?><img src="<?=e(app_url((string)$branding['logo_path']))?>" alt="Current vendor receipt logo"><?php else:?><span><i class="fa-solid fa-building"></i> Uses the default company logo</span><?php endif;?></div><div class="form-field"><label for="logo_image">Upload logo</label><input id="logo_image" type="file" name="logo_image" accept="image/jpeg,image/png,image/webp" data-branding-input="logo"><small>JPG, PNG, or WebP. Maximum 5MB.</small></div><?php if(!empty($branding['logo_path'])):?><label class="standalone-promo-choice"><input type="checkbox" name="remove_logo" value="1"><span><strong>Remove current logo</strong><small>Receipts will return to the default company logo.</small></span></label><?php endif;?></section>
   <section class="receipt-branding-card"><div class="receipt-branding-card__heading"><span><i class="fa-solid fa-signature"></i></span><div><h2>Signature Image</h2><p>Upload the signature artwork only; vendor details remain sourced from the vendor profile.</p></div></div><div class="receipt-branding-preview receipt-branding-preview--signature" data-branding-preview="signature"><?php if(!empty($branding['signature_path'])):?><img src="<?=e(app_url((string)$branding['signature_path']))?>" alt="Current receipt signature"><?php else:?><span><i class="fa-solid fa-pen-nib"></i> No signature uploaded</span><?php endif;?></div><div class="form-field"><label for="signature_image">Upload signature</label><input id="signature_image" type="file" name="signature_image" accept="image/jpeg,image/png,image/webp" data-branding-input="signature"><small>For best results, use a clean image on a white or transparent background.</small></div><label class="standalone-promo-choice"><input type="checkbox" name="show_signature" value="1" <?=!empty($branding['show_signature'])?'checked':''?>><span><strong>Show signature on receipts</strong><small>You can keep the image saved while hiding it from receipts.</small></span></label><?php if(!empty($branding['signature_path'])):?><label class="standalone-promo-choice"><input type="checkbox" name="remove_signature" value="1"><span><strong>Remove signature image</strong><small>The saved signature file will be removed.</small></span></label><?php endif;?></section>
  </div>
  <?php if($branding&&!empty($branding['updated_at']?:$branding['created_at'])):?><p class="receipt-branding-audit">Last updated <?=e(date('d M Y H:i',strtotime((string)($branding['updated_at']?:$branding['created_at']))))?><?php if(!empty($branding['updated_by'])):?> by <?=e((string)$branding['updated_by'])?><?php endif;?>.</p><?php endif;?>
  <div class="form-actions"><a class="secondary-button" href="<?=e($returnTo)?>"><i class="fa-solid fa-arrow-left"></i><span>Back to POS Setup</span></a><button class="login-button" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>Save Receipt Branding</span></button></div>
 </form>
 <?php else:?><div class="empty-state">Select a vendor to manage its receipt logo and signature.</div><?php endif;?>
</section>
<script>document.querySelectorAll('[data-branding-input]').forEach(input=>input.addEventListener('change',()=>{const file=input.files&&input.files[0];if(!file)return;const preview=document.querySelector('[data-branding-preview="'+input.dataset.brandingInput+'"]');if(!preview)return;const reader=new FileReader();reader.onload=()=>{preview.innerHTML='';const image=document.createElement('img');image.src=reader.result;image.alt='Selected image preview';preview.appendChild(image);};reader.readAsDataURL(file);}));</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
