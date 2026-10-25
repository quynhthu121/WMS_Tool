<?php
/**
 * Báo cáo tồn kho theo SKU (giá trị = giá × On-hand), đọc từ inventory_balances. Xuất CSV.
 */

use App\Support\View;

$rows = $pdo->query("SELECT p.sku, p.name, p.size, p.color, p.price,
        COALESCE(SUM(b.on_hand), 0) AS on_hand, COALESCE(SUM(b.reserved), 0) AS reserved,
        COALESCE(SUM(IF(l.is_allocatable = 1, b.on_hand - b.reserved, 0)), 0) AS available
    FROM products p LEFT JOIN inventory_balances b ON b.product_sku = p.sku LEFT JOIN warehouse_locations l ON l.id = b.location_id
    WHERE p.is_legacy = 0 GROUP BY p.id ORDER BY p.sku")->fetchAll();
$totalValue = array_sum(array_map(fn ($r) => (float) $r['price'] * (int) $r['on_hand'], $rows));

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=BaoCao_TonKho_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['BÁO CÁO TỒN KHO – DEMO, dữ liệu giả lập']);
    fputcsv($out, ['Thời gian xuất', date('d/m/Y H:i:s')]);
    fputcsv($out, ['SKU', 'Tên hàng', 'Size', 'Màu', 'On-hand', 'Reserved', 'Available', 'Giá', 'Giá trị tồn']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['sku'], $r['name'], $r['size'], $r['color'], $r['on_hand'], $r['reserved'], $r['available'], $r['price'], (float) $r['price'] * (int) $r['on_hand']]);
    }
    fclose($out);
    exit;
}
?>
<div class="grid grid-4" style="margin-bottom:18px">
    <div class="kpi"><div class="label">Giá trị tồn (giá × On-hand)</div><div class="value"><?= View::n($totalValue) ?></div><div class="note">VNĐ – giá mô phỏng</div></div>
    <div class="kpi"><div class="label">Số SKU demo</div><div class="value"><?= count($rows) ?></div></div>
</div>
<div class="card">
    <div class="row"><h2 style="margin:0">Tồn kho theo SKU</h2><span class="spacer"></span><a class="btn btn-secondary btn-small" href="<?= View::e(View::url('reports', ['export' => 'csv'])) ?>">Xuất CSV</a></div>
    <div class="table-wrap"><table class="data" style="margin-top:10px">
        <tr><th>SKU</th><th>Tên hàng</th><th class="num">On-hand</th><th class="num">Reserved</th><th class="num">Available</th><th class="num">Giá</th><th class="num">Giá trị tồn</th><th>Cảnh báo</th></tr>
        <?php foreach ($rows as $r): ?>
            <tr><td class="mono"><?= View::e($r['sku']) ?></td><td><?= View::e("{$r['name']} {$r['size']}/{$r['color']}") ?></td>
                <td class="num"><?= View::n($r['on_hand']) ?></td><td class="num"><?= View::n($r['reserved']) ?></td><td class="num"><?= View::n($r['available']) ?></td>
                <td class="num"><?= View::n($r['price']) ?></td><td class="num"><?= View::n((float) $r['price'] * (int) $r['on_hand']) ?></td>
                <td><?= (int) $r['available'] <= 10 ? View::badge('Khả dụng thấp (≤ 10)', 'warn') : '' ?></td></tr>
        <?php endforeach; ?>
    </table></div>
</div>
