<?php
/**
 * Outbound (A3): Order Created → Reservation (FIFO) → Picking → Packing → Shipping, kèm hủy đơn và pick thiếu.
 */

use App\Service\InventoryQuery;
use App\Service\OutboundService;
use App\Support\Csrf;
use App\Support\View;

$svc = new OutboundService($pdo);
$query = new InventoryQuery($pdo);
$id = (int) ($_GET['id'] ?? 0);
$back = $id ? View::url('outbound', ['id' => $id]) : View::url('outbound');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    View::handle(function () use ($act, $svc, $query, $id, $actor) {
        switch ($act) {
            case 'create':
                $lines = [];
                foreach ((array) ($_POST['sku'] ?? []) as $i => $sku) {
                    $lines[] = ['sku' => trim((string) $sku), 'qty' => (int) ($_POST['qty'][$i] ?? 0)];
                }
                $newId = $svc->createOrder((string) $_POST['code'], (string) $_POST['channel'], (string) $_POST['address'], $lines, $actor);
                return ['Đã tạo đơn (CREATED). Chưa thay đổi tồn kho.', View::url('outbound', ['id' => $newId])];
            case 'reserve':
                $before = [];
                foreach ($svc->lines($id) as $l) {
                    $before[$l['product_sku']] = $query->skuTotals($l['product_sku'])['available'];
                }
                $alloc = $svc->reserve($id, $actor);
                $parts = [];
                foreach ($alloc as $a) {
                    $parts[] = "{$a['sku']}: giữ {$a['qty']} tại {$a['location']}";
                }
                $avail = [];
                foreach ($before as $sku => $b) {
                    $avail[] = "{$sku} Available {$b} → " . $query->skuTotals($sku)['available'];
                }
                return 'Reservation (FIFO): ' . implode('; ', $parts) . '. ' . implode('; ', $avail) . '. On-hand không đổi.';
            case 'picklist':
                $svc->createPickList($id, $actor);
                return 'Đã tạo Pick List.';
            case 'pick':
                $r = $svc->confirmPick((int) $_POST['line_id'], (string) $_POST['scan_location'], (string) $_POST['scan_sku'], (int) $_POST['actual'], $actor);
                return $r['short'] > 0
                    ? "Đã lấy {$r['picked']}, THIẾU {$r['short']}: hệ thống khóa phân bổ cặp này và tự tạo phiếu kiểm kê. Đơn giữ trạng thái PICKING + cờ ngoại lệ."
                    : "Đã lấy {$r['picked']} → PICK-STG. On-hand không đổi (chỉ đổi location).";
            case 'reallocate':
                $r = $svc->reallocate($id, $actor);
                $parts = array_map(fn ($a) => "{$a['qty']} tại {$a['location']}", $r['reserved']);
                return 'Phân bổ lại: ' . ($parts ? implode(', ', $parts) : 'không có') . ($r['backorder'] ? ". Backorder {$r['backorder']} (không tạo tồn ảo)." : '.');
            case 'accept_backorder':
                $svc->acceptBackorder($id, $actor);
                return 'Điều phối chấp nhận giao thiếu phần backorder.';
            case 'pack_in':
                $svc->packIn($id, $actor);
                return 'Packing (1): PICK-STG → PACK-01.';
            case 'pack_out':
                $scans = [];
                foreach ((array) ($_POST['scan_qty'] ?? []) as $sku => $qty) {
                    $scans[(string) $sku] = (int) $qty;
                }
                $t = $svc->packOut($id, $scans, $actor);
                return "Packing (2): quét đối soát khớp, Tracking ID {$t}, hàng ra OUT-STG.";
            case 'ship':
                $svc->ship($id, (string) $_POST['scan_tracking'], $actor);
                return 'Đã xuất kho: On-hand và Reserved giảm đúng một lần; đã ghi sự kiện báo OMS.';
            case 'cancel':
                $svc->cancel($id, (string) $_POST['reason'], $actor);
                return 'Đã hủy đơn: hoàn Reserved, hàng đã pick trả về location gốc.';
        }
        return '';
    }, $back);
}

$flow = ['CREATED', 'RESERVED', 'PICKING', 'PICKED', 'PACKING', 'PACKED', 'SHIPPED'];

