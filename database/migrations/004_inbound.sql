-- 004 – Inbound (A2): phiếu ASN, kiểm đếm, QC, xác nhận Receiving, task put-away.
--
-- goods_receipts.receipt_code là mã ASN (ví dụ ASN-DEMO-001).
-- goods_receipt_items:
--   expected_qty  = số trên ASN (dự kiến, không phải tồn – A2-R5)
--   counted_qty   = số kiểm đếm thực tế
--   qc_pass_qty   = số đạt QC (đổi tên từ accepted_qty), qc_fail_qty = số lỗi
--   received_qty  = số XÁC NHẬN NHẬP (thực nhận đạt, A2-R6) – số làm tăng tồn
--   putaway_qty   = số đã put-away vào Storage
--   over_qty      = phần nhận thừa chưa được duyệt nhập (E2)
--
-- down: DROP TABLE putaway_tasks, qc_records; ALTER TABLE goods_receipt_items CHANGE qc_pass_qty accepted_qty INT NOT NULL DEFAULT 0, DROP ...; ALTER TABLE goods_receipts DROP ...;

UPDATE goods_receipts SET status = 'EXPECTED' WHERE status = 'draft';

ALTER TABLE goods_receipts
    MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'EXPECTED',
    ADD COLUMN warehouse_id INT NULL AFTER partner_id,
    ADD COLUMN supplier_name VARCHAR(255) NULL AFTER warehouse_id,
    ADD COLUMN created_by INT NULL AFTER created_at,
    ADD COLUMN checked_in_at DATETIME NULL,
    ADD COLUMN checked_in_by INT NULL,
    ADD COLUMN counted_at DATETIME NULL,
    ADD COLUMN counted_by INT NULL,
    ADD COLUMN qc_at DATETIME NULL,
    ADD COLUMN qc_by INT NULL,
    ADD COLUMN received_by INT NULL COMMENT 'Người xác nhận Receiving (confirmed_at = thời điểm)',
    ADD COLUMN variance_approved_by INT NULL,
    ADD COLUMN variance_approved_at DATETIME NULL,
    ADD COLUMN over_receipt_approved_by INT NULL,
    ADD COLUMN over_receipt_approved_at DATETIME NULL,
    ADD COLUMN closed_at DATETIME NULL,
    ADD COLUMN closed_by INT NULL,
    ADD COLUMN close_note VARCHAR(255) NULL,
    ADD CONSTRAINT chk_receipt_status CHECK (status IN ('EXPECTED', 'CHECKED_IN', 'COUNTED', 'QC_DONE', 'RECEIVED', 'PUTAWAY_DONE', 'CLOSED', 'CANCELLED')),
    ADD CONSTRAINT fk_receipt_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id);

ALTER TABLE goods_receipt_items
    CHANGE COLUMN accepted_qty qc_pass_qty INT NOT NULL DEFAULT 0,
    ADD COLUMN line_no INT NOT NULL DEFAULT 1 AFTER receipt_id,
    ADD COLUMN target_location_id INT NULL AFTER product_sku,
    ADD COLUMN counted_qty INT NULL AFTER expected_qty,
    ADD COLUMN variance_reason VARCHAR(255) NULL AFTER counted_qty,
    ADD COLUMN qc_fail_qty INT NOT NULL DEFAULT 0 AFTER qc_pass_qty,
    ADD COLUMN putaway_qty INT NOT NULL DEFAULT 0 AFTER received_qty,
    ADD COLUMN over_qty INT NOT NULL DEFAULT 0 AFTER putaway_qty,
    ADD COLUMN over_status VARCHAR(20) NOT NULL DEFAULT 'NONE' AFTER over_qty,
    ADD COLUMN shortage_status VARCHAR(20) NOT NULL DEFAULT 'NONE' AFTER over_status,
    ADD COLUMN shortage_note VARCHAR(255) NULL AFTER shortage_status,
    ADD CONSTRAINT fk_receipt_item_location FOREIGN KEY (target_location_id) REFERENCES warehouse_locations(id),
    ADD CONSTRAINT chk_receipt_item_qty CHECK (
        expected_qty > 0 AND (counted_qty IS NULL OR counted_qty >= 0)
        AND qc_pass_qty >= 0 AND qc_fail_qty >= 0 AND received_qty >= 0 AND over_qty >= 0
        AND putaway_qty >= 0 AND putaway_qty <= received_qty),
    ADD CONSTRAINT chk_receipt_item_over CHECK (over_status IN ('NONE', 'PENDING', 'ACCEPTED', 'RETURNED')),
    ADD CONSTRAINT chk_receipt_item_shortage CHECK (shortage_status IN ('NONE', 'OPEN', 'WAITING', 'CLOSED'));

-- Phiếu QC cho hàng lỗi (E3): hàng lỗi không vào tồn, chờ trả nhà cung cấp.
CREATE TABLE IF NOT EXISTS qc_records (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    qc_code VARCHAR(50) NOT NULL,
    receipt_item_id INT NOT NULL,
    product_sku VARCHAR(50) NOT NULL,
    fail_qty INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    disposition VARCHAR(30) NOT NULL DEFAULT 'RETURN_TO_SUPPLIER',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qc_code (qc_code),
    CONSTRAINT fk_qc_item FOREIGN KEY (receipt_item_id) REFERENCES goods_receipt_items(id),
    CONSTRAINT chk_qc_fail_qty CHECK (fail_qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task put-away (A2 bước 6–9).
CREATE TABLE IF NOT EXISTS putaway_tasks (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_code VARCHAR(50) NOT NULL,
    receipt_item_id INT NOT NULL,
    product_sku VARCHAR(50) NOT NULL,
    qty INT NOT NULL,
    source_location_id INT NOT NULL,
    target_location_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    assigned_to INT NULL,
    movement_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    UNIQUE KEY uq_putaway_task_code (task_code),
    CONSTRAINT fk_putaway_item FOREIGN KEY (receipt_item_id) REFERENCES goods_receipt_items(id),
    CONSTRAINT fk_putaway_source FOREIGN KEY (source_location_id) REFERENCES warehouse_locations(id),
    CONSTRAINT fk_putaway_target FOREIGN KEY (target_location_id) REFERENCES warehouse_locations(id),
    CONSTRAINT chk_putaway_qty CHECK (qty > 0),
    CONSTRAINT chk_putaway_status CHECK (status IN ('OPEN', 'DONE', 'CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
