<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Truy vấn chỉ đọc cho Inventory Visibility.
 * Available (cặp) = On-hand − Reserved nếu location allocatable, ngược lại 0.
 * Available (SKU/toàn kho) = tổng Available của các cặp (không phải tổng On-hand − tổng Reserved).
 */
final class InventoryQuery
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>> các cặp SKU + Location có On-hand hoặc Reserved khác 0
     */
    public function pairs(?string $sku = null, ?string $locationCode = null, bool $includeZero = false): array
    {
        $sql = "SELECT b.product_sku, p.name AS product_name, p.size, p.color, p.uom,
                       l.code AS location_code, l.name AS location_name, l.zone_type, l.is_allocatable, l.pick_sequence,
                       b.on_hand, b.reserved, b.alloc_locked, b.alloc_lock_reason, b.first_in_at,
                       IF(l.is_allocatable = 1, b.on_hand - b.reserved, 0) AS available
                FROM inventory_balances b
                JOIN warehouse_locations l ON l.id = b.location_id
                JOIN products p ON p.sku = b.product_sku
                WHERE b.stock_status = 'available'";
        $params = [];
        if (!$includeZero) {
            $sql .= ' AND (b.on_hand <> 0 OR b.reserved <> 0)';
        }
        if ($sku !== null && $sku !== '') {
            $sql .= ' AND b.product_sku = ?';
            $params[] = $sku;
        }
        if ($locationCode !== null && $locationCode !== '') {
            $sql .= ' AND l.code = ?';
            $params[] = $locationCode;
        }
        $sql .= ' ORDER BY b.product_sku, l.pick_sequence';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'intify'], $stmt->fetchAll());
    }

    /** @return array{on_hand:int, reserved:int, available:int} */
    public function skuTotals(string $sku): array
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(b.on_hand), 0) AS on_hand, COALESCE(SUM(b.reserved), 0) AS reserved,
                COALESCE(SUM(IF(l.is_allocatable = 1, b.on_hand - b.reserved, 0)), 0) AS available
            FROM inventory_balances b JOIN warehouse_locations l ON l.id = b.location_id
            WHERE b.product_sku = ? AND b.stock_status = 'available'");
        $stmt->execute([$sku]);
        return array_map('intval', $stmt->fetch());
    }

    /** @return array{on_hand:int, reserved:int, available:int} */
    public function warehouseTotals(): array
    {
        $row = $this->pdo->query("SELECT COALESCE(SUM(b.on_hand), 0) AS on_hand, COALESCE(SUM(b.reserved), 0) AS reserved,
                COALESCE(SUM(IF(l.is_allocatable = 1, b.on_hand - b.reserved, 0)), 0) AS available
            FROM inventory_balances b JOIN warehouse_locations l ON l.id = b.location_id
            WHERE b.stock_status = 'available'")->fetch();
        return array_map('intval', $row);
    }

    /** @return array<string, int> mã location => tổng On-hand (đủ 7 location, kể cả 0) */
    public function onHandByLocation(): array
    {
        $rows = $this->pdo->query("SELECT l.code, COALESCE(SUM(b.on_hand), 0) AS on_hand
            FROM warehouse_locations l LEFT JOIN inventory_balances b ON b.location_id = l.id AND b.stock_status = 'available'
            WHERE l.active = 1 GROUP BY l.id, l.code, l.pick_sequence ORDER BY l.pick_sequence")->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }

    /** @return array{on_hand:int, reserved:int, available:int} */
    public function pair(string $sku, string $locationCode): array
    {
        foreach ($this->pairs($sku, $locationCode, true) as $p) {
            return ['on_hand' => $p['on_hand'], 'reserved' => $p['reserved'], 'available' => $p['available']];
        }
        return ['on_hand' => 0, 'reserved' => 0, 'available' => 0];
    }

    /** @return list<array<string, mixed>> */
    public function locations(): array
    {
        return $this->pdo->query('SELECT * FROM warehouse_locations WHERE active = 1 ORDER BY pick_sequence')->fetchAll();
    }

    /** @return list<array<string, mixed>> SKU thuộc demo (không gồm dữ liệu kế thừa) */
    public function products(bool $includeLegacy = false): array
    {
        $sql = 'SELECT p.*, l.code AS putaway_location_code FROM products p LEFT JOIN warehouse_locations l ON l.id = p.putaway_location_id';
        if (!$includeLegacy) {
            $sql .= ' WHERE p.is_legacy = 0';
        }
        return $this->pdo->query($sql . ' ORDER BY p.sku')->fetchAll();
    }

    private function intify(array $row): array
    {
        foreach (['on_hand', 'reserved', 'available', 'alloc_locked', 'is_allocatable', 'pick_sequence'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        return $row;
    }
}
