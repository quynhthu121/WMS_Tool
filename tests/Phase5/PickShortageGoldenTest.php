<?php

declare(strict_types=1);

namespace Tests\Phase5;

use App\Service\AuditService;
use App\Service\BusinessRuleException;
use App\Service\OutboundService;
use App\Support\Actor;
use Tests\Support\DemoTestCase;

/**
 * Golden test ngoại lệ bắt buộc A4 §3.3 – kịch bản độc lập ORD-DEMO-002 trên preset `exception`.
 * Giả định bổ sung của A4: AO-001-M-DEN A-01 = 50, B-01 = 10; đơn yêu cầu 48. Toàn kho 315 → 261.
 */
final class PickShortageGoldenTest extends DemoTestCase
{
    private const SKU = 'AO-001-M-DEN';

    private OutboundService $out;
    private AuditService $audit;
    private Actor $operator;
    private Actor $supervisor;

    protected function preset(): string
    {
        return 'exception';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = new OutboundService($this->pdo);
        $this->audit = new AuditService($this->pdo);
        $this->operator = new Actor(null, 'Nhân viên', Actor::OPERATOR);
        $this->supervisor = new Actor(null, 'Giám sát', Actor::SUPERVISOR);
    }

    public function testMuoiMotBuocA4Muc33(): void
    {
        // 0 – Ban đầu: 60/0/60; A-01 50, B-01 10
        $this->assertSku(self::SKU, 60, 0, 60, 'B0');
        $this->assertWarehouse(315, 315, 0, 'B0');

        // 1 – Reservation 48 tại A-01
        $orderId = $this->out->createOrder('ORD-DEMO-002', OutboundService::DEFAULT_CHANNEL, OutboundService::DEFAULT_ADDRESS,
            [['sku' => self::SKU, 'qty' => 48]], $this->operator);
        $this->assertSame([['sku' => self::SKU, 'location' => 'A-01', 'qty' => 48]], $this->out->reserve($orderId, $this->operator));
        $this->assertSku(self::SKU, 60, 48, 12, 'B1');
        $this->assertPair(self::SKU, 'A-01', 50, 48, 2, 'B1');
        $this->assertPair(self::SKU, 'B-01', 10, 0, 10, 'B1');

        // 2 + 3 – Pick thực tế chỉ lấy được 44 → phát hiện thiếu 4, khóa cặp A-01, tự tạo phiếu kiểm kê
        $this->out->createPickList($orderId, $this->operator);
        $line = $this->out->pickLines($orderId, true)[0];
        $r = $this->out->confirmPick((int) $line['id'], 'A-01', self::SKU, 44, $this->operator);
        $this->assertSame(4, $r['short']);
        $this->assertSku(self::SKU, 60, 48, 12, 'B2');
        $this->assertPair(self::SKU, 'A-01', 6, 4, 2, 'B2');
        $this->assertPair(self::SKU, 'PICK-STG', 44, 44, 0, 'B2');
        $order = $this->out->order($orderId);
        $this->assertSame('PICKING', $order['status'], 'Không tạo trạng thái mới; giữ PICKING');
        $this->assertNotNull($order['hold_reason']);
        $this->assertTrue((new \App\Service\InventoryService($this->pdo))->balance(self::SKU, 'A-01')['alloc_locked'], 'B3: khóa phân bổ cặp A-01');
        $count = $this->audit->count((int) $r['count_id']);
        $this->assertSame(['PICK_SHORTAGE', 'A-01', 6, 'ORD-DEMO-002'], [$count['trigger_type'], $count['location_code'], (int) $count['system_qty'], $count['order_code']]);
        // Cặp bị khóa: đơn khác không phân bổ được từ A-01
        $other = $this->out->createOrder('ORD-KHAC', '', '', [['sku' => self::SKU, 'qty' => 2]], $this->operator);
        $this->assertSame([['location' => 'B-01', 'qty' => 2, 'available' => 10]], $this->out->planFifo(self::SKU, 2));
        $this->out->cancel($other, 'Chỉ để kiểm tra khóa phân bổ', $this->operator);
        // Chưa kiểm kê xong thì chưa phân bổ lại được
        try {
            $this->out->reallocate($orderId, $this->operator);
            $this->fail('Phải chờ kiểm kê xong');
        } catch (BusinessRuleException) {
        }
        $this->assertSku(self::SKU, 60, 48, 12, 'B3');

        // 4 – Cycle count: hệ thống 6, thực tế 0, lệch −6 (recount 0)
        $this->assertSame('RECOUNT', $this->audit->submitCount((int) $r['count_id'], 0, $this->operator));
        $this->assertSame('PENDING_APPROVAL', $this->audit->submitRecount((int) $r['count_id'], 0, $this->operator));
        $this->assertSame(-6, (int) $this->audit->count((int) $r['count_id'])['difference']);
        $this->assertSku(self::SKU, 60, 48, 12, 'B4');

        // 5 – Hủy 4 Reserved không còn hàng đỡ: 60/44/16; A-01 6/0/6
        $this->assertSame(4, $this->audit->releaseUnbackedReservations((int) $r['count_id'], $this->supervisor));
        $this->assertSku(self::SKU, 60, 44, 16, 'B5');
        $this->assertPair(self::SKU, 'A-01', 6, 0, 6, 'B5 (Available tạm thời, A4 ghi chú)');

        // 6 – ADJUST −6 tại A-01: 54/44/10; A-01 0/0/0; B-01 10/0/10
        $this->audit->approve((int) $r['count_id'], 'Recount xác nhận A-01 không còn hàng', $this->supervisor);
        $this->assertSku(self::SKU, 54, 44, 10, 'B6');
        $this->assertPair(self::SKU, 'A-01', 0, 0, 0, 'B6');
        $this->assertPair(self::SKU, 'B-01', 10, 0, 10, 'B6');
        $this->assertFalse((new \App\Service\InventoryService($this->pdo))->balance(self::SKU, 'A-01')['alloc_locked'], 'Mở khóa sau kiểm kê');

        // 7 – Reservation lại 4 từ B-01: 54/48/6; B-01 10/4/6
        $re = $this->out->reallocate($orderId, $this->operator);
        $this->assertSame([['sku' => self::SKU, 'location' => 'B-01', 'qty' => 4]], $re['reserved']);
        $this->assertSame(0, $re['backorder']);
        $this->assertSku(self::SKU, 54, 48, 6, 'B7');
        $this->assertPair(self::SKU, 'B-01', 10, 4, 6, 'B7');
        $this->assertNull($this->out->order($orderId)['hold_reason']);

        // 8 – Pick 4 từ B-01: B-01 6/0/6; PICK-STG 48/48/0
        $line = $this->out->pickLines($orderId, true)[0];
        $this->assertSame(['B-01', 4], [$line['location_code'], (int) $line['qty']]);
        $this->out->confirmPick((int) $line['id'], 'B-01', self::SKU, 4, $this->operator);
        $this->assertSku(self::SKU, 54, 48, 6, 'B8');
        $this->assertPair(self::SKU, 'B-01', 6, 0, 6, 'B8');
        $this->assertPair(self::SKU, 'PICK-STG', 48, 48, 0, 'B8');
        $this->assertSame('PICKED', $this->out->order($orderId)['status']);

        // 9 – Packing (1)+(2): OUT-STG 48
        $this->out->packIn($orderId, $this->operator);
        $tracking = $this->out->packOut($orderId, [self::SKU => 48], $this->operator);
        $this->assertSku(self::SKU, 54, 48, 6, 'B9');
        $this->assertPair(self::SKU, 'OUT-STG', 48, 48, 0, 'B9');

        // 10 – Shipping 48: 6/0/6; B-01 6/0/6
        $this->out->ship($orderId, $tracking, $this->operator);
        $this->assertSku(self::SKU, 6, 0, 6, 'B10');
        $this->assertPair(self::SKU, 'B-01', 6, 0, 6, 'B10');

        // Công thức SKU 60 − 48 − 6 = 6; toàn kho 315 − 48 − 6 = 261
        $this->assertWarehouse(261, 261, 0, 'Cuối kịch bản');
        $t = $this->recon->totals();
        $this->assertSame([315, 0, 48, 6, 261], [$t['opening'], $t['received'], $t['shipped'], $t['adjust_minus'], $t['actual']]);
        $this->assertLedgerReconciles('ORD-DEMO-002');
    }

