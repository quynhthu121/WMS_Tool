<?php
/**
 * Phiếu điều chuyển kế thừa (stock_picking / stock_move của FlowLink-SCM) – ĐÓNG BĂNG, chỉ đọc.
 * Nhập/xuất kho hiện thực hiện ở Inbound / Outbound, ghi inventory_balances + stock_movements.
 */

use App\Support\View;

$pickings = $pdo->query('SELECT sp.*, sm.product_sku, sm.product_qty FROM stock_picking sp
    JOIN stock_move sm ON sp.id = sm.picking_id ORDER BY sp.scheduled_date DESC')->fetchAll();
?>
<div class="callout warn">Bảng <code>stock_picking</code> / <code>stock_move</code> của source nền đã <strong>đóng băng</strong>: không module nào ghi thêm,
    để không có hai hệ thống ghi tồn song song. Dùng <a href="<?= View::e(View::url('inbound')) ?>">Nhập kho</a> và <a href="<?= View::e(View::url('outbound')) ?>">Xuất kho</a>.</div>
<div class="card">
    <h2>Phiếu kế thừa (chỉ đọc)</h2>
    <div class="table-wrap"><table class="data">
        <tr><th>Mã phiếu</th><th>Loại</th><th>Nguồn</th><th>SKU</th><th class="num">SL</th><th>Trạng thái</th><th>Ngày</th></tr>
        <?php foreach ($pickings as $p): ?>
            <tr class="dim"><td class="mono"><?= View::e($p['picking_number']) ?></td><td><?= $p['type'] === 'in' ? 'Nhập' : 'Xuất' ?></td><td><?= View::e($p['origin']) ?></td>
                <td class="mono"><?= View::e($p['product_sku']) ?></td><td class="num"><?= View::n($p['product_qty']) ?></td><td><?= View::e($p['state']) ?></td><td class="small"><?= View::e($p['scheduled_date']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$pickings): ?><tr><td colspan="7" class="muted">Không có phiếu kế thừa.</td></tr><?php endif; ?>
    </table></div>
</div>
