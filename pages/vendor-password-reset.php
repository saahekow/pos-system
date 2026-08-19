<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

if (!is_super_admin()) {
    http_response_code(403);
    exit('Only a super administrator can reset vendor passwords.');
}

ensure_vendor_module_assignments_schema();
ensure_user_phone_schema();

$message = '';
$error = '';
$vendorId = max(0, (int) ($_POST['vendor_id'] ?? 0));
$vendors = db()->query(
    "SELECT v.id,v.vendor_name,u.phone,u.email
     FROM vendors v
     INNER JOIN users u ON u.id=v.user_id AND u.role='vendor'
     WHERE v.is_active=1
     ORDER BY v.vendor_name"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$vendorId) {
        $error = 'Select a vendor account.';
    } else {
        $statement = db()->prepare(
            "SELECT u.id
             FROM vendors v
             INNER JOIN users u ON u.id=v.user_id AND u.role='vendor'
             WHERE v.id=? AND v.is_active=1
             LIMIT 1"
        );
        $statement->execute([$vendorId]);
        $vendorUserId = (int) ($statement->fetchColumn() ?: 0);

        if (!$vendorUserId) {
            $error = 'The selected vendor does not have an active vendor login.';
        } else {
            try {
                db()->prepare('UPDATE users SET password_hash=?,force_password_change=1 WHERE id=?')
                    ->execute([password_hash(VENDOR_DEFAULT_PASSWORD, PASSWORD_DEFAULT), $vendorUserId]);
                ensure_remember_login_schema();
                db()->prepare('DELETE FROM user_remember_tokens WHERE user_id=?')->execute([$vendorUserId]);
                $message = 'Vendor password reset successfully. Temporary password: ' . VENDOR_DEFAULT_PASSWORD;
            } catch (Throwable $exception) {
                $error = 'The vendor password could not be reset.';
            }
        }
    }
}

$pageTitle = 'Vendor Password Reset';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Vendor Password Reset'],
];
$internalBackUrl = app_url('admin.php?view=system');
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Super Admin</span>
            <h1>Vendor Password Reset</h1>
            <p>Reset a vendor to the temporary password <?=e(VENDOR_DEFAULT_PASSWORD)?>. They must create a new password after signing in.</p>
        </div>
        <div class="management-icon"><i class="fa-solid fa-key"></i></div>
    </div>

    <?php if ($message !== ''): ?><div class="profile-message is-success"><?=e($message)?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="profile-message is-error"><?=e($error)?></div><?php endif; ?>

    <form class="record-form" method="post" data-confirm-title="Reset vendor password?" data-confirm-message="The vendor will be signed out and required to change the temporary password at the next login.">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <div class="form-grid">
            <div class="form-field form-field--wide">
                <label for="vendor_id">Vendor account</label>
                <select id="vendor_id" name="vendor_id" data-vendor-selector data-popup-select data-popup-search data-popup-hide-empty required>
                    <option value="">Search or select vendor</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?=(int)$vendor['id']?>" <?=$vendorId===(int)$vendor['id']?'selected':''?>>
                            <?=e(implode(' · ', array_filter([(string)$vendor['vendor_name'], (string)$vendor['phone']])))?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <a class="secondary-button" href="<?=e(app_url('admin.php?view=system'))?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>
            <button class="login-button" type="submit"><i class="fa-solid fa-key"></i><span>Reset Vendor Password</span></button>
        </div>
    </form>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
