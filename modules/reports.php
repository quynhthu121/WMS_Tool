<?php
// Kiểm tra bảo mật: Không cho phép truy cập trực tiếp file này từ URL bên ngoài
if (!isset($_SESSION['user_name'])) {
    exit("Truy cập bị từ chối!");
}
// 🛑 ĐOẠN XỬ LÝ LOGIC ÉP TRÌNH DUYỆT TẢI FILE EXCEL (.CSV) KHI NGƯỜI DÙNG BẤM NÚT
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    // 1. Cấu hình Header để thông báo với trình duyệt đây là một tệp tin tải về thay vì trang web HTML
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=BaoCao_TonKho_' . date('Ymd_His') . '.csv');
    
    // 2. XUẤT CHUỖI UTF-8 BOM (Bắt buộc phải có để Microsoft Excel mở file không bị lỗi font tiếng Việt)
    echo "\xEF\xBB\xBF";
    
    // Mở luồng ghi dữ liệu trực tiếp ra file tải về
    $output = fopen('php://output', 'w');
    
    // 3. Ghi các dòng tiêu đề giới thiệu trên cùng của file báo cáo
    fputcsv($output, ['BÁO CÁO THỐNG KÊ CHI TIẾT GIÁ TRỊ VỐN TỒN KHO HỆ THỐNG']);
    fputcsv($output, ['Thời gian xuất bản:', date('d/m/Y H:i:s')]);
    fputcsv($output, []); // Tạo một hàng trống để giãn cách dữ liệu
    
    // 4. Định nghĩa hàng tiêu đề của các cột trong bảng Excel
    fputcsv($output, ['STT', 'Mã SKU', 'Tên sản phẩm hàng hóa', 'Số lượng tồn thực tế', 'Giá niêm yết (VNĐ)', 'Tổng giá trị vốn kho']);
    
    // 5. Kết nối database tạm thời (PDO) để quét dữ liệu sản phẩm xuất ra file
    try {
        $export_db = getPDOLayerConnection();
        $stt = 1;
        $grandTotalValue = 0;

        $stmt = $export_db->query("SELECT sku, name, qty, price FROM products ORDER BY id DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $totalStockValue = (float)$row['price'] * (int)$row['qty'];
            $grandTotalValue += $totalStockValue;

            fputcsv($output, [
                $stt++,
                $row['sku'],
                $row['name'],
                number_format((int)$row['qty']) . ' cái',
                number_format((float)$row['price']) . ' VNĐ',
                number_format($totalStockValue) . ' VNĐ'
            ]);
        }

        // Tạo hàng tổng kết tổng giá trị vốn ở cuối cùng của file Excel
        fputcsv($output, []);
        fputcsv($output, ['', '', '', '', 'TỔNG GIÁ TRỊ VỐN KHO TOÀN HỆ THỐNG:', number_format($grandTotalValue) . ' VNĐ']);
    } catch (Exception $e) {
        // Nếu có lỗi kết nối PDO, bỏ qua export (trong ngữ cảnh export CSV)
    }
    
    // Đóng luồng tải file
    fclose($output);
    exit(); // Chặn đứng không cho mã HTML bên dưới chạy tiếp vào file Excel
}

/**
 * 🛠️ TẦNG KẾT NỐI VÀ XỬ LÝ SỐ LIỆU PHÂN TÍCH CHUYÊN SÂU (BUSINESS INTELLIGENCE LAYER)
 * Thực hiện tính toán tài chính, giá trị tồn kho động từ MySQL
 */
$db_connection = null;
$totalInventoryValue = 0; // Tổng giá trị vốn kho (Giá x Số lượng của tất cả sản phẩm)
$highestValueProducts = []; // Top sản phẩm đọng vốn lớn nhất
$outOfStockProducts = [];   // Sản phẩm sắp cháy kho (Số lượng <= 10)

try {
    try {
        $db_connection = getPDOLayerConnection();

        // 📊 1. Tính tổng giá trị toàn bộ kho hàng (Tổng SUM của Price * Qty)
        $value_stmt = $db_connection->query("SELECT SUM(price * qty) as total_val FROM products");
        $value_row = $value_stmt ? $value_stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($value_row) {
            $totalInventoryValue = floatval($value_row['total_val'] ?? 0);
        }

        // 📋 2. Truy vấn danh sách cơ cấu vốn kho (Sắp xếp theo Giá trị tồn giảm dần)
        $highest_stmt = $db_connection->query("SELECT sku, name, price, qty, (price * qty) as total_item_val FROM products ORDER BY total_item_val DESC");
        if ($highest_stmt) {
            $rows = $highest_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $row['price'] = isset($row['price']) ? floatval($row['price']) : 0;
                $row['total_item_val'] = isset($row['total_item_val']) ? floatval($row['total_item_val']) : 0;
                $highestValueProducts[] = $row;

                if (intval($row['qty']) <= 10) {
                    $outOfStockProducts[] = $row;
                }
            }
        }
    } catch (Exception $e) {
        // bảo vệ luồng giao diện
    }
} catch (Exception $e) {
    // Đóng băng ngoại lệ bảo vệ luồng giao diện
}
?>

