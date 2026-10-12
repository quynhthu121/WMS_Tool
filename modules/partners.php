<?php
/**
 * 👥 MÔ-ĐUN QUẢN LÝ ĐỐI TÁC (KHACH HÀNG / NHÀ CUNG CẤP)
 */
$pdo = getPDOLayerConnection();
$errors = []; $messages = [];
$edit_mode = false; $edit_data = null;

try {
    // TẦNG KIỂM TRA BẢO VỆ
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'partners'")->fetch();
    if (!$tableCheck) throw new Exception("Bảng 'partners' chưa có. Hãy chạy file SQL cấu trúc trước.");

    // [Chức năng 3] XỬ LÝ HÀNH ĐỘNG XÓA
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['code'])) {
        $stmt = $pdo->prepare("DELETE FROM partners WHERE code = ?");
        $stmt->execute([$_GET['code']]);
        $messages[] = "Đã xóa đối tác có mã [{$_GET['code']}] thành công.";
    }

    // LẤY DỮ LIỆU ĐỂ SỬA
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['code'])) {
        $stmt = $pdo->prepare("SELECT * FROM partners WHERE code = ?");
        $stmt->execute([$_GET['code']]);
        $edit_data = $stmt->fetch();
        if ($edit_data) $edit_mode = true;
    }

    // [Chức năng 1 & 2] XỬ LÝ THÊM MỚI HOẶC CẬP NHẬT
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_partner'])) {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'customer';
        $phone = trim($_POST['phone'] ?? '');

        if (empty($code) || empty($name)) {
            $errors[] = "Mã đối tác và Tên không được để trống.";
        } else {
            if (isset($_POST['is_edit']) && $_POST['is_edit'] == '1') {
                $stmt = $pdo->prepare("UPDATE partners SET name=?, type=?, phone=? WHERE code=?");
                $stmt->execute([$name, $type, $phone, $code]);
                $messages[] = "Đã cập nhật thông tin đối tác [{$code}].";
                $edit_mode = false; // Thoát chế độ sửa
            } else {
                $stmtCheck = $pdo->prepare("SELECT code FROM partners WHERE code = ?");
                $stmtCheck->execute([$code]);
                if ($stmtCheck->fetch()) {
                    $errors[] = "Mã Đối tác [{$code}] này đã tồn tại.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO partners (code, name, type, phone) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$code, $name, $type, $phone]);
                    $messages[] = "Đã thêm đối tác mới: [{$code}] - {$name}.";
                }
            }
        }
    }

    // LẤY DANH SÁCH
    $partners = $tableCheck ? $pdo->query("SELECT * FROM partners ORDER BY id DESC")->fetchAll() : [];

} catch (Exception $e) {
    $errors[] = "Lỗi nghiệp vụ Đối tác: " . $e->getMessage();
}
?>

