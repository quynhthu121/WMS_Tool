<?php
/**
 * Bảng điều khiển Demo: preset / Reset, chế độ trình diễn từng bước (3 kịch bản), kích hoạt ngoại lệ E1–E4.
 * Mỗi bước gọi service thật; bảng Trước / Sau / Kỳ vọng theo tài liệu / ✓✗.
 */

use App\Service\DemoRunner;
use App\Service\DemoScenarios;
use App\Service\DemoService;
use App\Service\ScenarioService;
use App\Support\Csrf;
use App\Support\View;

$runner = new DemoRunner($pdo, dirname(__DIR__));
$demo = $runner->demo();
$resetAllowed = DemoService::resetAllowed($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    $back = View::url('demo');
    View::handle(function () use ($act, $runner, $demo, $pdo, $actor) {
        if (in_array($act, ['reset', 'start', 'exception'], true) && !$actor->isSupervisor()) {
            throw new \App\Service\BusinessRuleException('Reset và chọn kịch bản cần vai trò Giám sát kho.');
        }
        switch ($act) {
            case 'reset':
                $demo->reset((string) $_POST['preset']);
                return 'Đã reset dữ liệu giao dịch và nạp preset "' . DemoService::PRESETS[$_POST['preset']] . '".';
            case 'start':
                $runner->start((string) $_POST['scenario'], ($_POST['mode'] ?? '') === 'continue');
                return 'Đã chuẩn bị kịch bản. Bấm "Bước tiếp theo" để chạy từng bước.';
            case 'next':
                $r = $runner->next();
                if ($r['error']) {
                    throw new \App\Service\BusinessRuleException('Bước bị từ chối: ' . $r['message']);
                }
                return ($r['ok'] ? '✓ ' : '✗ ') . $r['label'];
            case 'all':
                $results = $runner->runAll();
                $bad = count(array_filter($results, fn ($r) => !$r['ok']));
                return 'Đã chạy ' . count($results) . ' bước, ' . ($bad ? "{$bad} bước không khớp." : 'tất cả khớp tài liệu.');
            case 'exception':
                $demo->reset('main');
                $id = ScenarioService::prepareInboundException($pdo, (string) $_POST['case']);
                return [ScenarioService::INBOUND_EXCEPTIONS[$_POST['case']] . ': đã dựng sẵn ASN-DEMO-001. Tiếp tục thao tác trên phiếu này.', View::url('inbound', ['id' => $id])];
        }
        return '';
    }, $back);
}

$state = $runner->state();
$scenario = $state['scenario'];
$preset = $demo->currentPreset();
?>
<?php if (!$resetAllowed): ?>
    <div class="callout warn">Reset đang tắt trên database này. Đặt <code>DEMO_RESET_ENABLED=1</code> trong <code>.env</code> rồi <code>docker compose up -d</code> để bật Reset và stepper (chỉ xóa dữ liệu giao dịch WMS; không đụng tài khoản, đối tác, bảng kế thừa).</div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <h2>Preset dữ liệu</h2>
        <p class="hint">Preset hiện tại: <strong><?= View::e($preset ? DemoService::PRESETS[$preset] : 'chưa nạp') ?></strong></p>
        <form method="post" onsubmit="return confirm('Xóa toàn bộ dữ liệu giao dịch WMS và nạp lại preset?')"><?= Csrf::field() ?><input type="hidden" name="act" value="reset">
            <?php foreach (DemoService::PRESETS as $k => $label): ?>
                <label class="row small" style="margin-bottom:6px"><input type="radio" name="preset" value="<?= View::e($k) ?>" <?= $k === ($preset ?? 'main') ? 'checked' : '' ?>> <strong class="mono"><?= View::e($k) ?></strong> – <?= View::e($label) ?></label>
            <?php endforeach; ?>
            <button class="btn btn-danger" <?= $resetAllowed ? '' : 'disabled' ?>>Reset về đầu kỳ</button>
        </form>
    </div>
    <div class="card">
        <h2>Ngoại lệ Inbound (A2 §3)</h2>
        <p class="hint">Reset preset main rồi dựng sẵn ASN-DEMO-001 tới điểm cần trình diễn. E1–E3: bấm "Xác nhận Receiving" bằng tài khoản Nhân viên (bị chặn) rồi Giám sát (duyệt). E4: gõ sai location đích ở task put-away.</p>
        <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="exception">
            <?php foreach (ScenarioService::INBOUND_EXCEPTIONS as $k => $label): ?>
                <button class="btn btn-secondary btn-small" name="case" value="<?= View::e($k) ?>" title="<?= View::e($label) ?>" <?= $resetAllowed ? '' : 'disabled' ?>><?= View::e($k) ?></button>
            <?php endforeach; ?></form>
        <ul class="small" style="margin:8px 0 0 18px"><?php foreach (ScenarioService::INBOUND_EXCEPTIONS as $label): ?><li><?= View::e($label) ?></li><?php endforeach; ?></ul>
    </div>
