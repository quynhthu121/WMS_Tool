<?php
/**
 * Bằng chứng: 6 kết quả prototype phải chứng minh (foundation mục 6), tính trực tiếp từ dữ liệu hiện tại.
 */

use App\Service\EvidenceService;
use App\Support\View;

$results = (new EvidenceService($pdo))->all();
$passed = count(array_filter($results, fn ($r) => $r['status'] === 'pass'));
?>
<div class="card">
    <h2>North Star</h2>
    <p>Tồn kho hiện tại phải được giải thích bằng lịch sử giao dịch và vị trí hàng – không chỉ hiển thị một con số tổng.
        Trang này kiểm tra trực tiếp trên dữ liệu đang có (<?= $passed ?>/6 đạt). Chạy các kịch bản ở <a href="<?= View::e(View::url('demo')) ?>">Bảng điều khiển Demo</a> để có đủ dữ liệu cho cả 6 kết quả.</p>
</div>
<?php foreach ($results as $r): ?>
    <div class="card">
        <div class="row"><h2 style="margin:0"><?= View::mark($r['status'] === 'nodata' ? null : $r['status'] === 'pass') ?> <?= (int) $r['no'] ?>. <?= View::e($r['title']) ?></h2></div>
        <p style="margin-top:6px"><?= View::e($r['summary']) ?></p>
        <?php if ($r['details']): ?>
            <details <?= $r['status'] === 'fail' ? 'open' : '' ?>><summary class="small">Chi tiết (<?= count($r['details']) ?>)</summary>
                <ul style="margin:6px 0 0 18px" class="small"><?php foreach ($r['details'] as $d): ?><li><?= View::e($d) ?></li><?php endforeach; ?></ul></details>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
