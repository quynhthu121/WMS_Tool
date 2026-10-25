<?php

declare(strict_types=1);

namespace Tests\Phase1;

use App\Service\BusinessRuleException;
use App\Service\DemoService;
use Tests\Support\DemoTestCase;

final class InventoryCoreTest extends DemoTestCase
{
    public function testPresetMainKhopA1(): void
    {
        // A1 mục 2.4: A-01 = 145, B-01 = 160, 5 location còn lại = 0, tổng 305.
        $this->assertLocations(['RECEIVING' => 0, 'A-01' => 145, 'B-01' => 160, 'PICK-STG' => 0, 'PACK-01' => 0, 'OUT-STG' => 0, 'DOCK' => 0]);
        $this->assertWarehouse(305, 305, 0);
        $this->assertCount(8, $this->query->products());
        $this->assertCount(7, $this->query->locations());
        $this->assertSku('AO-001-M-DEN', 50, 0, 50);
        $this->assertSku('QUAN-002-L-DEN', 20, 0, 20);

        $opening = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'OPENING'")->fetchColumn();
        $this->assertSame(8, $opening, 'Tồn đầu kỳ phải nạp bằng 8 dòng OPENING');
        $this->assertLedgerReconciles();
        $totals = $this->recon->totals();
        $this->assertSame(305, $totals['opening']);
        $this->assertSame(0, $totals['received'], 'OPENING không tính là Nhập');
    }

    public function testPresetExceptionTong315(): void
    {
        $this->demo()->reset('exception');
        $this->assertWarehouse(315, 315, 0);
        $this->assertPair('AO-001-M-DEN', 'A-01', 50, 0, 50);
        $this->assertPair('AO-001-M-DEN', 'B-01', 10, 0, 10);
        $this->assertSku('AO-001-M-DEN', 60, 0, 60);
        $this->assertSame('exception', $this->demo()->currentPreset());
        $this->assertLedgerReconciles();
    }

    public function testResetLaiXoaGiaoDichCu(): void
    {
        $this->inventory->receive('AO-001-M-DEN', 20, ['code' => 'X']);
        $this->demo()->reset('main');
        $this->assertWarehouse(305, 305, 0);
        $this->assertSame(8, (int) $this->pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn());
    }

    public function testTriggerChanUpdateVaDeleteLedger(): void
    {
        try {
            $this->pdo->exec('UPDATE stock_movements SET qty = qty + 1');
            $this->fail('UPDATE stock_movements phải bị chặn');
        } catch (\PDOException $e) {
            $this->assertSame('45000', $e->getCode());
        }
        try {
            $this->pdo->exec('DELETE FROM stock_movements');
            $this->fail('DELETE stock_movements phải bị chặn');
        } catch (\PDOException $e) {
            $this->assertSame('45000', $e->getCode());
        }
        $this->assertSame(8, (int) $this->pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn());
    }

    public function testCheckChanOnHandAmVaReservedVuotOnHand(): void
    {
        foreach ([
            "UPDATE inventory_balances SET on_hand = -1 WHERE product_sku = 'AO-001-M-DEN'",
            "UPDATE inventory_balances SET reserved = on_hand + 1 WHERE product_sku = 'AO-001-M-DEN'",
            "UPDATE inventory_balances SET reserved = -1 WHERE product_sku = 'AO-001-M-DEN'",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
                $this->fail("CHECK phải chặn: {$sql}");
            } catch (\PDOException $e) {
                $this->assertStringContainsString('chk_balance_qty', $e->getMessage());
            }
        }
        try {
            $this->pdo->exec("INSERT INTO stock_movements (movement_type, product_sku, qty, to_location_id) SELECT 'RECEIVE', 'AO-001-M-DEN', 0, id FROM warehouse_locations WHERE code = 'RECEIVING'");
            $this->fail('CHECK phải chặn qty = 0');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('chk_movement_qty', $e->getMessage());
        }
        $this->assertSku('AO-001-M-DEN', 50, 0, 50);
    }

