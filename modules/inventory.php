<?php
/**
 * Inventory Visibility: hàng gì, bao nhiêu, ở đâu – On-hand / Reserved / Available theo cặp SKU + Location.
 * Nhấn SKU để mở ledger của SKU (đúng dạng bảng A4 §4).
 */

use App\Service\InventoryQuery;
use App\Service\LedgerService;
use App\Support\View;

$query = new InventoryQuery($pdo);
$skuFilter = trim((string) ($_GET['sku'] ?? ''));
$locFilter = trim((string) ($_GET['loc'] ?? ''));
$ledgerSku = trim((string) ($_GET['ledger'] ?? ''));
$pairs = $query->pairs($skuFilter ?: null, $locFilter ?: null);
$wh = $query->warehouseTotals();
$byLocation = $query->onHandByLocation();
$locations = $query->locations();
$products = $query->products();

$bySku = [];
foreach ($pairs as $p) {
    $bySku[$p['product_sku']] ??= ['name' => "{$p['product_name']} {$p['size']}/{$p['color']}", 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'locations' => []];
    $bySku[$p['product_sku']]['on_hand'] += $p['on_hand'];
    $bySku[$p['product_sku']]['reserved'] += $p['reserved'];
    $bySku[$p['product_sku']]['available'] += $p['available'];
    $bySku[$p['product_sku']]['locations'][] = $p['location_code'];
}
?>
<div class="grid grid-4" style="margin-bottom:18px">
    <div class="kpi"><div class="label">Tổng tồn (mọi location)</div><div class="value"><?= View::n($wh['on_hand']) ?></div><div class="note">On-hand toàn kho</div></div>
    <div class="kpi"><div class="label">Tồn khả dụng</div><div class="value"><?= View::n($wh['available']) ?></div><div class="note">Σ Available của cặp Storage</div></div>
    <div class="kpi"><div class="label">Đang giữ cho đơn</div><div class="value"><?= View::n($wh['reserved']) ?></div><div class="note">Reserved (đi theo hàng)</div></div>
    <div class="kpi"><div class="label">Không khả dụng</div><div class="value"><?= View::n($wh['on_hand'] - $wh['reserved'] - $wh['available']) ?></div><div class="note">Hàng ở RECEIVING / staging chưa giữ</div></div>
</div>

<div class="card">
    <h2>Tổng tồn theo location (7 location của Master Location A1)</h2>
    <div class="table-wrap"><table class="data">
        <tr><?php foreach ($locations as $l): ?><th class="num"><?= View::e($l['code']) ?><br><span class="small muted"><?= View::e($l['zone_type']) ?><?= $l['is_allocatable'] ? ' · phân bổ được' : '' ?></span></th><?php endforeach; ?><th class="num">TỔNG</th></tr>
        <tr><?php foreach ($locations as $l): ?><td class="num"><?= View::n($byLocation[$l['code']] ?? 0) ?></td><?php endforeach; ?><td class="num"><strong><?= View::n(array_sum($byLocation)) ?></strong></td></tr>
    </table></div>
</div>

