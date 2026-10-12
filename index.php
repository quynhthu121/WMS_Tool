<?php
/**
 * 🛰️ TÊN FILE: index.php
 * 🛰️ HỆ THỐNG ĐIỀU PHỐI TRUNG TÂM VÀ XÁC THỰC - PHÂN HỆ DOANH NGHIỆP PRO (ENTERPRISE MASTER ENGINE)
 */

if (version_compare(PHP_VERSION, '5.3.7', '<')) {
    exit("Sorry, Simple PHP Login does not run on a PHP version smaller than 5.3.7 !");
}

require_once("config/db.php");
require_once("classes/Login.php");

// 🚀 SỬA ĐOẠN NÀY: Nạp thêm lớp Đăng ký vào hệ thống điều phối trung tâm để đón đầu form hoàn thiện hồ sơ Google
require_once("classes/Registration.php");

$login = new Login();
$registration = new Registration(); // Khởi tạo xử lý song song

// --- ĐOẠN ĐIỀU HƯỚNG MỚI TÍCH HỢP LANDING PAGE ---
if ($login->isUserLoggedIn() == true) {
    // 1. Nếu người dùng ĐÃ ĐĂNG NHẬP -> Tiếp tục cho chạy code phía dưới để vào Dashboard
    // (Không làm gì cả, để code chạy tiếp xuống dưới)
} else {
    // 2. Nếu người dùng CHƯA ĐĂNG NHẬP -> Kiểm tra thao tác GET/POST
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        // Người dùng vừa gửi form login nhưng chưa được xác thực thành công.
        include("views/not_logged_in.php");
        exit();
    }

    if ($action === 'login') {
        // Người dùng chủ động bấm "Đăng nhập" -> Hiện form đăng nhập và ngắt code
        include("views/not_logged_in.php");
        exit();
    } elseif ($action === 'register') {
        // Người dùng chủ động bấm "Dùng thử / Đăng ký" -> Hiện form đăng ký và ngắt code
        include("views/register.php"); 
        exit();
    } else {
        // MẶC ĐỊNH: Nếu vừa mở web lên (chưa làm gì) -> Hiện trang Landing Page và ngắt code
        include("landing.php");
        exit();
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Khởi tạo các biến đếm thông tin hệ thống mặc định
$total_products_count = 0;
$total_warehouse_orders = 0;

if (file_exists("modules/connection.php")) {
    include_once("modules/connection.php");
}
$db = function_exists('getPDOLayerConnection') ? getPDOLayerConnection() : null;

if (isset($_GET['page'], $_GET['action']) && $_GET['page'] === 'reports' && $_GET['action'] === 'export_csv') {
    include("modules/reports.php");
    exit();
}

if ($db instanceof PDO) {
    // 📊 TRUY VẤN SỐ LIỆU ĐẾM THỜI GIAN THỰC ĐỂ HIỂN THỊ LÊN PROFILE TRỰC QUAN
    try {
        $total_products_count = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $total_warehouse_orders = (int)$db->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
    } catch (PDOException $e) {
        $total_products_count = 0;
        $total_warehouse_orders = 0;
    }

    // 🛑 XỬ LÝ FORM CẬP NHẬT BẢO MẬT TÀI KHOẢN (ĐÃ CẢI TIẾN THÊM TRƯỜNG)
    if (isset($_POST['submit_update_profile']) && isset($_SESSION['user_id'])) {
        $current_user_id = (int)$_SESSION['user_id'];
        $password_current = $_POST['update_user_password_current'];
        
        // Nhận thêm dữ liệu Tên và Số điện thoại mới
        $new_name = trim($_POST['update_user_name']);
        $new_phone = trim($_POST['update_user_phone']);
        $new_email = filter_var(trim($_POST['update_user_email']), FILTER_VALIDATE_EMAIL);
        
        $new_pass = $_POST['update_user_password_new'];
        $new_pass_repeat = $_POST['update_user_password_repeat'];
        
        if (empty($new_name)) {
            echo "<script>alert('Lỗi: Họ và tên hiển thị không được để trống!'); window.history.back();</script>";
            exit();
        }

        if (!$new_email) {
            echo "<script>alert('Lỗi: Định dạng Email không hợp lệ!'); window.history.back();</script>";
            exit();
        }

        try {
            $stmt_check = $db->prepare("SELECT user_password_hash FROM users WHERE user_id = ? LIMIT 1");
            $stmt_check->execute([$current_user_id]);
            $user_record = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($user_record && password_verify($password_current, $user_record['user_password_hash'])) {
                
                // Trường hợp 1: Có yêu cầu thay đổi cả mật khẩu mới
                if (!empty($new_pass)) {
                    if (strlen($new_pass) < 6) {
                        echo "<script>alert('Lỗi: Mật khẩu mới phải từ 6 ký tự trở lên!'); window.history.back();</script>";
                        exit();
                    }
                    if ($new_pass !== $new_pass_repeat) {
                        echo "<script>alert('Lỗi: Nhập lại mật khẩu mới không trùng khớp!'); window.history.back();</script>";
                        exit();
                    }
                    
                    $hash_new_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                    $stmt_update = $db->prepare("UPDATE users SET user_name = ?, user_email = ?, user_phone = ?, user_password_hash = ? WHERE user_id = ?");
                    $stmt_update->execute([$new_name, $new_email, $new_phone, $hash_new_pass, $current_user_id]);
                } 
                // Trường hợp 2: Chỉ cập nhật thông tin cá nhân cơ bản (Không đổi mật khẩu)
                else {
                    $stmt_update = $db->prepare("UPDATE users SET user_name = ?, user_email = ?, user_phone = ? WHERE user_id = ?");
                    $stmt_update->execute([$new_name, $new_email, $new_phone, $current_user_id]);
                }

                // Đồng bộ cập nhật ngay lập tức vào Session hệ thống
                $_SESSION['user_name'] = $new_name;
                $_SESSION['user_email'] = $new_email;
                $_SESSION['user_phone'] = $new_phone; 

                echo "<script>alert('Hệ thống cập nhật thông tin tài khoản thành công!'); window.location.href='index.php';</script>";
                exit();
            } else {
                echo "<script>alert('Thất bại: Mật khẩu hiện tại không chính xác!'); window.history.back();</script>";
                exit();
            }
        } catch (PDOException $e) {
            echo "<script>alert('Lỗi hệ thống: Email này hoặc dữ liệu này đã tồn tại trên tài khoản khác!'); window.history.back();</script>";
            exit();
        }
    }
}

if (!isset($_SESSION['user_name']) && isset($_SESSION['user_id'])) {
    $_SESSION['user_name'] = $_SESSION['user_name'] ?? 'Admin';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ Thống Quản Lý Kho Chuẩn Odoo</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Roboto', sans-serif; }
        body { display: flex; background-color: #F4F6F9; min-height: 100vh; color: #333333; }
        
        .sidebar { width: 260px; background: linear-gradient(180deg, #0f172a 0%, #1E2A38 55%, #0f172a 100%); color: #ffffff; display: flex; flex-direction: column; box-shadow: 2px 0 12px rgba(15,23,42,0.18); }
        .sidebar-brand { padding: 18px 16px; text-align: left; font-size: 1.05rem; font-weight: 700; border-bottom: 1px solid rgba(255,255,255,0.04); background-color: transparent; color: #ffffff; letter-spacing: 0.2px; display:flex; align-items:center; gap:10px; }
        .sidebar-menu { list-style: none; padding: 15px 0; flex: 1; }
        .sidebar-menu li { padding: 14px 20px; transition: all 0.2s ease-in-out; }
        .sidebar-menu li:hover { background-color: rgba(255,255,255,0.08); padding-left: 22px; }
        .sidebar-menu a { color: rgba(255,255,255,0.92); text-decoration: none; display: flex; align-items: center; gap: 12px; font-size: 0.95rem; font-weight: 600; }
        .sidebar-menu li:hover a { color: #ffffff; }
        
        .sidebar-logout { padding: 18px; border-top: 1px solid rgba(255,255,255,0.04); }
        .btn-logout { display: block; text-align: center; background-color: transparent; color: rgba(255,255,255,0.95); padding: 10px; text-decoration: none; border-radius: 6px; font-weight: 700; transition: all 0.18s; border: 1px solid rgba(255,255,255,0.06); }
        .btn-logout:hover { background-color: rgba(255,255,255,0.03); transform: translateY(-1px); }

        .main-content { flex: 1; display: flex; flex-direction: column; }
        .main-header { height: 65px; background-color: #ffffff; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-bottom: 1px solid rgba(30,42,56,0.04); }
        .page-title { font-size: 1.1rem; font-weight: 600; color: #1E2A38; display: flex; align-items: center; gap: 8px; }
        
        /* 👤 AVATAR PROFILE DESIGN */
        .user-profile { display: flex; align-items: center; gap: 12px; cursor: pointer; position: relative; }
        .user-info { text-align: right; }
        .user-info .username { display: block; font-size: 0.9rem; font-weight: 600; color: #2c3e50; }
        .user-info .role { display: block; font-size: 0.75rem; color: #7f8c8d; }
        .avatar-circle { width: 38px; height: 38px; background-color: #178978; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; box-shadow: 0 2px 4px rgba(23, 137, 120, 0.2); border: 2px solid #ffffff; }

        .main-body { padding: 30px; flex: 1; overflow-y: auto; background-color: #F4F6F9; }

        /* 🛠️ COMPONENT: TRUNG TÂM ĐIỀU KHIỂN ĐA PHÂN HỆ PRO */
        .profile-dropdown { display: none; position: absolute; right: 0; top: 55px; background-color: #ffffff; min-width: 350px; box-shadow: 0px 15px 35px rgba(30, 61, 89, 0.18); border-radius: 12px; padding: 0; z-index: 9999; border: 1px solid #e2e8f0; cursor: default; text-align: left; overflow: hidden; }
        .profile-dropdown.show { display: block; animation: dropdownFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
        
        /* Dropdown Header */
        .pro-dropdown-header { background: linear-gradient(135deg, #1E2A38 0%, #178978 100%); padding: 18px; color: white; display: flex; align-items: center; gap: 12px; }
        .pro-dropdown-header h4 { font-size: 1rem; font-weight: 600; margin-bottom: 2px; }
        .pro-badge { background: rgba(255, 255, 255, 0.22); padding: 2px 8px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.3px; display: inline-block; border: 1px solid rgba(255, 255, 255, 0.3); }

        /* Navigation Tabs Control */
        .profile-tabs-nav { display: flex; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .profile-tab-btn { flex: 1; text-align: center; padding: 10px; font-size: 0.85rem; font-weight: 600; color: #64748b; background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s; }
        .profile-tab-btn.active { color: #1E2A38; border-bottom-color: #1E2A38; background: #ffffff; }

        /* Tabs Content Layer */
        .profile-tab-content { padding: 20px; display: none; max-height: 420px; overflow-y: auto; }
        .profile-tab-content.active { display: block; }

        /* Thống kê KPIs Widget */
        .kpi-mini-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; }
        .kpi-mini-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; text-align: center; }
        .kpi-mini-card p { font-size: 0.75rem; color: #64748b; font-weight: 500; margin-bottom: 4px; }
        .kpi-mini-card h5 { font-size: 1.25rem; color: #1E2A38; font-weight: 700; }
        
        .sys-info-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dotted #e2e8f0; font-size: 0.8rem; color: #475569; }
        .sys-info-item strong { color: #1E2A38; }

        /* Form styling */
        .form-mini-group { margin-bottom: 12px; }
        .form-mini-group label { display: block; font-size: 0.78rem; margin-bottom: 5px; color: #475569; font-weight: 600; }
        .form-mini-group label span { color: #ff6b6b; }
        .form-mini-group input { width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; color: #1e293b; transition: all 0.15s; }
        .form-mini-group input:focus { border-color: #178978; box-shadow: 0 0 0 3px rgba(23, 137, 120, 0.15); }
        
        /* STYLE CHO KHỐI ĐỔI MẬT KHẨU ẨN HIỆN LINH HOẠT */
        .btn-trigger-password { display: block; width: 100%; background: #f1f5f9; color: #475569; border: 1px dashed #cbd5e1; padding: 8px; text-align: center; font-size: 0.8rem; font-weight: 600; border-radius: 6px; cursor: pointer; margin: 10px 0; transition: all 0.2s; }
        .btn-trigger-password:hover { background: #e2e8f0; color: #1e293b; }
        .password-toggle-section { display: none; border-top: 1px dashed #e2e8f0; padding-top: 12px; margin-top: 12px; }
        .password-toggle-section.show { display: block; }

        .dropdown-mini-actions { display: flex; justify-content: space-between; margin-top: 18px; gap: 10px; }
        .btn-mini-save { background-color: #178978; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.85rem; flex: 1; box-shadow: 0 2px 4px rgba(23, 137, 120, 0.15); }
        .btn-mini-save:hover { background-color: #14a066; }
        .btn-mini-cancel { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 0.85rem; text-align: center; flex: 1; }
        .btn-mini-cancel:hover { background-color: #e2e8f0; }
        .btn-logout-dropdown { background-color: #dc3545 !important; color: white !important; border: none !important; padding: 10px !important; text-decoration: none; border-radius: 6px; font-weight: 700; display: block; text-align: center; transition: all 0.18s; }
        .btn-logout-dropdown:hover { background-color: #c82333 !important; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(220, 53, 69, 0.2); }
        @keyframes dropdownFadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-brand">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="2" y="2" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                <rect x="14" y="2" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                <rect x="2" y="14" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                <rect x="14" y="14" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
            </svg>
            <div style="margin-left:6px; line-height:1;">
                <div style="font-weight:700;">FlowLink <span style="color:#34d399;">SCM</span></div>
                <div style="font-size:0.75rem; color: rgba(255,255,255,0.75);">Hệ thống SCM</div>
            </div>
        </div>
        <ul class="sidebar-menu">
            <li><a href="index.php?page=dashboard">📊 Dashboard</a></li>
            <li><a href="index.php?page=products">📦 Sản phẩm</a></li>
            <li><a href="index.php?page=partners">👥 Đối tác (KH/NCC)</a></li>
            <li><a href="index.php?page=warehouse">🚚 Điều phối Kho</a></li>
            <li><a href="index.php?page=audit">📋 Kiểm kê kho</a></li>
            <li><a href="index.php?page=reports">📈 Báo cáo vĩ mô</a></li>
            <li><a href="index.php?page=chat-ai">🤖 Trợ lý AI</a></li>
            <li><a href="index.php?page=settings">⚙️ Cài đặt</a></li>
        </ul>
    </aside>

    <div class="main-content">
        <header class="main-header">
            <div class="page-title">⚙️ Hệ thống điều phối dữ liệu</div>
            
            <div class="user-profile" id="userProfileTrigger">
                <div class="user-info">
                    <span class="username"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Quản trị viên'); ?></span>
                    <span class="role">Hạt nhân Hệ thống</span>
                </div>
                <div class="avatar-circle">
                    <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'Q', 0, 1)); ?>
                </div>

                <div class="profile-dropdown" id="profileDropdownMini">
                    
                    <div class="pro-dropdown-header">
                        <div class="avatar-circle" style="background: white; color: #1E2A38; box-shadow: none;">
                            <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'Q', 0, 1)); ?>
                        </div>
                        <div>
                            <h4><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Quản trị viên'); ?></h4>
                            <span class="pro-badge">ID Nhân viên: #00<?php echo htmlspecialchars($_SESSION['user_id'] ?? '1'); ?></span>
                        </div>
                    </div>

                    <div class="profile-tabs-nav">
                        <button type="button" class="profile-tab-btn active" data-target="tab-overview">📊 Tổng Quan KPI</button>
                        <button type="button" class="profile-tab-btn" data-target="tab-security">🔑 Bảo Mật Lõi</button>
                    </div>

                    <div class="profile-tab-content active" id="tab-overview">
                        <div class="kpi-mini-grid">
                            <div class="kpi-mini-card">
                                <p>📦 Sản Phẩm Lưu Kho</p>
                                <h5><?php echo $total_products_count; ?></h5>
                            </div>
                            <div class="kpi-mini-card">
                                <p>🚚 Lịch Sử Điều Phối</p>
                                <h5><?php echo $total_warehouse_orders; ?></h5>
                            </div>
                        </div>
                        <div class="sys-info-item"><span>Cấp bậc phân quyền:</span> <strong>Master Admin</strong></div>
                        <div class="sys-info-item"><span>Họ và tên:</span> <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Chưa cấu hình'); ?></strong></div>
                        <div class="sys-info-item"><span>Email đăng ký:</span> <strong><?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Chưa cấu hình'); ?></strong></div>
                        <div class="sys-info-item"><span>Số điện thoại:</span> <strong><?php echo htmlspecialchars($_SESSION['user_phone'] ?? 'Chưa cập nhật'); ?></strong></div>
                        <div class="sys-info-item"><span>Môi trường mạng:</span> <strong style="color: #178978;">Docker Container Stack</strong></div>
                        <button type="button" id="btnMiniClose" class="btn-mini-cancel" style="margin-top: 15px; width: 100%;">Đóng bảng điều khiển</button>
                    </div>

                    <div class="profile-tab-content" id="tab-security">
                        <form action="index.php" method="POST">
                            
                            <div class="form-mini-group">
                                <label>Họ và tên hiển thị: <span>*</span></label>
                                <input type="text" name="update_user_name" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>" required placeholder="Nhập họ và tên tài khoản">
                            </div>

                            <div class="form-mini-group">
                                <label>Số điện thoại liên hệ:</label>
                                <input type="text" name="update_user_phone" value="<?php echo htmlspecialchars($_SESSION['user_phone'] ?? ''); ?>" placeholder="Ví dụ: 0912345678">
                            </div>

                            <div class="form-mini-group">
                                <label>Email liên hệ nhận thông báo: <span>*</span></label>
                                <input type="email" name="update_user_email" value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? 'admin@warehouse.com'); ?>" required>
                            </div>
                            
                            <div class="btn-trigger-password" id="btnTogglePasswordFields">🔐 Yêu cầu thay đổi mật khẩu?</div>

                            <div class="password-toggle-section" id="passwordSection">
                                <div class="form-mini-group">
                                    <label>Mật khẩu mới:</label>
                                    <input type="password" id="input_new_pass" name="update_user_password_new" autocomplete="off" placeholder="Tối thiểu 6 ký tự">
                                </div>
                                <div class="form-mini-group">
                                    <label>Nhập lại mật khẩu mới:</label>
                                    <input type="password" id="input_repeat_pass" name="update_user_password_repeat" autocomplete="off" placeholder="Trùng khớp mật khẩu trên">
                                </div>
                            </div>

                            <div class="form-mini-group" style="background: #fff5f5; padding: 10px; border-radius: 6px; border: 1px solid #fed7d7; margin-top: 12px;">
                                <label style="color: #9b1c1c;">Mật khẩu hiện tại (Bắt buộc): <span>*</span></label>
                                <input type="password" name="update_user_password_current" required placeholder="Nhập mật khẩu hiện tại để xác minh">
                            </div>

                            <div class="dropdown-mini-actions">
                                <button type="submit" name="submit_update_profile" class="btn-mini-save">Lưu cấu trúc</button>
                                <button type="button" id="btnMiniCancel" class="btn-mini-cancel">Hủy bỏ</button>
                            </div>
                        </form>
                    </div>

                    <div class="dropdown-mini-actions" style="padding: 0 15px 15px 15px;">
                        <a href="index.php?logout" class="btn-logout-dropdown">🚪 Đăng xuất</a>
                    </div>

                </div>
            </div>
        </header>
        
        <main class="main-body">
            <?php
            $currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
            $allowedPages = ['dashboard', 'products', 'partners', 'warehouse', 'audit', 'reports', 'chat-ai', 'settings'];
            
            if (in_array($currentPage, $allowedPages)) {
                $targetFile = "modules/" . $currentPage . ".php";
                if (file_exists($targetFile)) {
                    include($targetFile);
                } else {
                    echo "<div style='background: #ffffff; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border-left: 4px solid #1E2A38;'>";
                    echo "<h3 style='color: #1E2A38;'>Mô-đun [ " . htmlspecialchars(ucfirst($currentPage)) . " ] đang được cấu trúc</h3>";
                    echo "<p style='color: #7f8c8d; margin-top: 10px;'>Hạt nhân Docker đang sẵn sàng nạp kết nối SQL cho tầng nghiệp vụ này.</p>";
                    echo "</div>";
                }
            } else {
                echo "<h3 style='color: #ff6b6b;'>Cảnh báo: Tầng truy cập nghiệp vụ không hợp lệ!</h3>";
            }
            ?>
        </main>
    </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        const trigger = document.getElementById('userProfileTrigger');
        const dropdown = document.getElementById('profileDropdownMini');
        const btnCancel = document.getElementById('btnMiniCancel');
        const btnClose = document.getElementById('btnMiniClose');
        const tabButtons = document.querySelectorAll('.profile-tab-btn');
        const tabContents = document.querySelectorAll('.profile-tab-content');
        
        const btnTogglePassword = document.getElementById('btnTogglePasswordFields');
        const passwordSection = document.getElementById('passwordSection');
        const inputNewPass = document.getElementById('input_new_pass');
        const inputRepeatPass = document.getElementById('input_repeat_pass');

        // XỬ LÝ SỰ KIỆN BẤM NÚT "ĐỔI MẬT KHẨU"
        if (btnTogglePassword && passwordSection) {
            btnTogglePassword.addEventListener('click', function(e) {
                e.stopPropagation(); 
                passwordSection.classList.toggle('show');
                
                if (passwordSection.classList.contains('show')) {
                    this.innerText = "❌ Hủy thay đổi mật khẩu";
                    this.style.background = "#fff5f5";
                    this.style.color = "#c53030";
                    this.style.borderColor = "#feb2b2";
                } else {
                    this.innerText = "🔐 Yêu cầu thay đổi mật khẩu?";
                    this.style.background = "#f1f5f9";
                    this.style.color = "#475569";
                    this.style.borderColor = "#cbd5e1";
                    if (inputNewPass) inputNewPass.value = "";
                    if (inputRepeatPass) inputRepeatPass.value = "";
                }
            });
        }

        // LẮNG NGHE SỰ KIỆN CHUYỂN TAB ĐA PHÂN HỆ
        tabButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation(); 
                const targetTab = this.getAttribute('data-target');

                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));

                this.classList.add('active');
                const targetEl = document.getElementById(targetTab);
                if (targetEl) targetEl.classList.add('active');
            });
        });

        // Ẩn/Hiện Dropdown khi click vào Avatar vùng Profile Header
        if (trigger) {
            trigger.addEventListener('click', function(e) {
                if (e.target.closest('.profile-dropdown')) return;
                if (dropdown) dropdown.classList.toggle('show');
            });
        }

        // 🚀 ĐOẠN ĐÃ ĐƯỢC CHUẨN HÓA VÀ FIX LỖI: Sử dụng classList.remove thay vì `.remove()` trực tiếp
        const closeBox = (e) => {
            if (e) e.stopPropagation();
            if (dropdown) dropdown.classList.remove('show');
            if (passwordSection) passwordSection.classList.remove('show');
            if (btnTogglePassword) {
                btnTogglePassword.innerText = "🔐 Yêu cầu thay đổi mật khẩu?";
                btnTogglePassword.style.background = "#f1f5f9";
                btnTogglePassword.style.color = "#475569";
                btnTogglePassword.style.borderColor = "#cbd5e1";
            }
            if (inputNewPass) inputNewPass.value = "";
            if (inputRepeatPass) inputRepeatPass.value = "";
        };
        
        if (btnCancel) btnCancel.addEventListener('click', closeBox);
        if (btnClose) btnClose.addEventListener('click', closeBox);

        // Click vùng trống bất kỳ bên ngoài màn hình tự động đóng bảng điều khiển dropdown
        document.addEventListener('click', function(e) {
            if (trigger && !trigger.contains(e.target)) {
                if (dropdown) dropdown.classList.remove('show');
            }
        });
    });
    </script>
</body>
</html>
