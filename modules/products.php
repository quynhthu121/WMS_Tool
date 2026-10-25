<?php
/**
 * Danh mục sản phẩm (master data). Tồn kho KHÔNG nhập ở đây: số tồn đọc từ inventory_balances
 * và chỉ thay đổi qua nghiệp vụ (Receiving, Shipping, Adjustment) có Stock Movement.
 */

use App\Service\InventoryQuery;
use App\Support\Csrf;
use App\Support\View;

$locations = (new InventoryQuery($pdo))->locations();
$storage = array_filter($locations, fn ($l) => $l['zone_type'] === 'storage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    View::handle(function () use ($act, $pdo) {
        if ($act === 'save') {
            $sku = strtoupper(trim((string) $_POST['sku']));
            $name = trim((string) $_POST['name']);
            if ($sku === '' || $name === '' || !preg_match('/^[A-Z0-9\-]{2,50}$/', $sku)) {
                throw new \App\Service\BusinessRuleException('Mã SKU (chữ in hoa, số, gạch ngang) và tên sản phẩm là bắt buộc.');
            }
            $loc = (int) ($_POST['putaway_location_id'] ?? 0) ?: null;
            $stmt = $pdo->prepare('INSERT INTO products (sku, name, size, color, uom, description, price, putaway_location_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), size = VALUES(size), color = VALUES(color), uom = VALUES(uom),
                    description = VALUES(description), price = VALUES(price), putaway_location_id = VALUES(putaway_location_id)');
            $stmt->execute([$sku, $name, trim((string) $_POST['size']) ?: null, trim((string) $_POST['color']) ?: null,
                trim((string) $_POST['uom']) ?: 'Cái', trim((string) $_POST['description']) ?: null, (float) $_POST['price'], $loc]);
            return "Đã lưu sản phẩm {$sku}. Tồn kho chỉ thay đổi qua nghiệp vụ nhập/xuất/kiểm kê.";
        }
        if ($act === 'delete') {
            $sku = (string) $_POST['sku'];
            $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM stock_movements WHERE product_sku = ?) + (SELECT COUNT(*) FROM inventory_balances WHERE product_sku = ?)
                + (SELECT COUNT(*) FROM goods_receipt_items WHERE product_sku = ?) + (SELECT COUNT(*) FROM outbound_order_lines WHERE product_sku = ?)
                + (SELECT COUNT(*) FROM stock_move WHERE product_sku = ?) + (SELECT COUNT(*) FROM inventory_audit WHERE product_sku = ?)');
            $stmt->execute(array_fill(0, 6, $sku));
            if ((int) $stmt->fetchColumn() > 0) {
                throw new \App\Service\BusinessRuleException("Không xóa được {$sku}: SKU đã có lịch sử giao dịch (ledger phải giữ nguyên để truy vết).");
            }
            $pdo->prepare('DELETE FROM products WHERE sku = ?')->execute([$sku]);
            return "Đã xóa sản phẩm {$sku}.";
        }
        return '';
    }, View::url('products'));
}

