<?php
/**
 * 📋 MÔ-ĐUN KIỂM KÊ VÀ ĐIỀU CHỈNH KHO (INVENTORY AUDIT)
 */
$pdo = getPDOLayerConnection();
$errors = []; $messages = [];

try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'inventory_audit'")->fetch();
    if (!$tableCheck) throw new Exception("Bảng 'inventory_audit' chưa có. Vui lòng nạp SQL cấu trúc.");

    // [Chức năng 1 & 2] XỬ LÝ ĐIỀU CHỈNH KHO & LƯU LÝ DO
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_audit'])) {
        $sku = $_POST['sku'] ?? '';
        $counted_qty = intval($_POST['counted_qty'] ?? -1);
        $note = trim($_POST['note'] ?? '');

        if (empty($sku) || $counted_qty < 0) {
            $errors[] = "Vui lòng chọn sản phẩm và nhập số lượng thực tế hợp lệ.";
        } else {
            try {
                $pdo->beginTransaction(); 
                
                $stmtProd = $pdo->prepare("SELECT qty FROM products WHERE sku = ?");
                $stmtProd->execute([$sku]);
                $product = $stmtProd->fetch();

                if (!$product) throw new Exception("Sản phẩm không tồn tại.");

                $system_qty = (int)$product['qty'];
                $difference = $counted_qty - $system_qty;

                if ($difference === 0) throw new Exception("Thực tế khớp với phần mềm. Không cần điều chỉnh.");

                // Cập nhật số lượng kho
                $pdo->prepare("UPDATE products SET qty = ? WHERE sku = ?")->execute([$counted_qty, $sku]);

                // Lưu lịch sử kèm lý do
                $audit_code = 'AUDIT/' . date('YmdHis');
                $pdo->prepare("INSERT INTO inventory_audit (audit_code, product_sku, system_qty, counted_qty, difference, note) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$audit_code, $sku, $system_qty, $counted_qty, $difference, $note]);

                $pdo->commit();
                $diff_text = $difference > 0 ? "+$difference" : $difference;
                $messages[] = "Cân bằng kho thành công. Độ lệch: {$diff_text} sản phẩm.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = "Lỗi kiểm kê: " . $e->getMessage();
            }
        }
    }

    $all_products = $pdo->query("SELECT sku, name, qty FROM products")->fetchAll();
    $audits = $tableCheck ? $pdo->query("SELECT * FROM inventory_audit ORDER BY id DESC")->fetchAll() : [];

} catch (Exception $e) {
    $errors[] = "Hệ thống: " . $e->getMessage();
}
?>