</div>

<div class="card">
    <h2>Chế độ trình diễn từng bước</h2>
    <div class="table-wrap"><table class="data">
        <tr><th>Kịch bản</th><th>Nguồn</th><th>Dữ liệu đầu</th><th></th></tr>
        <?php foreach (DemoScenarios::all() as $key => $s): ?>
            <tr><td><strong><?= View::e($s['title']) ?></strong></td><td class="small"><?= View::e($s['doc']) ?></td><td class="mono small"><?= View::e($s['preset']) ?></td>
                <td><form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="act" value="start"><input type="hidden" name="scenario" value="<?= View::e($key) ?>">
                    <button class="btn btn-small" name="mode" value="preset" <?= $resetAllowed ? '' : 'disabled' ?>>Bắt đầu (reset <?= View::e($s['preset']) ?>)</button>
                    <?php if (isset($s['continues'])): ?><button class="btn btn-secondary btn-small" name="mode" value="continue" <?= $state['completed'] === $s['continues'] ? '' : 'disabled title="Chạy xong Kịch bản 1 trước"' ?>>Nối tiếp Kịch bản 1</button><?php endif; ?>
                </form></td></tr>
        <?php endforeach; ?>
    </table></div>
</div>

<?php if ($scenario): $cols = DemoScenarios::COLUMN_LABELS[$scenario['columns']]; ?>
    <div class="card stepper">
        <div class="row"><h2 style="margin:0"><?= View::e($scenario['title']) ?></h2><span class="spacer"></span>
            <span class="small muted"><?= $state['mode'] === 'continue' ? 'Nối tiếp sau Kịch bản 1' : 'Bắt đầu từ preset ' . View::e($scenario['preset']) ?> · <?= View::e($scenario['doc']) ?></span></div>
        <p class="hint">Cột: <?= View::e(implode(' / ', $cols)) ?><?= $scenario['columns'] === 'sku' ? ' (SKU ' . DemoScenarios::SKU . '; cột cuối là tổng tồn toàn kho)' : '' ?>. Mỗi bước gọi service thật rồi đo lại từ database.</p>
        <div class="table-wrap"><table class="data">
            <tr><th>#</th><th>Bước</th><th>Thực hiện</th><th>Trước</th><th>Sau</th><th>Kỳ vọng theo tài liệu</th><th>Khớp</th><th>Diễn giải</th></tr>
            <tr class="dim"><td>0</td><td>Trạng thái đầu</td><td></td><td></td><td class="cmp mono"><?= View::e(implode(' / ', $scenario['initial'])) ?></td><td class="cmp mono"><?= View::e(implode(' / ', $scenario['initial'])) ?></td><td></td><td></td></tr>
            <?php foreach ($state['log'] as $r): ?>
                <tr class="<?= $r['ok'] ? '' : 'bad' ?>"><td><?= $r['index'] + 1 ?></td><td><?= View::e($r['label']) ?></td><td class="small"><?= View::e($r['role']) ?></td>
                    <td class="cmp mono"><?= View::e(implode(' / ', $r['before'])) ?></td><td class="cmp mono"><strong><?= View::e(implode(' / ', $r['after'])) ?></strong></td>
                    <td class="cmp mono"><?= View::e(implode(' / ', $r['expect'])) ?></td><td><?= View::mark($r['ok'] && $r['reconciled']) ?></td><td class="small"><?= View::e($r['message']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$state['done']): $next = $scenario['steps'][$state['step']]; ?>
                <tr class="next"><td><?= $state['step'] + 1 ?></td><td><?= View::e($next['label']) ?></td><td class="small"><?= View::e($next['role']) ?></td><td colspan="2" class="muted">chưa chạy</td>
                    <td class="cmp mono"><?= View::e(implode(' / ', $next['expect'])) ?></td><td></td><td></td></tr>
            <?php endif; ?>
        </table></div>
        <div class="row" style="margin-top:12px">
            <?php if (!$state['done']): ?>
                <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="act" value="next"><button class="btn">Bước tiếp theo ▶</button></form>
                <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="act" value="all"><button class="btn btn-secondary">Chạy hết các bước còn lại</button></form>
            <?php else: ?>
                <span><?= View::badge('Đã chạy xong ' . count($state['log']) . ' bước', 'ok') ?></span>
                <a class="btn btn-secondary btn-small" href="<?= View::e(View::url('reconcile')) ?>">Mở Đối soát</a>
                <a class="btn btn-secondary btn-small" href="<?= View::e(View::url('evidence')) ?>">Mở Bằng chứng</a>
                <a class="btn btn-secondary btn-small" href="<?= View::e(View::url('inventory', ['ledger' => DemoScenarios::SKU])) ?>">Ledger <?= DemoScenarios::SKU ?></a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
