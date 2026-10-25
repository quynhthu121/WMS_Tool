<?php

/**
 * Class Login
 * handles the user's login and logout process with secure Google confirmation
 */
class Login
{
    private $db_connection = null;
    public $errors = array();
    public $messages = array();

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Đăng xuất
        if (isset($_GET["logout"])) {
            $this->doLogout();
        }
        // Đăng nhập form Username/Password truyền thống
        elseif (isset($_POST["login"])) {
            $this->loginWithPostData();
        }
    }

    /**
     * Luồng xử lý đăng nhập thường
     */
    private function loginWithPostData()
    {
        if (empty($_POST['user_name']) || empty($_POST['user_password'])) {
            $this->errors[] = "Vui lòng điền tài khoản và mật khẩu.";
            return;
        }

        $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$this->db_connection->connect_errno) {
            $this->db_connection->set_charset("utf8");
            $user_name = $this->db_connection->real_escape_string($_POST['user_name']);

            $sql = "SELECT user_id, user_name, user_email, user_phone, user_password_hash
                    FROM users WHERE user_name = '" . $user_name . "' OR user_email = '" . $user_name . "';";
            $result = $this->db_connection->query($sql);

            if ($result->num_rows == 1) {
                $result_row = $result->fetch_object();
                if (password_verify($_POST['user_password'], $result_row->user_password_hash)) {
                    $_SESSION['user_id'] = $result_row->user_id;
                    $_SESSION['user_name'] = $result_row->user_name;
                    $_SESSION['user_email'] = $result_row->user_email;
                    $_SESSION['user_phone'] = $result_row->user_phone;
                    $_SESSION['user_login_status'] = 1;
                } else {
                    $this->errors[] = "Mật khẩu không chính xác.";
                }
            } else {
                $this->errors[] = "Tài khoản không tồn tại.";
            }
        }
    }

    public function doLogout()
    {
        $_SESSION = array();
        session_destroy();
        $this->messages[] = "Bạn đã đăng xuất.";
    }

    public function isUserLoggedIn()
    {
        if (isset($_SESSION['user_login_status']) && $_SESSION['user_login_status'] == 1) {
            return true;
        }
        return false;
    }
}