if ($id):
    $o = $svc->order($id);
    if (!$o) {
        echo '<div class="flash flash-error">Không tìm thấy đơn.</div>';
        return;
    }
    $lines = $svc->lines($id);
    $reservations = $svc->reservations($id);
    $pickLines = $svc->pickLines($id);
    $openPick = array_filter($pickLines, fn ($l) => $l['status'] === 'OPEN');
    $status = $o['status'];
    $print = (string) ($_GET['print'] ?? '');

    if ($print === 'label' && $o['tracking_id']): ?>
        <p class="no-print"><a href="<?= View::e($back) ?>">← Quay lại đơn</a> · <button class="btn btn-small" onclick="window.print()">In nhãn</button></p>
        <div class="label-box">
            <div><strong>NHÃN VẬN ĐƠN – DEMO (mô phỏng)</strong></div>
            <div class="trk"><?= View::e($o['tracking_id']) ?></div>
            <div>Đơn: <strong><?= View::e($o['order_code']) ?></strong></div>
            <div>Kênh: <?= View::e($o['channel']) ?></div>
            <div>Giao tới: <?= View::e($o['ship_address']) ?></div>
            <div style="margin-top:8px">
                <?php foreach ($lines as $l): ?><div><?= View::e($l['product_sku']) ?> × <?= View::n($l['packed_qty']) ?></div><?php endforeach; ?>
            </div>
            <div class="small" style="margin-top:8px">Từ: Kho demo (mô phỏng) – không có thật</div>
        </div>
        <?php return;
    endif;
    if ($print === 'picklist'): ?>
        <p class="no-print"><a href="<?= View::e($back) ?>">← Quay lại đơn</a> · <button class="btn btn-small" onclick="window.print()">In Pick List</button></p>
        <div class="card"><h2>PICK LIST – <?= View::e($o['order_code']) ?> (demo)</h2>
            <table class="data"><tr><th>Pick List</th><th>Location</th><th>SKU</th><th class="num">SL cần lấy</th><th>Thực lấy</th><th>Ký</th></tr>
                <?php foreach ($openPick as $l): ?><tr><td class="mono"><?= View::e($l['pick_code']) ?></td><td class="mono"><?= View::e($l['location_code']) ?></td><td class="mono"><?= View::e($l['product_sku']) ?></td><td class="num"><?= View::n($l['qty']) ?></td><td>______</td><td>______</td></tr><?php endforeach; ?>
            </table></div>
        <?php return;
    endif;
    ?>
    <p class="no-print"><a href="<?= View::e(View::url('outbound')) ?>">← Danh sách đơn</a></p>
    <div class="card">
        <div class="row"><h2>Đơn xuất <span class="mono"><?= View::e($o['order_code']) ?></span></h2>
            <?= View::badge(OutboundService::STATUS_LABELS[$status], View::statusTone($status)) ?>
            <?= $o['hold_reason'] ? View::badge('⚠ ' . $o['hold_reason'], 'fail') : '' ?><span class="spacer"></span>
            <span class="small muted"><?= View::e($o['channel']) ?> · <?= View::e($o['ship_address']) ?><?= $o['tracking_id'] ? ' · Tracking <span class="mono">' . View::e($o['tracking_id']) . '</span>' : '' ?></span></div>
        <?php if ($status !== 'CANCELLED'): ?>
            <div class="timeline"><?php $reached = true; foreach ($flow as $s): $cls = $s === $status ? 'current' : ($reached ? 'done' : ''); if ($s === $status) { $reached = false; } ?>
                <span class="st <?= $cls ?>"><?= View::e(OutboundService::STATUS_LABELS[$s]) ?><?php $ts = ['CREATED' => 'created_at', 'RESERVED' => 'reserved_at', 'PICKING' => 'picking_at', 'PICKED' => 'picked_at', 'PACKING' => 'packing_at', 'PACKED' => 'packed_at', 'SHIPPED' => 'shipped_at'][$s]; echo $o[$ts] ? '<br><span class="small">' . View::e(substr($o[$ts], 11, 8)) . '</span>' : ''; ?></span>
            <?php endforeach; ?></div>
        <?php else: ?><div class="callout">Đã hủy: <?= View::e($o['cancel_reason']) ?></div><?php endif; ?>
        <div class="table-wrap"><table class="data" style="margin-top:10px">
            <tr><th>SKU</th><th>Tên hàng</th><th class="num">Yêu cầu</th><th class="num">Đang giữ</th><th class="num">Đã pick</th><th class="num">Đã đóng gói</th><th class="num">Đã xuất</th><th class="num">Backorder</th><th class="num">Available SKU hiện tại</th></tr>
            <?php foreach ($lines as $l): ?><tr><td class="mono"><?= View::e($l['product_sku']) ?></td><td><?= View::e("{$l['product_name']} {$l['size']}/{$l['color']}") ?></td>
                <td class="num"><?= View::n($l['requested_qty']) ?></td><td class="num"><?= View::n($l['reserved_qty']) ?></td><td class="num"><?= View::n($l['picked_qty']) ?></td>
                <td class="num"><?= View::n($l['packed_qty']) ?></td><td class="num"><?= View::n($l['shipped_qty']) ?></td><td class="num"><?= View::n($l['backorder_qty']) ?></td>
                <td class="num"><?= View::n($query->skuTotals($l['product_sku'])['available']) ?></td></tr><?php endforeach; ?>
        </table></div>
    </div>

    <div class="card no-print">
        <?php if ($status === 'CREATED'): ?>
            <h2>Reservation – phân bổ và giữ hàng (FIFO)</h2>
            <p class="hint">Kiểm tra Requested ≤ Available; chỉ giữ tại location Storage chưa bị khóa; chọn cặp có hàng vào sớm nhất trước (hòa thì theo mã location); có thể tách nhiều location.</p>
            <table class="data"><tr><th>SKU</th><th class="num">Yêu cầu</th><th class="num">Available trước</th><th>Kế hoạch FIFO</th><th class="num">Available sau (dự kiến)</th></tr>
                <?php foreach ($lines as $l): $plan = $svc->planFifo($l['product_sku'], (int) $l['requested_qty']); $av = $query->skuTotals($l['product_sku'])['available']; $sum = array_sum(array_column($plan, 'qty')); ?>
                    <tr class="<?= $sum < (int) $l['requested_qty'] ? 'bad' : '' ?>"><td class="mono"><?= View::e($l['product_sku']) ?></td><td class="num"><?= View::n($l['requested_qty']) ?></td><td class="num"><?= View::n($av) ?></td>
                        <td class="mono small"><?= View::e(implode(', ', array_map(fn ($p) => "{$p['location']}: {$p['qty']} (còn {$p['available']})", $plan)) ?: '–') ?></td>
                        <td class="num"><?= $sum < (int) $l['requested_qty'] ? 'Không đủ' : View::n($av - (int) $l['requested_qty']) ?></td></tr>
                <?php endforeach; ?></table>
            <form method="post" class="row" style="margin-top:10px"><?= Csrf::field() ?><input type="hidden" name="act" value="reserve"><button class="btn">Tạo Reservation</button></form>
        <?php elseif ($status === 'RESERVED'): ?>
            <h2>Picking – tạo Pick List</h2>
            <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="picklist"><button class="btn">Tạo Pick List</button></form>
        <?php elseif ($status === 'PICKING'): ?>
            <h2>Picking – quét vị trí + SKU, nhập số thực lấy</h2>
            <?php if ($openPick): ?>
                <p class="hint">Thực lấy &lt; số trên Pick List ⇒ hệ thống kích hoạt Inventory Audit (A3). <a href="<?= View::e(View::url('outbound', ['id' => $id, 'print' => 'picklist'])) ?>">In Pick List</a></p>
                <table class="data"><tr><th>Pick List</th><th>Location</th><th>SKU</th><th class="num">Cần lấy</th><th>Quét & xác nhận</th></tr>
                    <?php foreach ($openPick as $l): ?><tr><td class="mono"><?= View::e($l['pick_code']) ?></td><td class="mono"><?= View::e($l['location_code']) ?></td><td class="mono"><?= View::e($l['product_sku']) ?></td><td class="num"><?= View::n($l['qty']) ?></td>
                        <td><form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="pick"><input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>">
                            <input class="scan" type="text" name="scan_location" placeholder="Location" size="9" required>
                            <input class="scan" type="text" name="scan_sku" placeholder="SKU" size="15" required>
                            <input class="qty" type="number" name="actual" min="0" max="<?= (int) $l['qty'] ?>" value="<?= (int) $l['qty'] ?>" title="Số thực lấy">
                            <button class="btn btn-small">Xác nhận pick</button></form></td></tr><?php endforeach; ?>
                </table>
            <?php elseif ($o['hold_reason'] && str_starts_with((string) $o['hold_reason'], 'Backorder')): ?>
                <div class="callout warn"><?= View::e($o['hold_reason']) ?></div>
                <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="accept_backorder"><button class="btn btn-warn">Chấp nhận giao thiếu, tiếp tục đóng gói</button></form>
            <?php elseif ($o['hold_reason']): ?>
                <div class="callout fail">Pick thiếu: <?= View::e($o['hold_reason']) ?>. Hoàn tất phiếu kiểm kê ở trang <a href="<?= View::e(View::url('audit')) ?>">Kiểm kê</a> (đếm, đếm lại, Giám sát duyệt), rồi phân bổ lại phần thiếu từ location khác.</div>
                <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="reallocate"><button class="btn">Phân bổ lại phần thiếu (FIFO)</button></form>
            <?php endif; ?>
        <?php elseif ($status === 'PICKED'): ?>
            <h2>Packing (1) – chuyển hàng đến bàn đóng gói</h2>
            <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="pack_in"><button class="btn">PICK-STG → PACK-01</button></form>
        <?php elseif ($status === 'PACKING'): ?>
            <h2>Packing (2) – quét SKU đối soát, đóng thùng, sinh Tracking ID</h2>
            <p class="hint">Nhập số lượng quét được cho từng SKU; phải đúng SKU và đủ số lượng của đơn.</p>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="act" value="pack_out">
                <table class="data"><tr><th>SKU</th><th class="num">Cần</th><th>Số lượng quét</th></tr>
                    <?php foreach ($lines as $l): if ((int) $l['picked_qty'] <= 0) { continue; } ?><tr><td class="mono"><?= View::e($l['product_sku']) ?></td><td class="num"><?= View::n($l['picked_qty']) ?></td>
                        <td><input class="qty" type="number" min="0" name="scan_qty[<?= View::e($l['product_sku']) ?>]" value="0"></td></tr><?php endforeach; ?></table>
                <p style="margin-top:10px"><button class="btn">Đối soát & chuyển OUT-STG</button></p></form>
        <?php elseif ($status === 'PACKED'): ?>
            <h2>Shipping – quét mã vận đơn tại cửa xuất (DOCK)</h2>
            <p class="hint"><a href="<?= View::e(View::url('outbound', ['id' => $id, 'print' => 'label'])) ?>">In nhãn vận đơn</a>. Xác nhận ship: OUT-STG → DOCK → ra khỏi kho trong một giao dịch.</p>
            <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="ship">
                <input class="scan" type="text" name="scan_tracking" placeholder="Quét Tracking ID" size="26" required><button class="btn">Xác nhận xuất kho</button></form>
        <?php elseif ($status === 'SHIPPED'): ?>
            <h2>Đã xuất kho</h2><p>Đơn hoàn tất; đã gửi tín hiệu cập nhật về OMS (oms_outbox). <a href="<?= View::e(View::url('outbound', ['id' => $id, 'print' => 'label'])) ?>">Xem nhãn</a></p>
        <?php endif; ?>
        <?php if (!in_array($status, ['SHIPPED', 'CANCELLED'], true)): ?>
            <form method="post" class="row" style="margin-top:14px" onsubmit="return confirm('Hủy đơn này? Reserved sẽ được hoàn, hàng đã pick trả về location gốc.')"><?= Csrf::field() ?><input type="hidden" name="act" value="cancel">
                <input type="text" name="reason" placeholder="Lý do hủy" required><button class="btn btn-secondary btn-small">Hủy đơn</button></form>
        <?php endif; ?>
    </div>

    <?php if ($reservations): ?>
        <div class="card"><h2>Reservation (rule FIFO)</h2>
            <table class="data"><tr><th>#</th><th>SKU</th><th>Location gốc</th><th>Hàng đang ở</th><th class="num">Giữ</th><th class="num">Đã pick</th><th class="num">Đã hủy</th><th>Trạng thái</th></tr>
                <?php foreach ($reservations as $r): ?><tr><td><?= (int) $r['id'] ?></td><td class="mono"><?= View::e($r['product_sku']) ?></td><td class="mono"><?= View::e($r['origin_code']) ?></td><td class="mono"><?= View::e($r['current_code']) ?></td>
                    <td class="num"><?= View::n($r['qty']) ?></td><td class="num"><?= View::n($r['picked_qty']) ?></td><td class="num"><?= View::n($r['released_qty']) ?></td><td><?= View::e($r['status']) ?></td></tr><?php endforeach; ?></table></div>
    <?php endif; ?>
    <?php if ($pickLines): ?>
        <div class="card"><h2>Lịch sử Pick List</h2>
            <table class="data"><tr><th>Pick List</th><th>Location</th><th>SKU</th><th class="num">Cần lấy</th><th class="num">Thực lấy</th><th>Trạng thái</th></tr>
                <?php foreach ($pickLines as $l): ?><tr><td class="mono"><?= View::e($l['pick_code']) ?></td><td class="mono"><?= View::e($l['location_code']) ?></td><td class="mono"><?= View::e($l['product_sku']) ?></td><td class="num"><?= View::n($l['qty']) ?></td>
                    <td class="num"><?= $l['picked_qty'] === null ? '–' : View::n($l['picked_qty']) ?></td><td><?= View::badge($l['status'], View::statusTone($l['status'])) ?></td></tr><?php endforeach; ?></table></div>
    <?php endif; ?>
    <?php $events = $svc->outboxEvents($o['order_code']); if ($events): ?>
        <div class="card"><h2>Sự kiện gửi OMS (oms_outbox – mô phỏng, không gọi dịch vụ ngoài)</h2>
            <table class="data"><tr><th>Thời điểm</th><th>Sự kiện</th><th>Payload</th></tr>
                <?php foreach ($events as $e): ?><tr><td class="small"><?= View::e($e['created_at']) ?></td><td class="mono"><?= View::e($e['event_type']) ?></td><td class="mono small"><?= View::e($e['payload']) ?></td></tr><?php endforeach; ?></table></div>
    <?php endif; ?>
    <?php
    return;
