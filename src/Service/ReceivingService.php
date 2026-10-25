<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Actor;
use PDO;

/**
 * Inbound theo A2: ASN → check-in → kiểm đếm → QC → xác nhận Receiving → put-away → đóng phiếu.
 *
 * Chỉ bước "Xác nhận Receiving" làm tăng tổng tồn (A2-R1), đúng bằng số thực nhận ĐẠT (A2-R6).
 * Có chênh lệch (thiếu/thừa/lỗi) thì cần Giám sát duyệt; phần nhận thừa chỉ vào tồn khi được duyệt rõ (E2).
 */
final class ReceivingService
{
    public const STATUS_LABELS = [
        'EXPECTED' => 'Dự kiến',
        'CHECKED_IN' => 'Đã check-in',
        'COUNTED' => 'Đã kiểm đếm',
        'QC_DONE' => 'Đã QC',
        'RECEIVED' => 'Đã xác nhận nhập',
        'PUTAWAY_DONE' => 'Đã put-away',
        'CLOSED' => 'Đã đóng',
        'CANCELLED' => 'Đã hủy',
    ];

    private InventoryService $inventory;

    public function __construct(private PDO $pdo, ?InventoryService $inventory = null)
    {
        $this->inventory = $inventory ?? new InventoryService($pdo);
    }

    /**
     * Bước 1: tạo ASN dự kiến. Không đổi tồn.
     * @param list<array{sku:string, qty:int, target?:string}> $lines
     */
    public function createAsn(string $code, string $supplier, array $lines, Actor $actor): int
    {
        $code = trim($code);
        if ($code === '' || !$lines) {
            throw new BusinessRuleException('ASN cần mã phiếu và ít nhất một dòng hàng.');
        }
        $skus = array_column($lines, 'sku');
        if (count($skus) !== count(array_unique($skus))) {
            throw new BusinessRuleException('Mỗi SKU chỉ xuất hiện một dòng trên ASN.');
        }
        return $this->inventory->transactional(function () use ($code, $supplier, $lines, $actor) {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM goods_receipts WHERE receipt_code = ?');
            $exists->execute([$code]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new BusinessRuleException("Mã ASN {$code} đã tồn tại.");
            }
            $wh = (int) $this->pdo->query("SELECT id FROM warehouses WHERE code = 'WH-DEMO'")->fetchColumn() ?: null;
            $this->pdo->prepare("INSERT INTO goods_receipts (receipt_code, warehouse_id, supplier_name, status, created_by) VALUES (?, ?, ?, 'EXPECTED', ?)")
                ->execute([$code, $wh, $supplier, $actor->id]);
            $receiptId = (int) $this->pdo->lastInsertId();

            $product = $this->pdo->prepare('SELECT p.sku, l.code AS putaway_code FROM products p LEFT JOIN warehouse_locations l ON l.id = p.putaway_location_id WHERE p.sku = ?');
            $insert = $this->pdo->prepare('INSERT INTO goods_receipt_items (receipt_id, line_no, product_sku, target_location_id, expected_qty) VALUES (?, ?, ?, ?, ?)');
            foreach (array_values($lines) as $i => $line) {
                $qty = (int) $line['qty'];
                if ($qty <= 0) {
                    throw new BusinessRuleException("Dòng " . ($i + 1) . ": số lượng ASN phải > 0.");
                }
                $product->execute([$line['sku']]);
                $p = $product->fetch();
                if (!$p) {
                    throw new BusinessRuleException("SKU {$line['sku']} không có trong danh mục.");
                }
                $targetCode = $line['target'] ?? $p['putaway_code'];
                $target = $targetCode ? $this->inventory->location($targetCode) : null;
                if (!$target || $target['zone_type'] !== 'storage') {
                    throw new BusinessRuleException("Location đích dự kiến của {$line['sku']} phải là Storage.");
                }
                $insert->execute([$receiptId, $i + 1, $line['sku'], $target['id'], $qty]);
            }
            return $receiptId;
        });
    }

