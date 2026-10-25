<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Đối soát: tồn hiện tại phải được giải thích bằng ledger (North Star).
 * - Mức cặp: inventory_balances == tổng đại số stock_movements (kể cả OPENING).
 * - Toàn kho: Tồn cuối = Đầu kỳ + Nhập (RECEIVE) − Xuất (SHIP) ± ADJUST.
 */
final class ReconciliationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Mọi cặp xuất hiện ở balance hoặc ledger, kèm số theo ledger và cờ khớp.
     * @return list<array<string, mixed>>
     */
    public function pairs(): array
    {
        return self::compute($this->pdo, false);
    }

    /** @return list<array<string, mixed>> chỉ các cặp lệch */
    public static function pairMismatches(PDO $pdo): array
    {
        return self::compute($pdo, true);
    }

    /**
     * @return array{opening:int, received:int, shipped:int, adjust_plus:int, adjust_minus:int,
     *               expected:int, actual:int, ok:bool}
     */
    public function totals(): array
    {
        $row = $this->pdo->query("SELECT
                COALESCE(SUM(CASE WHEN movement_type = 'OPENING' THEN qty END), 0) AS opening,
                COALESCE(SUM(CASE WHEN movement_type = 'RECEIVE' THEN qty END), 0) AS received,
                COALESCE(SUM(CASE WHEN movement_type = 'SHIP' THEN qty END), 0) AS shipped,
                COALESCE(SUM(CASE WHEN movement_type = 'ADJUST' AND to_location_id IS NOT NULL THEN qty END), 0) AS adjust_plus,
                COALESCE(SUM(CASE WHEN movement_type = 'ADJUST' AND from_location_id IS NOT NULL THEN qty END), 0) AS adjust_minus
            FROM stock_movements")->fetch();
        $row = array_map('intval', $row);
        $row['expected'] = $row['opening'] + $row['received'] - $row['shipped'] + $row['adjust_plus'] - $row['adjust_minus'];
        $row['actual'] = (int) $this->pdo->query('SELECT COALESCE(SUM(on_hand), 0) FROM inventory_balances')->fetchColumn();
        $row['ok'] = $row['expected'] === $row['actual'];
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private static function compute(PDO $pdo, bool $onlyMismatches): array
    {
        $ledger = $pdo->query("SELECT product_sku, location_id, SUM(oh) AS ledger_on_hand, SUM(rs) AS ledger_reserved FROM (
                SELECT product_sku, to_location_id AS location_id,
                       IF(movement_type IN ('RESERVE', 'UNRESERVE'), 0, qty) AS oh, reserved_moved AS rs
                FROM stock_movements WHERE to_location_id IS NOT NULL
                UNION ALL
                SELECT product_sku, from_location_id,
                       -IF(movement_type IN ('RESERVE', 'UNRESERVE'), 0, qty), -reserved_moved
                FROM stock_movements WHERE from_location_id IS NOT NULL
            ) x GROUP BY product_sku, location_id")->fetchAll();
        $balances = $pdo->query("SELECT product_sku, location_id, on_hand, reserved FROM inventory_balances WHERE stock_status = 'available'")->fetchAll();
        $codes = $pdo->query('SELECT id, code FROM warehouse_locations')->fetchAll(PDO::FETCH_KEY_PAIR);

        $pairs = [];
        foreach ($balances as $b) {
            $pairs[$b['product_sku'] . '@' . $b['location_id']] = [
                'product_sku' => $b['product_sku'], 'location_id' => (int) $b['location_id'],
                'on_hand' => (int) $b['on_hand'], 'reserved' => (int) $b['reserved'],
                'ledger_on_hand' => 0, 'ledger_reserved' => 0,
            ];
        }
        foreach ($ledger as $l) {
            $k = $l['product_sku'] . '@' . $l['location_id'];
            $pairs[$k] ??= ['product_sku' => $l['product_sku'], 'location_id' => (int) $l['location_id'], 'on_hand' => 0, 'reserved' => 0];
            $pairs[$k]['ledger_on_hand'] = (int) $l['ledger_on_hand'];
            $pairs[$k]['ledger_reserved'] = (int) $l['ledger_reserved'];
        }

        $out = [];
        foreach ($pairs as $p) {
            $p['location_code'] = $codes[$p['location_id']] ?? ('#' . $p['location_id']);
            $p['ok'] = $p['on_hand'] === $p['ledger_on_hand'] && $p['reserved'] === $p['ledger_reserved'];
            if (!$onlyMismatches || !$p['ok']) {
                $out[] = $p;
            }
        }
        usort($out, fn ($a, $b) => [$a['product_sku'], $a['location_code']] <=> [$b['product_sku'], $b['location_code']]);
        return $out;
    }
}
