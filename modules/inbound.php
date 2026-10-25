<?php
/**
 * Inbound (A2): ASN → check-in → kiểm đếm → QC → Xác nhận Receiving → Put-away (quét) → đóng phiếu.
 */

use App\Service\InventoryQuery;
use App\Service\ReceivingService;
use App\Service\ScenarioService;
use App\Support\Csrf;
use App\Support\View;

$svc = new ReceivingService($pdo);
$id = (int) ($_GET['id'] ?? 0);
$back = $id ? View::url('inbound', ['id' => $id]) : View::url('inbound');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    View::handle(function () use ($act, $svc, $id, $actor) {
        switch ($act) {
            case 'create':
                $lines = [];
                foreach ((array) ($_POST['sku'] ?? []) as $i => $sku) {
                    if (trim((string) $sku) !== '') {
                        $lines[] = ['sku' => trim((string) $sku), 'qty' => (int) ($_POST['qty'][$i] ?? 0)];
                    }
                }
                $newId = $svc->createAsn((string) $_POST['code'], (string) ($_POST['supplier'] ?? ''), $lines, $actor);
                return ['Đã tạo ASN (dự kiến). ASN chưa làm thay đổi tồn kho.', View::url('inbound', ['id' => $newId])];
            case 'checkin':
                $svc->checkIn($id, $actor);
                return 'Đã check-in: hàng ở khu nhận, hệ thống chưa ghi nhận tồn.';
            case 'count':
                $counts = [];
                foreach ((array) ($_POST['counted'] ?? []) as $itemId => $qty) {
                    $counts[(int) $itemId] = ['counted' => (int) $qty, 'reason' => (string) ($_POST['reason'][$itemId] ?? '')];
                }
                $svc->count($id, $counts, $actor);
                return 'Đã ghi nhận kiểm đếm.';
            case 'qc':
                $fails = [];
                foreach ((array) ($_POST['fail'] ?? []) as $itemId => $qty) {
                    $fails[(int) $itemId] = ['fail' => (int) $qty, 'reason' => (string) ($_POST['fail_reason'][$itemId] ?? '')];
                }
                $svc->qc($id, $fails, $actor);
                return 'Đã hoàn tất QC.';
            case 'confirm':
                $n = $svc->confirmReceiving($id, $actor, !empty($_POST['accept_over']));
                return "Đã xác nhận Receiving: tổng tồn +{$n} (vào RECEIVING, chưa khả dụng). Hệ thống đã tạo task put-away.";
            case 'putaway':
                $svc->confirmPutaway((int) $_POST['task_id'], (string) $_POST['scan_source'], (string) $_POST['scan_sku'], (string) $_POST['scan_target'], $actor);
                return 'Đã put-away: RECEIVING giảm, Storage tăng bằng nhau – tổng tồn không đổi, tồn khả dụng tăng.';
            case 'resolve_over':
                $svc->resolveOverReceipt((int) $_POST['item_id'], ($_POST['decision'] ?? '') === 'accept', $actor);
                return 'Đã ghi quyết định cho phần nhận thừa.';
            case 'resolve_shortage':
                $svc->resolveShortage((int) $_POST['item_id'], (string) $_POST['status'], (string) $_POST['note'], $actor);
                return 'Đã cập nhật phần thiếu.';
            case 'close':
                $svc->close($id, $actor, (string) ($_POST['note'] ?? ''));
                return 'Đã đóng phiếu nhập.';
            case 'cancel':
                $svc->cancel($id, $actor);
                return 'Đã hủy phiếu (không ảnh hưởng tồn).';
        }
        return '';
    }, $back);
}

$statuses = array_keys(ReceivingService::STATUS_LABELS);
array_pop($statuses); // bỏ CANCELLED khỏi dòng thời gian
$timeline = function (string $current) use ($statuses): string {
    $html = '<div class="timeline">';
    $reached = true;
    foreach ($statuses as $s) {
        $cls = $s === $current ? 'current' : ($reached ? 'done' : '');
        if ($s === $current) {
            $reached = false;
        }
        $html .= '<span class="st ' . $cls . '">' . View::e(ReceivingService::STATUS_LABELS[$s]) . '</span>';
    }
    return $html . '</div>';
};

