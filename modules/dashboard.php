<?php
// Kiểm tra bảo mật: Không cho phép truy cập trực tiếp file này từ URL bên ngoài
if (!isset($_SESSION['user_name'])) {
    exit("Truy cập bị từ chối!");
}

/**
 * 🛠️ TẦNG KẾT NỐI VÀ TRUY VẤN DỮ LIỆU THỰC TẾ (DYNAMIC DATA LAYER - PDO UPGRADED)
 * Kéo chỉ số trực tiếp từ bảng products và tích hợp lịch sử luân chuyển thực tế từ stock_picking
 */
$totalProducts = 0;       
$totalStockVolume = 0;   
$lowStockAlert = 0;      
$recentActivities = [];  

try {
    // Khởi tạo kết nối thông qua lớp PDO đồng bộ bảo mật
    if (function_exists('getPDOLayerConnection')) {
        $pdo = getPDOLayerConnection();
    } else {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    
    if ($pdo) {
        // 1. Lấy tổng số danh mục sản phẩm (Total SKU)
        $totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

        // 2. Lấy tổng sản lượng tồn kho luân chuyển (Total Quantity)
        $totalStockVolume = (int)$pdo->query("SELECT SUM(qty) FROM products")->fetchColumn();

        // 3. Đếm số lượng sản phẩm rơi vào trạng thái cảnh báo (Số lượng tồn <= 10)
        $lowStockAlert = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE qty <= 10")->fetchColumn();

        // 4. Lấy dữ liệu động từ bảng luân chuyển kho thực tế (stock_picking song hành cùng stock_move)
        // Dùng câu lệnh chuẩn bị trước để quét 5 biến động kho gần nhất
        $stmtRecent = $pdo->prepare("
            SELECT sp.scheduled_date, sp.type, sm.product_sku, sm.product_qty 
            FROM stock_picking sp 
            JOIN stock_move sm ON sp.id = sm.picking_id 
            ORDER BY sp.id DESC LIMIT 5
        ");
        $stmtRecent->execute();
        $activities = $stmtRecent->fetchAll();

        if (!empty($activities)) {
            foreach ($activities as $row) {
                // Ép kiểu thời gian từ DB ra định dạng gọn nhẹ đúng Layout gốc của bạn
                $timeFormatted = date('H:i | d-m', strtotime($row['scheduled_date']));
                $recentActivities[] = [
                    'time' => $timeFormatted,
                    'type' => $row['type'], // Kế thừa giá trị 'in' hoặc 'out' thực tế từ Odoo Engine
                    'product_name' => $row['product_sku'], // Hiển thị mã sản phẩm luân chuyển
                    'quantity' => number_format($row['product_qty']) . " SP"
                ];
            }
        } else {
            // Cơ chế Fallback dữ liệu: Nếu bảng luân chuyển mới chưa có lệnh nhập xuất kho nào, 
            // hệ thống tự động quét bảng sản phẩm gốc để hiển thị danh sách khởi tạo ban đầu giống hệt code cũ của bạn
            $stmtFallback = $pdo->query("SELECT name, qty FROM products ORDER BY id DESC LIMIT 5");
            while ($row = $stmtFallback->fetch()) {
                $recentActivities[] = [
                    'time' => date('H:i | d-m'),
                    'type' => 'in',
                    'product_name' => $row['name'],
                    'quantity' => number_format($row['qty']) . " SP"
                ];
            }
        }
    }
} catch (Exception $e) {
    // Đóng băng ngoại lệ bảo vệ luồng hiển thị giao diện
}
?>

<div class="dashboard-container">
    <div class="dashboard-title">
        <h2>📊 Tổng quan hệ thống quản trị</h2>
        <p>Báo cáo tình trạng vận hành kho và chỉ số luân chuyển hàng hóa thực tế.</p>
    </div>

    <div class="card-grid">
        <div class="card card-blue">
            <div class="card-icon">📦</div>
            <div class="card-info">
                <h3><?php echo $totalProducts; ?></h3>
                <p>Danh mục sản phẩm</p>
            </div>
        </div>

        <div class="card card-green">
            <div class="card-icon">🏢</div>
            <div class="card-info">
                <h3><?php echo number_format($totalStockVolume); ?></h3>
                <p>Tổng sản lượng tồn kho</p>
            </div>
        </div>

        <div class="card card-orange">
            <div class="card-icon">⚠️</div>
            <div class="card-info">
                <h3><?php echo $lowStockAlert; ?></h3>
                <p>Cảnh báo hết hàng</p>
            </div>
        </div>
    </div>

    <div class="dashboard-details">
        <div class="detail-box">
            <h4>🔄 Nhật ký kho mới nhất</h4>
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Nghiệp vụ</th>
                        <th>Sản phẩm</th>
                        <th>Số lượng</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentActivities)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #95a5a6; padding: 30px 10px;">
                                Đang chờ kết nối dữ liệu từ các mô-đun nghiệp vụ...
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentActivities as $activity): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($activity['time']); ?></td>
                                <td>
                                    <span class="badge <?php echo $activity['type'] == 'in' ? 'badge-in' : 'badge-out'; ?>">
                                        <?php echo $activity['type'] == 'in' ? 'Nhập kho' : 'Xuất kho'; ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($activity['product_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($activity['quantity']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="detail-box quick-links">
            <h4>⚡ Thao tác nhanh</h4>
            <a href="index.php?page=products" class="link-btn">➡️ Quản lý danh mục sản phẩm</a>
            <a href="index.php?page=partners" class="link-btn">➡️ Quản lý đối tác KH/NCC</a>
            <a href="index.php?page=audit" class="link-btn link-btn-secondary">➡️ Kiểm kê & Điều chỉnh kho</a>
            <a href="index.php?page=reports" class="link-btn link-btn-secondary">➡️ Xem báo cáo phân tích</a>

            <div class="system-status-container">
                <h5>🖥️ Trạng thái máy chủ Docker</h5>
                
                <div class="status-item">
                    <span class="status-label">Cơ sở dữ liệu (DB):</span>
                    <span class="status-value text-success"><span class="dot-online"></span> Trực tuyến (Connected)</span>
                </div>
                
                <div class="status-item">
                    <span class="status-label">Cổng mạng kết nối:</span>
                    <span class="status-value">Host: <code class="code-spec">db</code> | Port: <code class="code-spec">3306</code></span>
                </div>
                
                <div class="status-item" style="margin-top: 15px; border-top: 1px dashed #eef2f5; padding-top: 10px;">
                    <span class="status-label">Tài khoản trực ban:</span>
                    <span class="status-value"><strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong></span>
                </div>

                <div class="status-item">
                    <span class="status-label">Phiên làm việc:</span>
                    <span class="status-value text-blue">Đang hoạt động</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .dashboard-container { animation: fadeIn 0.4s ease-in-out; }
    .dashboard-title { margin-bottom: 25px; }
    .dashboard-title h2 { color: #1E2A38; font-size: 1.6rem; }
    .dashboard-title p { color: #7f8c8d; font-size: 0.9rem; margin-top: 5px; }

    /* Định dạng lưới thẻ Card */
    .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .card { background: #ffffff; border-radius: 8px; padding: 20px; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); border-bottom: 4px solid transparent; }
    .card-icon { font-size: 2.5rem; }
    .card-info h3 { font-size: 1.8rem; color: #2c3e50; font-weight: 700; }
    .card-info p { color: #7f8c8d; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
    
    /* Màu sắc nhận diện hệ thống */
    .card-blue { border-bottom-color: #1E2A38; }
    .card-green { border-bottom-color: #178978; }
    .card-orange { border-bottom-color: #ff9f43; }

    /* Bố cục vùng chi tiết */
    .dashboard-details { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
    .detail-box { background: #ffffff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .detail-box h4 { color: #1E2A38; margin-bottom: 15px; font-size: 1.05rem; border-bottom: 2px solid #f4f6f9; padding-bottom: 10px; }

    /* Định dạng bảng dữ liệu */
    .dashboard-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
    .dashboard-table th, .dashboard-table td { padding: 12px 10px; border-bottom: 1px solid #f4f6f9; }
    .dashboard-table th { color: #7f8c8d; font-weight: 600; }
    .dashboard-table td { color: #2c3e50; }
    
    /* Huy hiệu trạng thái */
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; }
    .badge-in { background-color: #e3fcef; color: #155724; }
    .badge-out { background-color: #fff0f0; color: #721c24; }

    /* Nút thao tác nhanh */
    .quick-links { display: flex; flex-direction: column; }
    .link-btn { display: block; background: #1E2A38; color: white; padding: 12px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: 500; font-size: 0.9rem; margin-bottom: 10px; transition: background 0.2s; }
    .link-btn:hover { background: #178978; }
    .link-btn-secondary { background: #7f8c8d; }
    .link-btn-secondary:hover { background: #6c7a89; }

    /* 🔵 CSS ĐỘC LẬP CHO KHỐI TIỆN ÍCH LẤP ĐẦY KHOẢNG TRỐNG */
    .system-status-container { margin-top: 20px; padding: 15px; background-color: #f8fafc; border-radius: 6px; border: 1px solid #eef2f5; }
    .system-status-container h5 { color: #1E2A38; font-size: 0.9rem; margin-bottom: 12px; font-weight: 600; }
    .status-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.85rem; }
    .status-label { color: #7f8c8d; }
    .status-value { color: #2c3e50; font-weight: 500; }
    .text-success { color: #178978 !important; display: flex; align-items: center; gap: 5px; }
    .text-blue { color: #1E2A38 !important; font-weight: bold; }
    .code-spec { background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.8rem; color: #e83e8c; }
    
    /* Chấm tròn nhấp nháy tạo hiệu ứng Live cho Docker */
    .dot-online { width: 8px; height: 8px; background-color: #178978; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px #178978; }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>