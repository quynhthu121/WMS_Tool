<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Actor;
use PDO;

/**
 * Outbound theo A3: Order Created → Reservation (FIFO) → Picking → Packing → Shipping.
 *
 * Reservation chỉ từ cặp Storage allocatable, không vượt Available. Picking/Packing chỉ đổi location,
 * Reserved đi theo hàng. Chỉ Shipping giảm On-hand và Reserved, đúng một lần.
 * Pick thiếu: giữ PICKING + hold_reason, khóa phân bổ cặp, tự tạo phiếu kiểm kê (A4 §3.3).
 */
final class OutboundService
{
    public const STATUS_LABELS = [
        'CREATED' => 'Đã tạo',
        'RESERVED' => 'Đã giữ hàng',
        'PICKING' => 'Đang lấy hàng',
        'PICKED' => 'Đã lấy hàng',
        'PACKING' => 'Đang đóng gói',
        'PACKED' => 'Đã đóng gói',
        'SHIPPED' => 'Đã xuất kho',
        'CANCELLED' => 'Đã hủy',
    ];

    public const DEFAULT_CHANNEL = 'Kênh online (mô phỏng)';
    public const DEFAULT_ADDRESS = 'Địa chỉ mẫu – không có thật';

    private InventoryService $inventory;

    public function __construct(private PDO $pdo, ?InventoryService $inventory = null)
    {
        $this->inventory = $inventory ?? new InventoryService($pdo);
    }

