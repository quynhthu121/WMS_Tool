<?php
/**
 * Inventory Audit (A4 §3): cycle count C1–C6 theo cặp SKU + Location Storage, đếm mù, đếm lại,
 * Giám sát duyệt Adjustment; phiếu tự sinh khi pick thiếu; hiển thị khóa phân bổ.
 * Bảng kế thừa inventory_audit chỉ còn đọc.
 */

use App\Service\AuditService;
use App\Service\InventoryQuery;
use App\Service\LedgerService;
use App\Support\Csrf;
use App\Support\View;

$svc = new AuditService($pdo);
$query = new InventoryQuery($pdo);
$id = (int) ($_GET['id'] ?? 0);
$back = $id ? View::url('audit', ['id' => $id]) : View::url('audit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    View::handle(function () use ($act, $svc, $id, $actor) {
        switch ($act) {
            case 'create':
                [$sku, $loc] = explode('@', (string) $_POST['pair'], 2) + [1 => ''];
                $newId = $svc->createCount($sku, $loc, $actor);
                return ['C1: đã tạo phiếu và chốt tồn hệ thống tại thời điểm tạo.', View::url('audit', ['id' => $newId])];
            case 'count':
                $st = $svc->submitCount($id, (int) $_POST['counted'], $actor);
                return $st === 'CLOSED_MATCH' ? 'Khớp tồn hệ thống – đã đóng phiếu.' : 'Có chênh lệch – cần đếm lại và rà soát giao dịch gần nhất (C4).';
            case 'recount':
                $st = $svc->submitRecount($id, (int) $_POST['recount'], $actor);
                return $st === 'CLOSED_MATCH' ? 'Đếm lại khớp – đã đóng phiếu.' : 'Đếm lại vẫn lệch – chờ Giám sát duyệt Adjustment.';
            case 'release':
                $n = $svc->releaseUnbackedReservations($id, $actor);
                return $n > 0 ? "Đã hủy {$n} Reserved không còn hàng đỡ." : 'Không có Reserved dư cần hủy.';
            case 'approve':
                $svc->approve($id, (string) $_POST['reason'], $actor);
                return 'C5–C6: đã tạo ADJUST đúng bằng chênh lệch và đóng phiếu.';
        }
        return '';
    }, $back);
}

