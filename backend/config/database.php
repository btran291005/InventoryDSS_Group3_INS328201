<?php

declare(strict_types=1);

class Database
{
    private const DB_HOST    = '127.0.0.1';
    private const DB_PORT    = '3306';
    private const DB_NAME    = 'project2';
    private const DB_USER    = 'root';
    private const DB_PASS    = '';
    private const DB_CHARSET = 'utf8mb4';

    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $portsToTry = array_values(array_unique([self::DB_PORT, '3306', '3307', '3308']));
            $lastException = null;

            foreach ($portsToTry as $port) {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    self::DB_HOST,
                    $port,
                    self::DB_NAME,
                    self::DB_CHARSET
                );

                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
                ];

                try {
                    self::$instance = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
                    break;
                } catch (PDOException $e) {
                    $lastException = $e;
                }
            }

            if (self::$instance === null) {
                throw $lastException ?? new PDOException('Unable to connect to database.');
            }
        }

        return self::$instance;
    }
}