<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Actor;
use PDO;

/**
 * Cycle count C1–C6 (A4 §3.1), chỉ cho cặp Storage (BR-14).
 * C1 chốt tồn hệ thống lúc tạo phiếu; C2 đếm mù; C3 tính chênh lệch; C4 đếm lại + rà soát giao dịch;
 * C5 Giám sát duyệt ⇒ ADJUST đúng bằng chênh lệch (hủy Reserved dư trước, BR-13); C6 đóng phiếu.
 */
final class AuditService
{
    public const STATUS_LABELS = [
        'OPEN' => 'Chờ đếm',
        'RECOUNT' => 'Lệch – chờ đếm lại',
        'PENDING_APPROVAL' => 'Chờ Giám sát duyệt',
        'ADJUSTED' => 'Đã điều chỉnh',
        'CLOSED_MATCH' => 'Khớp – đã đóng',
    ];

    public const TRIGGER_LABELS = [
        'CYCLE' => 'Kiểm kê định kỳ',
        'PICK_SHORTAGE' => 'Tự sinh do pick thiếu',
    ];

    private InventoryService $inventory;

    public function __construct(private PDO $pdo, ?InventoryService $inventory = null)
    {
        $this->inventory = $inventory ?? new InventoryService($pdo);
    }

    /** C1: tạo phiếu cho cặp (SKU + Location Storage), chốt tồn hệ thống. */
    public function createCount(string $sku, string $locationCode, Actor $actor, string $trigger = 'CYCLE', ?int $orderId = null): int
    {
        if ($trigger === 'CYCLE' && !$actor->isSupervisor()) {
            throw new BusinessRuleException('Chỉ Giám sát kho được tạo phiếu kiểm kê.');
        }
        return $this->inventory->transactional(function () use ($sku, $locationCode, $actor, $trigger, $orderId) {
            $loc = $this->inventory->location($locationCode);
            if ($loc['zone_type'] !== 'storage') {
                throw new BusinessRuleException('Cycle count chỉ áp dụng cho location Storage (A-01, B-01).');
            }
            $open = $this->pdo->prepare("SELECT count_code FROM cycle_counts WHERE product_sku = ? AND location_id = ? AND status IN ('OPEN', 'RECOUNT', 'PENDING_APPROVAL')");
            $open->execute([$sku, $loc['id']]);
            if ($code = $open->fetchColumn()) {
                throw new BusinessRuleException("Cặp {$sku} @ {$locationCode} đang có phiếu kiểm kê {$code} chưa đóng.");
            }
            $system = $this->inventory->balance($sku, $locationCode)['on_hand'];
            $n = (int) $this->pdo->query('SELECT COUNT(*) FROM cycle_counts')->fetchColumn() + 1;
            $this->pdo->prepare('INSERT INTO cycle_counts (count_code, product_sku, location_id, trigger_type, source_order_id, system_qty, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([sprintf('CC-%04d', $n), $sku, $loc['id'], $trigger, $orderId, $system, $actor->id]);
            return (int) $this->pdo->lastInsertId();
        });
    }

    /** C2 + C3: nhập tồn thực tế (đếm mù). Khớp ⇒ đóng phiếu; lệch ⇒ chờ đếm lại. */
    public function submitCount(int $countId, int $counted, Actor $actor): string
    {
        return $this->inventory->transactional(function () use ($countId, $counted, $actor) {
            $count = $this->lock($countId, ['OPEN']);
            $this->assertQty($counted);
            $diff = $counted - (int) $count['system_qty'];
            $status = $diff === 0 ? 'CLOSED_MATCH' : 'RECOUNT';
            $this->pdo->prepare('UPDATE cycle_counts SET counted_qty = ?, difference = ?, status = ?, counted_by = ?, counted_at = NOW(),
                    closed_at = IF(? = \'CLOSED_MATCH\', NOW(), NULL) WHERE id = ?')
                ->execute([$counted, $diff, $status, $actor->id, $status, $countId]);
            if ($status === 'CLOSED_MATCH') {
                $this->unlockIfShortage($count);
            }
            return $status;
        });
    }

    /** C4: đếm lại sau khi rà soát giao dịch gần nhất; số đếm lại là số thực tế cuối cùng. */
    public function submitRecount(int $countId, int $recount, Actor $actor): string
    {
        return $this->inventory->transactional(function () use ($countId, $recount, $actor) {
            $count = $this->lock($countId, ['RECOUNT']);
            $this->assertQty($recount);
            $diff = $recount - (int) $count['system_qty'];
            $status = $diff === 0 ? 'CLOSED_MATCH' : 'PENDING_APPROVAL';
            $this->pdo->prepare('UPDATE cycle_counts SET recount_qty = ?, difference = ?, status = ?, recounted_by = ?, recounted_at = NOW(),
                    closed_at = IF(? = \'CLOSED_MATCH\', NOW(), NULL) WHERE id = ?')
                ->execute([$recount, $diff, $status, $actor->id, $status, $countId]);
            if ($status === 'CLOSED_MATCH') {
                $this->unlockIfShortage($count);
            }
            return $status;
        });
    }

    /**
     * BR-13: nếu Adjustment (−) sẽ làm On-hand < Reserved thì hủy phần Reserved không còn hàng đỡ (trước khi hạ On-hand).
     * @return int số Reserved đã hủy
     */
    public function releaseUnbackedReservations(int $countId, Actor $actor): int
    {
        if (!$actor->isSupervisor()) {
            throw new BusinessRuleException('Chỉ Giám sát kho được hủy Reserved khi kiểm kê.');
        }
        return $this->inventory->withUser($actor->id)->transactional(function () use ($countId, $actor) {
            $count = $this->lock($countId, ['PENDING_APPROVAL']);
            $loc = $this->locationCode((int) $count['location_id']);
            $bal = $this->inventory->balance($count['product_sku'], $loc);
            $excess = (int) $bal['reserved'] - ((int) $bal['on_hand'] + (int) $count['difference']);
            if ($excess <= 0) {
                return 0;
            }
            $released = (new OutboundService($this->pdo, $this->inventory->withUser($actor->id)))
                ->releaseAtPair($count['product_sku'], $loc, $excess, "Kiểm kê {$count['count_code']}: hủy Reserved không còn hàng đỡ", $actor);
            if ($released < $excess) {
                throw new BusinessRuleException('Không hủy đủ phần Reserved dư; kiểm tra lại các đơn đang giữ hàng.');
            }
            $this->pdo->prepare('UPDATE cycle_counts SET released_reserved_qty = released_reserved_qty + ? WHERE id = ?')->execute([$released, $countId]);
            return $released;
        });
    }

    /** C5 + C6: Giám sát duyệt ⇒ ADJUST đúng bằng chênh lệch, ghi lý do, mở khóa phân bổ, đóng phiếu. */
    public function approve(int $countId, string $reason, Actor $actor): int
    {
        if (!$actor->isSupervisor()) {
            throw new BusinessRuleException('Chỉ Giám sát kho được duyệt Adjustment.');
        }
        if (trim($reason) === '') {
            throw new BusinessRuleException('Adjustment cần ghi lý do.');
        }
        return $this->inventory->withUser($actor->id)->transactional(function () use ($countId, $reason, $actor) {
            $this->releaseUnbackedReservations($countId, $actor);
            $count = $this->lock($countId, ['PENDING_APPROVAL']);
            $loc = $this->locationCode((int) $count['location_id']);
            $movementId = $this->inventory->withUser($actor->id)->adjust($count['product_sku'], $loc, (int) $count['difference'], [
                'code' => $count['count_code'], 'type' => 'cycle_count', 'id' => (int) $count['id'],
                'note' => "Adjustment {$count['difference']}: " . trim($reason),
            ]);
            $this->pdo->prepare("UPDATE cycle_counts SET status = 'ADJUSTED', reason = ?, approved_by = ?, approved_at = NOW(), adjust_movement_id = ?, closed_at = NOW() WHERE id = ?")
                ->execute([trim($reason), $actor->id, $movementId, $countId]);
            $this->unlockIfShortage($count);
            return $movementId;
        });
    }

    // ---------------------------------------------------------------- truy vấn

    /** @return array<string, mixed>|null */
    public function count(int $countId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, l.code AS location_code, o.order_code FROM cycle_counts c
            JOIN warehouse_locations l ON l.id = c.location_id LEFT JOIN outbound_orders o ON o.id = c.source_order_id WHERE c.id = ?');
        $stmt->execute([$countId]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function counts(): array
    {
        return $this->pdo->query('SELECT c.*, l.code AS location_code, o.order_code FROM cycle_counts c
            JOIN warehouse_locations l ON l.id = c.location_id LEFT JOIN outbound_orders o ON o.id = c.source_order_id ORDER BY c.id DESC')->fetchAll();
    }

    /** C4: giao dịch gần nhất của cặp (nhập/xuất/pick nhầm location). @return list<array<string, mixed>> */
    public function recentMovements(int $countId, int $limit = 10): array
    {
        $count = $this->count($countId);
        $stmt = $this->pdo->prepare('SELECT m.*, f.code AS from_code, t.code AS to_code FROM stock_movements m
            LEFT JOIN warehouse_locations f ON f.id = m.from_location_id LEFT JOIN warehouse_locations t ON t.id = m.to_location_id
            WHERE m.product_sku = ? AND (m.from_location_id = ? OR m.to_location_id = ?) ORDER BY m.id DESC LIMIT ' . (int) $limit);
        $stmt->execute([$count['product_sku'], $count['location_id'], $count['location_id']]);
        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------------- nội bộ

    private function unlockIfShortage(array $count): void
    {
        $loc = $this->locationCode((int) $count['location_id']);
        if ($this->inventory->balance($count['product_sku'], $loc)['alloc_locked']) {
            $this->inventory->setAllocationLock($count['product_sku'], $loc, false);
        }
    }

    private function assertQty(int $qty): void
    {
        if ($qty < 0) {
            throw new BusinessRuleException('Số đếm thực tế không được âm.');
        }
    }

    private function locationCode(int $locationId): string
    {
        $stmt = $this->pdo->prepare('SELECT code FROM warehouse_locations WHERE id = ?');
        $stmt->execute([$locationId]);
        return (string) $stmt->fetchColumn();
    }

    /** @param list<string> $allowed */
    private function lock(int $countId, array $allowed): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cycle_counts WHERE id = ? FOR UPDATE');
        $stmt->execute([$countId]);
        $count = $stmt->fetch();
        if (!$count) {
            throw new BusinessRuleException('Không tìm thấy phiếu kiểm kê.');
        }
        if (!in_array($count['status'], $allowed, true)) {
            throw new BusinessRuleException(sprintf('Phiếu %s đang ở trạng thái "%s".', $count['count_code'], self::STATUS_LABELS[$count['status']] ?? $count['status']));
        }
        return $count;
    }
}
