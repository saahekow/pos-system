<?php
declare(strict_types=1);

if (!isset($_GET['key']) || !hash_equals('spw-recovery-20260911', (string) $_GET['key'])) {
    http_response_code(404);
    exit('Not found.');
}

ini_set('display_errors', '1');
error_reporting(E_ALL);
ob_start();

try {
    require __DIR__ . '/dashboard.php';
    $rendered = (string) ob_get_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "SPW dashboard check\n";
    echo 'Dashboard render: OK (' . strlen($rendered) . " bytes)\n";
} catch (Throwable $exception) {
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "SPW dashboard check\n";
    echo "Dashboard render: FAILED\n";
    echo get_class($exception) . ': ' . $exception->getMessage() . "\n";
    echo 'File: ' . $exception->getFile() . ':' . $exception->getLine() . "\n";
}
