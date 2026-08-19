<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('admin');
ensure_user_phone_schema();

$pageTitle = 'Accounts';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Accounts'],
];
$internalBackUrl=app_url('admin.php?view=setup');
$message='';
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $phone=normalize_phone_number((string)($_POST['phone']??''));
    if(!verify_csrf_token((string)($_POST['csrf_token']??''))){
        $error='Your session expired. Please try again.';
    }elseif($phone!==''&&!is_valid_phone_number($phone)){
        $error='Enter a valid Ghana phone number, for example 0240000000.';
    }else{
        $duplicate=db()->prepare('SELECT COUNT(*) FROM users WHERE phone=? AND id<>?');
        $duplicate->execute([$phone,current_user_id()]);
        if($phone!==''&&(int)$duplicate->fetchColumn()>0){
            $error='That phone number is already used by another account.';
        }else{
            db()->prepare('UPDATE users SET phone=? WHERE id=?')->execute([$phone!==''?$phone:null,current_user_id()]);
            $message='Account phone number saved. You can now use it to log in.';
        }
    }
}

$statement=db()->prepare('SELECT full_name,email,phone,role FROM users WHERE id=? LIMIT 1');
$statement->execute([current_user_id()]);
$account=$statement->fetch()?:[];

require __DIR__ . '/../includes/header.php';
?>
<section class="management-panel">
    <div class="management-heading">
        <div><span class="section-kicker">Login Details</span><h1>Account</h1><p>Add a phone number so this account can sign in with either email or phone.</p></div>
        <div class="management-icon"><i class="fa-solid fa-users-gear"></i></div>
    </div>
    <?php if($message!==''):?><div class="profile-message is-success" role="status"><?=e($message)?></div><?php endif;?>
    <?php if($error!==''):?><div class="profile-message is-error" role="alert"><?=e($error)?></div><?php endif;?>
    <form class="record-form" method="post">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <div class="form-grid">
            <div class="form-field"><label>Name</label><input value="<?=e((string)($account['full_name']??''))?>" disabled></div>
            <div class="form-field"><label>Email</label><input value="<?=e((string)($account['email']??''))?>" disabled></div>
            <div class="form-field"><label for="phone">Login phone number</label><input id="phone" name="phone" type="tel" inputmode="tel" value="<?=e((string)($account['phone']??''))?>" placeholder="0240000000"></div>
        </div>
        <div class="form-actions"><button class="login-button" type="submit"><span>Save phone number</span><i class="fa-solid fa-floppy-disk"></i></button></div>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
