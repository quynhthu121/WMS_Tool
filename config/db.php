<?php
/**
 * Database configuration helper.
 *
 * Sử dụng biến môi trường khi chạy Docker, và fallback an toàn cho môi trường local.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: 'app_user');
define('DB_PASS', getenv('DB_PASS') ?: 'app_password');
define('DB_NAME', getenv('DB_NAME') ?: 'login');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
define('DB_ROOT_USER', getenv('DB_ROOT_USER') ?: 'root');
define('DB_ROOT_PASS', getenv('DB_ROOT_PASS') ?: 'root_password');

define('DB_CONNECTION_TIMEOUT', 10);

function buildDsn(): string
{
    return sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
}

function connectPdo(string $user, string $password): PDO
{
    $dsn = buildDsn();
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, $user, $password, $options);
}

function getPdoConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $pdo = connectPdo(DB_USER, DB_PASS);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database PDO primary user connection failed: ' . $e->getMessage());
    }

    if (DB_USER !== DB_ROOT_USER) {
        try {
            $pdo = connectPdo(DB_ROOT_USER, DB_ROOT_PASS);
            error_log('Database PDO fallback to root user succeeded.');
            return $pdo;
        } catch (PDOException $e) {
            error_log('Database PDO root fallback failed: ' . $e->getMessage());
        }
    }

    http_response_code(500);
    exit('Database connection error.');
}

function getPDOLayerConnection(): PDO
{
    return getPdoConnection();
}

function connectMysqli(string $user, string $password): mysqli
{
    $mysqli = mysqli_init();
    if ($mysqli === false) {
        throw new RuntimeException('MySQLi initialization failed.');
    }

    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, DB_CONNECTION_TIMEOUT);
    if (!$mysqli->real_connect(DB_HOST, $user, $password, DB_NAME, (int) DB_PORT)) {
        throw new RuntimeException('MySQLi connection failed: ' . $mysqli->connect_error);
    }

    if (!$mysqli->set_charset(DB_CHARSET)) {
        error_log('MySQLi set_charset failed: ' . $mysqli->error);
    }

    return $mysqli;
}

function getMysqliConnection(): mysqli
{
    static $mysqli = null;
    if ($mysqli !== null) {
        return $mysqli;
    }

    try {
        $mysqli = connectMysqli(DB_USER, DB_PASS);
        return $mysqli;
    } catch (RuntimeException $e) {
        error_log($e->getMessage());
    }

    if (DB_USER !== DB_ROOT_USER) {
        try {
            $mysqli = connectMysqli(DB_ROOT_USER, DB_ROOT_PASS);
            error_log('MySQLi fallback to root user succeeded.');
            return $mysqli;
        } catch (RuntimeException $e) {
            error_log($e->getMessage());
        }
    }

    http_response_code(500);
    exit('Database connection error.');
}

// Biến tương thích với mã hiện tại
$conn = getPdoConnection();
$db_connection = getMysqliConnection();

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost:8888/google_callback.php');