    /** Bước 2: xe đến, check-in, dỡ hàng xuống khu nhận (hệ thống chưa ghi nhận location). */
    public function checkIn(int $receiptId, Actor $actor): void
    {
        $this->transition($receiptId, ['EXPECTED'], 'CHECKED_IN', 'checked_in', $actor);
    }

    /**
     * Bước 3: kiểm đếm từng dòng; chênh lệch phải có lý do.
     * @param array<int, array{counted:int, reason?:string}> $counts item_id => số đếm
     */
    public function count(int $receiptId, array $counts, Actor $actor): void
    {
        $this->inventory->transactional(function () use ($receiptId, $counts, $actor) {
            $this->lockReceipt($receiptId, ['CHECKED_IN', 'COUNTED']);
            $items = $this->itemsById($receiptId);
            if (array_diff(array_keys($items), array_keys($counts))) {
                throw new BusinessRuleException('Cần nhập số kiểm đếm cho tất cả các dòng.');
            }
            $stmt = $this->pdo->prepare('UPDATE goods_receipt_items SET counted_qty = ?, variance_reason = ?, qc_pass_qty = 0, qc_fail_qty = 0 WHERE id = ?');
            foreach ($counts as $itemId => $c) {
                if (!isset($items[$itemId])) {
                    throw new BusinessRuleException('Dòng không thuộc phiếu này.');
                }
                $counted = (int) $c['counted'];
                if ($counted < 0) {
                    throw new BusinessRuleException('Số kiểm đếm không được âm.');
                }
                $reason = trim((string) ($c['reason'] ?? ''));
                if ($counted !== (int) $items[$itemId]['expected_qty'] && $reason === '') {
                    throw new BusinessRuleException("Dòng {$items[$itemId]['product_sku']}: lệch ASN cần ghi lý do.");
                }
                $stmt->execute([$counted, $reason ?: null, $itemId]);
            }
            $this->setStatus($receiptId, 'COUNTED', 'counted', $actor);
        });
    }

    /**
     * Bước 4: QC; phần lỗi lập phiếu QC, không vào tồn (E3).
     * @param array<int, array{fail:int, reason?:string}> $fails item_id => số lỗi (dòng không nêu = đạt 100%)
     */
    public function qc(int $receiptId, array $fails, Actor $actor): void
    {
        $this->inventory->transactional(function () use ($receiptId, $fails, $actor) {
            $receipt = $this->lockReceipt($receiptId, ['COUNTED']);
            $items = $this->itemsById($receiptId);
            $update = $this->pdo->prepare('UPDATE goods_receipt_items SET qc_pass_qty = ?, qc_fail_qty = ? WHERE id = ?');
            $record = $this->pdo->prepare('INSERT INTO qc_records (qc_code, receipt_item_id, product_sku, fail_qty, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($items as $itemId => $item) {
                $fail = (int) ($fails[$itemId]['fail'] ?? 0);
                $counted = (int) $item['counted_qty'];
                if ($fail < 0 || $fail > $counted) {
                    throw new BusinessRuleException("Dòng {$item['product_sku']}: số lỗi phải trong khoảng 0..{$counted}.");
                }
                $update->execute([$counted - $fail, $fail, $itemId]);
                if ($fail > 0) {
                    $reason = trim((string) ($fails[$itemId]['reason'] ?? ''));
                    if ($reason === '') {
                        throw new BusinessRuleException("Dòng {$item['product_sku']}: hàng lỗi cần ghi lý do QC.");
                    }
                    $record->execute([sprintf('QC-%s-%d', $receipt['receipt_code'], $item['line_no']), $itemId, $item['product_sku'], $fail, $reason, $actor->id]);
                }
            }
            $this->setStatus($receiptId, 'QC_DONE', 'qc', $actor);
        });
    }