if ($id):
    $r = $svc->receipt($id);
    if (!$r) {
        echo '<div class="flash flash-error">Không tìm thấy phiếu.</div>';
        return;
    }
    $items = $svc->items($id);
    $tasks = $svc->putawayTasks($id);
    $qcRecords = $svc->qcRecords($id);
    $status = $r['status'];
    $variance = in_array($status, ['QC_DONE'], true) && $svc->hasVariance($id);
    $hasOver = false;
    foreach ($items as $it) {
        $hasOver = $hasOver || (int) $it['qc_pass_qty'] > (int) $it['expected_qty'];
    }
    ?>
    <p class="no-print"><a href="<?= View::e(View::url('inbound')) ?>">← Danh sách phiếu nhập</a></p>
    <div class="card">
        <div class="row"><h2>Phiếu nhập <span class="mono"><?= View::e($r['receipt_code']) ?></span></h2>
            <?= View::badge(ReceivingService::STATUS_LABELS[$status], View::statusTone($status)) ?><span class="spacer"></span>
            <span class="small muted">Nhà cung cấp: <?= View::e($r['supplier_name']) ?></span></div>
        <?php if ($status !== 'CANCELLED') echo $timeline($status); ?>

        <div class="table-wrap"><table class="data" style="margin-top:12px">
            <tr><th>Dòng</th><th>SKU</th><th>Tên hàng</th><th>Location đích</th><th class="num">SL ASN</th><th class="num">Đếm</th><th class="num">QC đạt</th><th class="num">QC lỗi</th>
                <th class="num">Xác nhận nhập</th><th class="num">Đã put-away</th><th class="num">Chênh lệch</th><th>Theo dõi</th></tr>
            <?php foreach ($items as $it):
                $diff = $it['counted_qty'] === null ? null : (int) $it['counted_qty'] - (int) $it['expected_qty']; ?>
                <tr>
                    <td><?= (int) $it['line_no'] ?></td><td class="mono"><?= View::e($it['product_sku']) ?></td>
                    <td><?= View::e("{$it['product_name']} {$it['size']}/{$it['color']}") ?></td><td class="mono"><?= View::e($it['target_code']) ?></td>
                    <td class="num"><?= View::n($it['expected_qty']) ?></td>
                    <td class="num"><?= $it['counted_qty'] === null ? '–' : View::n($it['counted_qty']) ?></td>
                    <td class="num"><?= View::n($it['qc_pass_qty']) ?></td><td class="num"><?= View::n($it['qc_fail_qty']) ?></td>
                    <td class="num"><strong><?= View::n($it['received_qty']) ?></strong></td><td class="num"><?= View::n($it['putaway_qty']) ?></td>
                    <td class="num"><?= $diff === null ? '–' : ($diff === 0 ? '0' : sprintf('%+d', $diff)) ?></td>
                    <td class="small">
                        <?= $it['variance_reason'] ? View::e('Lý do: ' . $it['variance_reason']) . '<br>' : '' ?>
                        <?php if ($it['over_status'] !== 'NONE'): ?><?= View::badge('Nhận thừa ' . ($it['over_status'] === 'PENDING' ? $it['over_qty'] . ' – chờ quyết định' : strtolower($it['over_status'])), $it['over_status'] === 'PENDING' ? 'warn' : 'muted') ?>
                            <?php if ($it['over_status'] === 'PENDING' && $actor->isSupervisor()): ?>
                                <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="act" value="resolve_over"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                    <button class="btn btn-small" name="decision" value="accept">Duyệt nhận</button>
                                    <button class="btn btn-secondary btn-small" name="decision" value="return">Trả lại</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($it['shortage_status'] !== 'NONE'): ?><?= View::badge('Thiếu ' . ((int) $it['expected_qty'] - (int) $it['counted_qty']) . ' – ' . ['OPEN' => 'đang mở', 'WAITING' => 'chờ bổ sung', 'CLOSED' => 'đã đóng'][$it['shortage_status']], $it['shortage_status'] === 'CLOSED' ? 'muted' : 'warn') ?>
                            <?= $it['shortage_note'] ? '<br>' . View::e($it['shortage_note']) : '' ?>
                            <?php if (in_array($it['shortage_status'], ['OPEN', 'WAITING'], true)): ?>
                                <form method="post" class="row" style="margin-top:4px"><?= Csrf::field() ?><input type="hidden" name="act" value="resolve_shortage"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                    <select name="status"><option value="WAITING">Chờ bổ sung</option><option value="CLOSED">Đóng</option></select>
                                    <input type="text" name="note" placeholder="Lý do" required><button class="btn btn-secondary btn-small">Lưu</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr class="total"><td colspan="4">TỔNG</td>
                <?php foreach (['expected_qty', 'counted_qty', 'qc_pass_qty', 'qc_fail_qty', 'received_qty', 'putaway_qty'] as $k): ?><td class="num"><?= View::n(array_sum(array_map('intval', array_column($items, $k)))) ?></td><?php endforeach; ?><td colspan="2"></td></tr>
        </table></div>
    </div>

    <div class="card no-print">
        <?php if ($status === 'EXPECTED'): ?>
            <h2>Bước 2 – Check-in xe, dỡ hàng xuống khu nhận</h2>
            <p class="hint">Hàng xuống RECEIVING về mặt vật lý, hệ thống chưa ghi nhận tồn.</p>
            <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="checkin"><button class="btn">Check-in</button></form>
        <?php elseif ($status === 'CHECKED_IN' || $status === 'COUNTED'): ?>
            <h2>Bước 3 – Kiểm đếm theo SKU</h2>
            <p class="hint">Lệch so với ASN phải ghi lý do. Số đếm chưa làm đổi tồn.</p>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="act" value="count">
                <table class="data"><tr><th>SKU</th><th class="num">SL ASN</th><th>Số đếm thực tế</th><th>Lý do chênh lệch</th></tr>
                    <?php foreach ($items as $it): ?><tr><td class="mono"><?= View::e($it['product_sku']) ?></td><td class="num"><?= View::n($it['expected_qty']) ?></td>
                        <td><input class="qty" type="number" min="0" name="counted[<?= (int) $it['id'] ?>]" value="<?= View::e($it['counted_qty'] ?? $it['expected_qty']) ?>" required></td>
                        <td><input type="text" name="reason[<?= (int) $it['id'] ?>]" value="<?= View::e($it['variance_reason']) ?>" placeholder="Bắt buộc nếu lệch"></td></tr><?php endforeach; ?>
                </table><p style="margin-top:10px"><button class="btn">Lưu kiểm đếm</button></p></form>
            <?php if ($status === 'COUNTED'): ?>
                <h2 style="margin-top:18px">Bước 4 – QC: phân loại đạt / lỗi</h2>
                <p class="hint">Hàng lỗi lập phiếu QC, không vào tồn (master location không có khu hàng lỗi) và chờ trả nhà cung cấp.</p>
                <form method="post"><?= Csrf::field() ?><input type="hidden" name="act" value="qc">
                    <table class="data"><tr><th>SKU</th><th class="num">Số đếm</th><th>Số lỗi</th><th>Lý do lỗi</th></tr>
                        <?php foreach ($items as $it): ?><tr><td class="mono"><?= View::e($it['product_sku']) ?></td><td class="num"><?= View::n($it['counted_qty']) ?></td>
                            <td><input class="qty" type="number" min="0" max="<?= (int) $it['counted_qty'] ?>" name="fail[<?= (int) $it['id'] ?>]" value="0"></td>
                            <td><input type="text" name="fail_reason[<?= (int) $it['id'] ?>]" placeholder="Bắt buộc nếu có lỗi"></td></tr><?php endforeach; ?>
                    </table><p style="margin-top:10px"><button class="btn">Hoàn tất QC</button></p></form>
            <?php endif; ?>
        <?php elseif ($status === 'QC_DONE'): ?>
            <h2>Bước 5 – Xác nhận Receiving</h2>
            <p class="hint">Bước DUY NHẤT làm tăng tổng tồn, đúng bằng số thực nhận ĐẠT; hàng vào RECEIVING (chưa khả dụng).</p>
            <?php if ($variance): ?>
                <div class="callout warn">Phiếu có chênh lệch (thiếu / thừa / lỗi) ⇒ cần <strong>Giám sát kho</strong> duyệt khi xác nhận.
                    <?= $actor->isSupervisor() ? '' : ' Bạn đang đăng nhập vai trò Nhân viên kho – đăng nhập bằng tài khoản Giám sát để duyệt.' ?></div>
            <?php endif; ?>
            <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="confirm">
                <?php if ($hasOver): ?><label class="row small"><input type="checkbox" name="accept_over" value="1"> Giám sát duyệt nhận cả phần thừa (mặc định chỉ nhận đúng số ASN, phần thừa chờ quyết định)</label><?php endif; ?>
                <button class="btn"><?= $variance ? 'Duyệt chênh lệch và xác nhận Receiving' : 'Xác nhận Receiving' ?></button></form>
        <?php elseif ($status === 'RECEIVED'): ?>
            <h2>Bước 7–9 – Put-away: quét nguồn → SKU → đích</h2>
            <p class="hint">Gõ hoặc quét mã. Sai location nguồn, SKU, location đích không phải Storage hoặc không khớp task ⇒ bị chặn, không đổi tồn.</p>
        <?php elseif ($status === 'PUTAWAY_DONE'): ?>
            <h2>Bước 10 – Đóng phiếu, đối soát ASN – thực nhận – put-away</h2>
            <?php if ($actor->isSupervisor()): ?>
                <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="close"><input type="text" name="note" placeholder="Ghi chú đóng phiếu (tùy chọn)" style="min-width:320px"><button class="btn">Đóng phiếu</button></form>
            <?php else: ?><div class="callout">Chờ Giám sát kho đóng phiếu.</div><?php endif; ?>
        <?php elseif ($status === 'CLOSED'): ?>
            <h2>Đã đóng phiếu</h2>
            <p>ASN <?= View::n(array_sum(array_map('intval', array_column($items, 'expected_qty')))) ?> · Thực nhận đạt <?= View::n(array_sum(array_map('intval', array_column($items, 'received_qty')))) ?> · Đã put-away <?= View::n(array_sum(array_map('intval', array_column($items, 'putaway_qty')))) ?><?= $r['close_note'] ? ' · ' . View::e($r['close_note']) : '' ?></p>
        <?php endif; ?>
        <?php if (in_array($status, ['EXPECTED', 'CHECKED_IN', 'COUNTED', 'QC_DONE'], true)): ?>
            <form method="post" class="row" style="margin-top:14px" onsubmit="return confirm('Hủy phiếu nhập này?')"><?= Csrf::field() ?><input type="hidden" name="act" value="cancel"><button class="btn btn-secondary btn-small">Hủy phiếu</button></form>
        <?php endif; ?>
    </div>

    <?php if ($tasks): ?>
        <div class="card">
            <h2>Task put-away</h2>
            <div class="table-wrap"><table class="data">
                <tr><th>Task</th><th>SKU</th><th class="num">SL</th><th>Nguồn</th><th>Đích gợi ý</th><th>Trạng thái</th><th class="no-print">Quét xác nhận (nguồn → SKU → đích)</th></tr>
                <?php foreach ($tasks as $t): ?>
                    <tr><td class="mono"><?= View::e($t['task_code']) ?></td><td class="mono"><?= View::e($t['product_sku']) ?></td><td class="num"><?= View::n($t['qty']) ?></td>
                        <td class="mono"><?= View::e($t['source_code']) ?></td><td class="mono"><?= View::e($t['target_code']) ?></td><td><?= View::badge($t['status'], View::statusTone($t['status'])) ?></td>
                        <td class="no-print"><?php if ($t['status'] === 'OPEN'): ?>
                            <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="putaway"><input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                                <input class="scan" type="text" name="scan_source" placeholder="Nguồn" size="10" required>
                                <input class="scan" type="text" name="scan_sku" placeholder="SKU" size="15" required>
                                <input class="scan" type="text" name="scan_target" placeholder="Đích" size="8" required>
                                <button class="btn btn-small">Xác nhận put-away</button></form>
                        <?php endif; ?></td></tr>
                <?php endforeach; ?>
            </table></div>
        </div>
    <?php endif; ?>
    <?php if ($qcRecords): ?>
        <div class="card"><h2>Phiếu QC hàng lỗi (không vào tồn)</h2>
            <table class="data"><tr><th>Mã QC</th><th>SKU</th><th class="num">SL lỗi</th><th>Lý do</th><th>Xử lý</th></tr>
                <?php foreach ($qcRecords as $q): ?><tr><td class="mono"><?= View::e($q['qc_code']) ?></td><td class="mono"><?= View::e($q['product_sku']) ?></td><td class="num"><?= View::n($q['fail_qty']) ?></td><td><?= View::e($q['reason']) ?></td><td>Chờ trả nhà cung cấp</td></tr><?php endforeach; ?>
            </table></div>
    <?php endif; ?>
    <?php
    return;
