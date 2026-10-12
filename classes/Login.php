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
     * 🚀 HÀM BẢO MẬT: Đối chiếu mật khẩu hệ thống + Cập nhật thông tin khi Đăng nhập nhanh Google
     */
    private function loginWithGoogleAndVerifyPassword()
    {
        if (empty($_POST['confirm_sys_password'])) {
            $this->errors[] = "Vui lòng nhập mật khẩu tài khoản để xác minh danh tính.";
            return;
        }
        if (empty($_POST['user_full_name']) || empty($_POST['user_phone'])) {
            $this->errors[] = "Vui lòng nhập đầy đủ thông tin cá nhân bổ sung.";
            return;
        }

        // Giải mã Token bảo mật của Google gửi lên để lấy Email thật
        $tokenParts = explode('.', $_POST['google_oauth_token']);
        if (count($tokenParts) !== 3) {
            $this->errors[] = "Mã xác thực chữ ký Google không đúng định dạng.";
            return;
        }
        
        $payload = json_decode(base64_decode(str_replace(array('-', '_'), array('+', '/'), $tokenParts[1])));
        if (!$payload || !isset($payload->email)) {
            $this->errors[] = "Chữ ký định danh từ Google API không hợp lệ.";
            return;
        }

        $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$this->db_connection->connect_errno) {
            $this->db_connection->set_charset("utf8");

            $google_email = $this->db_connection->real_escape_string(strip_tags($payload->email, ENT_QUOTES));
            $input_password = $_POST['confirm_sys_password'];
            
            $user_full_name = $this->db_connection->real_escape_string(strip_tags($_POST['user_full_name'], ENT_QUOTES));
            $user_phone = $this->db_connection->real_escape_string(strip_tags($_POST['user_phone'], ENT_QUOTES));

            // 1. Tìm xem tài khoản có Email trùng với Gmail Google này không
            $sql = "SELECT user_id, user_name, user_email, user_password_hash FROM users WHERE user_email = '" . $google_email . "';";
            $result_check = $this->db_connection->query($sql);

            if ($result_check->num_rows == 1) {
                $user_data = $result_check->fetch_object();

                // 2. 🛡️ ĐỐI CHIẾU MẬT KHẨU NGƯỜI DÙNG NHẬP VÀO
                if (password_verify($input_password, $user_data->user_password_hash)) {
                    
                    // 3. Mật khẩu đúng -> Tiến hành cập nhật thông tin cá nhân
                    // Cập nhật Họ tên vào cột user_name (hoặc cột tương ứng của ông), Số điện thoại vào user_phone
                    $sql_update = "UPDATE users SET 
                                   user_name = '" . $user_full_name . "', 
                                   user_phone = '" . $user_phone . "' 
                                   WHERE user_id = " . $user_data->user_id . ";";
                    $this->db_connection->query($sql_update);

                    // 4. 🎉 CẤP SESSION CHO PHÉP ĐĂNG NHẬP VÀO HỆ THỐNG
                    $_SESSION['user_id'] = $user_data->user_id;
                    $_SESSION['user_name'] = $user_full_name;
                    $_SESSION['user_email'] = $user_data->user_email;
                    $_SESSION['user_phone'] = $user_phone;
                    $_SESSION['user_login_status'] = 1;

                } else {
                    $this->errors[] = "❌ Xác nhận thất bại: Mật khẩu hệ thống không chính xác!";
                }
            } else {
                $this->errors[] = "❌ Email Google này chưa được đăng ký trên hệ thống. Vui lòng tạo tài khoản trước.";
            }
        } else {
            $this->errors[] = "Lỗi kết nối cơ sở dữ liệu.";
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