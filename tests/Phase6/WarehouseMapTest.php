<?php

declare(strict_types=1);

namespace Tests\Phase6;

use App\Service\DemoRunner;
use App\Support\WarehouseMap;
use Tests\Support\DemoTestCase;

/** Sơ đồ kho đọc đúng tồn theo location và đánh dấu ô vừa thay đổi sau mỗi bước stepper. */
final class WarehouseMapTest extends DemoTestCase
{
    public function testAnhChupLocationKhopA1(): void
    {
        $snap = $this->query->locationSnapshot();
        $this->assertSame(['RECEIVING', 'A-01', 'B-01', 'PICK-STG', 'PACK-01', 'OUT-STG', 'DOCK'], array_keys($snap));
        $this->assertSame(145, $snap['A-01']['on_hand']);
        $this->assertSame(160, $snap['B-01']['on_hand']);
        $this->assertSame(0, $snap['RECEIVING']['on_hand']);
        $this->assertSame(['AO-001-L-DEN' => 40, 'AO-001-M-DEN' => 50, 'AO-002-L-TRG' => 25, 'AO-002-M-TRG' => 30], $snap['A-01']['skus']);
    }

    public function testBuocReceivingDanhDauRECEIVINGVaHangNhap(): void
    {
        $runner = new DemoRunner($this->pdo, dirname(__DIR__, 2));
        $runner->start('inbound');
        for ($i = 0; $i < 5; $i++) {
            $r = $runner->next();
        }
        // Bước 5: +45 vào RECEIVING, chỉ ô RECEIVING đổi.
        $this->assertSame(45, $r['map_after']['RECEIVING']['on_hand'] - $r['map_before']['RECEIVING']['on_hand']);
        $html = WarehouseMap::render($this->query->locationSnapshot(), $r['map_before']);
        $this->assertStringContainsString('+45 nhập', $html);
        $this->assertSame(1, substr_count($html, 'is-changed'));
        $this->assertMatchesRegularExpression('/is-staging has-stock is-changed[^>]*>.*?RECEIVING.*?\+45/s', $html);
    }

    public function testPickThieuToDoOBiKhoa(): void
    {
        $runner = new DemoRunner($this->pdo, dirname(__DIR__, 2));
        $runner->start('exception');
        $runner->next();
        $r = $runner->next(); // Pick 44/48 ⇒ A-01 bị khóa
        $this->assertTrue($r['map_after']['A-01']['locked']);
        $html = WarehouseMap::render($this->query->locationSnapshot(), $r['map_before']);
        $this->assertStringContainsString('is-locked', $html);
        $this->assertStringContainsString('>khóa<', $html);
        $this->assertStringContainsString('giữ 44', $html, 'Reserved đi theo hàng sang PICK-STG');
    }

    public function testShippingHienHangXuatRaKhoiKho(): void
    {
        $runner = new DemoRunner($this->pdo, dirname(__DIR__, 2));
        $runner->start('outbound_audit');
        for ($i = 0; $i < 6; $i++) {
            $r = $runner->next();
        }
        $html = WarehouseMap::render($this->query->locationSnapshot(), $r['map_before']);
        $this->assertStringContainsString('−30 xuất', $html);
    }
}
