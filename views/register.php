<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản - Hệ thống Quản Lý Kho</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Roboto', sans-serif; }
        body { background: linear-gradient(135deg, #1e3d59 0%, #17b978 100%); min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }
        .login-container { background: #ffffff; border-radius: 16px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); overflow: hidden; max-width: 480px; width: 100%; animation: slideIn 0.5s ease-out; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-30px); } to { opacity: 1; transform: translateY(0); } }
        .login-header { background: #178978; padding: 30px 20px; text-align: center; color: #ffffff; }
        .login-header h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 8px; }
        .login-body { padding: 25px 30px; }
        .message-box { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
        .error-box { background-color: #fee; color: #c33; border-left: 4px solid #e74c3c; }
        .success-box { background-color: #d4edda; color: #155724; border-left: 4px solid #17b978; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 6px; color: #1e3d59; font-weight: 600; font-size: 0.9rem; }
        .login_input { width: 100%; padding: 12px 14px; border: 2px solid #e0e6ed; border-radius: 8px; font-size: 0.95rem; background-color: #f8fafc; transition: all 0.3s ease; }
        .login_input:focus { outline: none; border-color: #17b978; background-color: #ffffff; }
        .btn-register { width: 100%; padding: 14px; background: #1E2A38; color: #ffffff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
        .login-link { text-align: center; margin-top: 20px; font-size: 0.9rem; color: #64748b; }
        .login-link a { color: #17b978; font-weight: 600; text-decoration: none; }
        .social-divider { display: flex; align-items: center; text-align: center; margin: 20px 0; color: #8898aa; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .social-divider::before, .social-divider::after { content: ''; flex: 1; border-bottom: 1px solid #e0e6ed; }
        .social-divider:not(:empty)::before { margin-right: .75em; }
        .social-divider:not(:empty)::after { margin-left: .75em; }
        .google-action { display: flex; justify-content: center; margin-top: 10px; }
        .google-note { text-align: center; margin-top: 12px; font-size: 0.9rem; color: #5b6b77; }
        .google-signin-card { width: 100%; max-width: 420px; }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-header">
            <h1 id="header-title">Đăng ký tài khoản Kho</h1>
            <p id="header-desc">Hệ thống quản lý chuỗi cung ứng logistics</p>
        </div>

        <div class="login-body">
            <?php
            if (isset($registration)) {
                if ($registration->errors) {
                    foreach ($registration->errors as $error) {
                        echo '<div class="message-box error-box">⚠️ ' . htmlspecialchars($error) . '</div>';
                    }
                }
                if ($registration->messages) {
                    foreach ($registration->messages as $message) {
                        echo '<div class="message-box success-box">✅ ' . htmlspecialchars($message) . '</div>';
                    }
                }
            }
            ?>

            <div id="standard-register-section">
                <form method="post" action="register.php" name="registerform">
                    <div class="form-group">
                        <label for="login_input_username">Tên đăng nhập (Chỉ gồm chữ và số, 2-64 ký tự)</label>
                        <input id="login_input_username" class="login_input" type="text" pattern="[a-zA-Z0-9]{2,64}" name="user_name" placeholder="Ví dụ: thuongkho2026" required />
                    </div>

                    <div class="form-group">
                        <label for="login_input_email">Địa chỉ Email người dùng</label>
                        <input id="login_input_email" class="login_input" type="email" name="user_email" placeholder="example@domain.com" required />
                    </div>

                    <div class="form-group">
                        <label for="login_input_password_new">Mật khẩu (Tối thiểu 6 ký tự)</label>
                        <input id="login_input_password_new" class="login_input" type="password" name="user_password_new" pattern=".{6,}" placeholder="••••••••" required autocomplete="off" />
                    </div>

                    <div class="form-group">
                        <label for="login_input_password_repeat">Nhập lại mật khẩu</label>
                        <input id="login_input_password_repeat" class="login_input" type="password" name="user_password_repeat" pattern=".{6,}" placeholder="••••••••" required autocomplete="off" />
                    </div>

                    <button type="submit" name="register" class="btn-register">Đăng ký hệ thống</button>
                </form>

                <div class="social-divider">Hoặc nhanh hơn với</div>
                <div class="google-action">
                    <div class="google-signin-card">
                        <div id="g_id_onload"
                             data-client_id="<?php echo GOOGLE_CLIENT_ID; ?>"
                             data-context="signup"
                             data-ux_mode="popup"
                             data-callback="handleGoogleResponse"
                             data-auto_prompt="false">
                        </div>
                        <div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="filled_blue" data-text="signup_with" data-size="large" data-width="420"></div>
                    </div>
                </div>
                <div class="google-note">Đăng ký nhanh bằng Google để tiết kiệm thời gian và tránh gõ lại email.</div>
            </div>

            <div id="google-profile-completion-section" style="display: none;">
                <form method="post" action="google_callback.php" name="googlecompletionform">
                    <input type="hidden" id="google_token" name="google_oauth_token">
                    <input type="hidden" name="google_flow_type" value="register">

                    <div class="form-group">
                        <label>Email liên kết từ Google (Đã xác minh thật)</label>
                        <input id="google_verified_email" class="login_input" type="text" readonly style="background-color: #e2e8f0; color: #475569; font-weight: 600;" />
                    </div>

                    <div class="form-group">
                        <label>Đặt tên Username viết liền không dấu *</label>
                        <input id="google_suggested_username" class="login_input" type="text" pattern="[a-zA-Z0-9]{2,64}" name="user_name" required />
                    </div>

                    <div class="form-group">
                        <label>Họ và tên thật nhân sự *</label>
                        <input class="login_input" type="text" name="user_full_name" placeholder="Ví dụ: Nguyễn Văn A" required />
                    </div>

                    <div class="form-group">
                        <label>Số điện thoại liên lạc *</label>
                        <input class="login_input" type="text" name="user_phone" placeholder="Ví dụ: 0912345678" required />
                    </div>

                    <button type="submit" name="register_google_profile" class="btn-register" style="background: linear-gradient(135deg, #17b978 0%, #1e3d59 100%);">Hoàn tất hoàn thiện hồ sơ</button>
                </form>
            </div>

            <div class="login-link">
                Đã có tài khoản? <a href="index.php">Đăng nhập ngay</a>
            </div>
        </div>
    </div>

    <script src="https://accounts.google.com/gsi/client" async defer></script>
    
    <script>
    // Hàm callback tự động kích hoạt KHI VÀ CHỈ KHI người dùng thật nhập đúng mật khẩu Gmail thành công trên popup Google
    function handleGoogleResponse(response) {
        const token = response.credential;
        
        // Tiến hành bóc tách gói tin JWT cục bộ ngay tại Client để lấy nhanh trường email điền vào form hồ sơ
        const base64Url = token.split('.')[1];
        const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
        const jsonPayload = decodeURIComponent(window.atob(base64).split('').map(function(c) {
            return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
        
        const googleUserData = JSON.parse(jsonPayload);

        // Đổi trạng thái giao diện: Ẩn form đăng ký thường, Hiện form nhập thông tin cá nhân bổ sung
        document.getElementById('standard-register-section').style.display = 'none';
        document.getElementById('google-profile-completion-section').style.display = 'block';
        
        // Cập nhật tiêu đề trang
        document.getElementById('header-title').innerText = "Hoàn thiện hồ sơ";
        document.getElementById('header-desc').innerText = "Nhập thông tin cá nhân để hoàn tất liên kết Google";

        // Gán các thông tin định danh thật thu được từ Google vào form bổ sung thông tin
        document.getElementById('google_token').value = token;
        document.getElementById('google_verified_email').value = googleUserData.email;
        
        // Tự động gợi ý username dựa trên tiền tố của Gmail người dùng vừa nhập
        const suggestUser = googleUserData.email.split('@')[0].replace(/[^a-zA-Z0-9]/g, '');
        document.getElementById('google_suggested_username').value = suggestUser;
    }
    </script>

    <div class="footer-text" style="text-align: center; margin-top: 15px; color: #e8f1f5; font-size: 0.85rem;">
        © 2026 Warehouse Management System - Powered by Google OAuth 2.0 Identity Server
    </div>
</body>
</html>