<div class="reports-container">
    <div class="reports-title">
        <h2>📊 Trung tâm Báo cáo & Phân tích Kinh doanh</h2>
        <p>Hệ thống tự động hóa tính toán giá trị dòng vốn tài sản và phân tích rủi ro lưu kho theo thời gian thực.</p>
    </div>

    <div class="report-summary-card">
        <div class="summary-icon">💵</div>
        <div class="summary-details">
            <p>TỔNG GIÁ TRỊ VỐN LƯU KHO ĐANG QUẢN LÝ</p>
            <h3><?php echo number_format($totalInventoryValue); ?> <span style="font-size: 1.2rem;">VNĐ</span></h3>
        </div>
    </div>

    <div class="reports-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 25px; align-items: start;">
        
        <div class="report-box">
            <h4>📈 Bảng Phân Tích Cơ Cấu Vốn Hàng Hóa</h4>
            <div style="margin: 10px 0 20px 0;">
                <a href="index.php?page=reports&action=export_csv" 
                    style="background-color: #27ae60; color: white; padding: 10px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(39,174,96,0.15); transition: 0.2s; font-family: 'Roboto', sans-serif;">
                    <i class="fas fa-file-excel"></i> 📥 Xuất báo cáo trực quan ra file Excel (.CSV)
                </a>
            </div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mã SKU</th>
                        <th>Tên sản phẩm</th>
                        <th>Đơn giá</th>
                        <th>Tồn kho</th>
                        <th>Giá trị vốn kho</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($highestValueProducts)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #95a5a6; padding: 30px 10px;">
                                Hệ thống trống. Chưa có dữ liệu sản phẩm để phân tích vốn kho.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($highestValueProducts as $prod): ?>
                            <tr>
                                <td><code class="report-sku"><?php echo htmlspecialchars($prod['sku']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($prod['name']); ?></strong></td>
                                <td><?php echo number_format($prod['price']); ?> đ</td>
                                <td><?php echo number_format($prod['qty']); ?></td>
                                <td style="color: #1e3d59; font-weight: bold;"><?php echo number_format($prod['total_item_val']); ?> đ</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="report-box alert-box">
            <h4>⚠️ Cảnh Báo Rủi Ro Hết Hàng (Qty ≤ 10)</h4>
            <div class="alert-list" style="display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                <?php if (empty($outOfStockProducts)): ?>
                    <div style="text-align: center; color: #17b978; padding: 20px; background: #e3fcef; border-radius: 6px; font-size: 0.85rem; font-weight: 500;">
                        ✅ Trạng thái lý tưởng: Không có sản phẩm nào sắp hết hàng!
                    </div>
                <?php else: ?>
                    <?php foreach ($outOfStockProducts as $alert_item): ?>
                        <div class="alert-card" style="background: #fff0f0; border-left: 4px solid #ff6b6b; padding: 12px; border-radius: 4px;">
                            <span style="font-size: 0.8rem; color: #721c24; font-weight: bold;">SKU: <?php echo htmlspecialchars($alert_item['sku']); ?></span>
                            <h5 style="margin: 4px 0; color: #2c3e50; font-size: 0.85rem;"><?php echo htmlspecialchars($alert_item['name']); ?></h5>
                            <p style="margin: 0; font-size: 0.8rem; color: #721c24;">
                                Nguy cơ cháy kho! Hiện chỉ còn: <strong><?php echo $alert_item['qty']; ?></strong> sản phẩm.
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<style>
    .reports-container { animation: fadeIn 0.4s ease-in-out; }
    .reports-title h2 { color: #1e3d59; font-size: 1.6rem; }
    .reports-title p { color: #7f8c8d; font-size: 0.9rem; margin-top: 5px; }

    /* Thẻ tổng vốn kho */
    .report-summary-card { background: linear-gradient(135deg, #1e3d59 0%, #17b978 100%); color: white; padding: 25px; border-radius: 8px; margin-top: 20px; display: flex; align-items: center; gap: 20px; box-shadow: 0 4px 15px rgba(23, 185, 120, 0.2); }
    .summary-icon { font-size: 3rem; opacity: 0.9; }
    .summary-details p { font-size: 0.8rem; letter-spacing: 1px; font-weight: 500; margin: 0; opacity: 0.8; }
    .summary-details h3 { font-size: 2.2rem; margin: 5px 0 0 0; font-weight: bold; }

    /* Box nội dung */
    .report-box { background: #ffffff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .report-box h4 { color: #1e3d59; font-size: 1.05rem; border-bottom: 2px solid #f4f6f9; padding-bottom: 10px; margin: 0; font-weight: 600; }

    /* Định dạng bảng */
    .report-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; margin-top: 15px; }
    .report-table th, .report-table td { padding: 12px 10px; border-bottom: 1px solid #f4f6f9; }
    .report-table th { color: #7f8c8d; font-weight: 600; }
    .report-table td { color: #2c3e50; }
    .report-sku { background: #eef2f5; padding: 2px 6px; border-radius: 4px; font-family: monospace; color: #e83e8c; font-weight: bold; }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>