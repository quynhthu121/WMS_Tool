-- 006 – Cycle count (A4 §3): phiếu kiểm kê theo cặp (SKU + Location Storage).
-- Bảng kế thừa inventory_audit giữ nguyên, chỉ đọc (không còn module nào ghi).
--
-- Trạng thái: OPEN (chờ đếm) → CLOSED_MATCH (khớp) | RECOUNT (lệch, chờ đếm lại)
--             RECOUNT → CLOSED_MATCH | PENDING_APPROVAL → ADJUSTED (Giám sát duyệt, đã tạo ADJUST)
-- down: DROP TABLE cycle_counts;

CREATE TABLE IF NOT EXISTS cycle_counts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    count_code VARCHAR(50) NOT NULL,
    product_sku VARCHAR(50) NOT NULL,
    location_id INT NOT NULL,
    trigger_type VARCHAR(20) NOT NULL DEFAULT 'CYCLE',
    source_order_id INT NULL COMMENT 'Đơn phát sinh pick thiếu (PICK_SHORTAGE)',
    system_qty INT NOT NULL COMMENT 'Tồn hệ thống chốt lúc tạo phiếu (C1)',
    counted_qty INT NULL,
    recount_qty INT NULL,
    difference INT NULL COMMENT 'Thực tế cuối − hệ thống',
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    reason VARCHAR(255) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    counted_by INT NULL,
    counted_at DATETIME NULL,
    recounted_by INT NULL,
    recounted_at DATETIME NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    released_reserved_qty INT NOT NULL DEFAULT 0,
    adjust_movement_id INT NULL,
    closed_at DATETIME NULL,
    UNIQUE KEY uq_count_code (count_code),
    KEY idx_count_pair (product_sku, location_id),
    CONSTRAINT fk_count_product FOREIGN KEY (product_sku) REFERENCES products(sku),
    CONSTRAINT fk_count_location FOREIGN KEY (location_id) REFERENCES warehouse_locations(id),
    CONSTRAINT fk_count_order FOREIGN KEY (source_order_id) REFERENCES outbound_orders(id),
    CONSTRAINT chk_count_qty CHECK (system_qty >= 0 AND (counted_qty IS NULL OR counted_qty >= 0) AND (recount_qty IS NULL OR recount_qty >= 0)),
    CONSTRAINT chk_count_trigger CHECK (trigger_type IN ('CYCLE', 'PICK_SHORTAGE')),
    CONSTRAINT chk_count_status CHECK (status IN ('OPEN', 'RECOUNT', 'PENDING_APPROVAL', 'ADJUSTED', 'CLOSED_MATCH'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
