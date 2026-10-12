<?php
/**
 * 📦 MÔ-ĐUN QUẢN LÝ DANH MỤC SẢN PHẨM (PRODUCT MASTER DATA)
 * Tích hợp tính năng: Thêm, Sửa, Xóa, hiển thị đồng bộ với Odoo Stock Engine.
 */
$pdo = getPDOLayerConnection();
$errors = [];
$messages = [];

// Khởi tạo biến xử lý form Sửa
$edit_mode = false;
$edit_product = null;

// =========================================================================
// ➕ BẮT ĐẦU: XỬ LÝ IMPORT TỪ FILE EXCEL (.CSV) - ĐÃ SỬA LỖI DÒNG TRỐNG
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_import'])) {
    if (isset($_FILES['file_csv']) && $_FILES['file_csv']['error'] == 0) {
        $filename = $_FILES['file_csv']['tmp_name'];
        $file = fopen($filename, "r");
        fgetcsv($file); // Bỏ qua dòng tiêu đề trong file
        
        $successCount = 0;
        $stmtInsertCSV = $pdo->prepare("INSERT INTO products (sku, name, qty) VALUES (?, ?, ?)");
        
        while (($column = fgetcsv($file, 1000, ",")) !== FALSE) {
            try {
                // Sử dụng ?? '' để bảo vệ code, không bao giờ bị lỗi Undefined Key hoặc mã rỗng nữa
                $sku = trim($column[0] ?? '');
                $name = trim($column[1] ?? '');
                
                // Đọc số lượng tồn (nếu file có 5 cột thì lấy cột index 4, nếu file có 3 cột thì lấy index 2)
                $qty = isset($column[4]) ? intval($column[4]) : (isset($column[2]) ? intval($column[2]) : 0);
                
                // Chỉ nạp vào database nếu dòng đó có SKU và Tên thực sự (bỏ qua dòng trống)
                if ($sku !== '' && $name !== '') {
                    $stmtInsertCSV->execute([$sku, $name, $qty]);
                    $successCount++;
                }
            } catch (Exception $e) {
                // Nếu trùng mã SKU, hệ thống tự bỏ qua và chạy dòng tiếp theo
            }
        }
        fclose($file);
        $messages[] = "Đã Import thành công {$successCount} sản phẩm từ file vào hệ thống kho!";
    } else {
        $errors[] = "Vui lòng chọn file dữ liệu (.csv) hợp lệ!";
    }
}
// =========================================================================
// ➖ KẾT THÚC XỬ LÝ IMPORT
// =========================================================================

