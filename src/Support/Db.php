<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/** Kết nối PDO dùng chung cho service, CLI và module (đọc env giống config/db.php). */
final class Db
{
    private static ?PDO $shared = null;

    public static function shared(): PDO
    {
        return self::$shared ??= self::connect();
    }

    public static function connect(?string $database = null): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'db',
            getenv('DB_PORT') ?: '3306',
            $database ?? (getenv('DB_NAME') ?: 'login'));
        $pdo = new PDO($dsn, getenv('DB_USER') ?: 'app_user', (string) getenv('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
        return $pdo;
    }

    public static function databaseName(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
}
