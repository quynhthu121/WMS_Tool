<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - FlowLink SCM</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Roboto', sans-serif; }
        body { background: linear-gradient(180deg, #1E2A38 0%, #178978 100%); min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }        .login-container { background: #ffffff; border-radius: 16px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); overflow: hidden; max-width: 450px; width: 100%; animation: slideIn 0.5s ease-out; position: relative; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-30px); } to { opacity: 1; transform: translateY(0); } }
        .login-header { background: linear-gradient(135deg, #0f172a 0%, #1E2A38 100%); padding: 40px 30px; text-align: center; color: #ffffff; }
        .login-header h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 8px; display:flex; align-items:center; justify-content:center; gap:10px; }
        .login-body { padding: 30px 30px; }
        .message-box { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
        .error-box { background-color: #fee; color: #c33; border-left: 4px solid #e74c3c; }
        .success-box { background-color: #d4edda; color: #155724; border-left: 4px solid #178978; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #1e3d59; font-weight: 600; font-size: 0.9rem; }
        .login_input { width: 100%; padding: 14px 16px; border: 2px solid #e0e6ed; border-radius: 8px; font-size: 1rem; background-color: #f8fafc; transition: all 0.3s ease; }
        .login_input:focus { outline: none; border-color: #178978; background-color: #ffffff; }
        .btn-login { width: 100%; padding: 14px; background: linear-gradient(135deg, #0f172a 0%, #1E2A38 100%); color: #ffffff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; }
        .social-divider { display: flex; align-items: center; text-align: center; margin: 25px 0; color: #8898aa; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }
        .social-divider::before, .social-divider::after { content: ''; flex: 1; border-bottom: 1px solid #e0e6ed; }
        .social-divider:not(:empty)::before { margin-right: .75em; }
        .social-divider:not(:empty)::after { margin-left: .75em; }
        .register-link { text-align: center; margin-top: 20px; font-size: 0.9rem; color: #64748b; }
        .register-link a { color: #178978; font-weight: 600; text-decoration: none; cursor: pointer; }
        .google-action { display: flex; justify-content: center; margin-top: 12px; }
        .google-note { text-align: center; margin-top: 12px; font-size: 0.9rem; color: #5b6b77; }
        .google-signin-card { width: 100%; max-width: 420px; }

        /* 🚀 PHẦN CSS THÊM MỚI: Cấu hình Modal Đăng ký nhanh không chia tab */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; justify-content: center; align-items: center; padding: 20px; }
        .modal-content { background: #ffffff; border-radius: 16px; max-width: 450px; width: 100%; overflow: hidden; animation: slideIn 0.3s ease-out; box-shadow: 0 10px 40px rgba(0,0,0,0.4); }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-header">
            <h1>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="2" y="2" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                    <rect x="14" y="2" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                    <rect x="2" y="14" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                    <rect x="14" y="14" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                </svg>
                <span style="font-size:1rem;">FlowLink <span style="color:#178978;">SCM</span></span>
            </h1>
            <p>Đăng nhập điều hành hệ thống logistics</p>
        </div>

        <div class="login-body">
            <?php
            if (isset($login)) {
                if ($login->errors) {
                    foreach ($login->errors as $error) {
                        echo '<div class="message-box error-box">⚠️ ' . htmlspecialchars($error) . '</div>';
                    }
                }
                if ($login->messages) {
                    foreach ($login->messages as $message) {
                        echo '<div class="message-box success-box">✅ ' . htmlspecialchars($message) . '</div>';
                    }
                }
            }
            // 🚀 Hiển thị thông báo nếu lớp đăng ký trả về lỗi/thành công từ Modal ngầm gửi lên
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

            <form method="post" action="index.php" name="loginform">
                <div class="form-group">
                    <label>Tên đăng nhập / Địa chỉ Email</label>
                    <input class="login_input" type="text" name="user_name" placeholder="Nhập tên đăng nhập hoặc email" required />
                </div>
                <div class="form-group">
                    <label>Mật khẩu</label>
                    <input class="login_input" type="password" name="user_password" placeholder="Nhập mật khẩu tài khoản" autocomplete="off" required />
                </div>
                <button type="submit" name="login" class="btn-login">Đăng nhập</button>
            </form>

            <div class="social-divider">Hoặc đăng nhập nhanh bằng</div>
            <div class="google-action">
                <div class="google-signin-card">
                    <div id="g_id_onload"
                         data-client_id="<?php echo GOOGLE_CLIENT_ID; ?>"
                         data-callback="handleGoogleLoginResponse"
                         data-auto_prompt="false">
                    </div>
                    <div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="filled_blue" data-text="signin_with" data-size="large" data-width="390"></div>
                </div>
            </div>
            <div class="google-note">Đăng nhập tức thì bằng Google, bảo mật và không cần nhập mật khẩu nếu bạn đã đăng nhập Google.</div>

            <div class="register-link">
                Chưa có tài khoản quản lý kho? <a onclick="openRegisterModal()">Đăng ký tài khoản mới</a>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="registerModal">
        <div class="modal-content">
            <div class="login-header" style="padding: 25px 20px;">
                <h2>Đăng ký thành viên mới</h2>
                <p>Khởi tạo tài khoản quản trị kho hàng</p>
            </div>
            <div class="login-body" style="padding: 20px 25px;">
                <form method="post" action="register.php" name="registerform">
                    <div class="form-group">
                        <label>Tên đăng nhập (chỉ chữ và số)</label>
                        <input class="login_input" type="text" pattern="[a-zA-Z0-9]{2,64}" name="user_name" placeholder="Ví dụ: nvanA" required />
                    </div>
                    <div class="form-group">
                        <label>Địa chỉ Email</label>
                        <input class="login_input" type="email" name="user_email" placeholder="email@example.com" required />
                    </div>
                    <div class="form-group">
                        <label>Mật khẩu (Tối thiểu 6 ký tự)</label>
                        <input class="login_input" type="password" name="user_password_new" pattern=".{6,}" required placeholder="••••••••" autocomplete="off" />
                    </div>
                    <div class="form-group">
                        <label>Nhập lại mật khẩu</label>
                        <input class="login_input" type="password" name="user_password_repeat" pattern=".{6,}" required placeholder="••••••••" autocomplete="off" />
                    </div>
                    <button type="submit" name="register" class="btn-login" style="background: linear-gradient(135deg, #178978 0%, #1E2A38 100%);">Xác nhận đăng ký</button>
                    
                    <div class="register-link" style="margin-top: 15px;">
                        <a onclick="closeRegisterModal()" style="color: #e74c3c;">Quay lại Đăng nhập</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://accounts.google.com/gsi/client" async defer></script>
    
    <script>
    // 🚀 HÀM JAVASCRIPT THÊM MỚI: Quản lý đóng mở cửa sổ đăng ký nhanh tại chỗ
    function openRegisterModal() {
        document.getElementById('registerModal').style.display = 'flex';
    }
    function closeRegisterModal() {
        document.getElementById('registerModal').style.display = 'none';
    }

    // Hàm này tự động chạy khi người dùng chọn tài khoản và xác minh mật khẩu Gmail thành công trên popup Google
    function handleGoogleLoginResponse(response) {
        // Tạo một form ảo chạy ngầm để gửi Token an toàn về cho file classes/Login.php kiểm tra
        const form = document.createElement('form');
        form.method = 'POST';
        form.action='google_callback.php';
        
        const tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = 'google_oauth_token';
        tokenInput.value = response.credential;

        const flowInput = document.createElement('input');
        flowInput.type = 'hidden';
        flowInput.name = 'google_flow_type';
        flowInput.value = 'login';
        
        form.appendChild(tokenInput);
        form.appendChild(flowInput);
        document.body.appendChild(form);
        form.submit();
    }
    </script>
</body>
</html>