try {
    // 🛑 1. XỬ LÝ HÀNH ĐỘNG XÓA (DELETE)
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['sku'])) {
        $delete_sku = $_GET['sku'];
        $stmtDelete = $pdo->prepare("DELETE FROM products WHERE sku = ?");
        $stmtDelete->execute([$delete_sku]);
        $messages[] = "Đã xóa sản phẩm với mã SKU [{$delete_sku}] thành công.";
    }

    // 🛑 2. XỬ LÝ HÀNH ĐỘNG LẤY THÔNG TIN ĐỂ SỬA (GET EDIT DATA)
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['sku'])) {
        $edit_sku = $_GET['sku'];
        $stmtGetEdit = $pdo->prepare("SELECT * FROM products WHERE sku = ?");
        $stmtGetEdit->execute([$edit_sku]);
        $edit_product = $stmtGetEdit->fetch();
        if ($edit_product) {
            $edit_mode = true;
        }
    }

    // 🛑 3. XỬ LÝ FORM SUBMIT (THÊM MỚI HOẶC CẬP NHẬT)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
        $sku = trim($_POST['sku'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $qty = intval($_POST['qty'] ?? 0); // Số lượng tồn ban đầu

        if (empty($sku) || empty($name)) {
            $errors[] = "Mã SKU và Tên sản phẩm không được để trống.";
        } else {
            if (isset($_POST['is_edit_mode']) && $_POST['is_edit_mode'] == '1') {
                try {
                    $stmtUpdate = $pdo->prepare("UPDATE products SET name = ?, description = ?, price = ?, qty = ? WHERE sku = ?");
                    $stmtUpdate->execute([$name, $description, $price, $qty, $sku]);
                    $messages[] = "Đã cập nhật thông tin sản phẩm [{$sku}] thành công.";
                } catch (Exception $ex) {
                    $stmtUpdate = $pdo->prepare("UPDATE products SET name = ?, qty = ? WHERE sku = ?");
                    $stmtUpdate->execute([$name, $qty, $sku]);
                    $messages[] = "Đã cập nhật thông tin sản phẩm [{$sku}] thành công.";
                }
                $edit_mode = false; 
            } else {
                $stmtCheck = $pdo->prepare("SELECT sku FROM products WHERE sku = ?");
                $stmtCheck->execute([$sku]);
                if ($stmtCheck->fetch()) {
                    throw new Exception("Mã SKU [{$sku}] này đã tồn tại trong hệ thống.");
                }

                try {
                    $stmtInsert = $pdo->prepare("INSERT INTO products (sku, name, description, price, qty) VALUES (?, ?, ?, ?, ?)");
                    $stmtInsert->execute([$sku, $name, $description, $price, $qty]);
                    $messages[] = "Đã thêm mới thành công sản phẩm [{$sku}].";
                } catch (Exception $ex) {
                    $stmtInsert = $pdo->prepare("INSERT INTO products (sku, name, qty) VALUES (?, ?, ?)");
                    $stmtInsert->execute([$sku, $name, $qty]);
                    $messages[] = "Đã thêm mới thành công sản phẩm [{$sku}].";
                }
            }
        }
    }

    // � 4. LẤY DANH SÁCH SẢN PHẨM CÓ TÍCH HỢP TÌM KIẾM, LỌC VÀ PHÂN TRANG (READ)

    // Cấu hình phân trang: Mỗi trang hiển thị tối đa 5 sản phẩm
    $limit = 5; 

    // Lấy số trang hiện tại từ thanh URL (?p=1, ?p=2...). Nếu không có thì mặc định là trang 1
    $page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    if ($page < 1) {
        $page = 1;
    }

    // Tính toán vị trí (bản ghi) bắt đầu lấy dữ liệu trong Database
    $offset = ($page - 1) * $limit;

    // Lấy từ khóa Tìm kiếm và Bộ lọc trạng thái kho từ Form gửi lên (nếu có)
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_stock = isset($_GET['filter_stock']) ? trim($_GET['filter_stock']) : 'all';

    // Khởi tạo mảng chứa các điều kiện WHERE và mảng chứa tham số truyền vào câu lệnh SQL
    $whereClauses = [];
    $params = [];

    // Nếu người dùng có nhập từ khóa tìm kiếm
    if ($search !== '') {
        $whereClauses[] = "(name LIKE :search OR sku LIKE :search)";
        $params[':search'] = "%" . $search . "%"; // Tìm kiếm tương đối (chứa từ khóa là được)
    }

    // Nếu người dùng chọn bộ lọc trạng thái số lượng tồn kho
    if ($filter_stock === 'low') {
        $whereClauses[] = "qty <= 10"; // Sắp hết hàng
    } elseif ($filter_stock === 'out') {
        $whereClauses[] = "qty = 0";   // Đã hết hàng
    } elseif ($filter_stock === 'available') {
        $whereClauses[] = "qty > 10";  // Còn hàng dồi dào
    }

    // Gộp các điều kiện lại với nhau bằng chữ "AND" nếu có nhiều hơn 1 điều kiện
    $whereSql = '';
    if (count($whereClauses) > 0) {
        $whereSql = " WHERE " . implode(' AND ', $whereClauses);
    }

    // BƯỚC A: Đếm tổng số lượng sản phẩm thỏa mãn điều kiện để tính tổng số trang
    $countSql = "SELECT COUNT(*) FROM products" . $whereSql;
    $stmtCount = $pdo->prepare($countSql);
    foreach ($params as $key => $val) {
        $stmtCount->bindValue($key, $val);
    }
    $stmtCount->execute();
    $totalRows = $stmtCount->fetchColumn(); // Trả về một con số tổng duy nhất

    // Tính tổng số trang (Dùng hàm ceil để làm tròn lên, ví dụ: 6 sản phẩm/5 = 1.2 -> cần 2 trang)
    $totalPages = max(1, ceil($totalRows / $limit));
    $page = min(max($page, 1), $totalPages);
    $offset = ($page - 1) * $limit;

    // BƯỚC B: Lấy danh sách sản phẩm thực tế của trang hiện tại (Sử dụng LIMIT và OFFSET)
    $sql = "SELECT * FROM products" . $whereSql . " ORDER BY id DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);

    // Ràng buộc (bind) các tham số tìm kiếm
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    // Ràng buộc tham số phân trang dưới dạng số nguyên (bắt buộc dùng PDO::PARAM_INT)
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    // Biến $products này sẽ chứa đúng 5 sản phẩm đã lọc để phần HTML ở dưới tự động vẽ ra bảng
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $errors[] = "Lỗi nghiệp vụ sản phẩm: " . $e->getMessage();
}
?>

