<?php
declare(strict_types=1);

if (!isset($_GET['key']) || !hash_equals('spw-recovery-20260911', (string) $_GET['key'])) {
    http_response_code(404);
    exit('Not found.');
}

ini_set('display_errors', '1');
error_reporting(E_ALL);
$report = [
    'SPW server check v2',
    'PHP version: ' . PHP_VERSION,
    'login.php readable: ' . (is_readable(__DIR__ . '/login.php') ? 'yes' : 'no'),
];

try {
    ob_start();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require __DIR__ . '/login.php';
    $rendered = (string) ob_get_clean();
    $report[] = 'Login render: OK (' . strlen($rendered) . ' bytes)';
} catch (Throwable $exception) {
    if (ob_get_level() > 0) ob_end_clean();
    $report[] = 'Login render: FAILED';
    $report[] = get_class($exception) . ': ' . $exception->getMessage();
    $report[] = 'File: ' . $exception->getFile() . ':' . $exception->getLine();
}

header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $report) . "\n";
