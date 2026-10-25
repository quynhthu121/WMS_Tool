<?php
/**
 * Cấu hình kết nối database.
 *
 * Chỉ đọc biến môi trường (Docker Compose truyền vào). Không có mật khẩu mặc định
 * và không tự chuyển sang tài khoản root: thiếu cấu hình thì báo lỗi rõ ràng.
 */

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'db');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_USER', getenv('DB_USER') ?: 'app_user');
    define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');
    define('DB_NAME', getenv('DB_NAME') ?: 'login');
    define('DB_CHARSET', 'utf8mb4');
    define('DB_CONNECTION_TIMEOUT', 10);

    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
    define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost:8890/google_callback.php');
}

if (!function_exists('getPdoConnection')) {

    function buildDsn(): string
    {
        return sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    }

    function dbConfigError(string $logMessage): void
    {
        error_log($logMessage);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $logMessage . PHP_EOL);
            exit(1);
        }
        http_response_code(500);
        exit('Lỗi kết nối cơ sở dữ liệu. Kiểm tra cấu hình DB_* trong .env.');
    }

    function getPdoConnection(): PDO
    {
        static $pdo = null;
        if ($pdo !== null) {
            return $pdo;
        }
        if (DB_PASS === '') {
            dbConfigError('Thiếu biến môi trường DB_PASS.');
        }
        try {
            $pdo = new PDO(buildDsn(), DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            dbConfigError('Kết nối PDO thất bại: ' . $e->getMessage());
        }
        return $pdo;
    }

    function getPDOLayerConnection(): PDO
    {
        return getPdoConnection();
    }

    function getMysqliConnection(): mysqli
    {
        static $mysqli = null;
        if ($mysqli !== null) {
            return $mysqli;
        }
        $mysqli = mysqli_init();
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, DB_CONNECTION_TIMEOUT);
        mysqli_report(MYSQLI_REPORT_OFF);
        if (!@$mysqli->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT)) {
            dbConfigError('Kết nối MySQLi thất bại: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset(DB_CHARSET);
        return $mysqli;
    }
}

// Biến tương thích với mã kế thừa
$conn = getPdoConnection();
$db_connection = getMysqliConnection();
