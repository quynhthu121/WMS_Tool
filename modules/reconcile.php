<?php
/**
 * Đối soát (North Star): với mọi cặp SKU + Location, tồn hiện tại = tổng đại số Stock Movement (kể cả OPENING);
 * toàn kho: Tồn cuối = Đầu kỳ + Nhập − Xuất ± Adjustment.
 */

use App\Service\ReconciliationService;
use App\Support\View;

$recon = new ReconciliationService($pdo);
$pairs = $recon->pairs();
$t = $recon->totals();
$bad = array_filter($pairs, fn ($p) => !$p['ok']);
?>
<div class="grid grid-2">
    <div class="card">
        <h2>Công thức toàn kho <?= View::mark($t['ok']) ?></h2>
        <p class="hint">Tồn cuối = Tồn đầu + Nhập (Receiving) − Xuất (Shipping) ± Adjustment. OPENING là tồn đầu kỳ, không tính là "Nhập".</p>
        <table class="data">
            <tr><td>Tồn đầu kỳ (OPENING)</td><td class="num"><?= View::n($t['opening']) ?></td></tr>
            <tr><td>+ Nhập (RECEIVE)</td><td class="num"><?= View::n($t['received']) ?></td></tr>
            <tr><td>− Xuất (SHIP)</td><td class="num"><?= View::n($t['shipped']) ?></td></tr>
            <tr><td>+ Adjustment tăng</td><td class="num"><?= View::n($t['adjust_plus']) ?></td></tr>
            <tr><td>− Adjustment giảm</td><td class="num"><?= View::n($t['adjust_minus']) ?></td></tr>
            <tr class="total"><td>= Tồn cuối theo ledger</td><td class="num"><?= View::n($t['expected']) ?></td></tr>
            <tr class="total"><td>Tổng On-hand thực tế (inventory_balances)</td><td class="num"><?= View::n($t['actual']) ?></td></tr>
        </table>
    </div>
    <div class="card">
        <h2>Đối soát theo cặp <?= View::mark(!$bad) ?></h2>
        <p><?= count($pairs) ?> cặp SKU + Location có lịch sử; <?= count($bad) ?> cặp lệch.</p>
        <p class="hint">On-hand theo ledger = Σ số lượng vào − Σ số lượng ra (bỏ qua Reservation). Reserved theo ledger = Σ phần reserved đi kèm vào − ra.</p>
    </div>
</div>
<div class="card">
    <h2>Chi tiết từng cặp</h2>
    <div class="table-wrap"><table class="data">
        <tr><th>SKU</th><th>Location</th><th class="num">On-hand (balance)</th><th class="num">On-hand (ledger)</th><th class="num">Reserved (balance)</th><th class="num">Reserved (ledger)</th><th>Khớp</th><th></th></tr>
        <?php foreach ($pairs as $p): ?>
            <tr class="<?= $p['ok'] ? '' : 'bad' ?> <?= $p['on_hand'] === 0 && $p['reserved'] === 0 ? 'dim' : '' ?>">
                <td class="mono"><?= View::e($p['product_sku']) ?></td><td class="mono"><?= View::e($p['location_code']) ?></td>
                <td class="num"><?= View::n($p['on_hand']) ?></td><td class="num"><?= View::n($p['ledger_on_hand']) ?></td>
                <td class="num"><?= View::n($p['reserved']) ?></td><td class="num"><?= View::n($p['ledger_reserved']) ?></td>
                <td><?= View::mark($p['ok']) ?></td>
                <td><a class="small" href="<?= View::e(View::url('ledger', ['sku' => $p['product_sku']])) ?>">Xem ledger</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pairs): ?><tr><td colspan="8" class="muted">Chưa có dữ liệu.</td></tr><?php endif; ?>
    </table></div>
</div>
