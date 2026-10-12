<?php
/**
 * ⚙️ MÔ-ĐUN CÀI ĐẶT TÀI KHOẢN NGƯỜI DÙNG
 * Chức năng:
 * - Xem thông tin tài khoản hiện tại
 * - Cập nhật username/email
 * - Đổi mật khẩu
 * - Cài đặt giao diện cơ bản bằng localStorage
 */

if (!isset($_SESSION['user_name'])) {
    exit("Truy cập bị từ chối!");
}

$pdo = getPDOLayerConnection();
$errors = [];
$messages = [];

// Tạo CSRF token đơn giản để bảo vệ form Settings
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function loadCurrentUser($pdo) {
    $stmt = $pdo->prepare("
        SELECT user_id, user_name, user_email, user_password_hash
        FROM users
        WHERE user_name = ?
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_name']]);
    return $stmt->fetch();
}

try {
    $currentUser = loadCurrentUser($pdo);

    if (!$currentUser) {
        throw new Exception("Không tìm thấy thông tin người dùng hiện tại.");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? '';

        if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            throw new Exception("Phiên bảo mật không hợp lệ. Vui lòng thử lại.");
        }

        /**
         * 1. CẬP NHẬT THÔNG TIN CÁ NHÂN
         */
        if (isset($_POST['update_profile'])) {
            $newUsername = trim($_POST['user_name'] ?? '');
            $newEmail = trim($_POST['user_email'] ?? '');

            if (empty($newUsername) || empty($newEmail)) {
                $errors[] = "Tên đăng nhập và email không được để trống.";
            } elseif (strlen($newUsername) < 2 || strlen($newUsername) > 64) {
                $errors[] = "Tên đăng nhập phải từ 2 đến 64 ký tự.";
            } elseif (!preg_match('/^[a-zA-Z0-9]{2,64}$/', $newUsername)) {
                $errors[] = "Tên đăng nhập chỉ được chứa chữ cái và số.";
            } elseif (strlen($newEmail) > 64 || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Email không hợp lệ hoặc dài quá 64 ký tự.";
            } else {
                $stmtCheck = $pdo->prepare("
                    SELECT user_id 
                    FROM users 
                    WHERE (user_name = ? OR user_email = ?)
                    AND user_id <> ?
                    LIMIT 1
                ");
                $stmtCheck->execute([$newUsername, $newEmail, $currentUser['user_id']]);

                if ($stmtCheck->fetch()) {
                    $errors[] = "Tên đăng nhập hoặc email đã được sử dụng bởi tài khoản khác.";
                } else {
                    $stmtUpdate = $pdo->prepare("
                        UPDATE users
                        SET user_name = ?, user_email = ?
                        WHERE user_id = ?
                    ");
                    $stmtUpdate->execute([$newUsername, $newEmail, $currentUser['user_id']]);

                    $_SESSION['user_name'] = $newUsername;
                    $_SESSION['user_email'] = $newEmail;

                    $messages[] = "Cập nhật thông tin tài khoản thành công.";
                    $currentUser = loadCurrentUser($pdo);
                }
            }
        }

        /**
         * 2. ĐỔI MẬT KHẨU
         */
        if (isset($_POST['change_password'])) {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $repeatPassword = $_POST['repeat_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword) || empty($repeatPassword)) {
                $errors[] = "Vui lòng nhập đầy đủ thông tin đổi mật khẩu.";
            } elseif (!password_verify($currentPassword, $currentUser['user_password_hash'])) {
                $errors[] = "Mật khẩu hiện tại không chính xác.";
            } elseif (strlen($newPassword) < 6) {
                $errors[] = "Mật khẩu mới phải có ít nhất 6 ký tự.";
            } elseif ($newPassword !== $repeatPassword) {
                $errors[] = "Mật khẩu mới và xác nhận mật khẩu không trùng khớp.";
            } else {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $stmtPassword = $pdo->prepare("
                    UPDATE users
                    SET user_password_hash = ?
                    WHERE user_id = ?
                ");
                $stmtPassword->execute([$newPasswordHash, $currentUser['user_id']]);

                $messages[] = "Đổi mật khẩu thành công.";
                $currentUser = loadCurrentUser($pdo);
            }
        }
    }
} catch (Exception $e) {
    $errors[] = "Lỗi Settings: " . $e->getMessage();
}
?>

<div class="settings-container">
    <div class="settings-title">
        <h2>⚙️ Cài đặt tài khoản</h2>
        <p>Quản lý thông tin cá nhân, bảo mật và tuỳ chỉnh giao diện làm việc.</p>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error">
            <strong>⚠️ Lỗi:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success">
            <strong>✅ Thành công:</strong> <?= htmlspecialchars($message) ?>
        </div>
    <?php endforeach; ?>

    <div class="settings-grid">
        <!-- THÔNG TIN TÀI KHOẢN -->
        <div class="settings-card">
            <div class="card-title">
                <h3>👤 Thông tin tài khoản</h3>
                <p>Cập nhật tên đăng nhập và email dùng trong hệ thống.</p>
            </div>

            <form method="POST" action="index.php?page=settings">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-group">
                    <label>Tên đăng nhập</label>
                    <input 
                        type="text" 
                        name="user_name" 
                        value="<?= htmlspecialchars($currentUser['user_name'] ?? '') ?>" 
                        required
                    >
                    <small>Chỉ dùng chữ cái và số, từ 2 đến 64 ký tự.</small>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input 
                        type="email" 
                        name="user_email" 
                        value="<?= htmlspecialchars($currentUser['user_email'] ?? '') ?>" 
                        required
                    >
                </div>

                <button type="submit" name="update_profile" class="btn-primary">
                    Lưu thay đổi
                </button>
            </form>
        </div>

        <!-- BẢO MẬT -->
        <div class="settings-card" id="security">
            <div class="card-title">
                <h3>🔐 Bảo mật</h3>
                <p>Đổi mật khẩu đăng nhập để bảo vệ tài khoản.</p>
            </div>

            <form method="POST" action="index.php?page=settings#security">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-group">
                    <label>Mật khẩu hiện tại</label>
                    <input type="password" name="current_password" autocomplete="off" required>
                </div>

                <div class="form-group">
                    <label>Mật khẩu mới</label>
                    <input type="password" name="new_password" autocomplete="off" required>
                    <small>Tối thiểu 6 ký tự.</small>
                </div>

                <div class="form-group">
                    <label>Nhập lại mật khẩu mới</label>
                    <input type="password" name="repeat_password" autocomplete="off" required>
                </div>

                <button type="submit" name="change_password" class="btn-warning">
                    Đổi mật khẩu
                </button>
            </form>
        </div>
    </div>

    <!-- TUỲ CHỈNH GIAO DIỆN -->
    <div class="settings-card full-width">
        <div class="card-title">
            <h3>🎨 Tuỳ chỉnh giao diện</h3>
            <p>Các lựa chọn này được lưu trên trình duyệt hiện tại bằng localStorage.</p>
        </div>

        <div class="preference-grid">
            <div class="form-group">
                <label>Chế độ giao diện</label>
                <select id="themeMode">
                    <option value="light">Sáng</option>
                    <option value="soft">Xanh nhạt</option>
                    <option value="dark">Tối nhẹ</option>
                </select>
            </div>

            <div class="form-group">
                <label>Kích thước hiển thị</label>
                <select id="displayDensity">
                    <option value="comfortable">Thoải mái</option>
                    <option value="compact">Gọn hơn</option>
                </select>
            </div>
        </div>

        <button type="button" class="btn-secondary" onclick="saveUiSettings()">
            Lưu tuỳ chỉnh giao diện
        </button>
    </div>
</div>

<style>
    .settings-container {animation: fadeIn 0.4s ease-in-out;}
    .settings-title {margin-bottom: 24px;}
    .settings-title h2 {color: #1e3d59; font-size: 1.6rem; margin-bottom: 6px;}
    .settings-title p {color: #7f8c8d; font-size: 0.92rem;}
    .alert {padding: 13px 15px; border-radius: 8px; margin-bottom: 14px; font-size: 0.9rem;}
    .alert-error {background-color: #fff0f0; color: #721c24; border-left: 5px solid #ff6b6b;}
    .alert-success {background-color: #e3fcef; color: #155724; border-left: 5px solid #17b978}
    .settings-grid {display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; align-items: start;}
    .settings-card {background-color: #ffffff; padding: 24px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); border: 1px solid #eef2f5;}
    .settings-card.full-width {margin-top: 20px;}
    .card-title {border-bottom: 2px solid #f4f6f9; padding-bottom: 12px; margin-bottom: 20px;}
    .card-title h3 {color: #1e3d59; font-size: 1.1rem; margin-bottom: 5px;}
    .card-title p {color: #7f8c8d; font-size: 0.86rem;}
    .form-group {margin-bottom: 18px;}
    .form-group label {display: block; color: #1e3d59; font-weight: 600; margin-bottom: 7px; font-size: 0.9rem;}
    .form-group input,
    .form-group select {width: 100%; padding: 11px 12px; border: 1px solid #dce3ea; border-radius: 6px; font-size: 0.95rem; background-color: #ffffff; outline: none; transition: border-color 0.2s ease, box-shadow 0.2s ease;}
    .form-group input:focus,
    .form-group select:focus {border-color: #17b978; box-shadow: 0 0 0 4px rgba(23, 185, 120, 0.1);}
    .form-group small {display: block; margin-top: 6px; color: #95a5a6; font-size: 0.8rem;}
    .preference-grid {display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;}
    .btn-primary,
    .btn-warning,
    .btn-secondary {border: none; padding: 11px 18px; border-radius: 6px; color: white; font-weight: 700; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease;}
    .btn-primary {background-color: #17b978;}
    .btn-warning {background-color: #ff9f43;}
    .btn-secondary {background-color: #1e3d59;}
    .btn-primary:hover,
    .btn-warning:hover,
    .btn-secondary:hover {transform: translateY(-1px);  box-shadow: 0 6px 16px rgba(0,0,0,0.12);}

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<script>
function applyUiSettings() {
    const themeMode = localStorage.getItem('warehouse_theme_mode') || 'light';
    const displayDensity = localStorage.getItem('warehouse_display_density') || 'comfortable';

    const themeSelect = document.getElementById('themeMode');
    const densitySelect = document.getElementById('displayDensity');

    if (themeSelect) {
        themeSelect.value = themeMode;
    }

    if (densitySelect) {
        densitySelect.value = displayDensity;
    }

    const mainBody = document.querySelector('.main-body');

    if (mainBody) {
        if (themeMode === 'soft') {
            mainBody.style.backgroundColor = '#eefaf5';
        } else if (themeMode === 'dark') {
            mainBody.style.backgroundColor = '#e9eef3';
        } else {
            mainBody.style.backgroundColor = '#f8fafc';
        }

        if (displayDensity === 'compact') {
            mainBody.style.padding = '20px';
        } else {
            mainBody.style.padding = '30px';
        }
    }
}

function saveUiSettings() {
    const themeMode = document.getElementById('themeMode').value;
    const displayDensity = document.getElementById('displayDensity').value;

    localStorage.setItem('warehouse_theme_mode', themeMode);
    localStorage.setItem('warehouse_display_density', displayDensity);

    applyUiSettings();
    alert('Đã lưu tuỳ chỉnh giao diện trên trình duyệt này.');
}

document.addEventListener('DOMContentLoaded', applyUiSettings);
</script>