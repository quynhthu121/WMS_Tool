<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/** Đọc ledger stock_movements cho màn hình (lọc, gộp LOAD+SHIP thành "Shipping", ledger theo SKU). */
final class LedgerService
{
    public const TYPE_LABELS = [
        'OPENING' => 'Tồn đầu kỳ',
        'RECEIVE' => 'Receiving (nhập)',
        'PUTAWAY' => 'Put-away',
        'RESERVE' => 'Reservation',
        'UNRESERVE' => 'Hủy reservation',
        'PICK' => 'Picking',
        'PACK_IN' => 'Packing (1)',
        'PACK_OUT' => 'Packing (2)',
        'LOAD' => 'Đưa ra DOCK',
        'SHIP' => 'Shipping (xuất)',
        'ADJUST' => 'Adjustment',
        'RESTOCK' => 'Trả về location gốc',
        'SHIPPING' => 'Shipping',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{sku?:string, type?:string, reference?:string, from?:string, to?:string} $filters
     * @return list<array<string, mixed>>
     */
    public function movements(array $filters = [], int $limit = 500): array
    {
        $sql = 'SELECT m.*, f.code AS from_code, t.code AS to_code, u.user_name
            FROM stock_movements m
            LEFT JOIN warehouse_locations f ON f.id = m.from_location_id
            LEFT JOIN warehouse_locations t ON t.id = m.to_location_id
            LEFT JOIN users u ON u.user_id = m.user_id WHERE 1 = 1';
        $params = [];
        if (!empty($filters['sku'])) {
            $sql .= ' AND m.product_sku = ?';
            $params[] = $filters['sku'];
        }
        if (!empty($filters['type'])) {
            if ($filters['type'] === 'SHIPPING') {
                $sql .= " AND m.movement_type IN ('LOAD', 'SHIP')";
            } else {
                $sql .= ' AND m.movement_type = ?';
                $params[] = $filters['type'];
            }
        }
        if (!empty($filters['reference'])) {
            $sql .= ' AND m.reference_code LIKE ?';
            $params[] = '%' . $filters['reference'] . '%';
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND m.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND m.created_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59.999999';
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY m.id LIMIT ' . (int) $limit);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Gộp các dòng LOAD + SHIP cùng group_code và cùng SKU thành một dòng "Shipping" (OUT-STG → DOCK → ra khỏi kho),
     * giữ chi tiết trong 'children'.
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function groupShipping(array $rows): array
    {
        $out = [];
        $index = [];
        foreach ($rows as $row) {
            if (in_array($row['movement_type'], ['LOAD', 'SHIP'], true) && $row['group_code']) {
                $key = $row['group_code'] . '|' . $row['product_sku'];
                if (isset($index[$key])) {
                    $out[$index[$key]]['children'][] = $row;
                    if ($row['movement_type'] === 'SHIP') {
                        $out[$index[$key]]['to_code'] = null;
                        $out[$index[$key]]['changes_on_hand'] = 1;
                        $out[$index[$key]]['id'] = $row['id'];
                    }
                    continue;
                }
                $index[$key] = count($out);
                $out[] = ['movement_type' => 'SHIPPING', 'children' => [$row]] + $row;
                continue;
            }
            $row['children'] = [];
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Ledger của một SKU (A4 §4): mỗi dòng kèm On-hand / Reserved / Available tổng theo SKU SAU dòng đó.
     * LOAD + SHIP gộp thành "Shipping".
     * @return list<array<string, mixed>>
     */
    public function skuLedger(string $sku): array
    {
        $allocatable = $this->pdo->query('SELECT id, is_allocatable FROM warehouse_locations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pairs = [];
        $rows = [];
        foreach ($this->movements(['sku' => $sku], 100000) as $m) {
            $qty = (int) $m['qty'];
            $res = (int) $m['reserved_moved'];
            $moves = !in_array($m['movement_type'], InventoryService::RESERVATION_TYPES, true);
            if ($m['to_location_id'] !== null) {
                $pairs[$m['to_location_id']] ??= [0, 0];
                $pairs[$m['to_location_id']][0] += $moves ? $qty : 0;
                $pairs[$m['to_location_id']][1] += $res;
            }
            if ($m['from_location_id'] !== null) {
                $pairs[$m['from_location_id']] ??= [0, 0];
                $pairs[$m['from_location_id']][0] -= $moves ? $qty : 0;
                $pairs[$m['from_location_id']][1] -= $res;
            }
            $onHand = $reserved = $available = 0;
            foreach ($pairs as $locId => [$oh, $rs]) {
                $onHand += $oh;
                $reserved += $rs;
                $available += (int) ($allocatable[$locId] ?? 0) ? $oh - $rs : 0;
            }
            $m['after'] = ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $available];
            $m['pairs_after'] = $pairs;
            $rows[] = $m;
        }
        // Gộp hiển thị LOAD+SHIP: giữ số "sau" của dòng SHIP.
        $grouped = $this->groupShipping($rows);
        foreach ($grouped as &$g) {
            if ($g['movement_type'] === 'SHIPPING') {
                $last = end($g['children']);
                $g['after'] = $last['after'];
            }
        }
        return $grouped;
    }

    public static function label(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /** Mô tả vị trí của một dòng ledger theo ngôn ngữ nghiệp vụ (giữ hàng, điều chỉnh, xuất khỏi kho…). */
    public static function route(array $m): string
    {
        $from = $m['from_code'] ?? null;
        $to = $m['to_code'] ?? null;
        return match ($m['movement_type']) {
            'RESERVE' => "giữ tại {$to}",
            'UNRESERVE' => "hủy giữ tại {$from}",
            'ADJUST' => $to !== null ? "tại {$to} (+)" : "tại {$from} (−)",
            'SHIPPING' => "{$from} → DOCK → (ra khỏi kho)",
            'SHIP' => "{$from} → (ra khỏi kho)",
            default => ($from ?? '(ngoài kho)') . ' → ' . ($to ?? '(ngoài kho)'),
        };
    }
}
