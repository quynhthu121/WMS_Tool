<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Trang "Bằng chứng": 6 kết quả prototype phải chứng minh (foundation mục 6),
 * tính trực tiếp từ dữ liệu hiện tại – không hard-code.
 * Mỗi kết quả: status = 'pass' | 'fail' | 'nodata', kèm chi tiết.
 */
final class EvidenceService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{no:int, title:string, status:string, summary:string, details:list<string>}> */
    public function all(): array
    {
        return [
            $this->visibility(),
            $this->receivingOnce(),
            $this->everyChangeHasMovement(),
            $this->reservationWithinAvailable(),
            $this->shippingOnce(),
            $this->auditExplains(),
        ];
    }

    private function result(int $no, string $title, array $problems, bool $hasData, string $okSummary, array $details = []): array
    {
        return [
            'no' => $no,
            'title' => $title,
            'status' => !$hasData ? 'nodata' : ($problems ? 'fail' : 'pass'),
            'summary' => !$hasData ? 'Chưa có dữ liệu để chứng minh – chạy kịch bản trong Bảng điều khiển Demo.' : ($problems ? count($problems) . ' vấn đề' : $okSummary),
            'details' => $problems ?: $details,
        ];
    }

    /** 1. Truy vấn một SKU thấy có bao nhiêu và nằm ở location nào. */
    private function visibility(): array
    {
        $query = new InventoryQuery($this->pdo);
        $problems = [];
        $details = [];
        $skus = $this->pdo->query('SELECT DISTINCT product_sku FROM inventory_balances WHERE on_hand > 0 ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($skus as $sku) {
            $pairs = $query->pairs($sku);
            $sum = array_sum(array_column($pairs, 'on_hand'));
            $total = $query->skuTotals($sku)['on_hand'];
            if ($sum !== $total) {
                $problems[] = "{$sku}: tổng theo location {$sum} ≠ tổng SKU {$total}";
            }
            $details[] = sprintf('%s = %d: %s', $sku, $total, implode(', ', array_map(fn ($p) => "{$p['location_code']} {$p['on_hand']}", $pairs)));
        }
        return $this->result(1, 'Truy vấn một SKU thấy đang có bao nhiêu và nằm ở location nào', $problems, (bool) $skus,
            count($skus) . ' SKU có tồn đều xác định được số lượng theo từng location', $details);
    }

    /** 2. Receiving tăng tồn đúng một lần; Put-away không tăng tổng tồn. */
    private function receivingOnce(): array
    {
        $problems = [];
        $items = $this->pdo->query('SELECT i.id, i.product_sku, i.received_qty, i.putaway_qty, r.receipt_code, r.id AS receipt_id,
                (SELECT COALESCE(SUM(qty), 0) FROM stock_movements m WHERE m.movement_type = \'RECEIVE\' AND m.reference_type = \'goods_receipt\' AND m.reference_id = r.id AND m.product_sku = i.product_sku) AS received_mv,
                (SELECT COALESCE(SUM(qty), 0) FROM stock_movements m WHERE m.movement_type = \'PUTAWAY\' AND m.reference_type = \'goods_receipt\' AND m.reference_id = r.id AND m.product_sku = i.product_sku) AS putaway_mv
            FROM goods_receipt_items i JOIN goods_receipts r ON r.id = i.receipt_id')->fetchAll();
        foreach ($items as $i) {
            if ((int) $i['received_mv'] !== (int) $i['received_qty']) {
                $problems[] = "{$i['receipt_code']} {$i['product_sku']}: RECEIVE {$i['received_mv']} ≠ xác nhận nhập {$i['received_qty']}";
            }
            if ((int) $i['putaway_mv'] !== (int) $i['putaway_qty']) {
                $problems[] = "{$i['receipt_code']} {$i['product_sku']}: PUTAWAY {$i['putaway_mv']} ≠ đã put-away {$i['putaway_qty']}";
            }
        }
        $badPutaway = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'PUTAWAY' AND (changes_on_hand <> 0 OR from_location_id IS NULL OR to_location_id IS NULL)")->fetchColumn();
        if ($badPutaway) {
            $problems[] = "{$badPutaway} dòng PUTAWAY làm đổi tổng tồn";
        }
        $orphan = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'RECEIVE' AND reference_type IS NULL")->fetchColumn();
        if ($orphan) {
            $problems[] = "{$orphan} dòng RECEIVE không gắn phiếu nhập";
        }
        $received = array_sum(array_column($items, 'received_qty'));
        return $this->result(2, 'Receiving tăng tồn đúng một lần; Put-away không tăng tổng tồn', $problems, (bool) $items,
            "Mỗi dòng phiếu có đúng một lần nhập (tổng +{$received}); mọi Put-away chỉ chuyển RECEIVING → Storage",
            array_map(fn ($i) => "{$i['receipt_code']} {$i['product_sku']}: nhập {$i['received_qty']}, put-away {$i['putaway_qty']}", $items));
    }

    /** 3. Mọi thay đổi số lượng/vị trí đều có Stock Movement. */
    private function everyChangeHasMovement(): array
    {
        $recon = new ReconciliationService($this->pdo);
        $pairs = $recon->pairs();
        $problems = [];
        foreach ($pairs as $p) {
            if (!$p['ok']) {
                $problems[] = sprintf('%s @ %s: tồn %d/%d nhưng ledger %d/%d', $p['product_sku'], $p['location_code'], $p['on_hand'], $p['reserved'], $p['ledger_on_hand'], $p['ledger_reserved']);
            }
        }
        $t = $recon->totals();
        if (!$t['ok']) {
            $problems[] = "Công thức toàn kho: {$t['expected']} ≠ tồn thực {$t['actual']}";
        }
        return $this->result(3, 'Mọi thay đổi số lượng hoặc vị trí đều có Stock Movement', $problems, (bool) $pairs,
            count($pairs) . ' cặp SKU + Location: On-hand và Reserved đều bằng tổng đại số ledger',
            [sprintf('Tồn cuối %d = Đầu kỳ %d + Nhập %d − Xuất %d + Điều chỉnh (+%d −%d)', $t['actual'], $t['opening'], $t['received'], $t['shipped'], $t['adjust_plus'], $t['adjust_minus'])]);
    }

    /** 4. Reservation không vượt Available; Picking/Packing không giảm tổng On-hand. */
    private function reservationWithinAvailable(): array
    {
        $problems = [];
        $over = (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_balances WHERE reserved > on_hand OR reserved < 0 OR on_hand < 0')->fetchColumn();
        if ($over) {
            $problems[] = "{$over} cặp có Reserved vượt On-hand";
        }
        $nonStorage = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements m JOIN warehouse_locations l ON l.id = m.to_location_id
            WHERE m.movement_type = 'RESERVE' AND l.is_allocatable = 0")->fetchColumn();
        if ($nonStorage) {
            $problems[] = "{$nonStorage} reservation tại location không phân bổ được";
        }
        $changing = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type IN ('PICK', 'PACK_IN', 'PACK_OUT', 'LOAD', 'RESTOCK')
            AND (changes_on_hand <> 0 OR from_location_id IS NULL OR to_location_id IS NULL)")->fetchColumn();
        if ($changing) {
            $problems[] = "{$changing} dòng Picking/Packing làm đổi tổng On-hand";
        }
        // Reserved trên balance phải khớp các reservation đang giữ (ở location gốc hoặc nơi hàng đang nằm).
        $expected = [];
        foreach ($this->pdo->query("SELECT product_sku, location_id, current_location_id, qty, picked_qty, released_qty FROM reservations WHERE status NOT IN ('released', 'shipped')") as $r) {
            $inStorage = (int) $r['qty'] - (int) $r['picked_qty'] - (int) $r['released_qty'];
            if ($inStorage > 0) {
                $k = $r['product_sku'] . '@' . $r['location_id'];
                $expected[$k] = ($expected[$k] ?? 0) + $inStorage;
            }
            if ((int) $r['picked_qty'] > 0) {
                $k = $r['product_sku'] . '@' . $r['current_location_id'];
                $expected[$k] = ($expected[$k] ?? 0) + (int) $r['picked_qty'];
            }
        }
        foreach ($this->pdo->query("SELECT b.product_sku, b.location_id, b.reserved, l.code FROM inventory_balances b JOIN warehouse_locations l ON l.id = b.location_id") as $b) {
            $k = $b['product_sku'] . '@' . $b['location_id'];
            if ((int) $b['reserved'] !== ($expected[$k] ?? 0)) {
                $problems[] = "{$b['product_sku']} @ {$b['code']}: Reserved {$b['reserved']} ≠ theo đơn " . ($expected[$k] ?? 0);
            }
        }
        $reservations = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'RESERVE'")->fetchColumn();
        $picks = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type IN ('PICK', 'PACK_IN', 'PACK_OUT')")->fetchColumn();
        return $this->result(4, 'Reservation không vượt Available; Picking/Packing không giảm tổng On-hand', $problems, $reservations > 0,
            "{$reservations} lần giữ hàng đều trong Available; {$picks} dòng Picking/Packing chỉ đổi location; Reserved khớp đơn");
    }

    /** 5. Shipping giảm On-hand đúng một lần và trạng thái đơn nhất quán. */
    private function shippingOnce(): array
    {
        $problems = [];
        $orders = $this->pdo->query("SELECT o.id, o.order_code, o.status,
                (SELECT COALESCE(SUM(shipped_qty), 0) FROM outbound_order_lines WHERE order_id = o.id) AS shipped_qty,
                (SELECT COALESCE(SUM(qty), 0) FROM stock_movements m WHERE m.movement_type = 'SHIP' AND m.reference_type = 'outbound_order' AND m.reference_id = o.id) AS ship_mv,
                (SELECT COUNT(DISTINCT group_code) FROM stock_movements m WHERE m.movement_type = 'SHIP' AND m.reference_type = 'outbound_order' AND m.reference_id = o.id) AS ship_groups,
                (SELECT status FROM shipments s WHERE s.order_id = o.id) AS shipment_status,
                (SELECT COUNT(*) FROM oms_outbox x WHERE x.order_code = o.order_code AND x.event_type = 'ORDER_SHIPPED') AS oms_events
            FROM outbound_orders o")->fetchAll();
        $shipped = 0;
        foreach ($orders as $o) {
            if ($o['status'] === 'SHIPPED') {
                $shipped++;
                if ((int) $o['ship_mv'] !== (int) $o['shipped_qty'] || (int) $o['ship_groups'] !== 1) {
                    $problems[] = "{$o['order_code']}: SHIP {$o['ship_mv']} ({$o['ship_groups']} lần) ≠ đã xuất {$o['shipped_qty']}";
                }
                if ($o['shipment_status'] !== 'SHIPPED' || (int) $o['oms_events'] !== 1) {
                    $problems[] = "{$o['order_code']}: trạng thái vận đơn/OMS không nhất quán";
                }
            } elseif ((int) $o['ship_mv'] > 0) {
                $problems[] = "{$o['order_code']}: có SHIP nhưng trạng thái {$o['status']}";
            }
        }
        $staging = (int) $this->pdo->query("SELECT COALESCE(SUM(b.on_hand), 0) FROM inventory_balances b JOIN warehouse_locations l ON l.id = b.location_id WHERE l.code = 'DOCK'")->fetchColumn();
        if ($staging > 0) {
            $problems[] = "DOCK còn {$staging} cái chưa xuất";
        }
        return $this->result(5, 'Shipping giảm On-hand đúng một lần và trạng thái đơn nhất quán', $problems, $shipped > 0,
            "{$shipped} đơn SHIPPED: mỗi đơn đúng một lần SHIP, vận đơn và sự kiện OMS khớp",
            array_map(fn ($o) => "{$o['order_code']}: {$o['status']}, xuất {$o['shipped_qty']}", $orders));
    }

    /** 6. Audit/Adjustment giải thích và ghi nhận được chênh lệch. */
    private function auditExplains(): array
    {
        $problems = [];
        $adjusts = $this->pdo->query("SELECT m.id, m.product_sku, m.qty, m.from_location_id, m.to_location_id, m.reference_type, m.reference_id,
                c.count_code, c.status, c.difference, c.reason, c.adjust_movement_id, c.system_qty, COALESCE(c.recount_qty, c.counted_qty) AS final_qty
            FROM stock_movements m LEFT JOIN cycle_counts c ON c.id = m.reference_id AND m.reference_type = 'cycle_count'
            WHERE m.movement_type = 'ADJUST'")->fetchAll();
        $details = [];
        foreach ($adjusts as $a) {
            $signed = $a['to_location_id'] !== null ? (int) $a['qty'] : -(int) $a['qty'];
            if ($a['count_code'] === null) {
                $problems[] = "ADJUST #{$a['id']} không gắn phiếu kiểm kê";
                continue;
            }
            if ($a['status'] !== 'ADJUSTED' || (int) $a['adjust_movement_id'] !== (int) $a['id'] || (int) $a['difference'] !== $signed || trim((string) $a['reason']) === '') {
                $problems[] = "{$a['count_code']}: Adjustment {$signed} không khớp phiếu (chênh lệch {$a['difference']}, trạng thái {$a['status']})";
            }
            $details[] = sprintf('%s %s: hệ thống %d, thực tế %d ⇒ ADJUST %+d – %s', $a['count_code'], $a['product_sku'], $a['system_qty'], $a['final_qty'], $signed, $a['reason']);
        }
        $pending = (int) $this->pdo->query("SELECT COUNT(*) FROM cycle_counts WHERE status = 'ADJUSTED' AND adjust_movement_id IS NULL")->fetchColumn();
        if ($pending) {
            $problems[] = "{$pending} phiếu ADJUSTED không có movement";
        }
        return $this->result(6, 'Audit/Adjustment giải thích và ghi nhận được chênh lệch', $problems, (bool) $adjusts,
            count($adjusts) . ' Adjustment đều gắn phiếu kiểm kê đã duyệt, đúng bằng chênh lệch, có lý do', $details);
    }
}
