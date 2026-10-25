-- 003 – Lõi tồn kho: ràng buộc số lượng, ledger chỉ-INSERT.
--
-- inventory_balances: on_hand/reserved theo cặp (SKU, location); không bao giờ âm, reserved ≤ on_hand.
-- stock_movements: qty luôn > 0; hướng do from/to (NULL = ngoài kho).
--   on_hand(cặp)  = Σ qty vào − Σ qty ra, bỏ qua RESERVE/UNRESERVE.
--   reserved(cặp) = Σ reserved_moved vào − Σ reserved_moved ra (RESERVE: to = location; UNRESERVE: from = location;
--                   PICK/PACK/LOAD mang theo phần reserved; SHIP tiêu thụ reserved).
--   changes_on_hand = 1 với OPENING, RECEIVE, SHIP, ADJUST (đổi tổng tồn).
--
-- down:
--   DROP TRIGGER trg_stock_movements_no_update; DROP TRIGGER trg_stock_movements_no_delete;
--   ALTER TABLE stock_movements DROP CHECK ..., DROP COLUMN ...;  ALTER TABLE inventory_balances DROP CHECK chk_balance_qty, DROP COLUMN ...;

ALTER TABLE inventory_balances
    ADD COLUMN alloc_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = khóa phân bổ (đang kiểm kê do pick thiếu)' AFTER reserved,
    ADD COLUMN alloc_lock_reason VARCHAR(255) NULL AFTER alloc_locked,
    ADD COLUMN first_in_at DATETIME(6) NULL COMMENT 'FIFO mức cặp: gán khi on_hand đi từ 0 lên dương' AFTER alloc_lock_reason,
    ADD CONSTRAINT chk_balance_qty CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand);

ALTER TABLE stock_movements
    ADD COLUMN stock_status VARCHAR(30) NOT NULL DEFAULT 'available' AFTER qty,
    ADD COLUMN reserved_moved INT NOT NULL DEFAULT 0 COMMENT 'Phần reserved đi kèm dòng này' AFTER stock_status,
    ADD COLUMN changes_on_hand TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = dòng làm đổi tổng tồn' AFTER reserved_moved,
    ADD COLUMN reference_type VARCHAR(30) NULL AFTER reference_code,
    ADD COLUMN reference_id INT NULL AFTER reference_type,
    ADD COLUMN group_code VARCHAR(50) NULL COMMENT 'Gom dòng hiển thị (LOAD+SHIP = Shipping)' AFTER reference_id,
    ADD COLUMN user_id INT NULL AFTER group_code,
    ADD COLUMN note VARCHAR(255) NULL AFTER user_id,
    MODIFY COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ADD KEY idx_movement_type (movement_type),
    ADD KEY idx_movement_ref (reference_type, reference_id),
    ADD CONSTRAINT chk_movement_qty CHECK (qty > 0),
    ADD CONSTRAINT chk_movement_reserved CHECK (reserved_moved >= 0 AND reserved_moved <= qty),
    ADD CONSTRAINT chk_movement_type CHECK (movement_type IN
        ('OPENING', 'RECEIVE', 'PUTAWAY', 'RESERVE', 'UNRESERVE', 'PICK', 'PACK_IN', 'PACK_OUT', 'LOAD', 'SHIP', 'ADJUST', 'RESTOCK'));
-- Ràng buộc "from hoặc to khác NULL" kiểm ở InventoryService: MySQL không cho CHECK trên cột FK có ON UPDATE CASCADE.

DELIMITER //
CREATE TRIGGER trg_stock_movements_no_update BEFORE UPDATE ON stock_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements chỉ cho phép INSERT. Sửa sai bằng bút toán đảo.';
END//

CREATE TRIGGER trg_stock_movements_no_delete BEFORE DELETE ON stock_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements chỉ cho phép INSERT. Không được xóa lịch sử.';
END//
DELIMITER ;

-- Trạng thái Bảng điều khiển Demo (preset hiện tại, tiến độ stepper).
CREATE TABLE IF NOT EXISTS demo_state (
    state_key VARCHAR(50) NOT NULL PRIMARY KEY,
    state_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
