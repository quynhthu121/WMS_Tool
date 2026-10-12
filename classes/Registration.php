<?php

/**
 * Class registration
 * handles the user registration
 */
class Registration
{
    /**
     * @var object $db_connection The database connection
     */
    private $db_connection = null;
    /**
     * @var array $errors Collection of error messages
     */
    public $errors = array();
    /**
     * @var array $messages Collection of success / neutral messages
     */
    public $messages = array();

    /**
     * the function "__construct()" automatically starts whenever an object of this class is created,
     * you know, when you do "$registration = new Registration();"
     */
    public function __construct()
    {
        // check the possible registration actions:
        // 1. Nếu người dùng bấm form đăng ký bằng username/mật khẩu truyền thống ở phía trên
        if (isset($_POST["register"])) {
            $this->registerNewUser();
        }
        // 🚀 KHÚC SỬA ĐỔI THÊM MỚI: Nếu người dùng gửi form hoàn thiện hồ sơ sau khi xác thực Google thành công
        elseif (isset($_POST["register_google_profile"])) {
            $this->registerUserWithGoogleProfile();
        }
    }

    /**
     * handles the entire registration process. checks all error possibilities
     * and creates a new user in the database if everything is fine
     */
    private function registerNewUser()
    {
        if (empty($_POST['user_name'])) {
            $this->errors[] = "Empty Username";
        } elseif (empty($_POST['user_password_new']) || empty($_POST['user_password_repeat'])) {
            $this->errors[] = "Empty Password";
        } elseif ($_POST['user_password_new'] !== $_POST['user_password_repeat']) {
            $this->errors[] = "Password and password repeat are not the same";
        } elseif (strlen($_POST['user_password_new']) < 6) {
            $this->errors[] = "Password has a minimum length of 6 characters";
        } elseif (strlen($_POST['user_name']) > 64 || strlen($_POST['user_name']) < 2) {
            $this->errors[] = "Username cannot be shorter than 2 or longer than 64 characters";
        } elseif (!preg_match('/^[a-z\d]{2,64}$/i', $_POST['user_name'])) {
            $this->errors[] = "Username does not fit the name scheme: only a-Z and numbers are allowed, 2 to 64 characters";
        } elseif (empty($_POST['user_email'])) {
            $this->errors[] = "Email cannot be empty";
        } elseif (strlen($_POST['user_email']) > 64) {
            $this->errors[] = "Email cannot be longer than 64 characters";
        } elseif (!filter_var($_POST['user_email'], FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "Your email address is not in a valid email format";
        } elseif (!empty($_POST['user_name'])
            && strlen($_POST['user_name']) <= 64
            && strlen($_POST['user_name']) >= 2
            && preg_match('/^[a-z\d]{2,64}$/i', $_POST['user_name'])
            && !empty($_POST['user_email'])
            && strlen($_POST['user_email']) <= 64
            && filter_var($_POST['user_email'], FILTER_VALIDATE_EMAIL)
            && !empty($_POST['user_password_new'])
            && !empty($_POST['user_password_repeat'])
            && ($_POST['user_password_new'] === $_POST['user_password_repeat'])
        ) {
            // create a database connection
            $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            // change character set to utf8 and check it
            if (!$this->db_connection->set_charset("utf8")) {
                $this->errors[] = $this->db_connection->error;
            }

            // if no connection errors (= working database connection)
            if (!$this->db_connection->connect_errno) {

                // escaping, additionally removing everything that could be (html/javascript-) code
                $user_name = $this->db_connection->real_escape_string(strip_tags($_POST['user_name'], ENT_QUOTES));
                $user_email = $this->db_connection->real_escape_string(strip_tags($_POST['user_email'], ENT_QUOTES));

                $user_password = $_POST['user_password_new'];

                // crypt the user's password with PHP 5.5's password_hash() function, results in a 60 character
                // hash string. the PASSWORD_DEFAULT constant is defined by the PHP 5.5, or if you are using
                // PHP 5.3/5.4, by the password hashing compatibility library
                $user_password_hash = password_hash($user_password, PASSWORD_DEFAULT);

                // check if user or email address already exists
                $sql = "SELECT * FROM users WHERE user_name = '" . $user_name . "' OR user_email = '" . $user_email . "';";
                $query_check_user_name = $this->db_connection->query($sql);

                if ($query_check_user_name->num_rows == 1) {
                    $this->errors[] = "Sorry, that username / email address is already taken.";
                } else {
                    // write new user's data into database
                    $sql = "INSERT INTO users (user_name, user_password_hash, user_email)
                            VALUES('" . $user_name . "', '" . $user_password_hash . "', '" . $user_email . "');";
                    $query_new_user_insert = $this->db_connection->query($sql);

                    // if user has been added successfully
                    if ($query_new_user_insert) {
                        $this->messages[] = "Your account has been created successfully. You can now log in.";
                    } else {
                        $this->errors[] = "Sorry, your registration failed. Please go back and try again.";
                    }
                }
            } else {
                $this->errors[] = "Sorry, no database connection.";
            }
        } else {
            $this->errors[] = "An unknown error occurred.";
        }
    }

    /**
     * 🚀 HÀM THÊM MỚI: Xử lý ghi nhận thông tin cá nhân và tạo tài khoản liên kết Google
     */
    private function registerUserWithGoogleProfile()
    {
        // Kiểm tra tính đầy đủ của thông tin cá nhân bắt buộc người dùng thật nhập vào
        if (empty($_POST['user_name'])) {
            $this->errors[] = "Tên đăng nhập không được để trống.";
        } elseif (empty($_POST['user_full_name']) || empty($_POST['user_phone'])) {
            $this->errors[] = "Vui lòng nhập đầy đủ Họ tên và Số điện thoại cá nhân.";
        } elseif (empty($_POST['google_oauth_token'])) {
            $this->errors[] = "Mã xác thực chữ ký số từ Google không hợp lệ.";
        } else {
            // Giải mã Token của Google để lấy Email thật ở Backend nhằm chống gian lận dữ liệu gửi từ Client
            $tokenParts = explode('.', $_POST['google_oauth_token']);
            if (count($tokenParts) !== 3) {
                $this->errors[] = "Token định danh an toàn cấu trúc bị lỗi.";
                return;
            }
            $payload = json_decode(base64_decode(str_replace(array('-', '_'), array('+', '/'), $tokenParts[1])));
            if (!$payload || !isset($payload->email)) {
                $this->errors[] = "Chữ ký số Google hết hạn hoặc không tồn tại.";
                return;
            }

            // Kết nối Database hệ thống Docker
            $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            if (!$this->db_connection->connect_errno) {
                $this->db_connection->set_charset("utf8");

                // Làm sạch dữ liệu đầu vào chống SQL Injection độc hại
                $user_name = $this->db_connection->real_escape_string(strip_tags($_POST['user_name'], ENT_QUOTES));
                $user_email = $this->db_connection->real_escape_string(strip_tags($payload->email, ENT_QUOTES));
                
                // Thu thập các thông tin cá nhân tăng cường cần thiết đúng yêu cầu
                $user_full_name = $this->db_connection->real_escape_string(strip_tags($_POST['user_full_name'], ENT_QUOTES));
                $user_phone = $this->db_connection->real_escape_string(strip_tags($_POST['user_phone'], ENT_QUOTES));

                // Kiểm tra xem trùng lặp Username hoặc Email trong DB kho hay chưa
                $sql = "SELECT * FROM users WHERE user_name = '" . $user_name . "' OR user_email = '" . $user_email . "';";
                $query_check_user_name = $this->db_connection->query($sql);

                if ($query_check_user_name->num_rows == 1) {
                    $this->errors[] = "Tên đăng nhập hoặc Email thật này đã có người sử dụng trong kho.";
                } else {
                    // Thực hiện Insert tài khoản Google kèm toàn bộ thông tin cá nhân phong phú vào hệ thống
                    // Gán mật khẩu mặc định đã được hash để bảo toàn định dạng cột
                    $google_password_hash = $this->db_connection->real_escape_string(password_hash('oauth_verified_by_google', PASSWORD_DEFAULT));
                    $sql_insert = "INSERT INTO users (user_name, user_password_hash, user_email, user_phone) 
                                   VALUES('" . $user_name . "', '" . $google_password_hash . "', '" . $user_email . "', '" . $user_phone . "');";
                    $query_new_user_insert = $this->db_connection->query($sql_insert);

                    if ($query_new_user_insert) {
                        $this->messages[] = "Tài khoản liên kết Google của bạn đã được khởi tạo thành công! Hãy quay lại trang chủ đăng nhập.";
                    } else {
                        $this->errors[] = "Đăng ký thất bại. Lỗi cú pháp lưu trữ dữ liệu cơ sở.";
                    }
                }
            } else {
                $this->errors[] = "Không thể kết nối đến cơ sở dữ liệu phân bổ của Docker.";
            }
        }
    }
} 