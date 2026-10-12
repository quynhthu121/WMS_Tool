-- Tạo bảng quản lý Phiếu dịch chuyển kho (Chuẩn Odoo Stock Picking)
CREATE TABLE IF NOT EXISTS `stock_picking` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `picking_number` VARCHAR(50) NOT NULL UNIQUE, -- Mã phiếu: WH/IN/0001 hoặc WH/OUT/0001
  `origin` VARCHAR(100) DEFAULT NULL,            -- Chứng từ gốc (Ví dụ: PO-001, SO-002)
  `type` ENUM('in', 'out') NOT NULL,             -- 'in' là Nhập kho, 'out' là Xuất kho
  `scheduled_date` DATETIME DEFAULT CURRENT_TIMESTAMP, -- Ngày thực hiện phiếu
  `state` ENUM('draft', 'done') DEFAULT 'draft'  -- Trạng thái phiếu
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tạo bảng chi tiết dòng dịch chuyển vật chất (Stock Move Line)
CREATE TABLE IF NOT EXISTS `stock_move` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `picking_id` INT NOT NULL,                     -- Kết nối song song với bảng stock_picking
  `product_sku` VARCHAR(64) NOT NULL,            -- Kết nối với SKU của bảng sản phẩm
  `product_qty` INT NOT NULL,                    -- Số lượng dịch chuyển của dòng này
  FOREIGN KEY (`picking_id`) REFERENCES `stock_picking`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;