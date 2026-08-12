<?php

require_once __DIR__ . '/app_config.php';

$DB_CONFIG = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_DATABASE') ?: 'tokolc',
    'username' => getenv('DB_USERNAME') ?: 'tokolc',
    'password' => getenv('DB_PASSWORD') ?: 'S4W5ThKxtynDZztL',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];

function get_db_connection(): PDO
{
    static $pdo = null;
    global $DB_CONFIG;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $DB_CONFIG['host'],
            $DB_CONFIG['port'],
            $DB_CONFIG['database'],
            $DB_CONFIG['charset']
        );

        $pdo = new PDO(
            $dsn,
            $DB_CONFIG['username'],
            $DB_CONFIG['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
            ]
        );
    }

    return $pdo;
}
