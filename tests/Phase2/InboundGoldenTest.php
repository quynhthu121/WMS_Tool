<?php

declare(strict_types=1);

namespace Tests\Phase2;

use App\Service\BusinessRuleException;
use App\Service\ReceivingService;
use App\Service\ScenarioService;
use App\Support\Actor;
use Tests\Support\DemoTestCase;

/**
 * Golden test bảng Inbound A2 §2.3–2.5 (docs/source/A2_inbound.txt).
 * Cột: RECEIVING / A-01 / B-01 / Tổng tồn / Tồn khả dụng.
 */
final class InboundGoldenTest extends DemoTestCase
{
    private ReceivingService $svc;
    private Actor $receiver;
    private Actor $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ReceivingService($this->pdo);
        $this->receiver = new Actor(null, 'Receiver', Actor::OPERATOR);
        $this->supervisor = new Actor(null, 'Giám sát', Actor::SUPERVISOR);
    }

    private function row(int $receiving, int $a01, int $b01, int $total, int $available, string $label): void
    {
        $this->assertLocations(['RECEIVING' => $receiving, 'A-01' => $a01, 'B-01' => $b01], $label);
        $this->assertWarehouse($total, $available, null, $label);
        $this->assertLocations(['PICK-STG' => 0, 'PACK-01' => 0, 'OUT-STG' => 0, 'DOCK' => 0], $label);
        $this->assertLedgerReconciles($label);
    }

    /** @return array<string, int> sku => item id */
    private function itemIds(int $id): array
    {
        $out = [];
        foreach ($this->svc->items($id) as $i) {
            $out[$i['product_sku']] = (int) $i['id'];
        }
        return $out;
    }

    public function testDuongDiChuanAsnDemo001(): void
    {
        $this->row(0, 145, 160, 305, 305, 'Đầu kỳ');

        // Bước 1–4: ASN, check-in, kiểm đếm, QC – hệ thống chưa ghi nhận tồn.
        $id = $this->svc->createAsn('ASN-DEMO-001', ScenarioService::SUPPLIER, ScenarioService::ASN_DEMO_001, $this->receiver);
        $this->row(0, 145, 160, 305, 305, 'Sau bước 1 (ASN chỉ là dự kiến)');
        $this->svc->checkIn($id, $this->receiver);
        $items = $this->itemIds($id);
        $this->svc->count($id, [
            $items['AO-001-M-DEN'] => ['counted' => 20],
            $items['AO-002-L-TRG'] => ['counted' => 10],
            $items['QUAN-002-L-DEN'] => ['counted' => 15],
        ], $this->receiver);
        $this->svc->qc($id, [], $this->receiver);
        $this->row(0, 145, 160, 305, 305, 'Sau bước 1–4');
        $this->assertFalse($this->svc->hasVariance($id), 'Đường chuẩn không có chênh lệch');

        // Bước 5: đường chuẩn không cần Giám sát duyệt.
        $this->assertSame(45, $this->svc->confirmReceiving($id, $this->receiver));
        $this->row(45, 145, 160, 350, 305, 'Sau bước 5 (Receiving)');
        $this->assertPair('AO-001-M-DEN', 'RECEIVING', 20, 0, 0);
        $this->assertPair('AO-002-L-TRG', 'RECEIVING', 10, 0, 0);
        $this->assertPair('QUAN-002-L-DEN', 'RECEIVING', 15, 0, 0);

        // Bước 6: hệ thống tạo 3 task, gợi ý A-01 cho Áo, B-01 cho Quần.
        $tasks = [];
        foreach ($this->svc->putawayTasks($id) as $t) {
            $tasks[$t['product_sku']] = $t;
        }
        $this->assertSame('A-01', $tasks['AO-001-M-DEN']['target_code']);
        $this->assertSame('A-01', $tasks['AO-002-L-TRG']['target_code']);
        $this->assertSame('B-01', $tasks['QUAN-002-L-DEN']['target_code']);

        // Bước 9a/9b/9c.
        $this->svc->confirmPutaway((int) $tasks['AO-001-M-DEN']['id'], 'RECEIVING', 'AO-001-M-DEN', 'A-01', $this->receiver);
        $this->row(25, 165, 160, 350, 325, 'Sau 9a');
        $this->svc->confirmPutaway((int) $tasks['AO-002-L-TRG']['id'], 'RECEIVING', 'AO-002-L-TRG', 'A-01', $this->receiver);
        $this->row(15, 175, 160, 350, 335, 'Sau 9b');
        $this->svc->confirmPutaway((int) $tasks['QUAN-002-L-DEN']['id'], 'RECEIVING', 'QUAN-002-L-DEN', 'B-01', $this->receiver);
        $this->row(0, 175, 175, 350, 350, 'Sau 9c');

        // Bước 10: đóng phiếu; ASN 45 = thực nhận 45 = put-away 45.
        $this->svc->close($id, $this->supervisor);
        $sum = ['expected_qty' => 0, 'received_qty' => 0, 'putaway_qty' => 0];
        foreach ($this->svc->items($id) as $i) {
            foreach ($sum as $k => $_) {
                $sum[$k] += (int) $i[$k];
            }
        }
        $this->assertSame(['expected_qty' => 45, 'received_qty' => 45, 'putaway_qty' => 45], $sum);
        $this->assertSame('CLOSED', $this->svc->receipt($id)['status']);
        $this->assertSku('AO-001-M-DEN', 70, 0, 70);
        $this->assertSku('AO-002-L-TRG', 35, 0, 35);
        $this->assertSku('QUAN-002-L-DEN', 35, 0, 35);

        // Ledger: RECEIVE đúng 3 dòng (+45), PUTAWAY 3 dòng.
        $counts = $this->pdo->query("SELECT movement_type, SUM(qty) FROM stock_movements WHERE reference_code = 'ASN-DEMO-001' GROUP BY movement_type")->fetchAll(\PDO::FETCH_KEY_PAIR);
        ksort($counts);
        $this->assertEquals(['PUTAWAY' => 45, 'RECEIVE' => 45], $counts);
    }

    public function testXacNhanReceivingHaiLanKhongCongThem(): void
    {
        $id = $this->prepareQcDone(['AO-001-M-DEN' => 20, 'AO-002-L-TRG' => 10, 'QUAN-002-L-DEN' => 15]);
        $this->svc->confirmReceiving($id, $this->receiver);
        try {
            $this->svc->confirmReceiving($id, $this->receiver);
            $this->fail('Xác nhận lần hai phải bị từ chối');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('đã được xác nhận', $e->getMessage());
        }
        $this->row(45, 145, 160, 350, 305, 'Sau xác nhận lần hai');
    }

    public function testPutawayHaiLanKhongChuyenThem(): void
    {
        $id = $this->prepareQcDone(['AO-001-M-DEN' => 20, 'AO-002-L-TRG' => 10, 'QUAN-002-L-DEN' => 15]);
        $this->svc->confirmReceiving($id, $this->receiver);
        $task = $this->svc->putawayTasks($id)[0];
        $this->svc->confirmPutaway((int) $task['id'], 'RECEIVING', $task['product_sku'], $task['target_code'], $this->receiver);
        $this->expectException(BusinessRuleException::class);
        try {
            $this->svc->confirmPutaway((int) $task['id'], 'RECEIVING', $task['product_sku'], $task['target_code'], $this->receiver);
        } finally {
            $this->row(25, 165, 160, 350, 325, 'Sau put-away lần hai');
        }
    }

    public function testE1NhanThieu(): void
    {
        // ASN dòng 1 là 20, thực nhận 18.
        $id = $this->prepareQcDone(['AO-001-M-DEN' => 18, 'AO-002-L-TRG' => 10, 'QUAN-002-L-DEN' => 15], ['AO-001-M-DEN' => 'Nhà cung cấp giao thiếu 2']);
        $this->assertTrue($this->svc->hasVariance($id));
        try {
            $this->svc->confirmReceiving($id, $this->receiver);
            $this->fail('Có chênh lệch phải cần Giám sát duyệt');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Giám sát', $e->getMessage());
        }
        $this->row(0, 145, 160, 305, 305, 'Chưa duyệt');

        $this->assertSame(43, $this->svc->confirmReceiving($id, $this->supervisor));
        $this->row(43, 145, 160, 348, 305, 'E1 sau Receiving (+18, không phải +20)');
        $this->putawayAll($id);
        $this->assertSku('AO-001-M-DEN', 68, 0, 68);

        $shortages = $this->svc->openShortages();
        $this->assertCount(1, $shortages);
        $this->assertSame(2, (int) $shortages[0]['shortage_qty']);
        $this->svc->resolveShortage((int) $shortages[0]['id'], 'WAITING', 'Chờ nhà cung cấp giao bổ sung', $this->supervisor);
        $this->assertCount(1, $this->svc->openShortages(), 'Phần thiếu vẫn được theo dõi');
        $this->assertNotNull($this->svc->receipt($id)['variance_approved_at']);
    }

    public function testE2NhanThuaChuaDuyetKhongDoiTon(): void
    {
        // ASN dòng 2 là 10, thực nhận 12.
        $id = $this->prepareQcDone(['AO-001-M-DEN' => 20, 'AO-002-L-TRG' => 12, 'QUAN-002-L-DEN' => 15], ['AO-002-L-TRG' => 'Giao thừa 2']);
        // Giám sát duyệt chênh lệch nhưng KHÔNG duyệt nhận thừa ⇒ chỉ nhận 10.
        $this->assertSame(45, $this->svc->confirmReceiving($id, $this->supervisor, false));
        $this->row(45, 145, 160, 350, 305, 'E2 chưa duyệt nhận thừa');
        $item = $this->findItem($id, 'AO-002-L-TRG');
        $this->assertSame([10, 2, 'PENDING'], [(int) $item['received_qty'], (int) $item['over_qty'], $item['over_status']]);

        // Nhân viên không được duyệt nhận thừa.
        try {
            $this->svc->resolveOverReceipt((int) $item['id'], true, $this->receiver);
            $this->fail('Nhân viên không được duyệt nhận thừa');
        } catch (BusinessRuleException) {
        }
        // Giám sát duyệt ⇒ +2, tổng 12 cho dòng này.
        $this->svc->resolveOverReceipt((int) $item['id'], true, $this->supervisor);
        $this->row(47, 145, 160, 352, 305, 'E2 sau khi Giám sát duyệt nhận thừa');
        $this->putawayAll($id);
        $this->assertSku('AO-002-L-TRG', 37, 0, 37);
        $this->svc->close($id, $this->supervisor);
    }

    public function testE2DuyetNhanThuaNgayKhiXacNhan(): void
    {
        $id = $this->prepareQcDone(['AO-001-M-DEN' => 20, 'AO-002-L-TRG' => 12, 'QUAN-002-L-DEN' => 15], ['AO-002-L-TRG' => 'Giao thừa 2']);
        try {
            $this->svc->confirmReceiving($id, $this->receiver, true);
            $this->fail('Nhân viên không được duyệt nhận thừa');
        } catch (BusinessRuleException) {
        }
        $this->assertSame(47, $this->svc->confirmReceiving($id, $this->supervisor, true));
        $this->row(47, 145, 160, 352, 305, 'E2 duyệt nhận thừa 12');
        $this->assertNotNull($this->svc->receipt($id)['over_receipt_approved_at']);
    }

    public function testE3HangLoiKhongVaoTon(): void
    {
        // Dòng 3: 15, đạt 13, lỗi 2.
        $id = $this->prepareCounted(['AO-001-M-DEN' => 20, 'AO-002-L-TRG' => 10, 'QUAN-002-L-DEN' => 15]);
        $items = $this->itemIds($id);
        $this->svc->qc($id, [$items['QUAN-002-L-DEN'] => ['fail' => 2, 'reason' => 'Rách đường may']], $this->receiver);
        $this->assertSame(43, $this->svc->confirmReceiving($id, $this->supervisor));
        $this->row(43, 145, 160, 348, 305, 'E3 sau Receiving');
        $this->putawayAll($id);
        $this->assertPair('QUAN-002-L-DEN', 'B-01', 33, 0, 33);
        $qc = $this->svc->qcRecords($id);
        $this->assertCount(1, $qc);
        $this->assertSame(2, (int) $qc[0]['fail_qty']);
        $this->assertSame('RETURN_TO_SUPPLIER', $qc[0]['disposition']);
        $this->row(0, 175, 173, 348, 348, 'E3 sau put-away');
    }

    public function testE4QuetSaiLocationVaSkuBiChan(): void
    {
        $id = $this->prepareQcDone(['AO-001-M-DEN' => 20, 'AO-002-L-TRG' => 10, 'QUAN-002-L-DEN' => 15]);
        $this->svc->confirmReceiving($id, $this->receiver);
        $task = null;
        foreach ($this->svc->putawayTasks($id) as $t) {
            if ($t['product_sku'] === 'AO-001-M-DEN') {
                $task = $t;
            }
        }
        foreach ([
            ['RECEIVING', 'AO-001-M-DEN', 'PICK-STG', 'không phải Storage'],
            ['RECEIVING', 'AO-001-M-DEN', 'B-01', 'không khớp task'],
            ['RECEIVING', 'AO-001-M-DEN', 'Z-99', 'không phải Storage'],
            ['A-01', 'AO-001-M-DEN', 'A-01', 'Sai location nguồn'],
            ['RECEIVING', 'AO-001-L-DEN', 'A-01', 'Sai SKU'],
        ] as [$src, $sku, $dst, $msg]) {
            try {
                $this->svc->confirmPutaway((int) $task['id'], $src, $sku, $dst, $this->receiver);
                $this->fail("Quét {$src}/{$sku}/{$dst} phải bị chặn");
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $this->row(45, 145, 160, 350, 305, 'E4 không đổi tồn');
        $this->svc->confirmPutaway((int) $task['id'], 'receiving', 'ao-001-m-den', 'a-01', $this->receiver);
        $this->row(25, 165, 160, 350, 325, 'E4 quét đúng');
    }

    public function testPresetAfterInboundTong350(): void
    {
        $this->demo()->reset('after_inbound');
        $this->row(0, 175, 175, 350, 350, 'Preset after_inbound');
        $this->assertSame('CLOSED', $this->svc->receipt((int) $this->svc->receiptIdByCode('ASN-DEMO-001'))['status']);
    }

    // ---------------------------------------------------------------- helpers

    private function prepareCounted(array $counted, array $reasons = []): int
    {
        $id = $this->svc->createAsn('ASN-DEMO-001', ScenarioService::SUPPLIER, ScenarioService::ASN_DEMO_001, $this->receiver);
        $this->svc->checkIn($id, $this->receiver);
        $items = $this->itemIds($id);
        $input = [];
        foreach ($counted as $sku => $qty) {
            $input[$items[$sku]] = ['counted' => $qty, 'reason' => $reasons[$sku] ?? ''];
        }
        $this->svc->count($id, $input, $this->receiver);
        return $id;
    }

    private function prepareQcDone(array $counted, array $reasons = []): int
    {
        $id = $this->prepareCounted($counted, $reasons);
        $this->svc->qc($id, [], $this->receiver);
        return $id;
    }

    private function putawayAll(int $id): void
    {
        foreach ($this->svc->putawayTasks($id, true) as $t) {
            $this->svc->confirmPutaway((int) $t['id'], 'RECEIVING', $t['product_sku'], $t['target_code'], $this->receiver);
        }
    }

    private function findItem(int $id, string $sku): array
    {
        foreach ($this->svc->items($id) as $i) {
            if ($i['product_sku'] === $sku) {
                return $i;
            }
        }
        $this->fail("Không có dòng {$sku}");
    }
}
