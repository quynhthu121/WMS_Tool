<?php
/**
 * 🛰️ TẦNG KHỞI CHẠY HỆ THỐNG TRUNG TÂM (CORE ENGINE LAYER)
 * Đảm bảo Session và cấu hình Database được nạp trước khi render HTML
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra bảo mật tầng gốc: Nếu chưa đăng nhập, lập tức chuyển hướng về trang login
if (!isset($_SESSION['user_name'])) {
    header("Location: index.php?action=login");
    exit();
}

// Nạp các tệp cấu hình cốt lõi và hàm kết nối PDO dùng chung (kế thừa từ Hành động 2)
if (file_exists("config/db.php")) {
    include_once("config/db.php");
}
if (file_exists("modules/connection.php")) { 
    include_once("modules/connection.php"); // Nạp hàm getPDOLayerConnection() nếu có
}

// Thiết lập biến $db để dùng cho việc cập nhật thông tin cá nhân bên dưới
if (function_exists('getPDOLayerConnection')) {
    $db = getPDOLayerConnection(); 
}

// 📥 ĐOẠN XỬ LÝ LƯU THÔNG TIN KHI CÓ SUBMIT FORM CẬP NHẬT TÀI KHOẢN
if (isset($_POST['submit_update_profile'])) {
    $new_name = trim($_POST['update_user_name']);
    $new_email = trim($_POST['update_user_email']);
    $new_pass = $_POST['update_user_password_new'];
    
    if (isset($db) && $db instanceof PDO) {
        try {
            if (empty($new_name) || empty($new_email)) {
                echo "<script>alert('Không được bỏ trống Tên hoặc Email!');</script>";
            } else {
                if (!empty($new_pass)) {
                    // Người dùng muốn đổi mật khẩu mới
                    if (strlen($new_pass) < 6) {
                        echo "<script>alert('Mật khẩu mới phải từ 6 ký tự trở lên!');</script>";
                    } else {
                        $hash_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                        // Cập nhật cả mật khẩu dựa trên user_id của Session (hoặc user_name cũ)
                        $stmt = $db->prepare("UPDATE users SET user_name = ?, user_email = ?, user_password_hash = ? WHERE user_name = ?");
                        $stmt->execute([$new_name, $new_email, $hash_pass, $_SESSION['user_name']]);
                        
                        $_SESSION['user_name'] = $new_name;
                        $_SESSION['user_email'] = $new_email;
                        echo "<script>alert('Cập nhật thông tin và mật khẩu thành công!'); window.location.href='index.php';</script>";
                    }
                } else {
                    // Chỉ cập nhật Tên và Email
                    $stmt = $db->prepare("UPDATE users SET user_name = ?, user_email = ? WHERE user_name = ?");
                    $stmt->execute([$new_name, $new_email, $_SESSION['user_name']]);
                    
                    $_SESSION['user_name'] = $new_name;
                    $_SESSION['user_email'] = $new_email;
                    echo "<script>alert('Cập nhật thông tin cá nhân thành công!'); window.location.href='index.php';</script>";
                }
            }
        } catch (PDOException $e) {
            echo "<script>alert('Lỗi: Tên tài khoản hoặc Email này đã tồn tại!');</script>";
        }
    } else {
        echo "<script>alert('Lỗi: Không tìm thấy kết nối Cơ sở dữ liệu!');</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ Thống Quản Lý Kho Chuẩn Odoo</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Roboto', sans-serif; }
        body { display: flex; background-color: #f4f6f9; min-height: 100vh; color: #333333; }
        
        /* 🔵 THANH SIDEBAR BÊN TRÁI - TÔNG XANH NƯỚC BIỂN ĐẬM */
        .sidebar { width: 260px; background-color: #1e3d59; color: #ffffff; display: flex; flex-direction: column; box-shadow: 2px 0 5px rgba(0,0,0,0.1); }
        .sidebar-brand { padding: 20px; text-align: center; font-size: 1.15rem; font-weight: bold; border-bottom: 1px solid #17b978; background-color: #17b978; color: #ffffff; letter-spacing: 0.5px; }
        .sidebar-menu { list-style: none; padding: 15px 0; flex: 1; }
        .sidebar-menu li { padding: 14px 20px; transition: all 0.2s ease-in-out; }
        
        /* Trạng thái Hover & Active của Menu */
        .sidebar-menu li:hover { background-color: #17b978; padding-left: 25px; }
        .sidebar-menu a { color: #e8f1f5; text-decoration: none; display: flex; align-items: center; gap: 12px; font-size: 0.95rem; font-weight: 500; }
        .sidebar-menu li:hover a { color: #ffffff; }
        
        .sidebar-logout { padding: 20px; border-top: 1px solid #2b5278; }
        .btn-logout { display: block; text-align: center; background-color: #ff6b6b; color: white; padding: 10px; text-decoration: none; border-radius: 6px; font-weight: bold; transition: background 0.2s; }
        .btn-logout:hover { background-color: #ee5253; }

        /* ⚪ VÙNG NỘI DUNG CHÍNH BÊN PHẢI - TÔNG TRẮNG CHỦ ĐẠO */
        .main-content { flex: 1; display: flex; flex-direction: column; }
        
        /* Header trên cùng */
        .main-header { height: 65px; background-color: #ffffff; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-bottom: 1px solid #eef2f5; }
        .page-title { font-size: 1.1rem; font-weight: 600; color: #1e3d59; display: flex; align-items: center; gap: 8px; }
        
        /* 👤 KHU VỰC AVATAR GÓC TRÊN CÙNG BÊN PHẢI */
        .user-profile { display: flex; align-items: center; gap: 12px; cursor: pointer; position: relative; /* Giúp căn vị trí box nhỏ theo avatar */ }
        .user-info { text-align: right; }
        .user-info .username { display: block; font-size: 0.9rem; font-weight: 600; color: #2c3e50; }
        .user-info .role { display: block; font-size: 0.75rem; color: #7f8c8d; }
        
        /* Khung tròn Avatar */
        .avatar-circle { width: 38px; height: 38px; background-color: #17b978; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; box-shadow: 0 2px 4px rgba(23, 185, 120, 0.2); border: 2px solid #ffffff; }

        /* Ruột nội dung nghiệp vụ */
        .main-body { padding: 30px; flex: 1; overflow-y: auto; background-color: #f8fafc; }

        /* Định dạng cho chiếc Box nhỏ */
        .profile-dropdown {
            display: none; /* Mặc định ẩn đi */
            position: absolute;
            right: 0;
            top: 55px;
            background-color: #ffffff;
            min-width: 290px;
            box-shadow: 0px 10px 25px rgba(0,0,0,0.15);
            border-radius: 8px;
            padding: 20px;
            z-index: 9999;
            border: 1px solid #eef2f5;
            cursor: default;
            text-align: left;
        }

        /* Hiển thị box khi có class 'show' kích hoạt từ JS */
        .profile-dropdown.show {
            display: block;
            animation: dropdownFadeIn 0.25s ease-out;
        }

        .profile-dropdown h3 {
            font-size: 1rem;
            color: #1e3d59;
            margin-bottom: 12px;
            border-bottom: 1px solid #eef2f5;
            padding-bottom: 8px;
        }

        .form-mini-group {
            margin-bottom: 12px;
        }

        .form-mini-group label {
            display: block;
            font-size: 0.8rem;
            margin-bottom: 4px;
            color: #7f8c8d;
            font-weight: 500;
        }

        .form-mini-group input {
            width: 100%;
            padding: 8px;
            font-size: 0.9rem;
            border: 1px solid #cccccc;
            border-radius: 4px;
            outline: none;
        }

        .form-mini-group input:focus {
            border-color: #17b978;
        }

        .dropdown-mini-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            gap: 10px;
        }

        .btn-mini-save {
            background-color: #17b978;
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.85rem;
            flex: 1;
        }

        .btn-mini-cancel {
            background-color: #ff6b6b;
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.85rem;
        }

        @keyframes dropdownFadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-brand">📦 Warehouse</div>
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
        <div class="sidebar-logout">
            <a href="index.php?logout" class="btn-logout">🚪 Đăng xuất</a>
        </div>
    </aside>

    <div class="main-content">
        <header class="main-header">
            <div class="page-title">⚙️ Hệ thống điều phối dữ liệu</div>
            
            <div class="user-profile" id="userProfileTrigger">
                <div class="user-info">
                    <span class="username"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
                    <span class="role">Quản trị viên Hệ thống</span>
                </div>
                <div class="avatar-circle">
                    <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?>
                </div>

                <div class="profile-dropdown" id="profileDropdownMini">
                    <h3>Chỉnh sửa tài khoản</h3>
                    <form action="" method="POST">
                        <div class="form-mini-group">
                            <label>Tên tài khoản:</label>
                            <input type="text" name="update_user_name" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-mini-group">
                            <label>Email cá nhân:</label>
                            <input type="email" name="update_user_email" value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>" required>
                        </div>
                        <div class="form-mini-group">
                            <label>Mật khẩu mới (bỏ trống nếu giữ cũ):</label>
                            <input type="password" name="update_user_password_new" autocomplete="off" placeholder="Tối thiểu 6 ký tự">
                        </div>
                        
                        <div class="dropdown-mini-actions">
                            <button type="submit" name="submit_update_profile" class="btn-mini-save">Cập nhật</button>
                            <button type="button" id="btnMiniCancel" class="btn-mini-cancel">Hủy</button>
                        </div>
                    </form>
                </div>
            </div>
        </header>
        
        <main class="main-body">
            <?php
            // BỘ ĐIỀU PHỐI TINH GỌN (ROUTER MECHANISM)
            $currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
            
            // Khai báo danh mục trang hợp pháp
            $allowedPages = ['dashboard', 'products', 'partners', 'warehouse', 'audit', 'reports', 'chat-ai', 'settings'];
            
            if (in_array($currentPage, $allowedPages)) {
                $targetFile = "modules/" . $currentPage . ".php";
                if (file_exists($targetFile)) {
                    // Inject cổng kết nối database an toàn cho các module sử dụng
                    include($targetFile);
                } else {
                    echo "<div style='background: #ffffff; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border-left: 4px solid #1e3d59;'>";
                    echo "<h3 style='color: #1e3d59;'>Mô-đun [ " . htmlspecialchars(ucfirst($currentPage)) . " ] đang được cấu trúc</h3>";
                    echo "<p style='color: #7f8c8d; margin-top: 10px;'>Hạt nhân Docker đang sẵn sàng nạp kết nối SQL cho tầng nghiệp vụ này.</p>";
                    echo "</div>";
                }
            } else {
                echo "<h3 style='color: #ff6b6b;'>Cảnh báo: Tầng truy cập không hợp lệ!</h3>";
            }
            ?>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const trigger = document.getElementById('userProfileTrigger');
        const dropdown = document.getElementById('profileDropdownMini');
        const btnCancel = document.getElementById('btnMiniCancel');

        // Bấm vào khu vực Avatar -> Tắt/Bật Box nhỏ
        trigger.addEventListener('click', function(e) {
            // Nếu người dùng đang tương tác gõ chữ bên trong Form thì không đóng box
            if (e.target.closest('.profile-dropdown')) return;
            dropdown.classList.toggle('show');
        });

        // Bấm nút Hủy -> Đóng Box
        btnCancel.addEventListener('click', function(e) {
            e.stopPropagation(); // Không kích hoạt sự kiện click của trigger cha
            dropdown.classList.remove('show');
        });

        // Click ra vùng trống bất kỳ trên màn hình -> Tự động đóng Box lại
        document.addEventListener('click', function(e) {
            if (!trigger.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
    });
    </script>
</body>
</html>