<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Actor;
use PDO;

/** Kịch bản dựng sẵn theo tài liệu nguồn, chạy bằng service thật (không ghi dữ liệu cứng). */
final class ScenarioService
{
    /** ASN-DEMO-001 theo A2 §2.1. */
    public const ASN_DEMO_001 = [
        ['sku' => 'AO-001-M-DEN', 'qty' => 20, 'target' => 'A-01'],
        ['sku' => 'AO-002-L-TRG', 'qty' => 10, 'target' => 'A-01'],
        ['sku' => 'QUAN-002-L-DEN', 'qty' => 15, 'target' => 'B-01'],
    ];

    public const SUPPLIER = 'Nhà cung cấp mẫu (mô phỏng)';

    /** Đường đi chuẩn A2: nhận đủ 45, QC đạt 100%, put-away 3 task (dùng cho preset after_inbound). */
    public static function runInboundHappyPath(PDO $pdo): void
    {
        $actor = Actor::system();
        $svc = new ReceivingService($pdo);
        $id = $svc->createAsn('ASN-DEMO-001', self::SUPPLIER, self::ASN_DEMO_001, $actor);
        $svc->checkIn($id, $actor);
        $counts = [];
        foreach ($svc->items($id) as $item) {
            $counts[(int) $item['id']] = ['counted' => (int) $item['expected_qty']];
        }
        $svc->count($id, $counts, $actor);
        $svc->qc($id, [], $actor);
        $svc->confirmReceiving($id, $actor);
        foreach ($svc->putawayTasks($id, true) as $task) {
            $svc->confirmPutaway((int) $task['id'], $task['source_code'], $task['product_sku'], $task['target_code'], $actor);
        }
        $svc->close($id, $actor, 'Đường đi chuẩn: ASN 45 = thực nhận 45 = put-away 45');
    }
}