    /** Có chênh lệch (thiếu, thừa, lỗi) ở bất kỳ dòng nào ⇒ cần Giám sát duyệt. */
    public function hasVariance(int $receiptId): bool
    {
        foreach ($this->itemsById($receiptId) as $item) {
            if ((int) $item['counted_qty'] !== (int) $item['expected_qty'] || (int) $item['qc_fail_qty'] > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bước 5 – XÁC NHẬN RECEIVING: ghi nhận phần đạt vào RECEIVING (tổng tồn tăng đúng một lần),
     * tạo task put-away (bước 6). Idempotent: phiếu đã xác nhận thì từ chối, không cộng thêm.
     */
    public function confirmReceiving(int $receiptId, Actor $actor, bool $acceptOverReceipt = false): int
    {
        return $this->inventory->withUser($actor->id)->transactional(function () use ($receiptId, $actor, $acceptOverReceipt) {
            $receipt = $this->lockReceipt($receiptId, null);
            if (in_array($receipt['status'], ['RECEIVED', 'PUTAWAY_DONE', 'CLOSED'], true)) {
                throw new BusinessRuleException("Phiếu {$receipt['receipt_code']} đã được xác nhận nhập trước đó; không ghi nhận thêm.");
            }
            if ($receipt['status'] !== 'QC_DONE') {
                throw new BusinessRuleException('Phải hoàn tất kiểm đếm và QC trước khi xác nhận Receiving.');
            }
            $items = $this->itemsById($receiptId);
            $variance = $this->hasVariance($receiptId);
            $hasOver = false;
            foreach ($items as $item) {
                $hasOver = $hasOver || (int) $item['qc_pass_qty'] > (int) $item['expected_qty'];
            }
            if ($variance && !$actor->isSupervisor()) {
                throw new BusinessRuleException('Phiếu có chênh lệch (thiếu/thừa/lỗi): cần Giám sát kho duyệt khi xác nhận Receiving.');
            }
            if ($acceptOverReceipt && !$actor->isSupervisor()) {
                throw new BusinessRuleException('Chỉ Giám sát kho được duyệt nhận thừa.');
            }

            $inventory = $this->inventory->withUser($actor->id);
            $receivingId = (int) $inventory->location('RECEIVING')['id'];
            $updateItem = $this->pdo->prepare('UPDATE goods_receipt_items SET received_qty = ?, over_qty = ?, over_status = ?, shortage_status = ? WHERE id = ?');
            $total = 0;
            foreach ($items as $itemId => $item) {
                $expected = (int) $item['expected_qty'];
                $pass = (int) $item['qc_pass_qty'];
                $over = max(0, $pass - $expected);
                $received = ($over > 0 && !$acceptOverReceipt) ? $expected : $pass;
                $overStatus = $over === 0 ? 'NONE' : ($acceptOverReceipt ? 'ACCEPTED' : 'PENDING');
                $shortage = (int) $item['counted_qty'] < $expected ? 'OPEN' : 'NONE';
                $updateItem->execute([$received, $acceptOverReceipt ? 0 : $over, $overStatus, $shortage, $itemId]);
                if ($received > 0) {
                    $inventory->receive($item['product_sku'], $received, $this->ref($receipt, 'Xác nhận Receiving'));
                    $this->createPutawayTask($receipt, $item, $received, $receivingId);
                }
                $total += $received;
            }

            $this->pdo->prepare("UPDATE goods_receipts SET status = ?, confirmed_at = NOW(), received_by = ?,
                    variance_approved_by = ?, variance_approved_at = IF(? IS NULL, NULL, NOW()),
                    over_receipt_approved_by = ?, over_receipt_approved_at = IF(? IS NULL, NULL, NOW())
                WHERE id = ?")
                ->execute([
                    $total > 0 ? 'RECEIVED' : 'PUTAWAY_DONE', $actor->id,
                    $variance ? $actor->id : null, $variance ? 1 : null,
                    ($acceptOverReceipt && $hasOver) ? $actor->id : null, ($acceptOverReceipt && $hasOver) ? 1 : null,
                    $receiptId,
                ]);
            return $total;
        });
    }

    /** E2 sau xác nhận: Giám sát quyết định phần thừa đang chờ (nhận vào tồn hoặc trả lại). */
    public function resolveOverReceipt(int $itemId, bool $accept, Actor $actor): void
    {
        if (!$actor->isSupervisor()) {
            throw new BusinessRuleException('Chỉ Giám sát kho được quyết định phần nhận thừa.');
        }
        $this->inventory->withUser($actor->id)->transactional(function () use ($itemId, $accept, $actor) {
            $item = $this->lockItem($itemId);
            $receipt = $this->lockReceipt((int) $item['receipt_id'], ['RECEIVED', 'PUTAWAY_DONE']);
            if ($item['over_status'] !== 'PENDING') {
                throw new BusinessRuleException('Dòng này không có phần nhận thừa đang chờ quyết định.');
            }
            $over = (int) $item['over_qty'];
            if ($accept) {
                $inventory = $this->inventory->withUser($actor->id);
                $inventory->receive($item['product_sku'], $over, $this->ref($receipt, "Giám sát duyệt nhận thừa +{$over}"));
                $this->createPutawayTask($receipt, $item, $over, (int) $inventory->location('RECEIVING')['id']);
                $this->pdo->prepare("UPDATE goods_receipt_items SET received_qty = received_qty + over_qty, over_qty = 0, over_status = 'ACCEPTED' WHERE id = ?")->execute([$itemId]);
                $this->pdo->prepare("UPDATE goods_receipts SET status = 'RECEIVED', over_receipt_approved_by = ?, over_receipt_approved_at = NOW() WHERE id = ?")->execute([$actor->id, $receipt['id']]);
            } else {
                $this->pdo->prepare("UPDATE goods_receipt_items SET over_status = 'RETURNED' WHERE id = ?")->execute([$itemId]);
            }
        });
    }

    /** E1: phần thiếu – chờ bổ sung hoặc đóng có lý do. */
    public function resolveShortage(int $itemId, string $status, string $note, Actor $actor): void
    {
        if (!in_array($status, ['WAITING', 'CLOSED'], true) || trim($note) === '') {
            throw new BusinessRuleException('Chọn "chờ bổ sung" hoặc "đóng" và ghi lý do.');
        }
        $item = $this->lockItem($itemId);
        if ($item['shortage_status'] === 'NONE' || $item['shortage_status'] === 'CLOSED') {
            throw new BusinessRuleException('Dòng này không có phần thiếu đang mở.');
        }
        $this->pdo->prepare('UPDATE goods_receipt_items SET shortage_status = ?, shortage_note = ? WHERE id = ?')->execute([$status, trim($note), $itemId]);
    }

    /**
     * Bước 7–9: quét location nguồn → SKU → location đích rồi xác nhận put-away.
     * Sai bất kỳ mã nào ⇒ từ chối, không đổi tồn (E4). Task đã xong ⇒ từ chối (idempotent).
     */
    public function confirmPutaway(int $taskId, string $scanSource, string $scanSku, string $scanTarget, Actor $actor): int
    {
        return $this->inventory->withUser($actor->id)->transactional(function () use ($taskId, $scanSource, $scanSku, $scanTarget, $actor) {
            $stmt = $this->pdo->prepare('SELECT t.*, s.code AS source_code, d.code AS target_code FROM putaway_tasks t
                JOIN warehouse_locations s ON s.id = t.source_location_id JOIN warehouse_locations d ON d.id = t.target_location_id
                WHERE t.id = ? FOR UPDATE');
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
            if (!$task) {
                throw new BusinessRuleException('Không tìm thấy task put-away.');
            }
            if ($task['status'] !== 'OPEN') {
                throw new BusinessRuleException("Task {$task['task_code']} đã hoàn tất; không chuyển thêm.");
            }
            $scanSource = strtoupper(trim($scanSource));
            $scanSku = strtoupper(trim($scanSku));
            $scanTarget = strtoupper(trim($scanTarget));
            if ($scanSource !== $task['source_code']) {
                throw new BusinessRuleException("Sai location nguồn: quét {$scanSource}, task yêu cầu {$task['source_code']}.");
            }
            if ($scanSku !== $task['product_sku']) {
                throw new BusinessRuleException("Sai SKU: quét {$scanSku}, task yêu cầu {$task['product_sku']}.");
            }
            $loc = $this->pdo->prepare('SELECT zone_type FROM warehouse_locations WHERE code = ?');
            $loc->execute([$scanTarget]);
            $zone = $loc->fetchColumn();
            if ($zone !== 'storage') {
                throw new BusinessRuleException("Location đích {$scanTarget} không phải Storage hợp lệ.");
            }
            if ($scanTarget !== $task['target_code']) {
                throw new BusinessRuleException("Location đích {$scanTarget} không khớp task (yêu cầu {$task['target_code']}).");
            }

            $item = $this->lockItem((int) $task['receipt_item_id']);
            $receipt = $this->lockReceipt((int) $item['receipt_id'], ['RECEIVED']);
            $movementId = $this->inventory->withUser($actor->id)->move('PUTAWAY', $task['product_sku'], 'RECEIVING', $task['target_code'],
                (int) $task['qty'], 0, $this->ref($receipt, "Put-away {$task['task_code']}"));
            $this->pdo->prepare("UPDATE putaway_tasks SET status = 'DONE', movement_id = ?, completed_at = NOW(), completed_by = ? WHERE id = ?")
                ->execute([$movementId, $actor->id, $taskId]);
            $this->pdo->prepare('UPDATE goods_receipt_items SET putaway_qty = putaway_qty + ? WHERE id = ?')->execute([$task['qty'], $item['id']]);

            $open = $this->pdo->prepare("SELECT COUNT(*) FROM putaway_tasks t JOIN goods_receipt_items i ON i.id = t.receipt_item_id WHERE i.receipt_id = ? AND t.status = 'OPEN'");
            $open->execute([$receipt['id']]);
            if ((int) $open->fetchColumn() === 0) {
                $this->pdo->prepare("UPDATE goods_receipts SET status = 'PUTAWAY_DONE' WHERE id = ?")->execute([$receipt['id']]);
            }
            return $movementId;
        });
    }

    /** Bước 10: Giám sát đóng phiếu, đối soát ASN – thực nhận – put-away. */
    public function close(int $receiptId, Actor $actor, string $note = ''): void
    {
        if (!$actor->isSupervisor()) {
            throw new BusinessRuleException('Chỉ Giám sát kho được đóng phiếu nhập.');
        }
        $this->inventory->transactional(function () use ($receiptId, $actor, $note) {
            $receipt = $this->lockReceipt($receiptId, ['PUTAWAY_DONE']);
            foreach ($this->itemsById($receiptId) as $item) {
                if ($item['over_status'] === 'PENDING') {
                    throw new BusinessRuleException("Dòng {$item['product_sku']} còn phần nhận thừa chờ quyết định.");
                }
            }
            $this->pdo->prepare("UPDATE goods_receipts SET status = 'CLOSED', closed_at = NOW(), closed_by = ?, close_note = ? WHERE id = ?")
                ->execute([$actor->id, $note !== '' ? $note : null, $receipt['id']]);
        });
    }

    public function cancel(int $receiptId, Actor $actor): void
    {
        $this->transition($receiptId, ['EXPECTED', 'CHECKED_IN', 'COUNTED', 'QC_DONE'], 'CANCELLED', null, $actor);
    }

    // ---------------------------------------------------------------- truy vấn

    /** @return array<string, mixed>|null */
    public function receipt(int $receiptId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM goods_receipts WHERE id = ?');
        $stmt->execute([$receiptId]);
        return $stmt->fetch() ?: null;
    }

    public function receiptIdByCode(string $code): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM goods_receipts WHERE receipt_code = ?');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return list<array<string, mixed>> */
    public function receipts(): array
    {
        return $this->pdo->query("SELECT r.*, COALESCE(SUM(i.expected_qty), 0) AS expected_total, COALESCE(SUM(i.received_qty), 0) AS received_total,
                COALESCE(SUM(i.putaway_qty), 0) AS putaway_total
            FROM goods_receipts r LEFT JOIN goods_receipt_items i ON i.receipt_id = r.id
            GROUP BY r.id ORDER BY r.id DESC")->fetchAll();
    }

    /** @return list<array<string, mixed>> dòng phiếu kèm tên SKU và location đích */
    public function items(int $receiptId): array
    {
        $stmt = $this->pdo->prepare('SELECT i.*, p.name AS product_name, p.size, p.color, l.code AS target_code
            FROM goods_receipt_items i JOIN products p ON p.sku = i.product_sku LEFT JOIN warehouse_locations l ON l.id = i.target_location_id
            WHERE i.receipt_id = ? ORDER BY i.line_no');
        $stmt->execute([$receiptId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function putawayTasks(?int $receiptId = null, bool $openOnly = false): array
    {
        $sql = 'SELECT t.*, s.code AS source_code, d.code AS target_code, r.receipt_code, r.id AS receipt_id
            FROM putaway_tasks t JOIN goods_receipt_items i ON i.id = t.receipt_item_id JOIN goods_receipts r ON r.id = i.receipt_id
            JOIN warehouse_locations s ON s.id = t.source_location_id JOIN warehouse_locations d ON d.id = t.target_location_id WHERE 1 = 1';
        $params = [];
        if ($receiptId !== null) {
            $sql .= ' AND r.id = ?';
            $params[] = $receiptId;
        }
        if ($openOnly) {
            $sql .= " AND t.status = 'OPEN'";
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY t.id');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function qcRecords(int $receiptId): array
    {
        $stmt = $this->pdo->prepare('SELECT q.* FROM qc_records q JOIN goods_receipt_items i ON i.id = q.receipt_item_id WHERE i.receipt_id = ? ORDER BY q.id');
        $stmt->execute([$receiptId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> phần thiếu đang theo dõi (E1) */
    public function openShortages(): array
    {
        return $this->pdo->query("SELECT i.*, r.receipt_code, i.expected_qty - i.counted_qty AS shortage_qty
            FROM goods_receipt_items i JOIN goods_receipts r ON r.id = i.receipt_id
            WHERE i.shortage_status IN ('OPEN', 'WAITING') ORDER BY i.id")->fetchAll();
    }

    // ---------------------------------------------------------------- nội bộ

    private function ref(array $receipt, string $note): array
    {
        return ['code' => $receipt['receipt_code'], 'type' => 'goods_receipt', 'id' => (int) $receipt['id'], 'note' => $note];
    }

    private function createPutawayTask(array $receipt, array $item, int $qty, int $sourceId): void
    {
        $n = (int) $this->pdo->query('SELECT COUNT(*) FROM putaway_tasks')->fetchColumn() + 1;
        $this->pdo->prepare('INSERT INTO putaway_tasks (task_code, receipt_item_id, product_sku, qty, source_location_id, target_location_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([sprintf('PA-%04d', $n), $item['id'], $item['product_sku'], $qty, $sourceId, $item['target_location_id']]);
    }

    /** @param list<string>|null $allowed */
    private function lockReceipt(int $receiptId, ?array $allowed): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM goods_receipts WHERE id = ? FOR UPDATE');
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch();
        if (!$receipt) {
            throw new BusinessRuleException('Không tìm thấy phiếu nhập.');
        }
        if ($allowed !== null && !in_array($receipt['status'], $allowed, true)) {
            throw new BusinessRuleException(sprintf('Phiếu %s đang ở trạng thái "%s", không thực hiện được thao tác này.',
                $receipt['receipt_code'], self::STATUS_LABELS[$receipt['status']] ?? $receipt['status']));
        }
        return $receipt;
    }

    private function lockItem(int $itemId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM goods_receipt_items WHERE id = ? FOR UPDATE');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            throw new BusinessRuleException('Không tìm thấy dòng phiếu nhập.');
        }
        return $item;
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsById(int $receiptId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM goods_receipt_items WHERE receipt_id = ? ORDER BY line_no');
        $stmt->execute([$receiptId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private function transition(int $receiptId, array $from, string $to, ?string $stamp, Actor $actor): void
    {
        $this->inventory->transactional(function () use ($receiptId, $from, $to, $stamp, $actor) {
            $this->lockReceipt($receiptId, $from);
            $this->setStatus($receiptId, $to, $stamp, $actor);
        });
    }

    private function setStatus(int $receiptId, string $status, ?string $stamp, Actor $actor): void
    {
        $sql = 'UPDATE goods_receipts SET status = ?' . ($stamp ? ", {$stamp}_at = NOW(), {$stamp}_by = ?" : '') . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($stamp ? [$status, $actor->id, $receiptId] : [$status, $receiptId]);
    }
}
