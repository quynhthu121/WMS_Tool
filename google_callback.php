<?php

session_start();

require_once("config/db.php");
require 'vendor/autoload.php';

use Google\Client;

if (empty($_POST['google_oauth_token'])) {
    header("Location: index.php");
    exit();
}

$google_flow_type = isset($_POST['google_flow_type']) ? $_POST['google_flow_type'] : 'login';

$client = new Client([
    'client_id' => GOOGLE_CLIENT_ID
]);

$payload = $client->verifyIdToken($_POST['google_oauth_token']);

if (!$payload || empty($payload['email'])) {
    header("Location: index.php");
    exit();
}

$email = $payload['email'];
$name = isset($payload['name']) ? $payload['name'] : '';
$user_name = isset($_POST['user_name']) ? trim($_POST['user_name']) : '';
$user_phone = isset($_POST['user_phone']) ? trim($_POST['user_phone']) : null;

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_errno) {
    die("Lỗi kết nối cơ sở dữ liệu: " . $db->connect_error);
}
$db->set_charset('utf8');

$escaped_email = $db->real_escape_string($email);
$sql = "SELECT * FROM users WHERE user_email='" . $escaped_email . "' LIMIT 1";
$result = $db->query($sql);

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_object();
    $_SESSION['user_id'] = $user->user_id;
    $_SESSION['user_name'] = $user->user_name;
    $_SESSION['user_email'] = $user->user_email;
    $_SESSION['user_phone'] = isset($user->user_phone) ? $user->user_phone : null;
    $_SESSION['user_login_status'] = 1;
} else {
    // Nếu chưa có tài khoản, khởi tạo mới.
    if ($google_flow_type === 'register' && empty($user_name)) {
        $user_name = preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]);
    }

    if (empty($user_name)) {
        $user_name = preg_replace('/[^a-zA-Z0-9]/', '', $name);
    }

    if (empty($user_name)) {
        $user_name = preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]);
    }

    $user_name = substr($user_name, 0, 64);
    if (empty($user_name)) {
        $user_name = 'googleuser_' . time();
    }

    $base_user_name = $user_name;
    $suffix = 0;
    $escaped_user_name = $db->real_escape_string($user_name);
    while (true) {
        $check_sql = "SELECT user_id FROM users WHERE user_name='" . $escaped_user_name . "' LIMIT 1";
        $check_result = $db->query($check_sql);
        if (!$check_result || $check_result->num_rows === 0) {
            break;
        }
        $suffix++;
        $user_name = substr($base_user_name, 0, 60) . '_' . $suffix;
        $escaped_user_name = $db->real_escape_string($user_name);
    }

    $escaped_phone = $user_phone !== null ? $db->real_escape_string($user_phone) : null;
    $google_password_hash = $db->real_escape_string(password_hash('oauth_verified_by_google', PASSWORD_DEFAULT));

    $sql = "INSERT INTO users (user_name, user_password_hash, user_email";
    if ($escaped_phone !== null && $escaped_phone !== '') {
        $sql .= ", user_phone";
    }
    $sql .= ") VALUES ('" . $escaped_user_name . "', '" . $google_password_hash . "', '" . $escaped_email . "'";
    if ($escaped_phone !== null && $escaped_phone !== '') {
        $sql .= ", '" . $escaped_phone . "'";
    }
    $sql .= ")";

    if (!$db->query($sql)) {
        die('Lỗi khi tạo tài khoản Google: ' . $db->error);
    }

    $_SESSION['user_id'] = $db->insert_id;
    $_SESSION['user_name'] = $escaped_user_name;
    $_SESSION['user_email'] = $escaped_email;
    $_SESSION['user_phone'] = $escaped_phone;
    $_SESSION['user_login_status'] = 1;
}

header("Location: index.php");
exit();
