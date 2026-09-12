<?php
declare(strict_types=1);

const SPW_SERVER_CHECK_KEY = 'spw-recovery-20260911';

if (!isset($_GET['key']) || !hash_equals(SPW_SERVER_CHECK_KEY, (string) $_GET['key'])) {
    http_response_code(404);
    exit('Not found.');
}

ini_set('display_errors', '1');
error_reporting(E_ALL);

$report = [];
$report[] = 'SPW server check';
$report[] = 'PHP version: ' . PHP_VERSION;
$report[] = 'PHP SAPI: ' . PHP_SAPI;
$report[] = 'config/app.php readable: ' . (is_readable(__DIR__ . '/config/app.php') ? 'yes' : 'no');
$report[] = 'config/database.php readable: ' . (is_readable(__DIR__ . '/config/database.php') ? 'yes' : 'no');
$report[] = 'login.php readable: ' . (is_readable(__DIR__ . '/login.php') ? 'yes' : 'no');

if (PHP_VERSION_ID < 80000) {
    $report[] = 'FAILED: This application requires PHP 8.0 or newer.';
    header('Content-Type: text/plain; charset=utf-8');
    exit(implode("\n", $report) . "\n");
}

try {
    require_once __DIR__ . '/config/app.php';
    $report[] = 'Bootstrap: OK';

    try {
        db()->query('SELECT 1');
        $report[] = 'Database connection: OK';
    } catch (Throwable $exception) {
        $report[] = 'Database connection: FAILED';
        $report[] = get_class($exception) . ': ' . $exception->getMessage();
    }
} catch (Throwable $exception) {
    $report[] = 'Bootstrap: FAILED';
    $report[] = get_class($exception) . ': ' . $exception->getMessage();
    $report[] = 'File: ' . $exception->getFile() . ':' . $exception->getLine();
}

if (is_readable(__DIR__ . '/login.php')) {
    try {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        require __DIR__ . '/login.php';
        $loginOutput = (string) ob_get_clean();
        $report[] = 'Login render: OK (' . strlen($loginOutput) . ' bytes)';
    } catch (Throwable $exception) {
        if (ob_get_level() > 0) ob_end_clean();
        $report[] = 'Login render: FAILED';
        $report[] = get_class($exception) . ': ' . $exception->getMessage();
        $report[] = 'File: ' . $exception->getFile() . ':' . $exception->getLine();
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $report) . "\n";