endif;

$receipts = $svc->receipts();
$products = (new InventoryQuery($pdo))->products();
$shortages = $svc->openShortages();
$prefill = $svc->receiptIdByCode('ASN-DEMO-001') ? [] : ScenarioService::ASN_DEMO_001;
?>
<div class="card">
    <h2>Phiếu nhập (ASN)</h2>
    <div class="table-wrap"><table class="data">
        <tr><th>Mã ASN</th><th>Nhà cung cấp</th><th>Trạng thái</th><th class="num">SL ASN</th><th class="num">Xác nhận nhập</th><th class="num">Đã put-away</th><th>Tạo lúc</th></tr>
        <?php foreach ($receipts as $r): ?>
            <tr><td><a class="mono" href="<?= View::e(View::url('inbound', ['id' => $r['id']])) ?>"><?= View::e($r['receipt_code']) ?></a></td><td><?= View::e($r['supplier_name']) ?></td>
                <td><?= View::badge(ReceivingService::STATUS_LABELS[$r['status']] ?? $r['status'], View::statusTone($r['status'])) ?></td>
                <td class="num"><?= View::n($r['expected_total']) ?></td><td class="num"><?= View::n($r['received_total']) ?></td><td class="num"><?= View::n($r['putaway_total']) ?></td>
                <td class="small"><?= View::e($r['created_at']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$receipts): ?><tr><td colspan="7" class="muted">Chưa có phiếu nhập.</td></tr><?php endif; ?>
    </table></div>
