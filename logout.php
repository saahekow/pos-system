<?php
require_once __DIR__ . '/config/app.php';

logout_user();

header('Location: ' . app_url('login.php'));
exit;
