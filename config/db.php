<?php

require_once __DIR__ . '/app_config.php';

$LOCAL_DB = $SETUP_CONFIG['db'] ?? setup_load_config()['db'] ?? [];

$DB_CONFIG = [
    'host' => $LOCAL_DB['host'] ?? getenv('DB_HOST') ?: 'localhost',
    'port' => (int) ($LOCAL_DB['port'] ?? getenv('DB_PORT') ?: 3306),
    'database' => $LOCAL_DB['database'] ?? getenv('DB_DATABASE') ?: 'tokolc',
    'username' => $LOCAL_DB['username'] ?? getenv('DB_USERNAME') ?: 'tokolc',
    'password' => array_key_exists('password', $LOCAL_DB)
        ? $LOCAL_DB['password']
        : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ''),
    'charset' => $LOCAL_DB['charset'] ?? getenv('DB_CHARSET') ?: 'utf8mb4',
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

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
        ];

        $password = $DB_CONFIG['password'];
        if ($password === '' || $password === null) {
            $pdo = new PDO($dsn, $DB_CONFIG['username'], null, $options);
        } else {
            $pdo = new PDO($dsn, $DB_CONFIG['username'], $password, $options);
        }
    }

    return $pdo;
}