    /**
     * Order Created: kiểm tra sơ bộ (SKU tồn tại, qty > 0, không trùng SKU). Không đổi tồn.
     * @param list<array{sku:string, qty:int}> $lines
     */
    public function createOrder(string $code, string $channel, string $address, array $lines, Actor $actor): int
    {
        $code = trim($code);
        $lines = array_values(array_filter($lines, fn ($l) => trim((string) ($l['sku'] ?? '')) !== ''));
        if ($code === '' || !$lines) {
            throw new BusinessRuleException('Đơn cần mã đơn và ít nhất một dòng hàng.');
        }
        $skus = array_column($lines, 'sku');
        if (count($skus) !== count(array_unique($skus))) {
            throw new BusinessRuleException('Mỗi SKU chỉ xuất hiện một dòng trong đơn.');
        }
        return $this->inventory->transactional(function () use ($code, $channel, $address, $lines, $actor) {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM outbound_orders WHERE order_code = ?');
            $exists->execute([$code]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new BusinessRuleException("Mã đơn {$code} đã tồn tại.");
            }
            $this->pdo->prepare('INSERT INTO outbound_orders (order_code, channel, ship_address, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$code, $channel ?: self::DEFAULT_CHANNEL, $address ?: self::DEFAULT_ADDRESS, $actor->id]);
            $orderId = (int) $this->pdo->lastInsertId();
            $product = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
            $insert = $this->pdo->prepare('INSERT INTO outbound_order_lines (order_id, line_no, product_sku, requested_qty) VALUES (?, ?, ?, ?)');
            foreach ($lines as $i => $line) {
                $qty = (int) $line['qty'];
                $product->execute([$line['sku']]);
                if ((int) $product->fetchColumn() === 0) {
                    throw new BusinessRuleException("SKU {$line['sku']} không có trong danh mục.");
                }
                if ($qty <= 0) {
                    throw new BusinessRuleException("SKU {$line['sku']}: số lượng yêu cầu phải > 0.");
                }
                $insert->execute([$orderId, $i + 1, $line['sku'], $qty]);
            }
            $this->outbox('ORDER_CREATED', $code, ['lines' => $lines]);
            return $orderId;
        });
    }

    /**
     * Gợi ý phân bổ FIFO (không ghi): cặp Storage chưa khóa, còn Available, first_in_at sớm nhất trước,
     * hòa thì theo mã location.
     * @return list<array{location:string, qty:int, available:int}>
     */
    public function planFifo(string $sku, int $need): array
    {
        $stmt = $this->pdo->prepare("SELECT l.code, b.on_hand - b.reserved AS available
            FROM inventory_balances b JOIN warehouse_locations l ON l.id = b.location_id
            WHERE b.product_sku = ? AND b.stock_status = 'available' AND l.is_allocatable = 1 AND b.alloc_locked = 0
              AND b.on_hand - b.reserved > 0
            ORDER BY b.first_in_at IS NULL, b.first_in_at, l.code");
        $stmt->execute([$sku]);
        $plan = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($need <= 0) {
                break;
            }
            $take = min($need, (int) $row['available']);
            $plan[] = ['location' => $row['code'], 'qty' => $take, 'available' => (int) $row['available']];
            $need -= $take;
        }
        return $plan;
    }

    /**
     * Reservation: Requested ≤ Available (A3), giữ hàng theo FIFO, có thể tách nhiều location.
     * @return list<array{sku:string, location:string, qty:int}>
     */
    public function reserve(int $orderId, Actor $actor): array
    {
        return $this->inventory->withUser($actor->id)->transactional(function () use ($orderId, $actor) {
            $order = $this->lockOrder($orderId, ['CREATED']);
            $lines = $this->lines($orderId);
            $plans = [];
            foreach ($lines as $line) {
                $need = (int) $line['requested_qty'];
                $plan = $this->planFifo($line['product_sku'], $need);
                $available = array_sum(array_column($plan, 'qty'));
                if ($available < $need) {
                    throw new BusinessRuleException(sprintf('%s: yêu cầu %d vượt Available %d – không tạo reservation.', $line['product_sku'], $need, $available));
                }
                $plans[(int) $line['id']] = $plan;
            }
            $result = [];
            foreach ($lines as $line) {
                foreach ($plans[(int) $line['id']] as $p) {
                    $this->createReservation($order, $line, $p['location'], $p['qty'], $actor);
                    $result[] = ['sku' => $line['product_sku'], 'location' => $p['location'], 'qty' => $p['qty']];
                }
            }
            $this->setStatus($orderId, 'RESERVED', 'reserved_at');
            return $result;
        });
    }

    /** Picking: tạo Pick List (SKU, Location, qty) từ phần giữ chưa pick. */
    public function createPickList(int $orderId, Actor $actor): int
    {
        return $this->inventory->transactional(function () use ($orderId) {
            $order = $this->lockOrder($orderId, ['RESERVED', 'PICKING']);
            $stmt = $this->pdo->prepare("SELECT r.* FROM reservations r JOIN outbound_order_lines ol ON ol.id = r.order_line_id
                WHERE ol.order_id = ? AND r.status = 'active' AND r.qty - r.picked_qty - r.released_qty > 0
                  AND NOT EXISTS (SELECT 1 FROM pick_list_lines pl WHERE pl.reservation_id = r.id AND pl.status = 'OPEN')
                ORDER BY r.id");
            $stmt->execute([$orderId]);
            $reservations = $stmt->fetchAll();
            if (!$reservations) {
                throw new BusinessRuleException('Không còn phần giữ hàng nào cần lấy.');
            }
            $n = (int) $this->pdo->query('SELECT COUNT(*) FROM pick_lists')->fetchColumn() + 1;
            $this->pdo->prepare('INSERT INTO pick_lists (pick_code, order_id) VALUES (?, ?)')->execute([sprintf('PL-%04d', $n), $orderId]);
            $listId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO pick_list_lines (pick_list_id, reservation_id, product_sku, location_id, qty) VALUES (?, ?, ?, ?, ?)');
            foreach ($reservations as $r) {
                $insert->execute([$listId, $r['id'], $r['product_sku'], $r['location_id'], (int) $r['qty'] - (int) $r['picked_qty'] - (int) $r['released_qty']]);
            }
            if ($order['status'] === 'RESERVED') {
                $this->setStatus($orderId, 'PICKING', 'picking_at');
            }
            return $listId;
        });
    }

    /**
     * Xác nhận pick một dòng: quét location + SKU, nhập số thực lấy (≤ số trên Pick List).
     * Thiếu hàng ⇒ giữ PICKING + hold_reason, khóa phân bổ cặp, tạo phiếu kiểm kê PICK_SHORTAGE.
     * @return array{picked:int, short:int, count_id:?int}
     */
    public function confirmPick(int $pickLineId, string $scanLocation, string $scanSku, int $actualQty, Actor $actor): array
    {
        return $this->inventory->withUser($actor->id)->transactional(function () use ($pickLineId, $scanLocation, $scanSku, $actualQty, $actor) {
            $stmt = $this->pdo->prepare('SELECT pl.*, l.code AS location_code, p.order_id, p.id AS list_id
                FROM pick_list_lines pl JOIN pick_lists p ON p.id = pl.pick_list_id JOIN warehouse_locations l ON l.id = pl.location_id
                WHERE pl.id = ? FOR UPDATE');
            $stmt->execute([$pickLineId]);
            $line = $stmt->fetch();
            if (!$line) {
                throw new BusinessRuleException('Không tìm thấy dòng Pick List.');
            }
            if ($line['status'] !== 'OPEN') {
                throw new BusinessRuleException('Dòng Pick List này đã được xác nhận.');
            }
            $order = $this->lockOrder((int) $line['order_id'], ['PICKING']);
            $scanLocation = strtoupper(trim($scanLocation));
            $scanSku = strtoupper(trim($scanSku));
            if ($scanLocation !== $line['location_code']) {
                throw new BusinessRuleException("Sai vị trí: quét {$scanLocation}, Pick List yêu cầu {$line['location_code']}.");
            }
            if ($scanSku !== $line['product_sku']) {
                throw new BusinessRuleException("Sai SKU: quét {$scanSku}, Pick List yêu cầu {$line['product_sku']}.");
            }
            $qty = (int) $line['qty'];
            if ($actualQty < 0 || $actualQty > $qty) {
                throw new BusinessRuleException("Số thực lấy phải trong khoảng 0..{$qty}.");
            }

            $inventory = $this->inventory->withUser($actor->id);
            $movementId = null;
            if ($actualQty > 0) {
                $movementId = $inventory->move('PICK', $line['product_sku'], $line['location_code'], 'PICK-STG', $actualQty, $actualQty, $this->ref($order, 'Picking'));
                $pickStg = (int) $inventory->location('PICK-STG')['id'];
                $this->pdo->prepare("UPDATE reservations SET picked_qty = picked_qty + ?, current_location_id = ?,
                        status = IF(picked_qty + released_qty >= qty, 'picked', status) WHERE id = ?")
                    ->execute([$actualQty, $pickStg, $line['reservation_id']]);
                $this->pdo->prepare('UPDATE outbound_order_lines ol JOIN reservations r ON r.order_line_id = ol.id SET ol.picked_qty = ol.picked_qty + ? WHERE r.id = ?')
                    ->execute([$actualQty, $line['reservation_id']]);
            }
            $short = $qty - $actualQty;
            $this->pdo->prepare('UPDATE pick_list_lines SET picked_qty = ?, status = ?, picked_at = NOW(), picked_by = ?, movement_id = ? WHERE id = ?')
                ->execute([$actualQty, $short > 0 ? 'SHORT' : 'PICKED', $actor->id, $movementId, $pickLineId]);

            $countId = null;
            if ($short > 0) {
                $reason = sprintf('Pick thiếu %d %s tại %s – đang kiểm kê', $short, $line['product_sku'], $line['location_code']);
                $inventory->setAllocationLock($line['product_sku'], $line['location_code'], true, $reason);
                $countId = (new AuditService($this->pdo, $inventory))->createCount(
                    $line['product_sku'], $line['location_code'], Actor::system(), 'PICK_SHORTAGE', (int) $order['id']);
                $this->pdo->prepare('UPDATE outbound_orders SET hold_reason = ? WHERE id = ?')->execute([$reason, $order['id']]);
            }

            $open = $this->pdo->prepare("SELECT COUNT(*) FROM pick_list_lines WHERE pick_list_id = ? AND status = 'OPEN'");
            $open->execute([$line['list_id']]);
            if ((int) $open->fetchColumn() === 0) {
                $this->pdo->prepare("UPDATE pick_lists SET status = 'DONE', completed_at = NOW() WHERE id = ?")->execute([$line['list_id']]);
            }
            $this->refreshPicked((int) $order['id']);
            return ['picked' => $actualQty, 'short' => $short, 'count_id' => $countId];
        });
    }

    /**
     * Sau kiểm kê pick thiếu: phân bổ lại phần còn thiếu từ location khác theo FIFO và tạo Pick List mới.
     * Không còn location nào đủ ⇒ phần thiếu chuyển backorder (không tạo tồn ảo).
     * @return array{reserved:list<array{location:string, qty:int}>, backorder:int, pick_list_id:?int}
     */
    public function reallocate(int $orderId, Actor $actor): array
    {
        return $this->inventory->withUser($actor->id)->transactional(function () use ($orderId, $actor) {
            $order = $this->lockOrder($orderId, ['PICKING']);
            $pending = $this->pdo->prepare("SELECT COUNT(*) FROM cycle_counts WHERE source_order_id = ? AND status NOT IN ('ADJUSTED', 'CLOSED_MATCH')");
            $pending->execute([$orderId]);
            if ((int) $pending->fetchColumn() > 0) {
                throw new BusinessRuleException('Phiếu kiểm kê do pick thiếu chưa hoàn tất; chưa phân bổ lại được.');
            }
            $reserved = [];
            $backorder = 0;
            foreach ($this->lines($orderId) as $line) {
                $held = $this->pdo->prepare('SELECT COALESCE(SUM(qty - released_qty), 0) FROM reservations WHERE order_line_id = ?');
                $held->execute([$line['id']]);
                $outstanding = (int) $line['requested_qty'] - (int) $line['backorder_qty'] - (int) $held->fetchColumn();
                if ($outstanding <= 0) {
                    continue;
                }
                foreach ($this->planFifo($line['product_sku'], $outstanding) as $p) {
                    $this->createReservation($order, $line, $p['location'], $p['qty'], $actor);
                    $reserved[] = ['sku' => $line['product_sku'], 'location' => $p['location'], 'qty' => $p['qty']];
                    $outstanding -= $p['qty'];
                }
                if ($outstanding > 0) {
                    $this->pdo->prepare('UPDATE outbound_order_lines SET backorder_qty = backorder_qty + ? WHERE id = ?')->execute([$outstanding, $line['id']]);
                    $backorder += $outstanding;
                }
            }
            $hold = $backorder > 0 ? "Backorder {$backorder} – không còn location đủ hàng, chờ điều phối quyết định" : null;
            $this->pdo->prepare('UPDATE outbound_orders SET hold_reason = ? WHERE id = ?')->execute([$hold, $orderId]);
            if ($backorder > 0) {
                $this->outbox('ORDER_BACKORDER', $order['order_code'], ['backorder_qty' => $backorder]);
            }
            $listId = $reserved ? $this->createPickList($orderId, $actor) : null;
            $this->refreshPicked($orderId);
            return ['reserved' => $reserved, 'backorder' => $backorder, 'pick_list_id' => $listId];
        });
    }

    /** Điều phối chấp nhận giao thiếu phần backorder: bỏ cờ giữ để đơn tiếp tục. */
    public function acceptBackorder(int $orderId, Actor $actor): void
    {
        $this->inventory->transactional(function () use ($orderId) {
            $this->lockOrder($orderId, ['PICKING', 'PICKED']);
            $this->pdo->prepare('UPDATE outbound_orders SET hold_reason = NULL WHERE id = ?')->execute([$orderId]);
            $this->refreshPicked($orderId);
        });
    }

    /** Packing (1): hàng đã pick chuyển PICK-STG → PACK-01. */
    public function packIn(int $orderId, Actor $actor): void
    {
        $this->inventory->withUser($actor->id)->transactional(function () use ($orderId, $actor) {
            $order = $this->lockOrder($orderId, ['PICKED']);
            $inventory = $this->inventory->withUser($actor->id);
            foreach ($this->lines($orderId) as $line) {
                if ((int) $line['picked_qty'] > 0) {
                    $inventory->move('PACK_IN', $line['product_sku'], 'PICK-STG', 'PACK-01', (int) $line['picked_qty'], (int) $line['picked_qty'], $this->ref($order, 'Packing (1)'));
                }
            }
            $this->moveReservations($orderId, 'PACK-01', null);
            $this->setStatus($orderId, 'PACKING', 'packing_at');
        });
    }

    /**
     * Packing (2): quét SKU đối soát đơn (đúng SKU, đủ số lượng), sinh Tracking ID, chuyển PACK-01 → OUT-STG.
     * @param array<string, int> $scans sku => số lượng quét
     */
    public function packOut(int $orderId, array $scans, Actor $actor): string
    {
        return $this->inventory->withUser($actor->id)->transactional(function () use ($orderId, $scans, $actor) {
            $order = $this->lockOrder($orderId, ['PACKING']);
            $lines = $this->lines($orderId);
            $expected = [];
            foreach ($lines as $line) {
                if ((int) $line['picked_qty'] > 0) {
                    $expected[$line['product_sku']] = (int) $line['picked_qty'];
                }
            }
            $scans = array_filter(array_change_key_case(array_map('intval', $scans), CASE_UPPER), fn ($q) => $q > 0);
            ksort($expected);
            ksort($scans);
            if ($scans !== $expected) {
                $diff = [];
                foreach (array_unique(array_merge(array_keys($expected), array_keys($scans))) as $sku) {
                    if (($expected[$sku] ?? 0) !== ($scans[$sku] ?? 0)) {
                        $diff[] = sprintf('%s: quét %d / cần %d', $sku, $scans[$sku] ?? 0, $expected[$sku] ?? 0);
                    }
                }
                throw new BusinessRuleException('Quét đối soát không khớp đơn – ' . implode('; ', $diff));
            }
            $tracking = $this->newTrackingId($order);
            $inventory = $this->inventory->withUser($actor->id);
            foreach ($expected as $sku => $qty) {
                $inventory->move('PACK_OUT', $sku, 'PACK-01', 'OUT-STG', $qty, $qty, $this->ref($order, "Packing (2) – {$tracking}"));
            }
            $this->pdo->prepare('UPDATE outbound_order_lines SET packed_qty = picked_qty WHERE order_id = ?')->execute([$orderId]);
            $this->moveReservations($orderId, 'OUT-STG', 'packed');
            $this->pdo->prepare('INSERT INTO shipments (order_id, tracking_id) VALUES (?, ?)')->execute([$orderId, $tracking]);
            $this->pdo->prepare('UPDATE outbound_orders SET tracking_id = ? WHERE id = ?')->execute([$tracking, $orderId]);
            $this->setStatus($orderId, 'PACKED', 'packed_at');
            return $tracking;
        });
    }

    /**
     * Shipping: quét tracking tại DOCK; LOAD (OUT-STG → DOCK) + SHIP (ra khỏi kho) trong một transaction.
     * On-hand và Reserved giảm đúng một lần; ship lần hai bị chặn. Ghi sự kiện báo OMS vào oms_outbox.
     */
    public function ship(int $orderId, string $scanTracking, Actor $actor): void
    {
        $this->inventory->withUser($actor->id)->transactional(function () use ($orderId, $scanTracking, $actor) {
            $order = $this->lockOrder($orderId, null);
            if ($order['status'] === 'SHIPPED') {
                throw new BusinessRuleException("Đơn {$order['order_code']} đã xuất kho; không xuất lần hai.");
            }
            if ($order['status'] !== 'PACKED') {
                throw new BusinessRuleException('Chỉ xuất được đơn đã đóng gói (PACKED).');
            }
            if (strtoupper(trim($scanTracking)) !== $order['tracking_id']) {
                throw new BusinessRuleException('Mã vận đơn quét không khớp đơn hàng.');
            }
            $inventory = $this->inventory->withUser($actor->id);
            $group = 'SHIP-' . $order['order_code'];
            $shipped = [];
            foreach ($this->lines($orderId) as $line) {
                $qty = (int) $line['packed_qty'];
                if ($qty <= 0) {
                    continue;
                }
                $ref = $this->ref($order, "Shipping – {$order['tracking_id']}") + ['group' => $group];
                $inventory->move('LOAD', $line['product_sku'], 'OUT-STG', 'DOCK', $qty, $qty, $ref);
                $inventory->ship($line['product_sku'], $qty, $ref);
                $this->pdo->prepare('UPDATE outbound_order_lines SET shipped_qty = ?, reserved_qty = reserved_qty - ? WHERE id = ?')
                    ->execute([$qty, $qty, $line['id']]);
                $shipped[] = ['sku' => $line['product_sku'], 'qty' => $qty];
            }
            $this->moveReservations($orderId, 'DOCK', 'shipped');
            $this->pdo->prepare("UPDATE shipments SET status = 'SHIPPED', shipped_at = NOW(), shipped_by = ? WHERE order_id = ?")->execute([$actor->id, $orderId]);
            $this->setStatus($orderId, 'SHIPPED', 'shipped_at');
            $this->outbox('ORDER_SHIPPED', $order['order_code'], ['tracking_id' => $order['tracking_id'], 'lines' => $shipped]);
        });
    }

    /**
     * Hủy đơn chưa ship: phần còn ở Storage thì hủy giữ; phần đã pick/pack thì trả về location gốc
     * ghi trong reservation (movement RESTOCK) rồi hủy giữ.
     */
    public function cancel(int $orderId, string $reason, Actor $actor): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleException('Hủy đơn cần ghi lý do.');
        }
        $this->inventory->withUser($actor->id)->transactional(function () use ($orderId, $reason, $actor) {
            $order = $this->lockOrder($orderId, ['CREATED', 'RESERVED', 'PICKING', 'PICKED', 'PACKING', 'PACKED']);
            $inventory = $this->inventory->withUser($actor->id);
            $ref = $this->ref($order, 'Hủy đơn: ' . $reason);
            $stmt = $this->pdo->prepare("SELECT r.*, o.code AS origin_code, c.code AS current_code FROM reservations r
                JOIN outbound_order_lines ol ON ol.id = r.order_line_id
                JOIN warehouse_locations o ON o.id = r.location_id JOIN warehouse_locations c ON c.id = r.current_location_id
                WHERE ol.order_id = ? AND r.status <> 'released' ORDER BY r.id");
            $stmt->execute([$orderId]);
            foreach ($stmt->fetchAll() as $r) {
                $inStorage = (int) $r['qty'] - (int) $r['picked_qty'] - (int) $r['released_qty'];
                $picked = (int) $r['picked_qty'];
                if ($picked > 0 && $r['current_code'] !== $r['origin_code']) {
                    $inventory->move('RESTOCK', $r['product_sku'], $r['current_code'], $r['origin_code'], $picked, $picked, $ref);
                }
                $toRelease = $inStorage + $picked;
                if ($toRelease > 0) {
                    $inventory->release($r['product_sku'], $r['origin_code'], $toRelease, $ref);
                }
                $this->pdo->prepare("UPDATE reservations SET released_qty = qty, picked_qty = 0, current_location_id = location_id, status = 'released' WHERE id = ?")
                    ->execute([$r['id']]);
            }
            $this->pdo->prepare('UPDATE outbound_order_lines SET reserved_qty = 0 WHERE order_id = ?')->execute([$orderId]);
            $this->pdo->prepare("UPDATE pick_list_lines pl JOIN pick_lists p ON p.id = pl.pick_list_id SET pl.status = 'SHORT', pl.picked_qty = 0 WHERE p.order_id = ? AND pl.status = 'OPEN'")->execute([$orderId]);
            $this->pdo->prepare("UPDATE pick_lists SET status = 'DONE', completed_at = NOW() WHERE order_id = ? AND status = 'OPEN'")->execute([$orderId]);
            $this->pdo->prepare("UPDATE outbound_orders SET status = 'CANCELLED', cancelled_at = NOW(), cancel_reason = ?, hold_reason = NULL WHERE id = ?")->execute([$reason, $orderId]);
            $this->outbox('ORDER_CANCELLED', $order['order_code'], ['reason' => $reason]);
        });
    }

    /**
     * Hủy phần Reserved không còn hàng đỡ tại một cặp Storage (dùng bởi Audit, BR-13).
     * @return int số lượng đã hủy
     */
    public function releaseAtPair(string $sku, string $locationCode, int $qty, string $note, Actor $actor): int
    {
        $loc = $this->inventory->location($locationCode);
        $stmt = $this->pdo->prepare("SELECT r.*, ol.order_id FROM reservations r JOIN outbound_order_lines ol ON ol.id = r.order_line_id
            WHERE r.product_sku = ? AND r.location_id = ? AND r.status = 'active' AND r.qty - r.picked_qty - r.released_qty > 0
            ORDER BY r.id DESC FOR UPDATE");
        $stmt->execute([$sku, $loc['id']]);
        $released = 0;
        foreach ($stmt->fetchAll() as $r) {
            if ($released >= $qty) {
                break;
            }
            $take = min($qty - $released, (int) $r['qty'] - (int) $r['picked_qty'] - (int) $r['released_qty']);
            $order = $this->lockOrder((int) $r['order_id'], null);
            $this->inventory->withUser($actor->id)->release($sku, $locationCode, $take, $this->ref($order, $note));
            $this->pdo->prepare("UPDATE reservations SET released_qty = released_qty + ?,
                    status = CASE WHEN picked_qty + released_qty >= qty THEN IF(picked_qty > 0, 'picked', 'released') ELSE status END
                WHERE id = ?")->execute([$take, $r['id']]);
            $this->pdo->prepare('UPDATE outbound_order_lines SET reserved_qty = reserved_qty - ? WHERE id = ?')->execute([$take, $r['order_line_id']]);
            $released += $take;
        }
        return $released;
    }

    // ---------------------------------------------------------------- truy vấn

    /** @return array<string, mixed>|null */
    public function order(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM outbound_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: null;
    }

    public function orderIdByCode(string $code): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM outbound_orders WHERE order_code = ?');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return list<array<string, mixed>> */
    public function orders(): array
    {
        return $this->pdo->query('SELECT o.*, COALESCE(SUM(l.requested_qty), 0) AS requested_total, COALESCE(SUM(l.shipped_qty), 0) AS shipped_total
            FROM outbound_orders o LEFT JOIN outbound_order_lines l ON l.order_id = o.id GROUP BY o.id ORDER BY o.id DESC')->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT ol.*, p.name AS product_name, p.size, p.color FROM outbound_order_lines ol
            JOIN products p ON p.sku = ol.product_sku WHERE ol.order_id = ? ORDER BY ol.line_no');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function reservations(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT r.*, o.code AS origin_code, c.code AS current_code FROM reservations r
            JOIN outbound_order_lines ol ON ol.id = r.order_line_id
            JOIN warehouse_locations o ON o.id = r.location_id JOIN warehouse_locations c ON c.id = r.current_location_id
            WHERE ol.order_id = ? ORDER BY r.id');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function pickLines(int $orderId, bool $openOnly = false): array
    {
        $stmt = $this->pdo->prepare('SELECT pl.*, p.pick_code, l.code AS location_code FROM pick_list_lines pl
            JOIN pick_lists p ON p.id = pl.pick_list_id JOIN warehouse_locations l ON l.id = pl.location_id
            WHERE p.order_id = ?' . ($openOnly ? " AND pl.status = 'OPEN'" : '') . ' ORDER BY pl.id');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function outboxEvents(?string $orderCode = null): array
    {
        if ($orderCode === null) {
            return $this->pdo->query('SELECT * FROM oms_outbox ORDER BY id DESC LIMIT 100')->fetchAll();
        }
        $stmt = $this->pdo->prepare('SELECT * FROM oms_outbox WHERE order_code = ? ORDER BY id');
        $stmt->execute([$orderCode]);
        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------------- nội bộ

    private function createReservation(array $order, array $line, string $locationCode, int $qty, Actor $actor): void
    {
        $locId = (int) $this->inventory->location($locationCode)['id'];
        $this->inventory->withUser($actor->id)->reserve($line['product_sku'], $locationCode, $qty, $this->ref($order, "Reservation FIFO tại {$locationCode}"));
        $this->pdo->prepare('INSERT INTO reservations (order_line_id, product_sku, location_id, current_location_id, qty) VALUES (?, ?, ?, ?, ?)')
            ->execute([$line['id'], $line['product_sku'], $locId, $locId, $qty]);
        $this->pdo->prepare('UPDATE outbound_order_lines SET reserved_qty = reserved_qty + ? WHERE id = ?')->execute([$qty, $line['id']]);
    }

    /** Đơn PICKING không còn dòng pick mở, không bị giữ và đã lấy đủ ⇒ PICKED. */
    private function refreshPicked(int $orderId): void
    {
        $order = $this->order($orderId);
        if ($order['status'] !== 'PICKING' || $order['hold_reason'] !== null) {
            return;
        }
        $open = $this->pdo->prepare("SELECT COUNT(*) FROM pick_list_lines pl JOIN pick_lists p ON p.id = pl.pick_list_id WHERE p.order_id = ? AND pl.status = 'OPEN'");
        $open->execute([$orderId]);
        if ((int) $open->fetchColumn() > 0) {
            return;
        }
        foreach ($this->lines($orderId) as $line) {
            if ((int) $line['picked_qty'] !== (int) $line['requested_qty'] - (int) $line['backorder_qty']) {
                return;
            }
        }
        $this->setStatus($orderId, 'PICKED', 'picked_at');
    }

    private function moveReservations(int $orderId, string $locationCode, ?string $status): void
    {
        $locId = (int) $this->inventory->location($locationCode)['id'];
        $this->pdo->prepare('UPDATE reservations r JOIN outbound_order_lines ol ON ol.id = r.order_line_id
                SET r.current_location_id = ?, r.status = COALESCE(?, r.status)
            WHERE ol.order_id = ? AND r.picked_qty > 0 AND r.status <> \'released\'')
            ->execute([$locId, $status, $orderId]);
    }

    private function newTrackingId(array $order): string
    {
        return sprintf('TRK-%s-%s', preg_replace('/[^A-Z0-9]/', '', strtoupper($order['order_code'])), strtoupper(bin2hex(random_bytes(3))));
    }

    private function ref(array $order, string $note): array
    {
        return ['code' => $order['order_code'], 'type' => 'outbound_order', 'id' => (int) $order['id'], 'note' => $note];
    }

    /** @param list<string>|null $allowed */
    private function lockOrder(int $orderId, ?array $allowed): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM outbound_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new BusinessRuleException('Không tìm thấy đơn xuất.');
        }
        if ($allowed !== null && !in_array($order['status'], $allowed, true)) {
            throw new BusinessRuleException(sprintf('Đơn %s đang ở trạng thái "%s", không thực hiện được thao tác này.',
                $order['order_code'], self::STATUS_LABELS[$order['status']] ?? $order['status']));
        }
        return $order;
    }

    private function setStatus(int $orderId, string $status, string $stampColumn): void
    {
        $this->pdo->prepare("UPDATE outbound_orders SET status = ?, {$stampColumn} = NOW() WHERE id = ?")->execute([$status, $orderId]);
    }

    private function outbox(string $event, string $orderCode, array $payload): void
    {
        $this->pdo->prepare('INSERT INTO oms_outbox (event_type, order_code, payload) VALUES (?, ?, ?)')
            ->execute([$event, $orderCode, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}
