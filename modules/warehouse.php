<?php
/**
 * 🚚 MÔ-ĐUN ĐIỀU PHỐI KHO VẬT CHẤT (ODOO STOCK ENGINE MODEL)
 * Đạt chuẩn xử lý Transaction song song, bảo trì tuyệt đối.
 */
$pdo = getPDOLayerConnection();
$errors = [];
$messages = [];

// Khởi tạo các biến danh sách để tránh lỗi hiển thị tầng giao diện
$all_products = [];
$pickings = [];

try {
    // TẦNG KIỂM TRA BẢO VỆ (SHIELD LAYER): Xác minh bảng có tồn tại thực tế trong DB không
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'products'")->fetch();
    if (!$tableCheck) {
        throw new Exception("Hạ tầng bảng 'products' chưa được khởi tạo. Vui lòng nạp tệp SQL cấu trúc vào Database.");
    }

    // XỬ LÝ LỆNH TẠO PHIẾU ĐIỀU CHUYỂN (Thực thi khi nhấn Validate)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_picking'])) {
        $type = $_POST['type'] ?? 'in';
        $origin = trim($_POST['origin'] ?? '');
        $origin_partner = trim($_POST['origin_partner'] ?? '');
        if (!empty($origin_partner)) {
            $origin = $origin_partner;
        }
        $sku = $_POST['sku'] ?? '';
        $qty = intval($_POST['qty'] ?? 0);

        if (empty($sku) || $qty <= 0) {
            $errors[] = "Dữ liệu sản phẩm hoặc số lượng dịch chuyển không hợp lệ.";
        } else {
            try {
                // Khởi động Transaction để bảo vệ tính toàn vẹn dữ liệu song song (ACID)
                $pdo->beginTransaction();

                // 1. Kiểm tra sản phẩm có tồn tại thực tế không
                $stmtProd = $pdo->prepare("SELECT qty FROM products WHERE sku = ?");
                $stmtProd->execute([$sku]);
                $product = $stmtProd->fetch();

                if (!$product) {
                    throw new Exception("Sản phẩm có mã SKU [{$sku}] này không tồn tại.");
                }

                // Nếu là xuất kho, kiểm tra xem lượng tồn thực tế có đủ không
                if ($type === 'out' && $product['qty'] < $qty) {
                    throw new Exception("Số lượng tồn kho không đủ để xuất! Hiện có: " . $product['qty']);
                }

                // 2. Tạo số phiếu tự động dạng chuỗi thời gian tuyến tính
                $prefix = ($type === 'in') ? 'WH/IN/' : 'WH/OUT/';
                $picking_number = $prefix . time();

                $stmtPick = $pdo->prepare("INSERT INTO stock_picking (picking_number, origin, type, state) VALUES (?, ?, ?, 'done')");
                $stmtPick->execute([$picking_number, $origin, $type]);
                $picking_id = $pdo->lastInsertId();

                // 3. Tạo dòng dịch chuyển chi tiết (Stock Move Line)
                $stmtMove = $pdo->prepare("INSERT INTO stock_move (picking_id, product_sku, product_qty) VALUES (?, ?, ?)");
                $stmtMove->execute([$picking_id, $sku, $qty]);

                // 4. Cập nhật trực tiếp số lượng tồn kho tổng ở bảng sản phẩm
                if ($type === 'in') {
                    $stmtUpdateStock = $pdo->prepare("UPDATE products SET qty = qty + ? WHERE sku = ?");
                } else {
                    $stmtUpdateStock = $pdo->prepare("UPDATE products SET qty = qty - ? WHERE sku = ?");
                }
                $stmtUpdateStock->execute([$qty, $sku]);

                // Cam kết dữ liệu an toàn vào DB
                $pdo->commit();
                $messages[] = "Đã xác nhận thành công phiếu hoạt động kho {$picking_number}!";

            } catch (Exception $e) {
                // Hủy bỏ mọi tác vụ dở dang nếu xuất hiện lỗi bất ngờ, đưa DB về trạng thái nguyên bản
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "Giao dịch kho thất bại: " . $e->getMessage();
            }
        }
    }

    // LẤY DANH SÁCH SẢN PHẨM PHỤC VỤ CHỌN LỰA TRÊN FORM
    $all_products = $pdo->query("SELECT sku, name FROM products")->fetchAll();

    // LẤY DANH SÁCH ĐỐI TÁC ĐỂ SỬ DỤNG CHO TRƯỜNG ORIGIN
    $partner_options = [];
    try {
        $partner_options = $pdo->query("SELECT code, name, type FROM partners ORDER BY type, name")->fetchAll();
    } catch (Exception $e) {
        $partner_options = [];
    }

    // LẤY TOÀN BỘ DANH SÁCH LỊCH SỬ PHIẾU ĐIỀU CHUYỂN
    $pickings = $pdo->query("
        SELECT sp.*, sm.product_sku, sm.product_qty 
        FROM stock_picking sp 
        JOIN stock_move sm ON sp.id = sm.picking_id 
        ORDER BY sp.scheduled_date DESC
    ")->fetchAll();

} catch (Exception $e) {
    $errors[] = "Lỗi hệ thống cơ sở dữ liệu: " . $e->getMessage();
}
?>

