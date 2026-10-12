-- Chọn Database đang sử dụng
USE `login`;

-- =====================================================================
-- 1. BẢNG PARTNERS (QUẢN LÝ ĐỐI TÁC: KHÁCH HÀNG / NHÀ CUNG CẤP)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `partners` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã định danh duy nhất (VD: KH001)',
  `name` VARCHAR(255) NOT NULL COMMENT 'Tên công ty hoặc đối tác',
  `type` ENUM('customer', 'vendor') NOT NULL COMMENT 'Phân loại: Khách hàng / Nhà cung cấp',
  `phone` VARCHAR(20) DEFAULT NULL COMMENT 'Số điện thoại liên hệ',
  `email` VARCHAR(100) DEFAULT NULL COMMENT 'Email (Dự phòng cho tương lai)',
  `address` TEXT DEFAULT NULL COMMENT 'Địa chỉ (Dự phòng cho tương lai)',
  `tax_code` VARCHAR(20) DEFAULT NULL COMMENT 'Mã số thuế (Dự phòng cho tương lai)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2. BẢNG INVENTORY_AUDIT (QUẢN LÝ KIỂM KÊ VÀ LỆCH KHO)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `inventory_audit` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `audit_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã phiếu kiểm kê sinh tự động',
  `product_sku` VARCHAR(50) NOT NULL COMMENT 'Mã SKU của sản phẩm được đếm',
  `system_qty` INT NOT NULL COMMENT 'Số lượng tồn trên phần mềm lúc kiểm kê',
  `counted_qty` INT NOT NULL COMMENT 'Số lượng nhân viên đếm tay thực tế',
  `difference` INT NOT NULL COMMENT 'Độ lệch (+ là thừa, - là thiếu, 0 là khớp)',
  `note` VARCHAR(255) DEFAULT NULL COMMENT 'Lý do / Giải trình (Chuột cắn, vỡ, đếm sai...)',
  `audit_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời gian thực hiện kiểm kê',
  
  -- Khóa ngoại: Ràng buộc chặt chẽ với bảng products
  FOREIGN KEY (`product_sku`) REFERENCES `products`(`sku`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;