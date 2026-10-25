<?php
/**
 * Chạy kịch bản demo từ đầu đến cuối, in bảng Trước / Sau / Kỳ vọng, thoát mã ≠ 0 nếu có số không khớp.
 *
 *   php bin/demo.php main            # Kịch bản 1 (Inbound) rồi nối tiếp Kịch bản 2 (Outbound + Audit): 305 → 350 → 318
 *   php bin/demo.php after_inbound   # Kịch bản 2 từ preset after_inbound: 350 → 318
 *   php bin/demo.php exception       # Kịch bản 3 độc lập (A4 §3.3): 315 → 261
 *   php bin/demo.php main --db=login_test
 *
 * Reset dữ liệu demo trước khi chạy: chỉ được phép trên DB *_test hoặc khi DEMO_RESET_ENABLED=1.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\DemoRunner;
use App\Service\DemoScenarios;
use App\Service\EvidenceService;
use App\Service\ReconciliationService;
use App\Support\Db;

$preset = $argv[1] ?? '';
$options = getopt('', ['db:']);
$plans = [
    'main' => [['inbound', false], ['outbound_audit', true]],
    'after_inbound' => [['outbound_audit', false]],
    'exception' => [['exception', false]],
];
if (!isset($plans[$preset])) {
    fwrite(STDERR, "Cách dùng: php bin/demo.php main|after_inbound|exception [--db=login_test]\n");
    exit(2);
}

try {
    $pdo = Db::connect($options['db'] ?? null);
    $runner = new DemoRunner($pdo, dirname(__DIR__));
    $failures = 0;
    foreach ($plans[$preset] as [$key, $continue]) {
        $scenario = DemoScenarios::get($key);
        $runner->start($key, $continue);
        $cols = DemoScenarios::COLUMN_LABELS[$scenario['columns']];
        echo "\n=== {$scenario['title']}\n    Nguồn: {$scenario['doc']} | DB: " . Db::databaseName($pdo) . "\n";
        printf("    %-4s %-62s %-30s %-30s %s\n", '#', 'Bước', 'Sau (' . implode('/', $cols) . ')', 'Kỳ vọng', '');
        foreach ($runner->runAll() as $r) {
            $fmt = fn (array $v) => implode(' / ', array_map('strval', $v));
            printf("    %-4d %-62s %-30s %-30s %s\n", $r['index'] + 1, mb_strimwidth($r['label'], 0, 60, '…'), $fmt($r['after']), $fmt($r['expect']), $r['ok'] ? '✓' : '✗');
            if (!$r['ok']) {
                $failures++;
                echo "         → {$r['message']}\n";
            }
        }
    }
    $mismatch = ReconciliationService::pairMismatches($pdo);
    $totals = (new ReconciliationService($pdo))->totals();
    printf("\nĐối soát cặp: %s | Công thức toàn kho: %d + %d − %d + %d − %d = %d (thực tế %d) %s\n",
        $mismatch ? '✗ ' . count($mismatch) . ' cặp lệch' : '✓ mọi cặp khớp ledger',
        $totals['opening'], $totals['received'], $totals['shipped'], $totals['adjust_plus'], $totals['adjust_minus'],
        $totals['expected'], $totals['actual'], $totals['ok'] ? '✓' : '✗');
    echo "Bằng chứng:\n";
    foreach ((new EvidenceService($pdo))->all() as $e) {
        $mark = ['pass' => '✓', 'fail' => '✗', 'nodata' => '–'][$e['status']];
        echo "  {$mark} {$e['no']}. {$e['title']}: {$e['summary']}\n";
        if ($e['status'] === 'fail') {
            $failures++;
        }
    }
    $failures += count($mismatch) + ($totals['ok'] ? 0 : 1);
    echo $failures === 0 ? "\nKẾT QUẢ: mọi số khớp tài liệu.\n" : "\nKẾT QUẢ: {$failures} điểm không khớp.\n";
    exit($failures === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Lỗi: ' . $e->getMessage() . "\n");
    exit(1);
}
