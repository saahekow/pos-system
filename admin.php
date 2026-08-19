<?php
require_once __DIR__ . '/config/app.php';
require_auth();
if ((string)($_GET['view'] ?? '') === 'assignment') {
    require_once __DIR__ . '/pages/admin.php';
    exit;
}
header('Location: ' . app_url('pos.php?view=admin'));
exit;
