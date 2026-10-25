<?php

declare(strict_types=1);

namespace Tests\Phase6;

use App\Service\DemoRunner;
use App\Service\EvidenceService;
use Tests\Support\DemoTestCase;

/** Ba kịch bản stepper chạy bằng service thật phải khớp mọi cột Kỳ vọng; trang Bằng chứng ✓ sau mỗi kịch bản. */
final class DemoRunnerTest extends DemoTestCase
{
    private DemoRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new DemoRunner($this->pdo, dirname(__DIR__, 2));
    }

    private function assertAllStepsPass(array $results, int $expectedSteps): void
    {
        $this->assertCount($expectedSteps, $results);
        foreach ($results as $r) {
            $this->assertFalse($r['error'], $r['label'] . ': ' . $r['message']);
            $this->assertSame($r['expect'], $r['after'], $r['label']);
            $this->assertTrue($r['ok'], $r['label']);
            $this->assertTrue($r['reconciled'], $r['label'] . ' – đối soát');
        }
    }

    private function assertEvidence(array $expected): void
    {
        foreach ((new EvidenceService($this->pdo))->all() as $e) {
            $this->assertSame($expected[$e['no']], $e['status'], "Bằng chứng #{$e['no']}: " . implode(' | ', $e['details']));
        }
    }

    public function testKichBan1RoiNoiTiepKichBan2(): void
    {
        $this->runner->start('inbound');
        $this->assertAllStepsPass($this->runner->runAll(), 9);
        $this->assertSame('inbound', $this->runner->state()['completed']);
        $this->assertEvidence([1 => 'pass', 2 => 'pass', 3 => 'pass', 4 => 'nodata', 5 => 'nodata', 6 => 'nodata']);

        $this->runner->start('outbound_audit', true);
        $this->assertAllStepsPass($this->runner->runAll(), 8);
        $this->assertWarehouse(318, 318, 0);
        $this->assertEvidence([1 => 'pass', 2 => 'pass', 3 => 'pass', 4 => 'pass', 5 => 'pass', 6 => 'pass']);
    }

    public function testKichBan2TuPresetAfterInbound(): void
    {
        $this->runner->start('outbound_audit');
        $this->assertAllStepsPass($this->runner->runAll(), 8);
        $this->assertWarehouse(318, 318, 0);
    }

    public function testKichBan3NgoaiLe(): void
    {
        $this->runner->start('exception');
        $this->assertAllStepsPass($this->runner->runAll(), 10);
        $this->assertWarehouse(261, 261, 0);
        $this->assertEvidence([1 => 'pass', 2 => 'nodata', 3 => 'pass', 4 => 'pass', 5 => 'pass', 6 => 'pass']);
    }

    public function testKhongNoiTiepKhiChuaXongKichBan1(): void
    {
        $this->expectException(\App\Service\BusinessRuleException::class);
        $this->runner->start('outbound_audit', true);
    }
}
