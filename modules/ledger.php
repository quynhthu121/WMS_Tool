<?php
/**
 * Stock Movement ledger toàn kho: lọc theo SKU, loại, chứng từ, thời gian; xuất CSV;
 * LOAD + SHIP gộp thành "Shipping" (bung chi tiết được). Ledger chỉ-INSERT.
 */

use App\Service\InventoryQuery;
use App\Service\LedgerService;
use App\Support\View;

$ledger = new LedgerService($pdo);
$filters = [
    'sku' => trim((string) ($_GET['sku'] ?? '')),
    'type' => trim((string) ($_GET['type'] ?? '')),
    'reference' => trim((string) ($_GET['reference'] ?? '')),
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to' => trim((string) ($_GET['to'] ?? '')),
];
$rows = $ledger->movements($filters, 2000);

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=stock_movements_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['DEMO – dữ liệu giả lập (SIMULATION)']);
    fputcsv($out, ['id', 'thoi_diem', 'loai', 'nhom_hien_thi', 'sku', 'so_luong', 'reserved_di_kem', 'doi_tong_ton', 'tu', 'den', 'chung_tu', 'ghi_chu', 'nguoi_thuc_hien']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['created_at'], $r['movement_type'], in_array($r['movement_type'], ['LOAD', 'SHIP'], true) ? 'Shipping' : LedgerService::label($r['movement_type']),
            $r['product_sku'], $r['qty'], $r['reserved_moved'], $r['changes_on_hand'] ? 'co' : 'khong',
            $r['from_code'] ?? '(ngoai kho)', $r['to_code'] ?? '(ngoai kho)', $r['reference_code'], $r['note'], $r['user_name']]);
    }
    fclose($out);
    exit;
}

$grouped = $ledger->groupShipping($rows);
$products = (new InventoryQuery($pdo))->products();
$types = LedgerService::TYPE_LABELS;
unset($types['LOAD'], $types['SHIP']);
?>
<div class="card">
    <form method="get" class="form-grid">
        <input type="hidden" name="page" value="ledger">
        <label class="field">SKU<select name="sku"><option value="">– Mọi SKU –</option><?php foreach ($products as $p): ?><option value="<?= View::e($p['sku']) ?>" <?= $p['sku'] === $filters['sku'] ? 'selected' : '' ?>><?= View::e($p['sku']) ?></option><?php endforeach; ?></select></label>
        <label class="field">Loại movement<select name="type"><option value="">– Mọi loại –</option><?php foreach ($types as $k => $label): ?><option value="<?= View::e($k) ?>" <?= $k === $filters['type'] ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></label>
        <label class="field">Chứng từ<input type="text" name="reference" value="<?= View::e($filters['reference']) ?>" placeholder="ASN-DEMO-001, ORD-…"></label>
        <label class="field">Từ ngày<input type="date" name="from" value="<?= View::e($filters['from']) ?>"></label>
        <label class="field">Đến ngày<input type="date" name="to" value="<?= View::e($filters['to']) ?>"></label>
        <div class="row"><button class="btn">Lọc</button>
            <a class="btn btn-secondary" href="<?= View::e(View::url('ledger', array_filter($filters) + ['export' => 'csv'])) ?>">Xuất CSV</a></div>
    </form>
</div>
<div class="card">
    <h2>Stock Movement (<?= count($rows) ?> dòng)</h2>
    <p class="hint">Bảng chỉ cho phép INSERT (trigger chặn UPDATE/DELETE). Dòng tô xanh làm đổi tổng tồn (OPENING, RECEIVE, SHIP, ADJUST). Số trong ngoặc là Reservation – không di chuyển hàng.</p>
    <div class="table-wrap"><table class="data">
        <tr><th>#</th><th>Thời điểm</th><th>Loại</th><th>SKU</th><th>Từ → Đến</th><th class="num">SL</th><th class="num">Reserved đi kèm</th><th>Chứng từ</th><th>Ghi chú</th><th>Người thực hiện</th></tr>
        <?php foreach ($grouped as $m): ?>
            <tr class="<?= $m['changes_on_hand'] ? 'highlight' : '' ?>">
                <td><?= (int) $m['id'] ?></td><td class="small"><?= View::e(substr((string) $m['created_at'], 0, 19)) ?></td>
                <td><?= View::e(LedgerService::label($m['movement_type'])) ?></td><td class="mono"><?= View::e($m['product_sku']) ?></td>
                <td class="mono small"><?= View::e(LedgerService::route($m)) ?>
                    <?php if (count($m['children']) > 1): ?><details><summary>bung chi tiết</summary><?php foreach ($m['children'] as $c): ?><div>#<?= (int) $c['id'] ?> <?= View::e($c['movement_type']) ?>: <?= View::e($c['from_code'] ?? '(ngoài kho)') ?> → <?= View::e($c['to_code'] ?? '(ngoài kho)') ?></div><?php endforeach; ?></details><?php endif; ?></td>
                <td class="num"><?= in_array($m['movement_type'], ['RESERVE', 'UNRESERVE'], true) ? '(' . View::n($m['qty']) . ')' : View::n($m['qty']) ?></td>
                <td class="num"><?= View::n($m['reserved_moved']) ?></td>
                <td class="mono small"><?= View::e($m['reference_code']) ?></td><td class="small"><?= View::e($m['note']) ?></td><td class="small"><?= View::e($m['user_name'] ?? 'Kịch bản/hệ thống') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$grouped): ?><tr><td colspan="10" class="muted">Không có dòng nào.</td></tr><?php endif; ?>
    </table></div>
</div>