<div class="container-fluid" style="padding: 10px;">
    <h2 style="color: #1e3d59; margin-bottom: 20px; font-weight: 700;">Quản Lý Danh Mục Sản Phẩm (Master Data)</h2>

    <?php foreach($errors as $error): ?>
    <div
        style="background-color: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px; border-left: 5px solid #f5c6cb;">
        <strong>⚠️ Lỗi:</strong> <?= htmlspecialchars($error) ?>
    </div>
    <?php endforeach; ?>

    <?php foreach($messages as $msg): ?>
    <div
        style="background-color: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px; border-left: 5px solid #c3e6cb;">
        <strong>✅ Thành công:</strong> <?= htmlspecialchars($msg) ?>
    </div>
    <?php endforeach; ?>

    <div
        style="background: #ffffff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 30px;">
        <div
            style="font-weight: bold; font-size: 1.1rem; color: #1e3d59; border-bottom: 2px solid #eef2f5; padding-bottom: 10px; margin-bottom: 20px;">
            <?= $edit_mode ? "🛠️ Hiệu Chỉnh Sản Phẩm: " . htmlspecialchars($edit_product['sku']) : "➕ Thêm Sản Phẩm Mới Vào Hệ Thống" ?>
        </div>

        <form method="POST" action="index.php?page=products">
            <?php if ($edit_mode): ?>
            <input type="hidden" name="is_edit_mode" value="1">
            <input type="hidden" name="sku" value="<?= htmlspecialchars($edit_product['sku']) ?>">
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div>
                    <label style="display:block; margin-bottom: 6px; font-size: 0.9rem; font-weight: 600;">Mã sản phẩm
                        (SKU)</label>
                    <input type="text" name="sku"
                        value="<?= $edit_mode ? htmlspecialchars($edit_product['sku']) : '' ?>"
                        <?= $edit_mode ? 'disabled' : '' ?> placeholder="Ví dụ: PROD-CPU-I9"
                        style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px; background-color: <?= $edit_mode ? '#eef2f5' : '#ffffff' ?>;"
                        required>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 6px; font-size: 0.9rem; font-weight: 600;">Tên mặt
                        hàng</label>
                    <input type="text" name="name"
                        value="<?= $edit_mode ? htmlspecialchars($edit_product['name']) : '' ?>"
                        placeholder="Nhập tên sản phẩm..."
                        style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px;" required>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 6px; font-size: 0.9rem; font-weight: 600;">Giá bán
                        (VNĐ)</label>
                    <input type="number" name="price"
                        value="<?= $edit_mode ? htmlspecialchars($edit_product['price'] ?? 0) : '0' ?>" min="0"
                        step="0.01" style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px;"
                        required>
                </div>
                <div>
                    <label style="display:block; margin-bottom: 6px; font-size: 0.9rem; font-weight: 600;">Số lượng tồn
                        đầu kỳ</label>
                    <input type="number" name="qty"
                        value="<?= $edit_mode ? htmlspecialchars($edit_product['qty'] ?? 0) : '0' ?>" min="0"
                        style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px;" required>
                </div>
            </div>

            <div style="margin-top: 15px;">
                <label style="display:block; margin-bottom: 6px; font-size: 0.9rem; font-weight: 600;">Mô tả sản
                    phẩm</label>
                <textarea name="description" rows="2" placeholder="Ghi chú thông số kỹ thuật, thuộc tính..."
                    style="width: 100%; padding: 10px; border: 1px solid #cccccc; border-radius: 4px;"><?= $edit_mode ? htmlspecialchars($edit_product['description'] ?? '') : '' ?></textarea>
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button type="submit" name="save_product"
                    style="background-color: #178978; color: white; border: none; padding: 10px 20px; font-weight: bold; border-radius: 4px; cursor: pointer;">
                    <?= $edit_mode ? "Cập Nhật (Save)" : "Lưu Sản Phẩm" ?>
                </button>
                <?php if ($edit_mode): ?>
                <a href="index.php?page=products"
                    style="background-color: #7f8c8d; color: white; text-decoration: none; padding: 10px 20px; font-weight: bold; border-radius: 4px; line-height: 1.5;">Hủy
                    bỏ</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="action-bar"
        style="background: #ffffff; padding: 15px; border-radius: 8px; border: 1px solid #eef2f5; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; box-shadow: 0 2px 5px rgba(0,0,0,0.02);">
        <div style="display: flex; gap: 10px;">
            <button onclick="exportToExcel()"
                style="background-color: #17b978; color: white; padding: 8px 15px; border: none; font-weight: bold; border-radius: 4px; font-size: 0.9rem; cursor: pointer;">
                <i class="fas fa-file-excel"></i> Xuất Excel
            </button>
            <button onclick="exportToPDF()"
                style="background-color: #1e3d59; color: white; padding: 8px 15px; border: none; font-weight: bold; border-radius: 4px; font-size: 0.9rem; cursor: pointer;">
                <i class="fas fa-file-pdf"></i> Xuất PDF
            </button>
        </div>

        <form action="index.php?page=products" method="post" enctype="multipart/form-data"
            style="display: flex; align-items: center; gap: 10px; margin: 0;">
            <span style="font-size: 0.9rem; font-weight: bold; color: #7f8c8d;">Nạp dữ liệu từ File (.csv):</span>
            <input type="file" name="file_csv" accept=".csv" required
                style="font-size: 0.85rem; padding: 5px; border: 1px solid #ccc; border-radius: 4px;">
            <button type="submit" name="btn_import"
                style="background-color: #3498db; color: white; padding: 8px 15px; border: none; font-weight: bold; border-radius: 4px; font-size: 0.85rem; cursor: pointer;">
                ⚡ Nạp (Import)
            </button>
        </form>
    </div>

    <h4 style="color: #1e3d59; margin-bottom: 15px; font-weight: 600;">Danh Sách Mặt Hàng Hiện Hữu</h4>
    <div
        style="background: #ffffff; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #cbd5e1;">
        <form method="GET" action="index.php" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">

            <input type="hidden" name="page" value="products">

            <div style="flex: 1; min-width: 200px;">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                    placeholder="Tìm theo tên sản phẩm hoặc mã SKU..."
                    style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box;">
            </div>

            <div style="width: 180px;">
                <select name="filter_stock"
                    style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; background: white;">
                    <option value="all" <?= $filter_stock === 'all' ? 'selected' : '' ?>>Tất cả trạng thái kho</option>
                    <option value="available" <?= $filter_stock === 'available' ? 'selected' : '' ?>>Còn hàng dồi dào
                        (>10)</option>
                    <option value="low" <?= $filter_stock === 'low' ? 'selected' : '' ?>>Sắp hết hàng (≤10)</option>
                    <option value="out" <?= $filter_stock === 'out' ? 'selected' : '' ?>>Đã cháy kho (0)</option>
                </select>
            </div>

            <div>
                <button type="submit"
                    style="background: #1e3d59; color: white; border: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; cursor: pointer;">
                    Tìm & Lọc
                </button>

                <?php if ($search !== '' || $filter_stock !== 'all'): ?>
                <a href="index.php?page=products"
                    style="margin-left: 10px; color: #ef4444; text-decoration: none; font-size: 0.9rem;">Xóa bộ lọc</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <table
        style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
        <thead>
            <tr style="background-color: #eef2f5; text-align: left; color: #1e3d59; font-size: 0.9rem;">
                <th style="padding: 15px;">ID</th>
                <th style="padding: 15px;">Mã SKU</th>
                <th style="padding: 15px;">Tên sản phẩm</th>
                <th style="padding: 15px;">Mô tả</th>
                <th style="padding: 15px;">Giá niêm yết</th>
                <th style="padding: 15px;">Số lượng trong kho</th>
                <th style="padding: 15px; text-align: center;">Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
            <tr>
                <td colspan="7" style="padding: 20px; text-align: center; color: #7f8c8d;">Không có sản phẩm nào tồn
                    tại. Hãy thêm mới sản phẩm ở form trên.</td>
            </tr>
            <?php else: ?>
            <?php foreach($products as $prod): ?>
            <tr style="border-bottom: 1px solid #eef2f5; font-size: 0.95rem;">
                <td style="padding: 15px; color: #7f8c8d;"><?= $prod['id'] ?></td>
                <td style="padding: 15px;"><strong><?= htmlspecialchars($prod['sku'] ?? '') ?></strong></td>
                <td style="padding: 15px; font-weight: 500; color: #2c3e50;">
                    <?= htmlspecialchars($prod['name'] ?? '') ?></td>
                <td
                    style="padding: 15px; color: #95a5a6; font-size: 0.85rem; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?= htmlspecialchars(($prod['description'] ?? '') ?: 'Chưa có mô tả') ?></td>
                <td style="padding: 15px; color: #e74c3c; font-weight: bold;">
                    <?= number_format(floatval($prod['price'] ?? 0)) ?> VNĐ</td>
                <td style="padding: 15px;">
                    <span
                        style="padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: bold; background-color: <?= ($prod['qty'] ?? 0) > 10 ? '#d4edda' : '#f8d7da' ?>; color: <?= ($prod['qty'] ?? 0) > 10 ? '#155724' : '#721c24' ?>;">
                        <?= number_format(intval($prod['qty'] ?? 0)) ?> cái
                    </span>
                </td>
                <td style="padding: 15px; text-align: center; display: flex; gap: 8px; justify-content: center;">
                    <a href="index.php?page=products&action=edit&sku=<?= $prod['sku'] ?>"
                        style="background-color: #3498db; color: white; padding: 6px 12px; font-size: 0.85rem; font-weight: bold; text-decoration: none; border-radius: 4px; transition: background 0.2s;">Sửa</a>
                    <a href="index.php?page=products&action=delete&sku=<?= $prod['sku'] ?>"
                        onclick="return confirm('Bạn chắc chắn muốn xóa sản phẩm này?');"
                        style="background-color: #e74c3c; color: white; padding: 6px 12px; font-size: 0.85rem; font-weight: bold; text-decoration: none; border-radius: 4px; transition: background 0.2s;">Xóa</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
    <div
        style="display: flex; justify-content: center; align-items: center; margin-top: 20px; gap: 5px; font-family: 'Roboto', sans-serif;">

        <?php if ($page > 1): ?>
        <a href="index.php?page=products&p=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&filter_stock=<?= urlencode($filter_stock) ?>"
            style="padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 4px; text-decoration: none; color: #1e3d59; background: white;">«
            Trước</a>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="index.php?page=products&p=<?= $i ?>&search=<?= urlencode($search) ?>&filter_stock=<?= urlencode($filter_stock) ?>"
            style="padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 4px; text-decoration: none; font-weight: bold;
                  <?= $page === $i ? 'background: #17b978; color: white; border-color: #17b978;' : 'background: white; color: #333;' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
        <a href="index.php?page=products&p=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&filter_stock=<?= urlencode($filter_stock) ?>"
            style="padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 4px; text-decoration: none; color: #1e3d59; background: white;">Sau
            »</a>
        <?php endif; ?>

    </div>

    <div style="text-align: center; margin-top: 8px; color: #64748b; font-size: 0.85rem; font-family: 'Roboto', sans-serif;">
        Hiển thị trang <?= $page ?> / <?= $totalPages ?> (Tổng số kết quả: <?= $totalRows ?> sản phẩm)
    </div>
    <?php endif; ?>