<!-- CSS Phục vụ chức năng IN BÁO CÁO -->
<style>
@media print {
    .no-print { display: none !important; }
    body, .main-content, .main-body { background: white !important; padding: 0 !important; margin: 0 !important; }
    table { width: 100%; border-collapse: collapse; border: 1px solid #000; }
    th, td { border: 1px solid #000 !important; padding: 8px !important; color: black !important; }
    h2, h4 { color: black !important; }
}
</style>

<div class="container-fluid" style="padding: 10px;">
    
    <!-- HEADER VÀ NÚT IN -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="color: #1e3d59; font-weight: 700;">Kiểm Kê & Cân Bằng Kho</h2>
        <!-- [Chức năng 5] NÚT IN BÁO CÁO -->
        <button onclick="window.print()" class="no-print" style="background: #1e3d59; color: white; border: none; padding: 10px 20px; font-weight: bold; border-radius: 4px; cursor: pointer;">🖨️ In Báo Cáo Kho</button>
    </div>
    
    <div class="no-print">
        <?php foreach($errors as $error): echo "<div style='background:#f8d7da; color:#721c24; padding:12px; margin-bottom:15px; border-radius:6px;'>⚠️ $error</div>"; endforeach; ?>
        <?php foreach($messages as $msg): echo "<div style='background:#d4edda; color:#155724; padding:12px; margin-bottom:15px; border-radius:6px;'>✅ $msg</div>"; endforeach; ?>
    </div>

    <!-- KHỐI FORM NHẬP LIỆU -->
    <div class="no-print" style="background: #ffffff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 30px;">
        <div style="font-weight: bold; font-size: 1.1rem; color: #1e3d59; border-bottom: 2px solid #eef2f5; padding-bottom: 10px; margin-bottom: 20px;">Tạo Phiếu Kiểm Kê Thực Tế</div>
        
        <form method="POST" action="">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div>
                    <label style="display:block; margin-bottom: 8px; font-weight: 600;">Sản phẩm kiểm kê</label>
                    <select name="sku" id="sku_select" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;" required>
                        <option value="" data-qty="0">-- Chọn sản phẩm đếm được --</option>
                        <?php foreach($all_products as $prod): ?>
                            <option value="<?= $prod['sku'] ?>" data-qty="<?= $prod['qty'] ?>">Tồn máy: [<?= $prod['qty'] ?>] - <?= htmlspecialchars($prod['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 8px; font-weight: 600;">Số lượng đếm tay</label>
                    <input type="number" name="counted_qty" id="counted_qty" min="0" placeholder="Số lượng thực tế ở ngoài kho..." style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;" required>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 8px; font-weight: 600;">Lý do sai lệch (Nếu có)</label>
                    <input type="text" name="note" placeholder="VD: Hàng bị vỡ do vận chuyển..." style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <!-- [Chức năng 3] TÍNH TOÁN ĐỘ LỆCH LIVE -->
            <div id="live_calculation" style="margin-top: 15px; font-size: 0.95rem; display: none; background: #eef2f5; padding: 10px; border-radius: 4px;">
                Tồn trên máy: <strong id="sys_qty_text">0</strong> | 
                Đếm thực tế: <strong id="count_qty_text">0</strong> | 
                Dự kiến lệch: <span id="diff_text" style="font-weight:bold; padding: 2px 6px; border-radius: 4px;">0</span>
            </div>

            <button type="submit" name="submit_audit" style="margin-top: 20px; background-color: #ff9f43; color: white; border: none; padding: 12px 24px; font-weight: bold; border-radius: 4px; cursor: pointer;">Cập Nhật Kho Ngay</button>
        </form>
    </div>

    <!-- KHU VỰC CÔNG CỤ JAVASCRIPT -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;" class="no-print">
        <h4 style="color: #1e3d59; font-weight: 600;">Lịch Sử Kiểm Kê Gần Nhất</h4>
        
        <!-- [Chức năng 4] BỘ LỌC DỮ LIỆU -->
        <div style="display: flex; gap: 8px;">
            <button onclick="filterTable('all')" style="padding: 6px 12px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; background: #fff;">Tất cả</button>
            <button onclick="filterTable('missing')" style="padding: 6px 12px; border: 1px solid #f8d7da; border-radius: 4px; cursor: pointer; background: #f8d7da; color: #721c24; font-weight: bold;">Chỉ hiện Mất Hàng (-)</button>
            <button onclick="filterTable('surplus')" style="padding: 6px 12px; border: 1px solid #d4edda; border-radius: 4px; cursor: pointer; background: #d4edda; color: #155724; font-weight: bold;">Chỉ hiện Dư Hàng (+)</button>
        </div>
    </div>

    <!-- BẢNG LỊCH SỬ -->
    <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.03);" id="auditTable">
        <thead>
            <tr style="background-color: #eef2f5; text-align: left; color: #1e3d59;">
                <th style="padding: 15px;">Mã Phiếu & Thời gian</th>
                <th style="padding: 15px;">Mã Sản Phẩm</th>
                <th style="padding: 15px;">Máy Tính</th>
                <th style="padding: 15px;">Thực Tế</th>
                <th style="padding: 15px;">Độ Lệch</th>
                <th style="padding: 15px;">Giải Trình / Ghi Chú</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($audits)): ?>
                <tr><td colspan="6" style="padding: 20px; text-align: center;">Chưa có lịch sử kiểm kê nào.</td></tr>
            <?php else: ?>
                <?php foreach($audits as $ad): ?>
                <tr style="border-bottom: 1px solid #eef2f5;" data-diff="<?= $ad['difference'] ?>">
                    <td style="padding: 15px;">
                        <strong><?= $ad['audit_code'] ?></strong><br>
                        <small style="color: #7f8c8d;"><?= date('d/m/Y H:i', strtotime($ad['audit_date'])) ?></small>
                    </td>
                    <td style="padding: 15px; font-weight: 500;"><?= htmlspecialchars($ad['product_sku']) ?></td>
                    <td style="padding: 15px; color: #7f8c8d;"><?= $ad['system_qty'] ?></td>
                    <td style="padding: 15px; font-weight: bold;"><?= $ad['counted_qty'] ?></td>
                    <td style="padding: 15px;">
                        <?php 
                        $diff = $ad['difference'];
                        $bg = $diff > 0 ? '#d4edda' : '#f8d7da';
                        $color = $diff > 0 ? '#155724' : '#721c24';
                        ?>
                        <span style="background-color: <?= $bg ?>; color: <?= $color ?>; padding: 4px 8px; border-radius: 4px; font-weight: bold;">
                            <?= ($diff > 0 ? '+' : '') . $diff ?>
                        </span>
                    </td>
                    <td style="padding: 15px; font-size: 0.85rem; color: #7f8c8d;">
                        <?= htmlspecialchars($ad['note'] ?? 'Không có') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// Logic: TÍNH ĐỘ LỆCH LIVE (Khi gõ phím)
const skuSelect = document.getElementById('sku_select');
const countedInput = document.getElementById('counted_qty');

function calculateLiveDiff() {
    let sysQty = parseInt(skuSelect.options[skuSelect.selectedIndex].getAttribute('data-qty')) || 0;
    let countQty = parseInt(countedInput.value);
    
    if(skuSelect.value !== "" && !isNaN(countQty)) {
        document.getElementById('live_calculation').style.display = 'block';
        document.getElementById('sys_qty_text').innerText = sysQty;
        document.getElementById('count_qty_text').innerText = countQty;
        
        let diff = countQty - sysQty;
        let dText = document.getElementById('diff_text');
        dText.innerText = (diff > 0 ? "+" : "") + diff;
        dText.style.color = diff > 0 ? '#155724' : (diff < 0 ? '#721c24' : '#333');
        dText.style.backgroundColor = diff > 0 ? '#d4edda' : (diff < 0 ? '#f8d7da' : '#fff');
    } else {
        document.getElementById('live_calculation').style.display = 'none';
    }
}
skuSelect.addEventListener('change', calculateLiveDiff);
countedInput.addEventListener('keyup', calculateLiveDiff);
countedInput.addEventListener('change', calculateLiveDiff);

// Logic: BỘ LỌC BẢNG THEO TRẠNG THÁI
function filterTable(type) {
    let rows = document.querySelectorAll("table#auditTable tbody tr");
    rows.forEach(row => {
        let diffAttr = row.getAttribute('data-diff');
        if(!diffAttr) return; // Bỏ qua dòng trống
        let diff = parseInt(diffAttr);
        
        if (type === 'all') row.style.display = "";
        else if (type === 'missing' && diff < 0) row.style.display = "";
        else if (type === 'surplus' && diff > 0) row.style.display = "";
        else row.style.display = "none";
    });
}
</script>