$search = trim((string) ($_GET['search'] ?? ''));
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE sku = ?');
    $stmt->execute([(string) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$stmt = $pdo->prepare("SELECT p.*, l.code AS putaway_code,
        COALESCE(SUM(b.on_hand), 0) AS on_hand,
        COALESCE(SUM(IF(bl.is_allocatable = 1, b.on_hand - b.reserved, 0)), 0) AS available
    FROM products p
    LEFT JOIN warehouse_locations l ON l.id = p.putaway_location_id
    LEFT JOIN inventory_balances b ON b.product_sku = p.sku
    LEFT JOIN warehouse_locations bl ON bl.id = b.location_id
    WHERE (? = '' OR p.sku LIKE ? OR p.name LIKE ?)
    GROUP BY p.id ORDER BY p.is_legacy, p.sku");
$stmt->execute([$search, "%{$search}%", "%{$search}%"]);
$products = $stmt->fetchAll();
?>
<div class="card">
    <h2><?= $edit ? 'Sửa sản phẩm ' . View::e($edit['sku']) : 'Thêm sản phẩm' ?></h2>
    <p class="hint">Không có ô "số lượng tồn": tồn đầu kỳ nạp bằng movement OPENING (Bảng điều khiển Demo), sau đó chỉ đổi qua Receiving, Shipping, Adjustment.</p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="act" value="save">
        <div class="form-grid">
            <label class="field">Mã SKU<input type="text" name="sku" value="<?= View::e($edit['sku'] ?? '') ?>" <?= $edit ? 'readonly' : '' ?> required></label>
            <label class="field">Tên hàng<input type="text" name="name" value="<?= View::e($edit['name'] ?? '') ?>" required></label>
            <label class="field">Size<input type="text" name="size" value="<?= View::e($edit['size'] ?? '') ?>"></label>
            <label class="field">Màu<input type="text" name="color" value="<?= View::e($edit['color'] ?? '') ?>"></label>
            <label class="field">Đơn vị<input type="text" name="uom" value="<?= View::e($edit['uom'] ?? 'Cái') ?>"></label>
            <label class="field">Giá (VNĐ)<input type="number" name="price" min="0" step="1000" value="<?= View::e((int) ($edit['price'] ?? 0)) ?>"></label>
            <label class="field">Location put-away gợi ý<select name="putaway_location_id"><option value="">–</option>
                <?php foreach ($storage as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) ($edit['putaway_location_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= View::e($l['code']) ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="field" style="margin-top:10px">Mô tả<textarea name="description" rows="2"><?= View::e($edit['description'] ?? '') ?></textarea></label>
        <p style="margin-top:10px"><button class="btn">Lưu</button> <?php if ($edit): ?><a class="btn btn-secondary" href="<?= View::e(View::url('products')) ?>">Hủy</a><?php endif; ?></p>
    </form>
</div>
<div class="card">
    <div class="row"><h2 style="margin:0">Danh mục</h2><span class="spacer"></span>
        <form method="get" class="row"><input type="hidden" name="page" value="products"><input type="text" name="search" value="<?= View::e($search) ?>" placeholder="Tìm SKU hoặc tên"><button class="btn btn-secondary btn-small">Tìm</button></form></div>
    <div class="table-wrap"><table class="data" style="margin-top:10px">
        <tr><th>SKU</th><th>Tên hàng</th><th>Size/Màu</th><th>Đơn vị</th><th>Put-away</th><th class="num">Giá</th><th class="num">On-hand</th><th class="num">Available</th><th></th></tr>
        <?php foreach ($products as $p): ?>
            <tr class="<?= $p['is_legacy'] ? 'dim' : '' ?>">
                <td class="mono"><?= View::e($p['sku']) ?> <?= $p['is_legacy'] ? View::badge('kế thừa – ngoài demo', 'muted') : '' ?></td>
                <td><?= View::e($p['name']) ?></td><td><?= View::e(trim(($p['size'] ?? '') . ' / ' . ($p['color'] ?? ''), ' /')) ?></td><td><?= View::e($p['uom']) ?></td>
                <td class="mono"><?= View::e($p['putaway_code']) ?></td><td class="num"><?= View::n($p['price']) ?></td>
                <td class="num"><a href="<?= View::e(View::url('inventory', ['sku' => $p['sku']])) ?>"><?= View::n($p['on_hand']) ?></a></td><td class="num"><?= View::n($p['available']) ?></td>
                <td class="row"><a class="btn btn-secondary btn-small" href="<?= View::e(View::url('products', ['edit' => $p['sku']])) ?>">Sửa</a>
                    <form method="post" class="inline" onsubmit="return confirm('Xóa sản phẩm <?= View::e($p['sku']) ?>?')"><?= Csrf::field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="sku" value="<?= View::e($p['sku']) ?>"><button class="btn btn-secondary btn-small">Xóa</button></form></td>
            </tr>
        <?php endforeach; ?>
    </table></div>
</div>