</div>

<script>
function exportToExcel() {
    let excelData = "\xEF\xBB\xBF<table border='1'><tr style='background-color:#1e3d59;color:white;'>";
    excelData +=
        "<th>ID</th><th>Mã SKU</th><th>Tên sản phẩm</th><th>Mô tả</th><th>Giá niêm yết</th><th>Số lượng trong kho</th></tr>";

    let tables = document.querySelectorAll("table");
    let table = tables[tables.length - 1];
    let rows = table.querySelectorAll("tbody tr");

    rows.forEach(row => {
        if (row.cells.length >= 6 && !row.innerText.includes("Không có sản phẩm nào tồn tại")) {
            excelData += "<tr>";
            excelData += "<td>" + row.cells[0].innerText.trim() + "</td>";
            excelData += "<td>" + row.cells[1].innerText.trim() + "</td>";
            excelData += "<td>" + row.cells[2].innerText.trim() + "</td>";
            excelData += "<td>" + row.cells[3].innerText.trim() + "</td>";
            excelData += "<td>" + row.cells[4].innerText.trim() + "</td>";
            excelData += "<td>" + row.cells[5].innerText.trim() + "</td>";
            excelData += "</tr>";
        }
    });
    excelData += "</table>";

    let blob = new Blob([excelData], {
        type: "application/vnd.ms-excel;charset=utf-8;"
    });
    let url = URL.createObjectURL(blob);
    let a = document.createElement("a");
    a.href = url;
    a.download = "Danh_Muc_San_Pham.xls";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function exportToPDF() {
    var style = document.createElement('style');
    style.innerHTML = `
        @media print {
            .sidebar, .main-header, .action-bar, form { display: none !important; }
            body { background: #fff; padding: 0; margin: 0; color: #000; }
            .main-content { margin: 0 !important; width: 100% !important; }
            table { width: 100% !important; border-collapse: collapse; margin-top: 10px; }
            th, td { border: 1px solid #000 !important; padding: 10px !important; font-size: 11pt; }
            th { background-color: #f2f2f2 !important; -webkit-print-color-adjust: exact; }
            th:last-child, td:last-child { display: none !important; }
        }
    `;
    document.head.appendChild(style);
    window.print();
    document.head.removeChild(style);
}
</script>