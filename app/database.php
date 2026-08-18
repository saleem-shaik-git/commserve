<?php
require_once __DIR__ . '/bootstrap.php';

final class Database {
    private static ?PDO $pdo = null;
    public static function connection(): PDO {
        if (self::$pdo) return self::$pdo;
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST','127.0.0.1'), env('DB_PORT','3306'), env('DB_NAME','commserve'));
        self::$pdo = new PDO($dsn, (string)env('DB_USER','root'), (string)env('DB_PASS',''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }
}