</div>

<?php if ($shortages): ?>
    <div class="card"><h2>Phần thiếu đang theo dõi (E1)</h2>
        <table class="data"><tr><th>Phiếu</th><th>SKU</th><th class="num">Thiếu</th><th>Trạng thái</th><th>Ghi chú</th></tr>
            <?php foreach ($shortages as $s): ?><tr><td><a class="mono" href="<?= View::e(View::url('inbound', ['id' => $s['receipt_id']])) ?>"><?= View::e($s['receipt_code']) ?></a></td><td class="mono"><?= View::e($s['product_sku']) ?></td><td class="num"><?= View::n($s['shortage_qty']) ?></td><td><?= View::e($s['shortage_status']) ?></td><td><?= View::e($s['shortage_note']) ?></td></tr><?php endforeach; ?>
        </table></div>
<?php endif; ?>

<div class="card no-print">
    <h2>Bước 1 – Tạo ASN dự kiến</h2>
    <p class="hint">ASN/PO chỉ là số lượng dự kiến, không làm thay đổi tồn (A2-R5). Location đích mặc định theo nhóm hàng: Áo → A-01, Quần → B-01.</p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="act" value="create">
        <div class="form-grid">
            <label class="field">Mã ASN<input type="text" name="code" value="<?= $prefill ? 'ASN-DEMO-001' : '' ?>" required></label>
            <label class="field">Nhà cung cấp<input type="text" name="supplier" value="<?= View::e(ScenarioService::SUPPLIER) ?>"></label>
        </div>
        <table class="data" style="margin-top:10px"><tr><th>SKU</th><th>Số lượng ASN</th></tr>
            <?php for ($i = 0; $i < 4; $i++): $line = $prefill[$i] ?? null; ?>
                <tr><td><select name="sku[]"><option value="">–</option><?php foreach ($products as $p): ?><option value="<?= View::e($p['sku']) ?>" <?= $line && $line['sku'] === $p['sku'] ? 'selected' : '' ?>><?= View::e("{$p['sku']} – {$p['name']} {$p['size']}/{$p['color']}") ?></option><?php endforeach; ?></select></td>
                    <td><input class="qty" type="number" min="1" name="qty[]" value="<?= $line ? (int) $line['qty'] : '' ?>"></td></tr>
            <?php endfor; ?>
        </table>
        <p style="margin-top:10px"><button class="btn">Tạo ASN</button></p>
    </form>
</div>