if ($id):
    $c = $svc->count($id);
    if (!$c) {
        echo '<div class="flash flash-error">Không tìm thấy phiếu.</div>';
        return;
    }
    $blind = $c['status'] === 'OPEN';
    $bal = $query->pair($c['product_sku'], $c['location_code']);
    ?>
    <p><a href="<?= View::e(View::url('audit')) ?>">← Danh sách phiếu kiểm kê</a></p>
    <div class="card">
        <div class="row"><h2>Phiếu <span class="mono"><?= View::e($c['count_code']) ?></span> – <span class="mono"><?= View::e($c['product_sku']) ?> @ <?= View::e($c['location_code']) ?></span></h2>
            <?= View::badge(AuditService::STATUS_LABELS[$c['status']], View::statusTone($c['status'])) ?>
            <?= View::badge(AuditService::TRIGGER_LABELS[$c['trigger_type']], $c['trigger_type'] === 'PICK_SHORTAGE' ? 'fail' : 'info') ?></div>
        <?php if ($c['order_code']): ?><p class="small">Phát sinh từ đơn <a class="mono" href="<?= View::e(View::url('outbound', ['id' => $c['source_order_id']])) ?>"><?= View::e($c['order_code']) ?></a> (pick thiếu).</p><?php endif; ?>
        <table class="data" style="margin-top:10px">
            <tr><th>Tồn hệ thống (chốt C1)</th><th>Đếm lần 1</th><th>Đếm lại</th><th>Chênh lệch</th><th>Hiện tại On-hand / Reserved</th><th>Lý do</th></tr>
            <tr><td class="num"><?= $blind ? '<span class="muted">ẩn khi đếm mù</span>' : View::n($c['system_qty']) ?></td>
                <td class="num"><?= $c['counted_qty'] === null ? '–' : View::n($c['counted_qty']) ?></td>
                <td class="num"><?= $c['recount_qty'] === null ? '–' : View::n($c['recount_qty']) ?></td>
                <td class="num"><?= $c['difference'] === null ? '–' : sprintf('%+d', $c['difference']) ?></td>
                <td class="num"><?= $blind ? '<span class="muted">ẩn</span>' : View::n($bal['on_hand']) . ' / ' . View::n($bal['reserved']) ?></td>
                <td><?= View::e($c['reason']) ?></td></tr>
        </table>
    </div>
    <div class="card">
        <?php if ($c['status'] === 'OPEN'): ?>
            <h2>C2 – Nhập tồn thực tế (đếm mù)</h2>
            <p class="hint">Số hệ thống được ẩn để nhân viên đếm độc lập.</p>
            <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="count">
                <input class="qty" type="number" min="0" name="counted" required><button class="btn">Ghi nhận số đếm</button></form>
        <?php elseif ($c['status'] === 'RECOUNT'): ?>
            <h2>C4 – Đếm lại và rà soát giao dịch gần nhất</h2>
            <p class="hint">Kiểm tra nhập/xuất/pick nhầm location trước khi đếm lại.</p>
            <?php $recent = $svc->recentMovements($id); ?>
            <table class="data"><tr><th>#</th><th>Thời điểm</th><th>Loại</th><th>Từ → Đến</th><th class="num">SL</th><th>Chứng từ</th></tr>
                <?php foreach ($recent as $m): ?><tr><td><?= (int) $m['id'] ?></td><td class="small"><?= View::e(substr($m['created_at'], 0, 19)) ?></td><td><?= View::e(LedgerService::label($m['movement_type'])) ?></td>
                    <td class="mono small"><?= View::e(LedgerService::route($m)) ?></td><td class="num"><?= View::n($m['qty']) ?></td><td class="mono small"><?= View::e($m['reference_code']) ?></td></tr><?php endforeach; ?></table>
            <form method="post" class="row" style="margin-top:10px"><?= Csrf::field() ?><input type="hidden" name="act" value="recount">
                <input class="qty" type="number" min="0" name="recount" required><button class="btn">Ghi nhận đếm lại</button></form>
        <?php elseif ($c['status'] === 'PENDING_APPROVAL'): ?>
            <h2>C5 – Giám sát duyệt Adjustment <?= sprintf('%+d', $c['difference']) ?></h2>
            <?php $excess = $bal['reserved'] - ($bal['on_hand'] + (int) $c['difference']); ?>
            <?php if ($excess > 0): ?>
                <div class="callout warn">Adjustment sẽ làm On-hand (<?= $bal['on_hand'] + (int) $c['difference'] ?>) nhỏ hơn Reserved (<?= $bal['reserved'] ?>): phải hủy <?= $excess ?> Reserved không còn hàng đỡ trước, các đơn liên quan sẽ được phân bổ lại.</div>
            <?php endif; ?>
            <?php if ($actor->isSupervisor()): ?>
                <?php if ($excess > 0): ?>
                    <form method="post" class="row" style="margin-bottom:10px"><?= Csrf::field() ?><input type="hidden" name="act" value="release"><button class="btn btn-warn">Bước 1: Hủy <?= $excess ?> Reserved</button></form>
                <?php endif; ?>
                <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="approve">
                    <input type="text" name="reason" placeholder="Lý do điều chỉnh" style="min-width:340px" required>
                    <button class="btn"><?= $excess > 0 ? 'Bước 2: ' : '' ?>Duyệt Adjustment <?= sprintf('%+d', $c['difference']) ?></button></form>
            <?php else: ?><div class="callout">Chờ Giám sát kho duyệt.</div><?php endif; ?>
        <?php else: ?>
            <h2>Đã đóng phiếu</h2>
            <p><?= $c['status'] === 'ADJUSTED' ? 'Đã tạo ADJUST ' . sprintf('%+d', $c['difference']) . ' (movement #' . (int) $c['adjust_movement_id'] . ')' . ($c['released_reserved_qty'] ? ', đã hủy ' . (int) $c['released_reserved_qty'] . ' Reserved' : '') . '.' : 'Số đếm khớp hệ thống, không điều chỉnh.' ?></p>
            <?php if ($c['order_code']): ?><p><a class="btn btn-small" href="<?= View::e(View::url('outbound', ['id' => $c['source_order_id']])) ?>">Về đơn <?= View::e($c['order_code']) ?> để phân bổ lại</a></p><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return;
endif;

