<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Chạy kịch bản trình diễn từng bước. Mỗi bước thực thi service thật, đo Trước/Sau và so với Kỳ vọng.
 * Tiến độ lưu trong demo_state nên trang web có thể bấm "Bước tiếp theo" qua nhiều request.
 */
final class DemoRunner
{
    private DemoService $demo;

    public function __construct(private PDO $pdo, string $projectRoot)
    {
        $this->demo = new DemoService($pdo, $projectRoot, [ScenarioService::class, 'runInboundHappyPath']);
    }

    /**
     * Bắt đầu kịch bản. $continue = true: kịch bản 2 nối tiếp ngay sau kịch bản 1 (không reset).
     */
    public function start(string $key, bool $continue = false): void
    {
        $scenario = DemoScenarios::get($key);
        if ($continue) {
            if (($scenario['continues'] ?? null) === null || $this->demo->getState('run.completed') !== $scenario['continues']) {
                throw new BusinessRuleException('Chỉ nối tiếp được khi kịch bản trước đã chạy xong.');
            }
        } else {
            $this->demo->reset($scenario['preset']);
        }
        $this->demo->setState('run.scenario', $key);
        $this->demo->setState('run.step', '0');
        $this->demo->setState('run.log', json_encode([], JSON_UNESCAPED_UNICODE));
        $this->demo->setState('run.completed', null);
        $this->demo->setState('run.mode', $continue ? 'continue' : 'preset');
    }

    /** @return array{scenario:?array, step:int, log:list<array>, done:bool, completed:?string, mode:?string} */
    public function state(): array
    {
        $key = $this->demo->getState('run.scenario');
        $scenario = $key ? DemoScenarios::get($key) : null;
        $step = (int) ($this->demo->getState('run.step') ?? 0);
        return [
            'scenario' => $scenario,
            'step' => $step,
            'log' => json_decode($this->demo->getState('run.log') ?? '[]', true) ?: [],
            'done' => $scenario !== null && $step >= count($scenario['steps']),
            'completed' => $this->demo->getState('run.completed'),
            'mode' => $this->demo->getState('run.mode'),
        ];
    }

    /** Thực thi bước kế tiếp. @return array<string, mixed> kết quả bước */
    public function next(): array
    {
        $state = $this->state();
        $scenario = $state['scenario'];
        if ($scenario === null) {
            throw new BusinessRuleException('Chưa chọn kịch bản.');
        }
        if ($state['done']) {
            throw new BusinessRuleException('Kịch bản đã chạy hết các bước.');
        }
        $def = $scenario['steps'][$state['step']];
        $before = DemoScenarios::measure($this->pdo, $scenario['columns']);
        $mapBefore = $this->mapState();
        $error = null;
        $message = '';
        try {
            $message = (string) ($def['run'])($this->pdo);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $after = DemoScenarios::measure($this->pdo, $scenario['columns']);
        $mapAfter = $this->mapState();
        $ok = $error === null && $after === $def['expect'];
        $result = [
            'index' => $state['step'],
            'label' => $def['label'],
            'role' => $def['role'],
            'before' => $before,
            'after' => $after,
            'expect' => $def['expect'],
            'ok' => $ok,
            'message' => $error ?? $message,
            'error' => $error !== null,
            'reconciled' => ReconciliationService::pairMismatches($this->pdo) === [],
            'map_before' => $mapBefore,
            'map_after' => $mapAfter,
        ];
        if ($error !== null) {
            // Không tiến bước khi lỗi để người trình bày xử lý rồi bấm lại.
            return $result;
        }
        $log = $state['log'];
        $log[] = $result;
        $this->demo->setState('run.log', json_encode($log, JSON_UNESCAPED_UNICODE));
        $this->demo->setState('run.step', (string) ($state['step'] + 1));
        if ($state['step'] + 1 >= count($scenario['steps'])) {
            $this->demo->setState('run.completed', $scenario['key']);
        }
        return $result;
    }

    /** Chạy hết các bước còn lại (CLI). @return list<array<string, mixed>> */
    public function runAll(): array
    {
        $results = [];
        while (!$this->state()['done']) {
            $r = $this->next();
            $results[] = $r;
            if ($r['error']) {
                break;
            }
        }
        return $results;
    }

    /**
     * Trạng thái gọn của sơ đồ kho để so sánh trước / sau một bước.
     * @return array<string, array{on_hand:int, reserved:int, locked:bool}>
     */
    public function mapState(): array
    {
        $out = [];
        foreach ((new InventoryQuery($this->pdo))->locationSnapshot() as $code => $l) {
            $out[$code] = ['on_hand' => $l['on_hand'], 'reserved' => $l['reserved'], 'locked' => $l['locked']];
        }
        return $out;
    }

    public function demo(): DemoService
    {
        return $this->demo;
    }
}
