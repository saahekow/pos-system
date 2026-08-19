<?php
require_once __DIR__ . '/config/app.php';
require_auth();
header('Location: ' . app_url('pos.php?view=setup'));
exit;
