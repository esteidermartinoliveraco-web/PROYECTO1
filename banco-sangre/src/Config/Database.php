<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host    = getenv('DB_HOST') ?: 'mysql';
            $port    = getenv('DB_PORT') ?: '3306';
            $dbName  = getenv('DB_NAME') ?: 'banco_sangre_db';
            $user    = getenv('DB_USER') ?: 'admin_sangre';
            $pass    = getenv('DB_PASSWORD') ?: 'B4nc0S4ngr3*';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                error_log('Error de conexión a MySQL: ' . $e->getMessage());
                throw new RuntimeException('No se pudo establecer conexión con la base de datos del banco de sangre.');
            }
        }

        return self::$instance;
    }
}