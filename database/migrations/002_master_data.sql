-- 002 – Dữ liệu chủ cho demo: thuộc tính location và sản phẩm.
-- down:
--   ALTER TABLE warehouse_locations DROP CHECK chk_location_zone_type, DROP COLUMN name, DROP COLUMN is_allocatable, DROP COLUMN pick_sequence;
--   ALTER TABLE products DROP FOREIGN KEY fk_products_putaway_location, DROP COLUMN size, DROP COLUMN color, DROP COLUMN uom, DROP COLUMN putaway_location_id, DROP COLUMN is_legacy;

ALTER TABLE warehouse_locations
    ADD COLUMN name VARCHAR(100) NULL AFTER code,
    ADD COLUMN is_allocatable TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = tồn tại đây được phân bổ cho đơn (chỉ Storage)' AFTER zone_type,
    ADD COLUMN pick_sequence INT NOT NULL DEFAULT 0 AFTER is_allocatable,
    ADD CONSTRAINT chk_location_zone_type CHECK (zone_type IN ('storage', 'staging', 'dock')),
    ADD CONSTRAINT chk_location_allocatable CHECK (is_allocatable = 0 OR zone_type = 'storage');

ALTER TABLE products
    ADD COLUMN size VARCHAR(20) NULL AFTER name,
    ADD COLUMN color VARCHAR(50) NULL AFTER size,
    ADD COLUMN uom VARCHAR(20) NOT NULL DEFAULT 'Cái' AFTER color,
    ADD COLUMN putaway_location_id INT NULL COMMENT 'Location gợi ý khi put-away' AFTER uom,
    ADD COLUMN is_legacy TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = dữ liệu mẫu kế thừa, ngoài kịch bản demo' AFTER putaway_location_id,
    ADD CONSTRAINT fk_products_putaway_location FOREIGN KEY (putaway_location_id) REFERENCES warehouse_locations(id) ON UPDATE CASCADE ON DELETE SET NULL;

-- Sản phẩm điện tử mẫu của source nền không thuộc kịch bản A1.
UPDATE products SET is_legacy = 1 WHERE sku IN ('PROD-CPU-I9', 'PROD-RAM-32', 'PROD-SSD-01');