<?php if ($ledgerSku !== ''): ?>
    <?php $ledger = (new LedgerService($pdo))->skuLedger($ledgerSku); ?>
    <div class="card">
        <div class="row"><h2>Ledger của SKU <?= View::e($ledgerSku) ?></h2><span class="spacer"></span>
            <a class="btn btn-secondary btn-small" href="<?= View::e(View::url('inventory')) ?>">Đóng ledger</a></div>
        <p class="hint">Mỗi dòng là một Stock Movement; ba cột cuối là On-hand / Reserved / Available của SKU <em>sau</em> dòng đó (Available = Σ Available của cặp Storage, hàng ở RECEIVING/staging không khả dụng). LOAD + SHIP gộp thành một dòng "Shipping" như A4.</p>
        <div class="table-wrap"><table class="data">
            <tr><th>#</th><th>Thời điểm</th><th>Bước</th><th>Từ → Đến</th><th class="num">SL</th><th>Chứng từ</th><th>Ghi chú</th><th class="num">On-hand</th><th class="num">Reserved</th><th class="num">Available</th></tr>
            <?php foreach ($ledger as $i => $m): ?>
                <tr class="<?= $m['changes_on_hand'] ? 'highlight' : '' ?>">
                    <td><?= $i ?></td>
                    <td class="small"><?= View::e(substr((string) $m['created_at'], 0, 19)) ?></td>
                    <td><?= View::e(LedgerService::label($m['movement_type'])) ?><?= $m['changes_on_hand'] ? ' ' . View::badge('đổi tổng tồn', 'warn') : '' ?></td>
                    <td class="mono small"><?= View::e(LedgerService::route($m)) ?>
                        <?php if (count($m['children']) > 1): ?><details><summary>chi tiết</summary><?php foreach ($m['children'] as $c): ?><div><?= View::e($c['movement_type']) ?>: <?= View::e($c['from_code'] ?? '–') ?> → <?= View::e($c['to_code'] ?? '(ngoài kho)') ?> (#<?= (int) $c['id'] ?>)</div><?php endforeach; ?></details><?php endif; ?></td>
                    <td class="num"><?= in_array($m['movement_type'], ['RESERVE', 'UNRESERVE'], true) ? '(' . View::n($m['qty']) . ')' : View::n($m['qty']) ?></td>
                    <td class="mono small"><?= View::e($m['reference_code']) ?></td>
                    <td class="small"><?= View::e($m['note']) ?></td>
                    <td class="num"><?= View::n($m['after']['on_hand']) ?></td>
                    <td class="num"><?= View::n($m['after']['reserved']) ?></td>
                    <td class="num"><?= View::n($m['after']['available']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Tồn theo cặp SKU + Location</h2>
    <form method="get" class="row no-print" style="margin-bottom:12px">
        <input type="hidden" name="page" value="inventory">
        <select name="sku"><option value="">– Mọi SKU –</option>
            <?php foreach ($products as $p): ?><option value="<?= View::e($p['sku']) ?>" <?= $p['sku'] === $skuFilter ? 'selected' : '' ?>><?= View::e($p['sku']) ?></option><?php endforeach; ?></select>
        <select name="loc"><option value="">– Mọi location –</option>
            <?php foreach ($locations as $l): ?><option value="<?= View::e($l['code']) ?>" <?= $l['code'] === $locFilter ? 'selected' : '' ?>><?= View::e($l['code']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-small">Lọc</button>
        <a class="btn btn-secondary btn-small" href="<?= View::e(View::url('inventory')) ?>">Bỏ lọc</a>
    </form>
    <p class="hint">Available (cặp) = On-hand − Reserved nếu location là Storage (A-01, B-01); location staging/dock luôn hiển thị Available = 0.</p>
    <div class="table-wrap"><table class="data">
        <tr><th>SKU</th><th>Tên hàng</th><th>Location</th><th>Loại</th><th class="num">On-hand</th><th class="num">Reserved</th><th class="num">Available</th><th>Ghi chú</th></tr>
        <?php foreach ($pairs as $p): ?>
            <tr>
                <td><a class="mono" href="<?= View::e(View::url('inventory', ['ledger' => $p['product_sku']])) ?>"><?= View::e($p['product_sku']) ?></a></td>
                <td><?= View::e("{$p['product_name']} {$p['size']}/{$p['color']}") ?></td>
                <td class="mono"><?= View::e($p['location_code']) ?></td>
                <td class="small muted"><?= View::e($p['zone_type']) ?></td>
                <td class="num"><?= View::n($p['on_hand']) ?></td>
                <td class="num"><?= View::n($p['reserved']) ?></td>
                <td class="num"><?= View::n($p['available']) ?></td>
                <td><?= $p['alloc_locked'] ? View::badge('Khóa phân bổ: ' . $p['alloc_lock_reason'], 'fail') : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pairs): ?><tr><td colspan="8" class="muted">Chưa có tồn. Vào Bảng điều khiển Demo để nạp preset.</td></tr><?php endif; ?>
        <tr class="total"><td colspan="4">TỔNG (theo bộ lọc)</td>
            <td class="num"><?= View::n(array_sum(array_column($pairs, 'on_hand'))) ?></td>
            <td class="num"><?= View::n(array_sum(array_column($pairs, 'reserved'))) ?></td>
            <td class="num"><?= View::n(array_sum(array_column($pairs, 'available'))) ?></td><td></td></tr>
    </table></div>
</div>

<div class="card">
    <h2>Tổng theo SKU</h2>
    <div class="table-wrap"><table class="data">
        <tr><th>SKU</th><th>Tên hàng</th><th>Nằm ở</th><th class="num">On-hand</th><th class="num">Reserved</th><th class="num">Available</th><th></th></tr>
        <?php foreach ($bySku as $sku => $s): ?>
            <tr><td class="mono"><?= View::e($sku) ?></td><td><?= View::e($s['name']) ?></td><td class="mono small"><?= View::e(implode(', ', $s['locations'])) ?></td>
                <td class="num"><?= View::n($s['on_hand']) ?></td><td class="num"><?= View::n($s['reserved']) ?></td><td class="num"><?= View::n($s['available']) ?></td>
                <td><a class="btn btn-secondary btn-small" href="<?= View::e(View::url('inventory', ['ledger' => $sku])) ?>">Ledger</a></td></tr>
        <?php endforeach; ?>
    </table></div>
</div>
