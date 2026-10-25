<?php
/**
 * Tổng quan: ba năng lực của prototype (Visibility, Traceability, Fulfillment Control) trên dữ liệu hiện tại.
 */

use App\Service\DemoService;
use App\Service\InventoryQuery;
use App\Service\LedgerService;
use App\Service\ReconciliationService;
use App\Support\View;

$query = new InventoryQuery($pdo);
$wh = $query->warehouseTotals();
$byLoc = $query->onHandByLocation();
$recon = new ReconciliationService($pdo);
$totals = $recon->totals();
$mismatch = count(ReconciliationService::pairMismatches($pdo));
$recent = array_slice(array_reverse((new LedgerService($pdo))->groupShipping((new LedgerService($pdo))->movements([], 100000))), 0, 8);
$openReceipts = (int) $pdo->query("SELECT COUNT(*) FROM goods_receipts WHERE status NOT IN ('CLOSED', 'CANCELLED')")->fetchColumn();
$openOrders = (int) $pdo->query("SELECT COUNT(*) FROM outbound_orders WHERE status NOT IN ('SHIPPED', 'CANCELLED')")->fetchColumn();
$holdOrders = (int) $pdo->query('SELECT COUNT(*) FROM outbound_orders WHERE hold_reason IS NOT NULL')->fetchColumn();
$openCounts = (int) $pdo->query("SELECT COUNT(*) FROM cycle_counts WHERE status IN ('OPEN', 'RECOUNT', 'PENDING_APPROVAL')")->fetchColumn();
$preset = (new DemoService($pdo, dirname(__DIR__)))->currentPreset();
?>
<div class="callout">
    <strong>Bài toán (PROPOSED SOLUTION của nhóm):</strong> quản lý chính xác tồn kho và dòng di chuyển hàng hóa trong trung tâm phân phối,
    với khả năng quan sát và kiểm soát gần thời gian thực. Luồng: Receiving → Put-away → Inventory → Outbound Order → Reservation → Picking → Packing → Shipping;
    Stock Movement chạy xuyên suốt, Audit/Adjustment là nhánh đối soát.
    <?= $preset ? '<br>Preset dữ liệu hiện tại: <strong>' . View::e(DemoService::PRESETS[$preset]) . '</strong>.' : '<br>Chưa nạp dữ liệu demo – vào <a href="' . View::e(View::url('demo')) . '">Bảng điều khiển Demo</a>.' ?>
</div>

<div class="grid grid-4" style="margin:18px 0">
    <div class="kpi"><div class="label">Tổng tồn</div><div class="value"><?= View::n($wh['on_hand']) ?></div><div class="note">Mọi location</div></div>
    <div class="kpi"><div class="label">Tồn khả dụng</div><div class="value"><?= View::n($wh['available']) ?></div><div class="note">Chỉ Storage, trừ phần đã giữ</div></div>
    <div class="kpi"><div class="label">Đang giữ cho đơn</div><div class="value"><?= View::n($wh['reserved']) ?></div><div class="note"><?= $openOrders ?> đơn đang xử lý<?= $holdOrders ? ", {$holdOrders} có cờ ngoại lệ" : '' ?></div></div>
    <div class="kpi"><div class="label">Đối soát ledger</div><div class="value"><?= View::mark($mismatch === 0 && $totals['ok']) ?></div><div class="note"><?= $mismatch === 0 ? 'Mọi cặp khớp' : "{$mismatch} cặp lệch" ?></div></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h2>Inventory Visibility – hàng ở đâu?</h2>
        <table class="data"><tr><?php foreach ($byLoc as $code => $_): ?><th class="num"><?= View::e($code) ?></th><?php endforeach; ?></tr>
            <tr><?php foreach ($byLoc as $qty): ?><td class="num"><?= View::n($qty) ?></td><?php endforeach; ?></tr></table>
        <p style="margin-top:10px"><a class="btn btn-secondary btn-small" href="<?= View::e(View::url('inventory')) ?>">Xem theo SKU + Location</a></p>
    </div>
    <div class="card">
        <h2>Order Fulfillment Control – việc đang mở</h2>
        <ul style="margin-left:18px">
            <li><a href="<?= View::e(View::url('inbound')) ?>"><?= $openReceipts ?> phiếu nhập</a> chưa đóng</li>
            <li><a href="<?= View::e(View::url('outbound')) ?>"><?= $openOrders ?> đơn xuất</a> chưa xuất kho</li>
            <li><a href="<?= View::e(View::url('audit')) ?>"><?= $openCounts ?> phiếu kiểm kê</a> đang mở</li>
        </ul>
        <p class="small muted" style="margin-top:8px">Công thức: <?= View::n($totals['opening']) ?> + <?= View::n($totals['received']) ?> − <?= View::n($totals['shipped']) ?> + <?= View::n($totals['adjust_plus']) ?> − <?= View::n($totals['adjust_minus']) ?> = <?= View::n($totals['expected']) ?></p>
    </div>
</div>

<div class="card">
    <div class="row"><h2 style="margin:0">Accuracy &amp; Traceability – Stock Movement gần nhất</h2><span class="spacer"></span><a class="btn btn-secondary btn-small" href="<?= View::e(View::url('ledger')) ?>">Toàn bộ ledger</a></div>
    <div class="table-wrap"><table class="data" style="margin-top:10px">
        <tr><th>#</th><th>Thời điểm</th><th>Loại</th><th>SKU</th><th>Từ → Đến</th><th class="num">SL</th><th>Chứng từ</th></tr>
        <?php foreach ($recent as $m): ?><tr class="<?= $m['changes_on_hand'] ? 'highlight' : '' ?>"><td><?= (int) $m['id'] ?></td><td class="small"><?= View::e(substr((string) $m['created_at'], 0, 19)) ?></td>
            <td><?= View::e(LedgerService::label($m['movement_type'])) ?></td><td class="mono"><?= View::e($m['product_sku']) ?></td>
            <td class="mono small"><?= View::e(LedgerService::route($m)) ?></td>
            <td class="num"><?= View::n($m['qty']) ?></td><td class="mono small"><?= View::e($m['reference_code']) ?></td></tr><?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="7" class="muted">Chưa có giao dịch.</td></tr><?php endif; ?>
    </table></div>
</div>
