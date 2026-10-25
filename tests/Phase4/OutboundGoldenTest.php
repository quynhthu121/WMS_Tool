<?php

declare(strict_types=1);

namespace Tests\Phase4;

use App\Service\AuditService;
use App\Service\BusinessRuleException;
use App\Service\OutboundService;
use App\Service\ReceivingService;
use App\Service\ScenarioService;
use App\Support\Actor;
use Tests\Support\DemoTestCase;

/**
 * Golden test ledger xuyên suốt AO-001-M-DEN (A4 §4, docs/source/A4_inventory_audit.txt),
 * nối tiếp kịch bản Inbound A2, cùng bảng cặp A4 §2.3/§2.4 và mốc toàn kho.
 */
final class OutboundGoldenTest extends DemoTestCase
{
    private const SKU = 'AO-001-M-DEN';

    private OutboundService $out;
    private Actor $operator;
    private Actor $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = new OutboundService($this->pdo);
        $this->operator = new Actor(null, 'Nhân viên', Actor::OPERATOR);
        $this->supervisor = new Actor(null, 'Giám sát', Actor::SUPERVISOR);
    }

    public function testLedgerXuyenSuotA4Muc4(): void
    {
        $in = new ReceivingService($this->pdo);
        // 0 – Đầu kỳ (A-01 = 50)
        $this->assertSku(self::SKU, 50, 0, 50, '#0');

        // 1 – Receiving +20 (RECEIVING = 20): Available hiển thị 50 (*)
        $id = $in->createAsn('ASN-DEMO-001', ScenarioService::SUPPLIER, ScenarioService::ASN_DEMO_001, $this->operator);
        $in->checkIn($id, $this->operator);
        $counts = [];
        foreach ($in->items($id) as $item) {
            $counts[(int) $item['id']] = ['counted' => (int) $item['expected_qty']];
        }
        $in->count($id, $counts, $this->operator);
        $in->qc($id, [], $this->operator);
        $in->confirmReceiving($id, $this->operator);
        $this->assertSku(self::SKU, 70, 0, 50, '#1');
        $this->assertPair(self::SKU, 'A-01', 50, 0, 50, '#1');
        $this->assertPair(self::SKU, 'RECEIVING', 20, 0, 0, '#1');

        // 2 – Put-away 20 → A-01 (+ hai task còn lại để toàn kho = 350 như A2)
        foreach ($in->putawayTasks($id, true) as $t) {
            $in->confirmPutaway((int) $t['id'], 'RECEIVING', $t['product_sku'], $t['target_code'], $this->operator);
            if ($t['product_sku'] === self::SKU) {
                $this->assertSku(self::SKU, 70, 0, 70, '#2');
                $this->assertPair(self::SKU, 'A-01', 70, 0, 70, '#2');
            }
        }
        $in->close($id, $this->supervisor);
        $this->assertLocations(['A-01' => 175, 'B-01' => 175, 'RECEIVING' => 0], 'Sau Inbound');
        $this->assertWarehouse(350, 350, 0, 'Sau Inbound');

        // 3 – Order created ORD-DEMO-001 (30): chưa đổi tồn
        $orderId = $this->out->createOrder('ORD-DEMO-001', OutboundService::DEFAULT_CHANNEL, OutboundService::DEFAULT_ADDRESS,
            [['sku' => self::SKU, 'qty' => 30]], $this->operator);
        $this->assertSku(self::SKU, 70, 0, 70, '#3');
        $this->assertSame('CREATED', $this->out->order($orderId)['status']);

        // 4 – Reservation 30 tại A-01
        $alloc = $this->out->reserve($orderId, $this->operator);
        $this->assertSame([['sku' => self::SKU, 'location' => 'A-01', 'qty' => 30]], $alloc);
        $this->assertSku(self::SKU, 70, 30, 40, '#4');
        $this->assertPair(self::SKU, 'A-01', 70, 30, 40, '#4');
        $this->assertSame('RESERVED', $this->out->order($orderId)['status']);
        // A4 §2.3: bảng cặp sau Inbound + Reservation: tổng 350 / 30 / 320; cặp khác Reserved 0
        $this->assertWarehouse(350, 320, 30, 'A4 §2.3');
        foreach ($this->query->pairs() as $p) {
            if ($p['product_sku'] !== self::SKU) {
                $this->assertSame(0, $p['reserved'], "{$p['product_sku']} Reserved");
            }
        }
        $expected23 = ['AO-001-L-DEN' => 40, 'AO-002-M-TRG' => 30, 'AO-002-L-TRG' => 35, 'QUAN-001-M-XAM' => 45,
            'QUAN-001-L-XAM' => 60, 'QUAN-002-M-DEN' => 35, 'QUAN-002-L-DEN' => 35];
        foreach ($expected23 as $sku => $qty) {
            $this->assertSku($sku, $qty, 0, $qty, 'A4 §2.3');
        }

        // 5 – Picking → PICK-STG (A-01: 40/0/40; PICK-STG: 30/30/0)
        $this->out->createPickList($orderId, $this->operator);
        $this->assertSame('PICKING', $this->out->order($orderId)['status']);
        $line = $this->out->pickLines($orderId, true)[0];
        $this->assertSame(['A-01', 30], [$line['location_code'], (int) $line['qty']]);
        $r = $this->out->confirmPick((int) $line['id'], 'A-01', self::SKU, 30, $this->operator);
        $this->assertSame(0, $r['short']);
        $this->assertSku(self::SKU, 70, 30, 40, '#5');
        $this->assertPair(self::SKU, 'A-01', 40, 0, 40, '#5');
        $this->assertPair(self::SKU, 'PICK-STG', 30, 30, 0, '#5');
        $this->assertSame('PICKED', $this->out->order($orderId)['status']);
        $this->assertLocations(['A-01' => 145, 'PICK-STG' => 30], 'Sau Picking 30');
        $this->assertWarehouse(350, 320, null, 'Sau Picking 30');

        // 6 – Packing (1) → PACK-01
        $this->out->packIn($orderId, $this->operator);
        $this->assertSku(self::SKU, 70, 30, 40, '#6');
        $this->assertPair(self::SKU, 'PACK-01', 30, 30, 0, '#6');
        $this->assertSame('PACKING', $this->out->order($orderId)['status']);

        // 7 – Packing (2) → OUT-STG, sinh Tracking ID
        $tracking = $this->out->packOut($orderId, [self::SKU => 30], $this->operator);
        $this->assertStringStartsWith('TRK-', $tracking);
        $this->assertSku(self::SKU, 70, 30, 40, '#7');
        $this->assertPair(self::SKU, 'OUT-STG', 30, 30, 0, '#7');
        $this->assertSame('PACKED', $this->out->order($orderId)['status']);

        // 8 – Shipping 30: On-hand −30, Reserved −30, Available không đổi
        $this->out->ship($orderId, $tracking, $this->operator);
        $this->assertSku(self::SKU, 40, 0, 40, '#8');
        $this->assertPair(self::SKU, 'A-01', 40, 0, 40, '#8');
        $this->assertPair(self::SKU, 'OUT-STG', 0, 0, 0, '#8');
        $this->assertPair(self::SKU, 'DOCK', 0, 0, 0, '#8');
        $this->assertSame('SHIPPED', $this->out->order($orderId)['status']);
        $this->assertWarehouse(320, 320, 0, 'Sau Shipping 30');
        $this->assertLocations(['A-01' => 145, 'B-01' => 175, 'PICK-STG' => 0, 'PACK-01' => 0, 'OUT-STG' => 0, 'DOCK' => 0]);
        $events = array_column($this->out->outboxEvents('ORD-DEMO-001'), 'event_type');
        $this->assertContains('ORDER_SHIPPED', $events);

        // 9 – Cycle count: hệ thống 40, thực tế 38 (chưa đổi tồn)
        $audit = new AuditService($this->pdo);
        $cc = $audit->createCount(self::SKU, 'A-01', $this->supervisor);
        $this->assertSame(40, (int) $audit->count($cc)['system_qty']);
        $this->assertSame('RECOUNT', $audit->submitCount($cc, 38, $this->operator));
        $this->assertSame('PENDING_APPROVAL', $audit->submitRecount($cc, 38, $this->operator));
        $this->assertSame(-2, (int) $audit->count($cc)['difference']);
        $this->assertSku(self::SKU, 40, 0, 40, '#9');

        // 10 – Adjustment −2
        $audit->approve($cc, 'Recount xác nhận 38, không tìm thấy giao dịch nhầm', $this->supervisor);
        $this->assertSku(self::SKU, 38, 0, 38, '#10');
        $this->assertSame('ADJUSTED', $audit->count($cc)['status']);
        $this->assertLocations(['A-01' => 143, 'B-01' => 175]);
        $this->assertWarehouse(318, 318, 0, 'Sau Adjustment −2');

        // Công thức: 305 + 45 − 30 − 2 = 318; SKU: 50 + 20 − 30 − 2 = 38
        $t = $this->recon->totals();
        $this->assertSame([305, 45, 30, 0, 2, 318], [$t['opening'], $t['received'], $t['shipped'], $t['adjust_plus'], $t['adjust_minus'], $t['actual']]);
        $this->assertLedgerReconciles('cuối kịch bản');

        // On-hand của SKU chỉ đổi ở RECEIVE, SHIP, ADJUST (+ OPENING ban đầu)
        $types = $this->pdo->query("SELECT DISTINCT movement_type FROM stock_movements WHERE product_sku = 'AO-001-M-DEN' AND changes_on_hand = 1 ORDER BY 1")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['ADJUST', 'OPENING', 'RECEIVE', 'SHIP'], $types);
    }

    public function testShipHaiLanBiChan(): void
    {
        [$orderId, $tracking] = $this->packedOrder(30);
        $this->out->ship($orderId, $tracking, $this->operator);
        try {
            $this->out->ship($orderId, $tracking, $this->operator);
            $this->fail('Ship lần hai phải bị chặn');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('không xuất lần hai', $e->getMessage());
        }
        $this->assertSku(self::SKU, 20, 0, 20);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'SHIP'")->fetchColumn());
        $this->assertLedgerReconciles();
    }

    public function testShipSaiTrackingBiChan(): void
    {
        [$orderId] = $this->packedOrder(10);
        $this->expectException(BusinessRuleException::class);
        $this->out->ship($orderId, 'TRK-SAI', $this->operator);
    }

    public function testReserveVuotAvailableKhongTaoReservation(): void
    {
        $orderId = $this->out->createOrder('ORD-X', '', '', [['sku' => self::SKU, 'qty' => 51]], $this->operator);
        try {
            $this->out->reserve($orderId, $this->operator);
            $this->fail('Requested > Available phải bị từ chối');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('vượt Available', $e->getMessage());
        }
        $this->assertSame('CREATED', $this->out->order($orderId)['status']);
        $this->assertSku(self::SKU, 50, 0, 50);
        $this->assertSame([], $this->out->reservations($orderId));
    }

    public function testDonTrungSkuHoacQtyKhongHopLeBiChan(): void
    {
        foreach ([
            [['sku' => self::SKU, 'qty' => 1], ['sku' => self::SKU, 'qty' => 2]],
            [['sku' => self::SKU, 'qty' => 0]],
            [['sku' => 'KHONG-CO', 'qty' => 1]],
        ] as $i => $lines) {
            try {
                $this->out->createOrder("ORD-BAD-{$i}", '', '', $lines, $this->operator);
                $this->fail('Đơn không hợp lệ phải bị chặn');
            } catch (BusinessRuleException) {
            }
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outbound_orders')->fetchColumn());
    }

    public function testPickQuetSaiBiTuChoi(): void
    {
        $orderId = $this->out->createOrder('ORD-Q', '', '', [['sku' => self::SKU, 'qty' => 5]], $this->operator);
        $this->out->reserve($orderId, $this->operator);
        $this->out->createPickList($orderId, $this->operator);
        $line = $this->out->pickLines($orderId, true)[0];
        foreach ([['B-01', self::SKU, 'Sai vị trí'], ['A-01', 'AO-001-L-DEN', 'Sai SKU']] as [$loc, $sku, $msg]) {
            try {
                $this->out->confirmPick((int) $line['id'], $loc, $sku, 5, $this->operator);
                $this->fail('Quét sai phải bị từ chối');
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $this->assertPair(self::SKU, 'A-01', 50, 5, 45);
        $this->assertPair(self::SKU, 'PICK-STG', 0, 0, 0);
    }

    public function testPackingQuetThieuBiTuChoi(): void
    {
        $orderId = $this->out->createOrder('ORD-P', '', '', [['sku' => self::SKU, 'qty' => 5]], $this->operator);
        $this->out->reserve($orderId, $this->operator);
        $this->out->createPickList($orderId, $this->operator);
        $line = $this->out->pickLines($orderId, true)[0];
        $this->out->confirmPick((int) $line['id'], 'A-01', self::SKU, 5, $this->operator);
        $this->out->packIn($orderId, $this->operator);
        $this->expectException(BusinessRuleException::class);
        $this->out->packOut($orderId, [self::SKU => 4], $this->operator);
    }

    public function testHuyDonSauKhiPickTraHangVeLocationGoc(): void
    {
        $orderId = $this->out->createOrder('ORD-C', '', '', [['sku' => self::SKU, 'qty' => 12]], $this->operator);
        $this->out->reserve($orderId, $this->operator);
        $this->out->createPickList($orderId, $this->operator);
        $line = $this->out->pickLines($orderId, true)[0];
        $this->out->confirmPick((int) $line['id'], 'A-01', self::SKU, 12, $this->operator);
        $this->out->packIn($orderId, $this->operator);
        $this->assertPair(self::SKU, 'PACK-01', 12, 12, 0);

        $this->out->cancel($orderId, 'Khách hủy đơn', $this->operator);
        $this->assertPair(self::SKU, 'PACK-01', 0, 0, 0);
        $this->assertPair(self::SKU, 'A-01', 50, 0, 50);
        $this->assertSame('CANCELLED', $this->out->order($orderId)['status']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'RESTOCK'")->fetchColumn());
        $this->assertWarehouse(305, 305, 0);
        $this->assertLedgerReconciles();
    }

    public function testFifoTachNhieuLocation(): void
    {
        $this->demo()->reset('exception');
        $orderId = $this->out->createOrder('ORD-F', '', '', [['sku' => self::SKU, 'qty' => 55]], $this->operator);
        $alloc = $this->out->reserve($orderId, $this->operator);
        $this->assertSame([
            ['sku' => self::SKU, 'location' => 'A-01', 'qty' => 50],
            ['sku' => self::SKU, 'location' => 'B-01', 'qty' => 5],
        ], $alloc);
        $this->assertSku(self::SKU, 60, 55, 5);
    }

    /** @return array{0:int, 1:string} */
    private function packedOrder(int $qty): array
    {
        $orderId = $this->out->createOrder('ORD-T' . $qty, '', '', [['sku' => self::SKU, 'qty' => $qty]], $this->operator);
        $this->out->reserve($orderId, $this->operator);
        $this->out->createPickList($orderId, $this->operator);
        $line = $this->out->pickLines($orderId, true)[0];
        $this->out->confirmPick((int) $line['id'], 'A-01', self::SKU, $qty, $this->operator);
        $this->out->packIn($orderId, $this->operator);
        return [$orderId, $this->out->packOut($orderId, [self::SKU => $qty], $this->operator)];
    }
}
