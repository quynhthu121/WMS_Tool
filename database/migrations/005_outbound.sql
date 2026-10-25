-- 005 – Outbound (A3): đơn xuất, reservation FIFO, pick list, packing, shipping, outbox báo OMS.
-- down: DROP TABLE oms_outbox, shipments, pick_list_lines, pick_lists, reservations, outbound_order_lines, outbound_orders;

CREATE TABLE IF NOT EXISTS outbound_orders (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(50) NOT NULL,
    channel VARCHAR(100) NOT NULL,
    ship_address VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'CREATED',
    hold_reason VARCHAR(255) NULL COMMENT 'Cờ ngoại lệ (pick thiếu, backorder) – không tạo trạng thái mới',
    tracking_id VARCHAR(50) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME NULL,
    picking_at DATETIME NULL,
    picked_at DATETIME NULL,
    packing_at DATETIME NULL,
    packed_at DATETIME NULL,
    shipped_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    UNIQUE KEY uq_order_code (order_code),
    UNIQUE KEY uq_order_tracking (tracking_id),
    CONSTRAINT chk_order_status CHECK (status IN ('CREATED', 'RESERVED', 'PICKING', 'PICKED', 'PACKING', 'PACKED', 'SHIPPED', 'CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outbound_order_lines (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    line_no INT NOT NULL,
    product_sku VARCHAR(50) NOT NULL,
    requested_qty INT NOT NULL,
    reserved_qty INT NOT NULL DEFAULT 0 COMMENT 'Đang giữ (chưa ship), gồm cả phần đã pick',
    picked_qty INT NOT NULL DEFAULT 0,
    packed_qty INT NOT NULL DEFAULT 0,
    shipped_qty INT NOT NULL DEFAULT 0,
    backorder_qty INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_order_line_sku (order_id, product_sku),
    CONSTRAINT fk_order_line_order FOREIGN KEY (order_id) REFERENCES outbound_orders(id),
    CONSTRAINT fk_order_line_product FOREIGN KEY (product_sku) REFERENCES products(sku),
    CONSTRAINT chk_order_line_qty CHECK (requested_qty > 0 AND reserved_qty >= 0 AND picked_qty >= 0 AND packed_qty >= 0
        AND shipped_qty >= 0 AND backorder_qty >= 0 AND shipped_qty + backorder_qty <= requested_qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Một dòng = phần giữ hàng của một dòng đơn tại một location Storage (location gốc, FIFO).
CREATE TABLE IF NOT EXISTS reservations (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_line_id INT NOT NULL,
    product_sku VARCHAR(50) NOT NULL,
    location_id INT NOT NULL COMMENT 'Location Storage gốc',
    current_location_id INT NOT NULL COMMENT 'Nơi hàng của reservation đang nằm',
    qty INT NOT NULL COMMENT 'Số giữ ban đầu',
    picked_qty INT NOT NULL DEFAULT 0,
    released_qty INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    rule VARCHAR(20) NOT NULL DEFAULT 'FIFO',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_reservation_line (order_line_id),
    CONSTRAINT fk_reservation_line FOREIGN KEY (order_line_id) REFERENCES outbound_order_lines(id),
    CONSTRAINT fk_reservation_location FOREIGN KEY (location_id) REFERENCES warehouse_locations(id),
    CONSTRAINT fk_reservation_current FOREIGN KEY (current_location_id) REFERENCES warehouse_locations(id),
    CONSTRAINT chk_reservation_qty CHECK (qty > 0 AND picked_qty >= 0 AND released_qty >= 0 AND picked_qty + released_qty <= qty),
    CONSTRAINT chk_reservation_status CHECK (status IN ('active', 'picked', 'packed', 'shipped', 'released'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pick_lists (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pick_code VARCHAR(50) NOT NULL,
    order_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_pick_code (pick_code),
    CONSTRAINT fk_pick_list_order FOREIGN KEY (order_id) REFERENCES outbound_orders(id),
    CONSTRAINT chk_pick_list_status CHECK (status IN ('OPEN', 'DONE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pick_list_lines (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pick_list_id INT NOT NULL,
    reservation_id INT NOT NULL,
    product_sku VARCHAR(50) NOT NULL,
    location_id INT NOT NULL,
    qty INT NOT NULL,
    picked_qty INT NULL COMMENT 'Số thực lấy (NULL = chưa pick)',
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    picked_at DATETIME NULL,
    picked_by INT NULL,
    movement_id INT NULL,
    CONSTRAINT fk_pick_line_list FOREIGN KEY (pick_list_id) REFERENCES pick_lists(id),
    CONSTRAINT fk_pick_line_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id),
    CONSTRAINT fk_pick_line_location FOREIGN KEY (location_id) REFERENCES warehouse_locations(id),
    CONSTRAINT chk_pick_line_qty CHECK (qty > 0 AND (picked_qty IS NULL OR (picked_qty >= 0 AND picked_qty <= qty))),
    CONSTRAINT chk_pick_line_status CHECK (status IN ('OPEN', 'PICKED', 'SHORT'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipments (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    tracking_id VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'LABELED',
    labeled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    shipped_at DATETIME NULL,
    shipped_by INT NULL,
    UNIQUE KEY uq_shipment_order (order_id),
    UNIQUE KEY uq_shipment_tracking (tracking_id),
    CONSTRAINT fk_shipment_order FOREIGN KEY (order_id) REFERENCES outbound_orders(id),
    CONSTRAINT chk_shipment_status CHECK (status IN ('LABELED', 'SHIPPED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sự kiện gửi OMS (mô phỏng): chỉ ghi, không gọi dịch vụ ngoài.
CREATE TABLE IF NOT EXISTS oms_outbox (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    order_code VARCHAR(50) NOT NULL,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_outbox_order (order_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