    public function testKhongConLocationDuHangThiBackorder(): void
    {
        // Đơn 58: A-01 50 (pick thiếu 4) + B-01 8 ⇒ sau kiểm kê, B-01 chỉ còn 2 Available ⇒ backorder 2.
        $orderId = $this->out->createOrder('ORD-BO', '', '', [['sku' => self::SKU, 'qty' => 58]], $this->operator);
        $this->out->reserve($orderId, $this->operator);
        $this->out->createPickList($orderId, $this->operator);
        $countId = null;
        foreach ($this->out->pickLines($orderId, true) as $line) {
            $actual = $line['location_code'] === 'A-01' ? 46 : (int) $line['qty'];
            $r = $this->out->confirmPick((int) $line['id'], $line['location_code'], self::SKU, $actual, $this->operator);
            $countId = $r['count_id'] ?? $countId;
        }
        $this->audit->submitCount($countId, 0, $this->operator);
        $this->audit->submitRecount($countId, 0, $this->operator);
        $this->audit->approve($countId, 'A-01 hết hàng thực tế', $this->supervisor);
        $re = $this->out->reallocate($orderId, $this->operator);
        $this->assertSame(2, $re['backorder']);
        $this->assertSame([['sku' => self::SKU, 'location' => 'B-01', 'qty' => 2]], $re['reserved']);
        $this->assertStringContainsString('Backorder', (string) $this->out->order($orderId)['hold_reason']);
        $this->assertSku(self::SKU, 56, 56, 0, 'Không tạo tồn ảo');
        $this->assertLedgerReconciles();
    }

    public function testCycleCountChiApDungStorage(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->audit->createCount(self::SKU, 'PICK-STG', $this->supervisor);
    }

    public function testNhanVienKhongDuocDuyetAdjustment(): void
    {
        $cc = $this->audit->createCount(self::SKU, 'B-01', $this->supervisor);
        $this->audit->submitCount($cc, 9, $this->operator);
        $this->audit->submitRecount($cc, 9, $this->operator);
        try {
            $this->audit->approve($cc, 'x', $this->operator);
            $this->fail('Nhân viên không được duyệt');
        } catch (BusinessRuleException) {
        }
        $this->assertPair(self::SKU, 'B-01', 10, 0, 10);
    }

    public function testDemKhopThiDongPhieuKhongDieuChinh(): void
    {
        $cc = $this->audit->createCount(self::SKU, 'B-01', $this->supervisor);
        $this->assertSame('CLOSED_MATCH', $this->audit->submitCount($cc, 10, $this->operator));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'ADJUST'")->fetchColumn());
    }
}