$counts = $svc->counts();
$storagePairs = array_filter($query->pairs(null, null, true), fn ($p) => $p['zone_type'] === 'storage');
$locked = array_filter($storagePairs, fn ($p) => $p['alloc_locked']);
$legacy = $pdo->query('SELECT * FROM inventory_audit ORDER BY id DESC LIMIT 50')->fetchAll();
?>
<?php if ($locked): ?>
    <div class="card"><h2>Cặp đang khóa phân bổ</h2>
        <table class="data"><tr><th>SKU</th><th>Location</th><th>Lý do</th></tr>
            <?php foreach ($locked as $p): ?><tr><td class="mono"><?= View::e($p['product_sku']) ?></td><td class="mono"><?= View::e($p['location_code']) ?></td><td><?= View::e($p['alloc_lock_reason']) ?></td></tr><?php endforeach; ?></table></div>
<?php endif; ?>
<div class="card">
    <h2>Phiếu kiểm kê</h2>
    <div class="table-wrap"><table class="data">
        <tr><th>Mã</th><th>SKU</th><th>Location</th><th>Nguồn</th><th>Trạng thái</th><th class="num">Hệ thống</th><th class="num">Thực tế</th><th class="num">Chênh lệch</th><th>Tạo lúc</th></tr>
        <?php foreach ($counts as $c): $open = $c['status'] === 'OPEN'; ?>
            <tr><td><a class="mono" href="<?= View::e(View::url('audit', ['id' => $c['id']])) ?>"><?= View::e($c['count_code']) ?></a></td><td class="mono"><?= View::e($c['product_sku']) ?></td><td class="mono"><?= View::e($c['location_code']) ?></td>
                <td><?= View::e(AuditService::TRIGGER_LABELS[$c['trigger_type']]) ?><?= $c['order_code'] ? ' (' . View::e($c['order_code']) . ')' : '' ?></td>
                <td><?= View::badge(AuditService::STATUS_LABELS[$c['status']], View::statusTone($c['status'])) ?></td>
                <td class="num"><?= $open ? '<span class="muted">ẩn</span>' : View::n($c['system_qty']) ?></td>
                <td class="num"><?= $c['recount_qty'] ?? $c['counted_qty'] ?? '–' ?></td><td class="num"><?= $c['difference'] === null ? '–' : sprintf('%+d', $c['difference']) ?></td><td class="small"><?= View::e($c['created_at']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$counts): ?><tr><td colspan="9" class="muted">Chưa có phiếu kiểm kê.</td></tr><?php endif; ?>
    </table></div>
</div>
<div class="card">
    <h2>C1 – Tạo phiếu cycle count</h2>
    <p class="hint">Chỉ áp dụng cho cặp Storage (A-01, B-01); hàng ở staging/dock không nằm trong cycle count của demo. Hệ thống chốt tồn hệ thống tại thời điểm tạo phiếu.</p>
    <?php if ($actor->isSupervisor()): ?>
        <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="create">
            <select name="pair" required><?php foreach ($storagePairs as $p): ?><option value="<?= View::e($p['product_sku'] . '@' . $p['location_code']) ?>"><?= View::e("{$p['product_sku']} @ {$p['location_code']}") ?></option><?php endforeach; ?></select>
            <button class="btn">Tạo phiếu</button></form>
    <?php else: ?><div class="callout">Chỉ Giám sát kho được tạo phiếu kiểm kê.</div><?php endif; ?>
</div>
<?php if ($legacy): ?>
    <div class="card"><h2>Lịch sử kiểm kê kế thừa (chỉ đọc)</h2>
        <p class="hint">Dữ liệu của module cũ (ghi trực tiếp products.qty, không có location). Không còn được ghi thêm.</p>
        <table class="data"><tr><th>Mã</th><th>SKU</th><th class="num">Hệ thống</th><th class="num">Đếm</th><th class="num">Lệch</th><th>Ghi chú</th><th>Ngày</th></tr>
            <?php foreach ($legacy as $a): ?><tr class="dim"><td><?= View::e($a['audit_code']) ?></td><td><?= View::e($a['product_sku']) ?></td><td class="num"><?= (int) $a['system_qty'] ?></td><td class="num"><?= (int) $a['counted_qty'] ?></td><td class="num"><?= (int) $a['difference'] ?></td><td><?= View::e($a['note']) ?></td><td><?= View::e($a['audit_date']) ?></td></tr><?php endforeach; ?></table></div>
<?php endif; ?>
