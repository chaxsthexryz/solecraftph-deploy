<?php

date_default_timezone_set('Asia/Manila');
// Read from the host's environment so no password lives in the code.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT .
               ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $opts = [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        // TiDB Cloud only accepts TLS connections.
        if (getenv('DB_SSL') !== '0') {
            $opts[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            // Log the real reason; never show database details to visitors.
            error_log('DB connect failed: ' . $e->getMessage());
            http_response_code(503);
            $msg = 'The store is busy right now. Please try again in a minute.';
            if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
                header('Content-Type: application/json');
                exit(json_encode(['error' => $msg]));
            }
            exit($msg);
        }
    }

    return $pdo;
}
