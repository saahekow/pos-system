<?php
declare(strict_types=1);

const DB_HOST = 'localhost';
const DB_PORT = '3306';
const DB_NAME = 'autotariff_spw_db';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function database_status(): array
{
    try {
        db()->query('SELECT 1');

        return [
            'connected' => true,
            'message' => 'Database connected',
        ];
    } catch (Throwable $exception) {
        return [
            'connected' => false,
            'message' => 'Database not connected',
        ];
    }
}
