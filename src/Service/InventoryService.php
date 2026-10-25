<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * NƠI DUY NHẤT được sửa inventory_balances và ghi stock_movements.
 *
 * Mọi phép đều chạy trong transaction (tự mở nếu caller chưa mở), khóa dòng balance
 * bằng SELECT … FOR UPDATE theo thứ tự (sku, location_id) để tránh deadlock.
 * Tham số $ref: ['code' => mã chứng từ, 'type' => loại, 'id' => id, 'group' => mã gom, 'note' => ghi chú].
 */
final class InventoryService
{
    /** Loại movement không di chuyển hàng (chỉ đổi Reserved). */
    public const RESERVATION_TYPES = ['RESERVE', 'UNRESERVE'];

    /** Loại movement làm đổi tổng tồn. */
    public const ON_HAND_CHANGING_TYPES = ['OPENING', 'RECEIVE', 'SHIP', 'ADJUST'];

    /** Hướng hợp lệ cho các movement chuyển vị trí: [zone/mã nguồn, zone/mã đích]. */
    private const MOVE_RULES = [
        'PUTAWAY'  => ['RECEIVING', 'storage'],
        'PICK'     => ['storage', 'PICK-STG'],
        'PACK_IN'  => ['PICK-STG', 'PACK-01'],
        'PACK_OUT' => ['PACK-01', 'OUT-STG'],
        'LOAD'     => ['OUT-STG', 'DOCK'],
        'RESTOCK'  => ['staging', 'storage'],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $locationsByCode = [];

    public function __construct(private PDO $pdo, private ?int $userId = null)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function withUser(?int $userId): self
    {
        $clone = clone $this;
        $clone->userId = $userId;
        return $clone;
    }

    /** Chạy $fn trong transaction; nếu đã ở trong transaction thì dùng chung. */
    public function transactional(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function location(string $code): array
    {
        if (!isset($this->locationsByCode[$code])) {
            $stmt = $this->pdo->prepare('SELECT * FROM warehouse_locations WHERE code = ? AND active = 1 ORDER BY id LIMIT 1');
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new BusinessRuleException("Location {$code} không tồn tại trong Master Location.");
            }
            $this->locationsByCode[$code] = $row;
        }
        return $this->locationsByCode[$code];
    }

    /** Tồn đầu kỳ (OPENING) – chỉ dùng khi nạp seed. */
    public function opening(string $sku, string $locationCode, int $qty, array $ref = []): int
    {
        $this->assertPositive($qty);
        return $this->transactional(function () use ($sku, $locationCode, $qty, $ref) {
            $loc = $this->location($locationCode);
            $this->lockPairs([[$sku, (int) $loc['id']]]);
            $this->addToPair($sku, (int) $loc['id'], $qty, 0);
            return $this->insertMovement('OPENING', $sku, $qty, null, (int) $loc['id'], 0, $ref);
        });
    }

    /** Xác nhận Receiving: (ngoài kho) → RECEIVING, tăng tổng tồn. */
    public function receive(string $sku, int $qty, array $ref = []): int
    {
        $this->assertPositive($qty);
        return $this->transactional(function () use ($sku, $qty, $ref) {
            $loc = $this->location('RECEIVING');
            $this->lockPairs([[$sku, (int) $loc['id']]]);
            $this->addToPair($sku, (int) $loc['id'], $qty, 0);
            return $this->insertMovement('RECEIVE', $sku, $qty, null, (int) $loc['id'], 0, $ref);
        });
    }

    /**
     * Chuyển vị trí trong kho (tổng tồn không đổi). $reservedQty là phần Reserved đi theo hàng.
     */
    public function move(string $type, string $sku, string $fromCode, string $toCode, int $qty, int $reservedQty = 0, array $ref = []): int
    {
        $this->assertPositive($qty);
        if (!isset(self::MOVE_RULES[$type])) {
            throw new BusinessRuleException("Loại movement {$type} không phải chuyển vị trí.");
        }
        if ($reservedQty < 0 || $reservedQty > $qty) {
            throw new BusinessRuleException('Phần Reserved đi kèm phải nằm trong khoảng 0..số lượng chuyển.');
        }
        $from = $this->location($fromCode);
        $to = $this->location($toCode);
        [$fromRule, $toRule] = self::MOVE_RULES[$type];
        if (!$this->matchesRule($from, $fromRule) || !$this->matchesRule($to, $toRule)) {
            throw new BusinessRuleException(sprintf('%s chỉ cho phép %s → %s, không cho phép %s → %s.',
                $type, $fromRule, $toRule, $fromCode, $toCode));
        }

        return $this->transactional(function () use ($type, $sku, $from, $to, $qty, $reservedQty, $ref) {
            $fromId = (int) $from['id'];
            $toId = (int) $to['id'];
            $rows = $this->lockPairs([[$sku, $fromId], [$sku, $toId]]);
            $src = $rows[$this->key($sku, $fromId)];
            $free = (int) $src['on_hand'] - (int) $src['reserved'];
            if ((int) $src['on_hand'] < $qty) {
                throw new BusinessRuleException(sprintf('%s tại %s chỉ có %d, không đủ %d để chuyển.', $sku, $from['code'], $src['on_hand'], $qty));
            }
            if ((int) $src['reserved'] < $reservedQty) {
                throw new BusinessRuleException(sprintf('%s tại %s chỉ giữ %d cho đơn, không đủ %d.', $sku, $from['code'], $src['reserved'], $reservedQty));
            }
            if ($free < $qty - $reservedQty) {
                throw new BusinessRuleException(sprintf('Không được lấy hàng đang giữ cho đơn khác: %s tại %s còn trống %d.', $sku, $from['code'], $free));
            }
            $this->addToPair($sku, $fromId, -$qty, -$reservedQty);
            $this->addToPair($sku, $toId, $qty, $reservedQty);
            return $this->insertMovement($type, $sku, $qty, $fromId, $toId, $reservedQty, $ref);
        });
    }

    /** Giữ hàng cho đơn tại một cặp Storage; On-hand không đổi, Available giảm. */
    public function reserve(string $sku, string $locationCode, int $qty, array $ref = []): int
    {
        $this->assertPositive($qty);
        return $this->transactional(function () use ($sku, $locationCode, $qty, $ref) {
            $loc = $this->location($locationCode);
            if (!(int) $loc['is_allocatable']) {
                throw new BusinessRuleException("Location {$locationCode} không được phân bổ cho đơn (chỉ Storage).");
            }
            $rows = $this->lockPairs([[$sku, (int) $loc['id']]]);
            $row = $rows[$this->key($sku, (int) $loc['id'])];
            if ((int) $row['alloc_locked']) {
                throw new BusinessRuleException("Cặp {$sku} @ {$locationCode} đang bị khóa phân bổ (kiểm kê).");
            }
            $available = (int) $row['on_hand'] - (int) $row['reserved'];
            if ($qty > $available) {
                throw new BusinessRuleException(sprintf('Reservation %d vượt Available %d của %s @ %s.', $qty, $available, $sku, $locationCode));
            }
            $this->addToPair($sku, (int) $loc['id'], 0, $qty);
            return $this->insertMovement('RESERVE', $sku, $qty, null, (int) $loc['id'], $qty, $ref);
        });
    }

    /** Hủy giữ hàng tại một cặp (hàng nằm ở đâu thì hủy ở đó). */
    public function release(string $sku, string $locationCode, int $qty, array $ref = []): int
    {
        $this->assertPositive($qty);
        return $this->transactional(function () use ($sku, $locationCode, $qty, $ref) {
            $loc = $this->location($locationCode);
            $rows = $this->lockPairs([[$sku, (int) $loc['id']]]);
            $row = $rows[$this->key($sku, (int) $loc['id'])];
            if ((int) $row['reserved'] < $qty) {
                throw new BusinessRuleException(sprintf('Không thể hủy %d: %s @ %s chỉ đang giữ %d.', $qty, $sku, $locationCode, $row['reserved']));
            }
            $this->addToPair($sku, (int) $loc['id'], 0, -$qty);
            return $this->insertMovement('UNRESERVE', $sku, $qty, (int) $loc['id'], null, $qty, $ref);
        });
    }

    /** Hàng rời kho tại DOCK: giảm On-hand và Reserved đúng bằng số xuất. */
    public function ship(string $sku, int $qty, array $ref = []): int
    {
        $this->assertPositive($qty);
        return $this->transactional(function () use ($sku, $qty, $ref) {
            $dock = $this->location('DOCK');
            $rows = $this->lockPairs([[$sku, (int) $dock['id']]]);
            $row = $rows[$this->key($sku, (int) $dock['id'])];
            if ((int) $row['reserved'] < $qty || (int) $row['on_hand'] < $qty) {
                throw new BusinessRuleException(sprintf('DOCK chỉ có %d (%d đã giữ) của %s, không thể xuất %d.', $row['on_hand'], $row['reserved'], $sku, $qty));
            }
            $this->addToPair($sku, (int) $dock['id'], -$qty, -$qty);
            return $this->insertMovement('SHIP', $sku, $qty, (int) $dock['id'], null, $qty, $ref);
        });
    }

    /**
     * Điều chỉnh sau kiểm kê tại một cặp Storage. Điều chỉnh âm không được đụng phần Reserved:
     * caller phải hủy Reserved trước (BR-13).
     */
    public function adjust(string $sku, string $locationCode, int $delta, array $ref = []): int
    {
        if ($delta === 0) {
            throw new BusinessRuleException('Adjustment phải khác 0.');
        }
        return $this->transactional(function () use ($sku, $locationCode, $delta, $ref) {
            $loc = $this->location($locationCode);
            if ($loc['zone_type'] !== 'storage') {
                throw new BusinessRuleException('Adjustment chỉ áp dụng cho location Storage.');
            }
            $locId = (int) $loc['id'];
            $rows = $this->lockPairs([[$sku, $locId]]);
            $row = $rows[$this->key($sku, $locId)];
            if ($delta < 0 && (int) $row['on_hand'] - (int) $row['reserved'] < -$delta) {
                throw new BusinessRuleException(sprintf('Adjustment %d làm On-hand nhỏ hơn Reserved (%d/%d). Hủy Reserved trước.', $delta, $row['on_hand'], $row['reserved']));
            }
            $this->addToPair($sku, $locId, $delta, 0);
            return $delta > 0
                ? $this->insertMovement('ADJUST', $sku, $delta, null, $locId, 0, $ref)
                : $this->insertMovement('ADJUST', $sku, -$delta, $locId, null, 0, $ref);
        });
    }

    public function setAllocationLock(string $sku, string $locationCode, bool $locked, ?string $reason = null): void
    {
        $this->transactional(function () use ($sku, $locationCode, $locked, $reason) {
            $loc = $this->location($locationCode);
            $this->lockPairs([[$sku, (int) $loc['id']]]);
            $stmt = $this->pdo->prepare('UPDATE inventory_balances SET alloc_locked = ?, alloc_lock_reason = ? WHERE product_sku = ? AND location_id = ? AND stock_status = \'available\'');
            $stmt->execute([$locked ? 1 : 0, $locked ? $reason : null, $sku, $loc['id']]);
        });
    }

    /** @return array{on_hand:int, reserved:int, available:int, alloc_locked:bool} */
    public function balance(string $sku, string $locationCode): array
    {
        $loc = $this->location($locationCode);
        $stmt = $this->pdo->prepare('SELECT on_hand, reserved, alloc_locked FROM inventory_balances WHERE product_sku = ? AND location_id = ? AND stock_status = \'available\'');
        $stmt->execute([$sku, $loc['id']]);
        $row = $stmt->fetch() ?: ['on_hand' => 0, 'reserved' => 0, 'alloc_locked' => 0];
        $onHand = (int) $row['on_hand'];
        $reserved = (int) $row['reserved'];
        return [
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => (int) $loc['is_allocatable'] ? $onHand - $reserved : 0,
            'alloc_locked' => (bool) $row['alloc_locked'],
        ];
    }

    // ---------------------------------------------------------------- nội bộ

    private function assertPositive(int $qty): void
    {
        if ($qty <= 0) {
            throw new BusinessRuleException('Số lượng phải lớn hơn 0.');
        }
    }

    private function matchesRule(array $location, string $rule): bool
    {
        return in_array($rule, ['storage', 'staging', 'dock'], true)
            ? $location['zone_type'] === $rule
            : $location['code'] === $rule;
    }

    private function key(string $sku, int $locationId): string
    {
        return $sku . '@' . $locationId;
    }

    /**
     * Tạo (nếu chưa có) và khóa các dòng balance theo thứ tự cố định.
     * @param list<array{0:string,1:int}> $pairs
     * @return array<string, array<string, mixed>>
     */
    private function lockPairs(array $pairs): array
    {
        usort($pairs, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $insert = $this->pdo->prepare("INSERT INTO inventory_balances (product_sku, location_id, stock_status) VALUES (?, ?, 'available') ON DUPLICATE KEY UPDATE id = id");
        $select = $this->pdo->prepare("SELECT * FROM inventory_balances WHERE product_sku = ? AND location_id = ? AND stock_status = 'available' FOR UPDATE");
        $rows = [];
        foreach ($pairs as [$sku, $locationId]) {
            $k = $this->key($sku, $locationId);
            if (isset($rows[$k])) {
                continue;
            }
            $insert->execute([$sku, $locationId]);
            $select->execute([$sku, $locationId]);
            $rows[$k] = $select->fetch();
        }
        return $rows;
    }

    private function addToPair(string $sku, int $locationId, int $onHandDelta, int $reservedDelta): void
    {
        $stmt = $this->pdo->prepare("UPDATE inventory_balances
            SET first_in_at = CASE
                    WHEN on_hand = 0 AND ? > 0 THEN NOW(6)
                    WHEN on_hand + ? = 0 THEN NULL
                    ELSE first_in_at END,
                on_hand = on_hand + ?,
                reserved = reserved + ?
            WHERE product_sku = ? AND location_id = ? AND stock_status = 'available'");
        $stmt->execute([$onHandDelta, $onHandDelta, $onHandDelta, $reservedDelta, $sku, $locationId]);
    }

    private function insertMovement(string $type, string $sku, int $qty, ?int $fromId, ?int $toId, int $reservedMoved, array $ref): int
    {
        if ($fromId === null && $toId === null) {
            throw new BusinessRuleException('Movement phải có location nguồn hoặc đích.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO stock_movements
            (movement_type, product_sku, qty, reserved_moved, changes_on_hand, from_location_id, to_location_id,
             reference_code, reference_type, reference_id, group_code, user_id, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $type, $sku, $qty, $reservedMoved,
            in_array($type, self::ON_HAND_CHANGING_TYPES, true) ? 1 : 0,
            $fromId, $toId,
            $ref['code'] ?? null, $ref['type'] ?? null, $ref['id'] ?? null, $ref['group'] ?? null,
            $this->userId, $ref['note'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
