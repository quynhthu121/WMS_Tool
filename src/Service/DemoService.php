<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;
use PDO;

/**
 * Reset dữ liệu demo về đầu kỳ và nạp preset.
 *
 * Reset chỉ TRUNCATE bảng giao dịch WMS (không đụng users, partners, bảng kế thừa),
 * rồi upsert dữ liệu chủ và nạp tồn đầu kỳ bằng movement OPENING.
 * Chỉ được chạy khi DB là *_test hoặc DEMO_RESET_ENABLED=1.
 */
final class DemoService
{
    public const PRESETS = [
        'main' => 'Đầu kỳ theo A1 (tổng 305)',
        'after_inbound' => 'A1 + ASN-DEMO-001 đã put-away xong (tổng 350)',
        'exception' => 'Kịch bản độc lập A4 §3.3: AO-001-M-DEN A-01 = 50, B-01 = 10 (tổng 315) – giả định bổ sung',
    ];

    /** Bảng giao dịch bị xóa khi Reset (bảng nào chưa tồn tại thì bỏ qua). */
    private const TRANSACTIONAL_TABLES = [
        'stock_movements', 'inventory_balances',
        'putaway_tasks', 'qc_records', 'goods_receipt_items', 'goods_receipts',
        'oms_outbox', 'shipments', 'pick_list_lines', 'pick_lists', 'reservations',
        'outbound_order_lines', 'outbound_orders', 'cycle_counts', 'demo_state',
    ];

    /** @var null|callable(PDO): void chạy kịch bản Inbound cho preset after_inbound */
    private $afterInboundRunner;

    public function __construct(private PDO $pdo, private string $projectRoot, ?callable $afterInboundRunner = null)
    {
        $this->afterInboundRunner = $afterInboundRunner;
    }

    public static function resetAllowed(PDO $pdo): bool
    {
        return str_ends_with(Db::databaseName($pdo), '_test') || getenv('DEMO_RESET_ENABLED') === '1';
    }

    public function reset(string $preset): void
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new BusinessRuleException("Preset không hợp lệ: {$preset}");
        }
        if (!self::resetAllowed($this->pdo)) {
            throw new BusinessRuleException('Reset đang tắt. Đặt DEMO_RESET_ENABLED=1 trong .env để bật trên DB demo.');
        }

        $existing = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::TRANSACTIONAL_TABLES as $table) {
                if (in_array($table, $existing, true)) {
                    $this->pdo->exec("TRUNCATE TABLE `{$table}`");
                }
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $seed = $this->loadSeed($preset === 'exception' ? 'demo_exception' : 'demo_main');
        $inventory = new InventoryService($this->pdo);
        $inventory->transactional(function () use ($seed, $inventory) {
            $warehouseId = $this->upsertMasterData($seed);
            foreach ($seed['opening'] as $line) {
                $inventory->opening($line['sku'], $line['location'], (int) $line['qty'],
                    ['code' => 'OPENING', 'type' => 'opening', 'note' => 'Tồn đầu kỳ (SIMULATION)']);
            }
            return $warehouseId;
        });

        if ($preset === 'after_inbound') {
            if ($this->afterInboundRunner === null) {
                throw new BusinessRuleException('Preset after_inbound cần trình chạy kịch bản Inbound.');
            }
            ($this->afterInboundRunner)($this->pdo);
        }
        $this->setState('preset', $preset);
    }

    public function currentPreset(): ?string
    {
        return $this->getState('preset');
    }

    public function getState(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT state_value FROM demo_state WHERE state_key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    public function setState(string $key, ?string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO demo_state (state_key, state_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)');
        $stmt->execute([$key, $value]);
    }

    /** @return array<string, mixed> */
    private function loadSeed(string $name): array
    {
        $seed = json_decode((string) file_get_contents("{$this->projectRoot}/database/seeds/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
        if (isset($seed['extends'])) {
            $base = $this->loadSeed($seed['extends']);
            $base['opening'] = array_merge($base['opening'], $seed['opening_extra'] ?? []);
            $base['_label'] = $seed['_label'];
            return $base;
        }
        return $seed;
    }

    private function upsertMasterData(array $seed): int
    {
        $wh = $seed['warehouse'];
        $this->pdo->prepare('INSERT INTO warehouses (code, name, active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1')
            ->execute([$wh['code'], $wh['name']]);
        $stmt = $this->pdo->prepare('SELECT id FROM warehouses WHERE code = ?');
        $stmt->execute([$wh['code']]);
        $warehouseId = (int) $stmt->fetchColumn();

        $loc = $this->pdo->prepare('INSERT INTO warehouse_locations (warehouse_id, code, name, zone_type, is_allocatable, pick_sequence, active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE name = VALUES(name), zone_type = VALUES(zone_type), is_allocatable = VALUES(is_allocatable),
                pick_sequence = VALUES(pick_sequence), active = 1');
        foreach ($seed['locations'] as $l) {
            $loc->execute([$warehouseId, $l['code'], $l['name'], $l['zone_type'], $l['is_allocatable'], $l['pick_sequence']]);
        }
        $codes = $this->pdo->query('SELECT code, id FROM warehouse_locations WHERE warehouse_id = ' . $warehouseId)->fetchAll(PDO::FETCH_KEY_PAIR);

        $prod = $this->pdo->prepare("INSERT INTO products (sku, name, size, color, uom, putaway_location_id, is_legacy)
            VALUES (?, ?, ?, ?, 'Cái', ?, 0)
            ON DUPLICATE KEY UPDATE name = VALUES(name), size = VALUES(size), color = VALUES(color), uom = 'Cái',
                putaway_location_id = VALUES(putaway_location_id), is_legacy = 0");
        foreach ($seed['products'] as $p) {
            $prod->execute([$p['sku'], $p['name'], $p['size'], $p['color'], $codes[$p['putaway_location']] ?? null]);
        }
        return $warehouseId;
    }
}
