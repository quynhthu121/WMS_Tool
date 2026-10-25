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

    public const INBOUND_EXCEPTIONS = [
        'E1' => 'E1 – Nhận thiếu: dòng 1 ASN 20, thực nhận 18',
        'E2' => 'E2 – Nhận thừa: dòng 2 ASN 10, thực nhận 12',
        'E3' => 'E3 – Hàng lỗi: dòng 3 15 cái, QC đạt 13, lỗi 2',
        'E4' => 'E4 – Quét sai location khi put-away',
    ];

    /**
     * Dựng sẵn ASN-DEMO-001 tới đúng điểm cần trình diễn ngoại lệ (trên DB vừa reset preset main).
     * E1–E3 dừng ở "Đã QC" để người trình bày bấm Xác nhận Receiving (cần Giám sát duyệt);
     * E4 dừng ở "Đã xác nhận nhập" để người trình bày quét sai location ở task put-away.
     */
    public static function prepareInboundException(PDO $pdo, string $case): int
    {
        if (!isset(self::INBOUND_EXCEPTIONS[$case])) {
            throw new BusinessRuleException("Không có ngoại lệ {$case}.");
        }
        $actor = Actor::system(Actor::OPERATOR);
        $svc = new ReceivingService($pdo);
        $id = $svc->createAsn('ASN-DEMO-001', self::SUPPLIER, self::ASN_DEMO_001, $actor);
        $svc->checkIn($id, $actor);
        $counts = [];
        $fails = [];
        foreach ($svc->items($id) as $item) {
            $counted = (int) $item['expected_qty'];
            $reason = '';
            if ($case === 'E1' && $item['product_sku'] === 'AO-001-M-DEN') {
                [$counted, $reason] = [18, 'Nhà cung cấp giao thiếu 2'];
            }
            if ($case === 'E2' && $item['product_sku'] === 'AO-002-L-TRG') {
                [$counted, $reason] = [12, 'Nhà cung cấp giao thừa 2'];
            }
            if ($case === 'E3' && $item['product_sku'] === 'QUAN-002-L-DEN') {
                $fails[(int) $item['id']] = ['fail' => 2, 'reason' => 'Lỗi đường may, sai nhãn size'];
            }
            $counts[(int) $item['id']] = ['counted' => $counted, 'reason' => $reason];
        }
        $svc->count($id, $counts, $actor);
        $svc->qc($id, $fails, $actor);
        if ($case === 'E4') {
            $svc->confirmReceiving($id, $actor);
        }
        return $id;
    }
}