<div class="container-fluid" style="padding: 10px;">
    <h2 style="color: #1e3d59; margin-bottom: 20px; font-weight: 700;">Điều Chuyển Kho Thực Tế (Odoo Engine Model)</h2>
    
    <?php foreach($errors as $error): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 5px solid #f5c6cb;">
            <strong>⚠️ Lỗi cấu trúc:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php endforeach; ?>
    
    <?php foreach($messages as $msg): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 5px solid #c3e6cb;">
            <strong>✅ Thành công:</strong> <?= htmlspecialchars($msg) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($tableCheck): ?>
    <div class="card mb-4" style="background: #ffffff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 30px;">
        <div style="font-weight: bold; font-size: 1.1rem; color: #1e3d59; border-bottom: 2px solid #eef2f5; padding-bottom: 10px; margin-bottom: 20px;">Khởi Tạo Phiếu Điều Chuyển Hàng Hóa</div>
        <form method="POST" action="">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
                <div>
                    <label style="display:block; margin-bottom: 8px; font-size: 0.9rem; font-weight: 600;">Loại hoạt động</label>
                    <select name="type" style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px;">
                        <option value="in">Nhập kho (Receipt - Mua hàng)</option>
                        <option value="out">Xuất kho (Delivery Order - Bán hàng)</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 8px; font-size: 0.9rem; font-weight: 600;">Đối tác / Chứng từ nguồn (Origin)</label>
                    <?php if (!empty($partner_options)): ?>
                        <select name="origin_partner" style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px; margin-bottom: 12px;">
                            <option value="">-- Chọn đối tác nguồn --</option>
                            <?php foreach ($partner_options as $partner): ?>
                                <option value="<?= htmlspecialchars($partner['code']) ?>"><?= htmlspecialchars($partner['code'] . ' - ' . $partner['name'] . ' (' . ucfirst($partner['type']) . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input type="text" name="origin" placeholder="Ví dụ: PO001 hoặc SO002" style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px;" <?php echo empty($partner_options) ? '' : ''; ?>>
                    <div style="margin-top: 8px; color: #7f8c8d; font-size: 0.85rem;">
                        Nếu chọn đối tác thì Origin sẽ lấy mã đối tác, nếu không bạn có thể nhập mã chứng từ nguồn thủ công.
                    </div>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 8px; font-size: 0.9rem; font-weight: 600;">Chọn sản phẩm dịch chuyển</label>
                    <select name="sku" style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px;" required>
                        <option value="">-- Chọn mặt hàng --</option>
                        <?php foreach($all_products as $prod): ?>
                            <option value="<?= $prod['sku'] ?>"><?= htmlspecialchars($prod['name']) ?> (<?= $prod['sku'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 8px; font-size: 0.9rem; font-weight: 600;">Số lượng luân chuyển</label>
                    <input type="number" name="qty" min="1" value="1" style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px;" required>
                </div>
            </div>
            <button type="submit" name="create_picking" style="margin-top: 20px; background-color: #17b978; color: white; border: none; padding: 12px 24px; font-weight: bold; border-radius: 4px; cursor: pointer; transition: background 0.2s;">Xác Nhận Lệnh Kho (Validate)</button>
        </form>
    </div>
    <?php endif; ?>

    <h4 style="color: #1e3d59; margin-bottom: 15px; font-weight: 600;">Nhật Ký Luân Chuyển Vật Chất Thực Tế</h4>
    <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
        <thead>
            <tr style="background-color: #eef2f5; text-align: left; color: #1e3d59; font-size: 0.9rem;">
                <th style="padding: 15px;">Mã phiếu hoạt động</th>
                <th style="padding: 15px;">Tài liệu gốc</th>
                <th style="padding: 15px;">Loại dịch chuyển</th>
                <th style="padding: 15px;">Sản phẩm SKU</th>
                <th style="padding: 15px;">Số lượng</th>
                <th style="padding: 15px;">Thời gian ghi nhận</th>
                <th style="padding: 15px;">Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($pickings)): ?>
                <tr><td colspan="7" style="padding: 20px; text-align: center; color: #7f8c8d;">Chưa phát sinh bất kỳ hoạt động luân chuyển kho nào hoặc cơ sở dữ liệu trống.</td></tr>
            <?php else: ?>
                <?php foreach($pickings as $pk): ?>
                <tr style="border-bottom: 1px solid #eef2f5; font-size: 0.95rem;">
                    <td style="padding: 15px;"><strong><?= $pk['picking_number'] ?></strong></td>
                    <td style="padding: 15px;"><?= htmlspecialchars($pk['origin'] ?: 'N/A') ?></td>
                    <td style="padding: 15px;">
                        <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; background-color: <?= $pk['type'] === 'in' ? '#d1ecf1' : '#fff3cd' ?>; color: <?= $pk['type'] === 'in' ? '#0c5460' : '#856404' ?>;">
                            <?= $pk['type'] === 'in' ? 'NHẬP KHO (IN)' : 'XUẤT KHO (OUT)' ?>
                        </span>
                    </td>
                    <td style="padding: 15px;"><?= htmlspecialchars($pk['product_sku']) ?></td>
                    <td style="padding: 15px;"><strong><?= number_format($pk['product_qty']) ?></strong> mục</td>
                    <td style="padding: 15px;"><?= $pk['scheduled_date'] ?></td>
                    <td style="padding: 15px;"><span style="background-color: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold;">Đã hoàn thành (Done)</span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>