<div class="container-fluid" style="padding: 10px;">
    <h2 style="color: #1e3d59; margin-bottom: 20px; font-weight: 700;">Danh Bạ Đối Tác (CRM)</h2>

    <?php foreach($errors as $error): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px; border-left: 5px solid #f5c6cb;">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>
    <?php foreach($messages as $msg): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px; border-left: 5px solid #c3e6cb;">✅ <?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>

    <!-- KHỐI FORM NHẬP LIỆU -->
    <div style="background: #ffffff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 30px;">
        <div style="font-weight: bold; font-size: 1.1rem; color: #1e3d59; border-bottom: 2px solid #eef2f5; padding-bottom: 10px; margin-bottom: 20px;">
            <?= $edit_mode ? '🛠️ Hiệu Chỉnh Đối Tác' : '➕ Thêm Mới Khách Hàng / Nhà Cung Cấp' ?>
        </div>
        
        <form method="POST" action="index.php?page=partners">
            <?php if($edit_mode): ?>
                <input type="hidden" name="is_edit" value="1">
                <input type="hidden" name="code" value="<?= htmlspecialchars($edit_data['code']) ?>">
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div>
                    <label style="display:block; margin-bottom: 6px; font-weight: 600;">Mã định danh (Code)</label>
                    <input type="text" name="code" value="<?= $edit_mode ? htmlspecialchars($edit_data['code']) : '' ?>" <?= $edit_mode ? 'disabled' : '' ?> style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; background: <?= $edit_mode ? '#f4f6f9' : '#fff' ?>;" required>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 6px; font-weight: 600;">Tên đối tác / Công ty</label>
                    <input type="text" name="name" value="<?= $edit_mode ? htmlspecialchars($edit_data['name']) : '' ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;" required>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 6px; font-weight: 600;">Phân loại</label>
                    <select name="type" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="customer" <?= ($edit_mode && $edit_data['type']=='customer') ? 'selected' : '' ?>>Khách Hàng (Đầu ra)</option>
                        <option value="vendor" <?= ($edit_mode && $edit_data['type']=='vendor') ? 'selected' : '' ?>>Nhà Cung Cấp (Đầu vào)</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 6px; font-weight: 600;">Số điện thoại</label>
                    <input type="text" name="phone" value="<?= $edit_mode ? htmlspecialchars($edit_data['phone']) : '' ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <button type="submit" name="save_partner" style="background-color: #17b978; color: white; border: none; padding: 10px 20px; font-weight: bold; border-radius: 4px; cursor: pointer;">
                    <?= $edit_mode ? 'Lưu Cập Nhật' : 'Lưu Đối Tác Mới' ?>
                </button>
                <?php if($edit_mode): ?>
                    <a href="index.php?page=partners" style="margin-left: 10px; padding: 10px 20px; background: #7f8c8d; color: white; text-decoration: none; border-radius: 4px;">Hủy bỏ</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- KHỐI THANH CÔNG CỤ JAVASCRIPT -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h4 style="color: #1e3d59; font-weight: 600;">Danh Sách Đối Tác Hiện Hữu</h4>
        <div style="display: flex; gap: 10px;">
            <!-- [Chức năng 4] TÌM KIẾM NHANH -->
            <input type="text" id="searchInput" placeholder="🔍 Tìm tên hoặc mã..." style="padding: 8px 15px; border: 1px solid #ccc; border-radius: 4px; outline: none; width: 250px;">
            <!-- [Chức năng 5] XUẤT CSV -->
            <button onclick="exportTableToCSV('danh_sach_doi_tac.csv')" style="background: #1e3d59; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">📥 Xuất Excel (CSV)</button>
        </div>
    </div>

    <!-- BẢNG DỮ LIỆU -->
    <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.03);" id="partnerTable">
        <thead>
            <tr style="background-color: #eef2f5; text-align: left; color: #1e3d59;">
                <th style="padding: 15px;">Mã Code</th>
                <th style="padding: 15px;">Tên Đối Tác</th>
                <th style="padding: 15px;">Phân Loại</th>
                <th style="padding: 15px;">Liên Hệ</th>
                <th style="padding: 15px;" class="no-export">Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($partners)): ?>
                <tr><td colspan="5" style="padding: 20px; text-align: center;">Chưa có dữ liệu.</td></tr>
            <?php else: ?>
                <?php foreach($partners as $pt): ?>
                <tr style="border-bottom: 1px solid #eef2f5;">
                    <td style="padding: 15px;"><strong><?= htmlspecialchars($pt['code']) ?></strong></td>
                    <td style="padding: 15px; font-weight: 500;"><?= htmlspecialchars($pt['name']) ?></td>
                    <td style="padding: 15px;">
                        <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: bold; background-color: <?= $pt['type'] == 'customer' ? '#e3fcef' : '#fff0f0' ?>; color: <?= $pt['type'] == 'customer' ? '#155724' : '#721c24' ?>;">
                            <?= $pt['type'] == 'customer' ? 'Khách hàng' : 'Nhà cung cấp' ?>
                        </span>
                    </td>
                    <td style="padding: 15px;"><?= htmlspecialchars($pt['phone']) ?></td>
                    <td style="padding: 15px;" class="no-export">
                        <a href="index.php?page=partners&action=edit&code=<?= $pt['code'] ?>" style="background-color: #3498db; color: white; padding: 6px 12px; font-size: 0.85rem; border-radius: 4px; text-decoration: none; margin-right: 5px;">Sửa</a>
                        <a href="index.php?page=partners&action=delete&code=<?= $pt['code'] ?>" onclick="return confirm('Bạn chắc chắn xóa?');" style="background-color: #e74c3c; color: white; padding: 6px 12px; font-size: 0.85rem; border-radius: 4px; text-decoration: none;">Xóa</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- JAVASCRIPT XỬ LÝ -->
<script>
// Logic Tìm kiếm Live
document.getElementById("searchInput").addEventListener("keyup", function() {
    let filter = this.value.toLowerCase();
    let rows = document.getElementById("partnerTable").getElementsByTagName("tr");
    for (let i = 1; i < rows.length; i++) {
        rows[i].style.display = rows[i].textContent.toLowerCase().includes(filter) ? "" : "none";
    }
});

// Logic Xuất CSV
function exportTableToCSV(filename) {
    let csv = [];
    let rows = document.querySelectorAll("table#partnerTable tr");
    for (let i = 0; i < rows.length; i++) {
        if(rows[i].style.display === "none") continue; // Không xuất dòng bị ẩn
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length; j++) {
            if(!cols[j].classList.contains("no-export")){
                row.push('"' + cols[j].innerText.trim().replace(/"/g, '""') + '"');
            }
        }
        csv.push(row.join(","));
    }
    let csvFile = new Blob(["\uFEFF"+csv.join("\n")], {type: "text/csv;charset=utf-8;"});
    let downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}
</script>