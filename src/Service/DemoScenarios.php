<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Actor;
use PDO;

/**
 * Ba kịch bản trình diễn từng bước. Mỗi bước gọi service thật; cột "Kỳ vọng" chép từ tài liệu nguồn
 * (A2 §2.3–2.4, A4 §4, A4 §3.3) để đối chiếu ✓/✗ với số đo thực tế sau bước.
 */
final class DemoScenarios
{
    public const SKU = 'AO-001-M-DEN';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'inbound' => self::inbound(),
            'outbound_audit' => self::outboundAudit(),
            'exception' => self::exception(),
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new BusinessRuleException("Không có kịch bản {$key}.");
        }
        return $all[$key] + ['key' => $key];
    }

    /**
     * Số đo hiện tại theo bộ cột của kịch bản.
     * @return array<string, int>
     */
    public static function measure(PDO $pdo, string $columns): array
    {
        $q = new InventoryQuery($pdo);
        $wh = $q->warehouseTotals();
        if ($columns === 'warehouse') {
            $loc = $q->onHandByLocation();
            return ['RECEIVING' => $loc['RECEIVING'] ?? 0, 'A-01' => $loc['A-01'] ?? 0, 'B-01' => $loc['B-01'] ?? 0,
                'total' => $wh['on_hand'], 'available' => $wh['available']];
        }
        $sku = $q->skuTotals(self::SKU);
        return ['on_hand' => $sku['on_hand'], 'reserved' => $sku['reserved'], 'available' => $sku['available'], 'total' => $wh['on_hand']];
    }

    public const COLUMN_LABELS = [
        'warehouse' => ['RECEIVING' => 'RECEIVING', 'A-01' => 'A-01', 'B-01' => 'B-01', 'total' => 'Tổng tồn', 'available' => 'Tồn khả dụng'],
        'sku' => ['on_hand' => 'On-hand', 'reserved' => 'Reserved', 'available' => 'Available', 'total' => 'Tổng tồn kho'],
    ];

    // ------------------------------------------------------------------ kịch bản 1

    private static function inbound(): array
    {
        $svc = fn (PDO $pdo) => new ReceivingService($pdo);
        $id = fn (PDO $pdo) => (int) (new ReceivingService($pdo))->receiptIdByCode('ASN-DEMO-001');
        $task = function (PDO $pdo, string $sku) use ($svc, $id): string {
            foreach ($svc($pdo)->putawayTasks($id($pdo), true) as $t) {
                if ($t['product_sku'] === $sku) {
                    $svc($pdo)->confirmPutaway((int) $t['id'], 'RECEIVING', $sku, $t['target_code'], Actor::system(Actor::OPERATOR));
                    return "Quét RECEIVING → {$sku} → {$t['target_code']}, xác nhận put-away {$t['qty']} cái ({$t['task_code']}).";
                }
            }
            throw new BusinessRuleException("Không còn task put-away cho {$sku}.");
        };
        $w = fn (int $r, int $a, int $b, int $t, int $av) => ['RECEIVING' => $r, 'A-01' => $a, 'B-01' => $b, 'total' => $t, 'available' => $av];

        return [
            'title' => 'Kịch bản 1 – Inbound ASN-DEMO-001',
            'doc' => 'A2 §2 (docs/source/A2_inbound.txt)',
            'preset' => 'main',
            'columns' => 'warehouse',
            'initial' => $w(0, 145, 160, 305, 305),
            'steps' => [
                ['label' => 'Bước 1 – Điều phối Inbound tạo ASN-DEMO-001 (20 + 10 + 15 = 45, chỉ là dự kiến)', 'role' => 'Inbound Coordinator',
                    'run' => function (PDO $pdo) use ($svc) {
                        $svc($pdo)->createAsn('ASN-DEMO-001', ScenarioService::SUPPLIER, ScenarioService::ASN_DEMO_001, Actor::system(Actor::OPERATOR));
                        return 'ASN tạo xong, trạng thái Dự kiến. ASN không phải tồn (A2-R5).';
                    }, 'expect' => $w(0, 145, 160, 305, 305)],
                ['label' => 'Bước 2 – Xe đến, check-in, dỡ hàng xuống khu nhận', 'role' => 'Receiver',
                    'run' => function (PDO $pdo) use ($svc, $id) {
                        $svc($pdo)->checkIn($id($pdo), Actor::system(Actor::OPERATOR));
                        return 'Hàng đã nằm vật lý ở RECEIVING nhưng hệ thống CHƯA ghi nhận.';
                    }, 'expect' => $w(0, 145, 160, 305, 305)],
                ['label' => 'Bước 3 – Kiểm đếm theo SKU, đối chiếu ASN', 'role' => 'Receiver',
                    'run' => function (PDO $pdo) use ($svc, $id) {
                        $counts = [];
                        foreach ($svc($pdo)->items($id($pdo)) as $i) {
                            $counts[(int) $i['id']] = ['counted' => (int) $i['expected_qty']];
                        }
                        $svc($pdo)->count($id($pdo), $counts, Actor::system(Actor::OPERATOR));
                        return 'Đếm đủ 20 / 10 / 15, không chênh lệch.';
                    }, 'expect' => $w(0, 145, 160, 305, 305)],
                ['label' => 'Bước 4 – QC: kiểm tra chất lượng, size/màu, nhãn', 'role' => 'Nhân viên QC',
                    'run' => function (PDO $pdo) use ($svc, $id) {
                        $svc($pdo)->qc($id($pdo), [], Actor::system(Actor::OPERATOR));
                        return 'QC đạt 100%.';
                    }, 'expect' => $w(0, 145, 160, 305, 305)],
                ['label' => 'Bước 5 – XÁC NHẬN RECEIVING (+45 vào RECEIVING) và bước 6 – hệ thống tạo 3 task put-away', 'role' => 'Receiver',
                    'run' => function (PDO $pdo) use ($svc, $id) {
                        $n = $svc($pdo)->confirmReceiving($id($pdo), Actor::system(Actor::OPERATOR));
                        $tasks = array_map(fn ($t) => "{$t['product_sku']} → {$t['target_code']}", $svc($pdo)->putawayTasks($id($pdo)));
                        return "Tổng tồn +{$n} – bước DUY NHẤT làm tăng tồn. Tồn khả dụng chưa tăng vì hàng còn ở RECEIVING. Task: " . implode('; ', $tasks) . '.';
                    }, 'expect' => $w(45, 145, 160, 350, 305)],
                ['label' => 'Bước 9a – Put-away AO-001-M-DEN 20 → A-01', 'role' => 'Nhân viên put-away',
                    'run' => fn (PDO $pdo) => $task($pdo, 'AO-001-M-DEN'), 'expect' => $w(25, 165, 160, 350, 325)],
                ['label' => 'Bước 9b – Put-away AO-002-L-TRG 10 → A-01', 'role' => 'Nhân viên put-away',
                    'run' => fn (PDO $pdo) => $task($pdo, 'AO-002-L-TRG'), 'expect' => $w(15, 175, 160, 350, 335)],
                ['label' => 'Bước 9c – Put-away QUAN-002-L-DEN 15 → B-01', 'role' => 'Nhân viên put-away',
                    'run' => fn (PDO $pdo) => $task($pdo, 'QUAN-002-L-DEN'), 'expect' => $w(0, 175, 175, 350, 350)],
                ['label' => 'Bước 10 – Giám sát đóng phiếu, đối soát ASN – thực nhận – put-away', 'role' => 'Giám sát kho',
                    'run' => function (PDO $pdo) use ($svc, $id) {
                        $svc($pdo)->close($id($pdo), Actor::system(), 'Đường đi chuẩn');
                        return 'ASN 45 = Thực nhận đạt 45 = Đã put-away 45, chênh lệch 0.';
                    }, 'expect' => $w(0, 175, 175, 350, 350)],
            ],
        ];
    }

    // ------------------------------------------------------------------ kịch bản 2

    private static function outboundAudit(): array
    {
        $out = fn (PDO $pdo) => new OutboundService($pdo);
        $order = fn (PDO $pdo) => (int) (new OutboundService($pdo))->orderIdByCode('ORD-DEMO-001');
        $s = fn (int $oh, int $r, int $av, int $t) => ['on_hand' => $oh, 'reserved' => $r, 'available' => $av, 'total' => $t];
        $op = Actor::system(Actor::OPERATOR);

        return [
            'title' => 'Kịch bản 2 – Outbound + Audit ORD-DEMO-001 (ledger AO-001-M-DEN)',
            'doc' => 'A4 §4 dòng 3–10 (docs/source/A4_inventory_audit.txt) + A3',
            'preset' => 'after_inbound',
            'continues' => 'inbound',
            'columns' => 'sku',
            'initial' => $s(70, 0, 70, 350),
            'steps' => [
                ['label' => '#3 – Order created ORD-DEMO-001: 30 cái AO-001-M-DEN', 'role' => 'OMS / kênh bán',
                    'run' => function (PDO $pdo) use ($out, $op) {
                        $out($pdo)->createOrder('ORD-DEMO-001', OutboundService::DEFAULT_CHANNEL, OutboundService::DEFAULT_ADDRESS, [['sku' => self::SKU, 'qty' => 30]], $op);
                        return 'Đơn CREATED, kiểm tra sơ bộ đạt. Chưa đổi tồn.';
                    }, 'expect' => $s(70, 0, 70, 350)],
                ['label' => '#4 – Reservation 30 tại A-01 (FIFO)', 'role' => 'Hệ thống',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $a = $out($pdo)->reserve($order($pdo), $op);
                        return 'Giữ ' . implode(', ', array_map(fn ($x) => "{$x['qty']} tại {$x['location']}", $a)) . '. On-hand không đổi, Available −30.';
                    }, 'expect' => $s(70, 30, 40, 350)],
                ['label' => '#5 – Picking: Pick List, quét A-01 + SKU, lấy 30 → PICK-STG', 'role' => 'Nhân viên kho',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $out($pdo)->createPickList($order($pdo), $op);
                        foreach ($out($pdo)->pickLines($order($pdo), true) as $l) {
                            $out($pdo)->confirmPick((int) $l['id'], $l['location_code'], $l['product_sku'], (int) $l['qty'], $op);
                        }
                        return 'A-01: 40/0/40; PICK-STG: 30/30/0. Chỉ đổi location, Reserved đi theo hàng.';
                    }, 'expect' => $s(70, 30, 40, 350)],
                ['label' => '#6 – Packing (1): PICK-STG → PACK-01', 'role' => 'Nhân viên đóng gói',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $out($pdo)->packIn($order($pdo), $op);
                        return 'PACK-01 = 30 (Reserved đi theo hàng).';
                    }, 'expect' => $s(70, 30, 40, 350)],
                ['label' => '#7 – Packing (2): quét đối soát, sinh Tracking ID, PACK-01 → OUT-STG', 'role' => 'Nhân viên đóng gói',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $t = $out($pdo)->packOut($order($pdo), [self::SKU => 30], $op);
                        return "Tracking {$t}. OUT-STG = 30.";
                    }, 'expect' => $s(70, 30, 40, 350)],
                ['label' => '#8 – Shipping: quét vận đơn tại DOCK, OUT-STG → DOCK → ra khỏi kho', 'role' => 'Nhân viên xuất',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $o = $out($pdo)->order($order($pdo));
                        $out($pdo)->ship((int) $o['id'], $o['tracking_id'], $op);
                        return 'On-hand −30, Reserved −30, Available không đổi. Đã ghi sự kiện ORDER_SHIPPED vào oms_outbox.';
                    }, 'expect' => $s(40, 0, 40, 320)],
                ['label' => '#9 – Cycle count AO-001-M-DEN @ A-01: hệ thống 40, thực tế 38, đếm lại 38', 'role' => 'Giám sát + nhân viên kiểm kê',
                    'run' => function (PDO $pdo) use ($op) {
                        $audit = new AuditService($pdo);
                        $id = $audit->createCount(self::SKU, 'A-01', Actor::system());
                        $audit->submitCount($id, 38, $op);
                        $audit->submitRecount($id, 38, $op);
                        return 'Chênh lệch −2, chờ Giám sát duyệt. Chưa đổi tồn.';
                    }, 'expect' => $s(40, 0, 40, 320)],
                ['label' => '#10 – Giám sát duyệt Adjustment −2', 'role' => 'Giám sát kho',
                    'run' => function (PDO $pdo) {
                        $audit = new AuditService($pdo);
                        foreach ($audit->counts() as $c) {
                            if ($c['status'] === 'PENDING_APPROVAL' && $c['product_sku'] === self::SKU) {
                                $audit->approve((int) $c['id'], 'Recount xác nhận 38, không tìm thấy giao dịch nhầm', Actor::system());
                                return "ADJUST −2 ({$c['count_code']}). Công thức: 50 + 20 − 30 − 2 = 38; toàn kho 305 + 45 − 30 − 2 = 318.";
                            }
                        }
                        throw new BusinessRuleException('Không có phiếu kiểm kê chờ duyệt.');
                    }, 'expect' => $s(38, 0, 38, 318)],
            ],
        ];
    }

    // ------------------------------------------------------------------ kịch bản 3

    private static function exception(): array
    {
        $out = fn (PDO $pdo) => new OutboundService($pdo);
        $order = fn (PDO $pdo) => (int) (new OutboundService($pdo))->orderIdByCode('ORD-DEMO-002');
        $count = function (PDO $pdo) use ($order): int {
            foreach ((new AuditService($pdo))->counts() as $c) {
                if ((int) $c['source_order_id'] === $order($pdo)) {
                    return (int) $c['id'];
                }
            }
            throw new BusinessRuleException('Chưa có phiếu kiểm kê do pick thiếu.');
        };
        $s = fn (int $oh, int $r, int $av, int $t) => ['on_hand' => $oh, 'reserved' => $r, 'available' => $av, 'total' => $t];
        $op = Actor::system(Actor::OPERATOR);

        return [
            'title' => 'Kịch bản 3 – Ngoại lệ pick thiếu ORD-DEMO-002 (độc lập)',
            'doc' => 'A4 §3.3 – giả định bổ sung: AO-001-M-DEN A-01 = 50, B-01 = 10',
            'preset' => 'exception',
            'columns' => 'sku',
            'initial' => $s(60, 0, 60, 315),
            'steps' => [
                ['label' => '1 – Tạo ORD-DEMO-002 (48 cái) và Reservation 48 tại A-01', 'role' => 'Hệ thống',
                    'run' => function (PDO $pdo) use ($out, $op) {
                        $id = $out($pdo)->createOrder('ORD-DEMO-002', OutboundService::DEFAULT_CHANNEL, OutboundService::DEFAULT_ADDRESS, [['sku' => self::SKU, 'qty' => 48]], $op);
                        $out($pdo)->reserve($id, $op);
                        return 'A-01: 50/48/2; B-01: 10/0/10.';
                    }, 'expect' => $s(60, 48, 12, 315)],
                ['label' => '2 – Picking: thực tế A-01 chỉ lấy được 44 → PICK-STG', 'role' => 'Nhân viên kho',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $out($pdo)->createPickList($order($pdo), $op);
                        $l = $out($pdo)->pickLines($order($pdo), true)[0];
                        $r = $out($pdo)->confirmPick((int) $l['id'], 'A-01', self::SKU, 44, $op);
                        return "Lấy 44/48, thiếu {$r['short']}. A-01: 6/4/2 (sổ còn 6); PICK-STG: 44/44/0.";
                    }, 'expect' => $s(60, 48, 12, 315)],
                ['label' => '3 – Phát hiện thiếu 4: khóa phân bổ cặp A-01, tự tạo phiếu kiểm kê (PICK_SHORTAGE)', 'role' => 'Hệ thống',
                    'run' => function (PDO $pdo) use ($count, $order) {
                        $c = (new AuditService($pdo))->count($count($pdo));
                        $o = (new OutboundService($pdo))->order($order($pdo));
                        return "Phiếu {$c['count_code']} (chốt hệ thống {$c['system_qty']}). Đơn giữ {$o['status']} + cờ: {$o['hold_reason']}.";
                    }, 'expect' => $s(60, 48, 12, 315)],
                ['label' => '4 – Cycle count: hệ thống 6, thực tế 0, đếm lại 0 ⇒ chênh lệch −6', 'role' => 'Nhân viên kiểm kê',
                    'run' => function (PDO $pdo) use ($count, $op) {
                        $a = new AuditService($pdo);
                        $a->submitCount($count($pdo), 0, $op);
                        $a->submitRecount($count($pdo), 0, $op);
                        return 'Recount xác nhận 0. Chờ Giám sát duyệt.';
                    }, 'expect' => $s(60, 48, 12, 315)],
                ['label' => '5 – Hủy 4 Reserved không còn hàng đỡ', 'role' => 'Giám sát kho',
                    'run' => function (PDO $pdo) use ($count) {
                        $n = (new AuditService($pdo))->releaseUnbackedReservations($count($pdo), Actor::system());
                        return "Hủy {$n} Reserved. A-01: 6/0/6 (Available tạm thời, sổ còn 6).";
                    }, 'expect' => $s(60, 44, 16, 315)],
                ['label' => '6 – Giám sát duyệt ADJUST −6 tại A-01, mở khóa phân bổ', 'role' => 'Giám sát kho',
                    'run' => function (PDO $pdo) use ($count) {
                        (new AuditService($pdo))->approve($count($pdo), 'Recount xác nhận A-01 không còn hàng', Actor::system());
                        return 'A-01: 0/0/0; PICK-STG: 44/44/0; B-01: 10/0/10.';
                    }, 'expect' => $s(54, 44, 10, 309)],
                ['label' => '7 – Reservation lại 4 từ location khác (FIFO ⇒ B-01)', 'role' => 'Hệ thống',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $r = $out($pdo)->reallocate($order($pdo), $op);
                        return 'Giữ ' . implode(', ', array_map(fn ($x) => "{$x['qty']} tại {$x['location']}", $r['reserved'])) . '. Đơn lại đủ 48 (44 đã pick + 4 mới giữ). B-01: 10/4/6.';
                    }, 'expect' => $s(54, 48, 6, 309)],
                ['label' => '8 – Picking 4 từ B-01', 'role' => 'Nhân viên kho',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $l = $out($pdo)->pickLines($order($pdo), true)[0];
                        $out($pdo)->confirmPick((int) $l['id'], $l['location_code'], self::SKU, (int) $l['qty'], $op);
                        return 'B-01: 6/0/6; PICK-STG: 48/48/0.';
                    }, 'expect' => $s(54, 48, 6, 309)],
                ['label' => '9 – Packing (1) và (2): PICK-STG → PACK-01 → OUT-STG (48)', 'role' => 'Nhân viên đóng gói',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $out($pdo)->packIn($order($pdo), $op);
                        $t = $out($pdo)->packOut($order($pdo), [self::SKU => 48], $op);
                        return "Tracking {$t}. Chỉ đổi location.";
                    }, 'expect' => $s(54, 48, 6, 309)],
                ['label' => '10 – Shipping 48', 'role' => 'Nhân viên xuất',
                    'run' => function (PDO $pdo) use ($out, $order, $op) {
                        $o = $out($pdo)->order($order($pdo));
                        $out($pdo)->ship((int) $o['id'], $o['tracking_id'], $op);
                        return 'B-01: 6/0/6. Công thức SKU: 60 − 48 − 6 = 6; toàn kho 315 − 48 − 6 = 261.';
                    }, 'expect' => $s(6, 0, 6, 261)],
            ],
        ];
    }
}
