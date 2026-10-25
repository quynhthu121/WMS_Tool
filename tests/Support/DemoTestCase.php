<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\DemoService;
use App\Service\InventoryQuery;
use App\Service\InventoryService;
use App\Service\ReconciliationService;

/** Test case reset DB test về một preset trước mỗi test. */
abstract class DemoTestCase extends DbTestCase
{
    protected InventoryService $inventory;
    protected InventoryQuery $query;
    protected ReconciliationService $recon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = new InventoryService($this->pdo);
        $this->query = new InventoryQuery($this->pdo);
        $this->recon = new ReconciliationService($this->pdo);
        $this->demo()->reset($this->preset());
    }

    protected function preset(): string
    {
        return 'main';
    }

    protected function demo(): DemoService
    {
        return new DemoService($this->pdo, dirname(__DIR__, 2), Scenarios::afterInboundRunner());
    }

    protected function assertLedgerReconciles(string $message = ''): void
    {
        $this->assertSame([], ReconciliationService::pairMismatches($this->pdo), 'Balance lệch ledger ' . $message);
        $this->assertTrue($this->recon->totals()['ok'], 'Công thức tồn toàn kho không khớp ' . $message);
    }

    /** Khẳng định On-hand theo location: ['A-01' => 145, ...] (location không nêu không kiểm). */
    protected function assertLocations(array $expected, string $message = ''): void
    {
        $actual = $this->query->onHandByLocation();
        foreach ($expected as $code => $qty) {
            $this->assertSame($qty, $actual[$code] ?? 0, "On-hand tại {$code} {$message}");
        }
    }

    protected function assertWarehouse(int $onHand, int $available, ?int $reserved = null, string $message = ''): void
    {
        $t = $this->query->warehouseTotals();
        $this->assertSame($onHand, $t['on_hand'], "Tổng tồn {$message}");
        $this->assertSame($available, $t['available'], "Tồn khả dụng {$message}");
        if ($reserved !== null) {
            $this->assertSame($reserved, $t['reserved'], "Reserved toàn kho {$message}");
        }
    }

    protected function assertSku(string $sku, int $onHand, int $reserved, int $available, string $message = ''): void
    {
        $this->assertSame(['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $available],
            $this->query->skuTotals($sku), "{$sku} (On-hand/Reserved/Available) {$message}");
    }

    protected function assertPair(string $sku, string $loc, int $onHand, int $reserved, int $available, string $message = ''): void
    {
        $this->assertSame(['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $available],
            $this->query->pair($sku, $loc), "{$sku} @ {$loc} {$message}");
    }
}