    public function testReserveVuotAvailableBiChanVaKhongDoiTon(): void
    {
        try {
            $this->inventory->reserve('AO-001-M-DEN', 'A-01', 51);
            $this->fail('Reserve vượt Available phải bị chặn');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('vượt Available', $e->getMessage());
        }
        $this->assertPair('AO-001-M-DEN', 'A-01', 50, 0, 50);
        $this->assertSame(8, (int) $this->pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn());
    }

    public function testReserveChiTuLocationAllocatable(): void
    {
        $this->inventory->receive('AO-001-M-DEN', 5);
        $this->expectException(BusinessRuleException::class);
        $this->inventory->reserve('AO-001-M-DEN', 'RECEIVING', 1);
    }

    public function testAvailableHienThiBangKhongOStagingVaDock(): void
    {
        $this->inventory->receive('AO-001-M-DEN', 20);
        $this->assertPair('AO-001-M-DEN', 'RECEIVING', 20, 0, 0);
        $this->assertSku('AO-001-M-DEN', 70, 0, 50);
    }

    public function testMoveSaiHuongBiChan(): void
    {
        $this->inventory->receive('AO-001-M-DEN', 20);
        foreach ([['PUTAWAY', 'RECEIVING', 'PICK-STG'], ['PUTAWAY', 'A-01', 'B-01'], ['PICK', 'A-01', 'DOCK']] as [$type, $from, $to]) {
            try {
                $this->inventory->move($type, 'AO-001-M-DEN', $from, $to, 1);
                $this->fail("{$type} {$from} → {$to} phải bị chặn");
            } catch (BusinessRuleException) {
                // đúng kỳ vọng
            }
        }
        $this->assertPair('AO-001-M-DEN', 'RECEIVING', 20, 0, 0);
        $this->assertLedgerReconciles();
    }

    public function testRollbackKhiLoiGiuaChung(): void
    {
        try {
            $this->inventory->transactional(function () {
                $this->inventory->receive('AO-001-M-DEN', 20, ['code' => 'ROLLBACK']);
                $this->inventory->move('PUTAWAY', 'AO-001-M-DEN', 'RECEIVING', 'A-01', 20);
                throw new \RuntimeException('Ép lỗi giữa chừng');
            });
        } catch (\RuntimeException) {
        }
        $this->assertWarehouse(305, 305, 0);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reference_code = 'ROLLBACK'")->fetchColumn());
        $this->assertLedgerReconciles();
    }

    public function testAdjustAmKhongDuocAnVaoReserved(): void
    {
        $this->inventory->reserve('AO-001-M-DEN', 'A-01', 48);
        try {
            $this->inventory->adjust('AO-001-M-DEN', 'A-01', -3);
            $this->fail('Adjustment âm làm On-hand < Reserved phải bị chặn');
        } catch (BusinessRuleException) {
        }
        $this->inventory->release('AO-001-M-DEN', 'A-01', 1);
        $this->inventory->adjust('AO-001-M-DEN', 'A-01', -3);
        $this->assertPair('AO-001-M-DEN', 'A-01', 47, 47, 0);
        $this->assertLedgerReconciles();
    }

    public function testHaiKetNoiSongSongCungReserve(): void
    {
        // Available 50; hai tiến trình cùng lúc giữ 30 → chỉ một được, reserved không bao giờ vượt on_hand.
        $startAt = microtime(true) + 1.0;
        $procs = [];
        foreach ([1, 2] as $i) {
            $cmd = [PHP_BINARY, dirname(__DIR__) . '/fixtures/reserve_worker.php', 'AO-001-M-DEN', 'A-01', '30', (string) $startAt];
            $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $results[] = trim(stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]));
            proc_close($p);
        }
        $this->assertCount(1, array_filter($results, fn ($r) => $r === 'OK'), 'Đúng một tiến trình reserve được: ' . implode(' | ', $results));
        $this->assertPair('AO-001-M-DEN', 'A-01', 50, 30, 20);
        $this->assertLedgerReconciles();
    }

    public function testResetBiChanNgoaiDbTestKhiChuaBat(): void
    {
        $this->assertTrue(DemoService::resetAllowed($this->pdo), 'DB test luôn được reset');
    }
}