endif;

$orders = $svc->orders();
$products = $query->products();
$hasDemo001 = $svc->orderIdByCode('ORD-DEMO-001') !== null;
?>
<div class="card">
    <h2>Đơn xuất</h2>
    <div class="table-wrap"><table class="data">
        <tr><th>Mã đơn</th><th>Kênh</th><th>Trạng thái</th><th>Cờ ngoại lệ</th><th class="num">Yêu cầu</th><th class="num">Đã xuất</th><th>Tracking</th><th>Tạo lúc</th></tr>
        <?php foreach ($orders as $o): ?>
            <tr><td><a class="mono" href="<?= View::e(View::url('outbound', ['id' => $o['id']])) ?>"><?= View::e($o['order_code']) ?></a></td><td><?= View::e($o['channel']) ?></td>
                <td><?= View::badge(OutboundService::STATUS_LABELS[$o['status']], View::statusTone($o['status'])) ?></td><td class="small"><?= View::e($o['hold_reason']) ?></td>
                <td class="num"><?= View::n($o['requested_total']) ?></td><td class="num"><?= View::n($o['shipped_total']) ?></td><td class="mono small"><?= View::e($o['tracking_id']) ?></td><td class="small"><?= View::e($o['created_at']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="8" class="muted">Chưa có đơn.</td></tr><?php endif; ?>
    </table></div>
</div>
<div class="card no-print">
    <h2>Order Created – tạo đơn xuất</h2>
    <p class="hint">Kiểm tra sơ bộ: SKU tồn tại, số lượng &gt; 0, không trùng SKU. Tạo đơn chưa làm thay đổi tồn kho.</p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="act" value="create">
        <div class="form-grid">
            <label class="field">Mã đơn<input type="text" name="code" value="<?= $hasDemo001 ? '' : 'ORD-DEMO-001' ?>" required></label>
            <label class="field">Kênh<input type="text" name="channel" value="<?= View::e(OutboundService::DEFAULT_CHANNEL) ?>"></label>
            <label class="field">Địa chỉ giao<input type="text" name="address" value="<?= View::e(OutboundService::DEFAULT_ADDRESS) ?>"></label>
        </div>
        <table class="data" style="margin-top:10px"><tr><th>SKU</th><th>Số lượng yêu cầu</th></tr>
            <?php for ($i = 0; $i < 3; $i++): ?>
                <tr><td><select name="sku[]"><option value="">–</option><?php foreach ($products as $p): ?><option value="<?= View::e($p['sku']) ?>" <?= !$hasDemo001 && $i === 0 && $p['sku'] === 'AO-001-M-DEN' ? 'selected' : '' ?>><?= View::e("{$p['sku']} – {$p['name']} {$p['size']}/{$p['color']}") ?></option><?php endforeach; ?></select></td>
                    <td><input class="qty" type="number" min="1" name="qty[]" value="<?= !$hasDemo001 && $i === 0 ? 30 : '' ?>"></td></tr>
            <?php endfor; ?>
        </table>
        <p style="margin-top:10px"><button class="btn">Tạo đơn</button></p>
    </form>
</div>
