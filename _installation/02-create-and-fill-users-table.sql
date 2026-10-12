SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `login`.`users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'auto incrementing user_id of each user, unique index',
  `user_name` varchar(64) COLLATE utf8_unicode_ci NOT NULL COMMENT 'user''s name, unique',
  `user_password_hash` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'user''s password in salted and hashed format',
  `user_email` varchar(64) COLLATE utf8_unicode_ci NOT NULL COMMENT 'user''s email, unique',
  `user_phone` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'user''s phone number, optional',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_name` (`user_name`),
  UNIQUE KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='user data';


-- 1. Tạo bảng danh mục sản phẩm và quản lý tồn kho tổng
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sku` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `qty` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tạo bảng quản lý phiếu điều chuyển (Receipts / Delivery Orders)
CREATE TABLE IF NOT EXISTS `stock_picking` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `picking_number` VARCHAR(50) NOT NULL UNIQUE,
  `origin` VARCHAR(100) NULL,
  `type` ENUM('in', 'out') NOT NULL,
  `state` ENUM('draft', 'confirmed', 'done') NOT NULL DEFAULT 'draft',
  `scheduled_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tạo bảng chi tiết dịch chuyển kho (Stock Move Lines) - Liên kết khóa ngoại chặt chẽ
CREATE TABLE IF NOT EXISTS `stock_move` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `picking_id` INT NOT NULL,
  `product_sku` VARCHAR(50) NOT NULL,
  `product_qty` INT NOT NULL,
  FOREIGN KEY (`picking_id`) REFERENCES `stock_picking`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_sku`) REFERENCES `products`(`sku`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bơm dữ liệu sản phẩm mẫu để hệ thống có sẵn vật chất luân chuyển
INSERT IGNORE INTO `products` (`sku`, `name`, `description`, `price`, `qty`) VALUES
('PROD-CPU-I9', 'Bộ xử lý Intel Core i9 14900K', 'CPU Intel Core i9 thế hệ 14', 18000000, 50),
('PROD-RAM-32', 'Thanh RAM DDR5 Corsair 32GB', 'RAM Corsair 32GB DDR5 hiệu suất cao', 3200000, 120),
('PROD-SSD-01', 'Ổ cứng SSD Samsung 990 Pro 1TB', 'SSD Samsung 990 Pro 1TB tốc độ cao', 4200000, 85);
