-- =====================================================
-- WMS_Tool - Sprint 1
-- Database Core Schema
-- MariaDB 10.4.32
-- =====================================================

CREATE TABLE IF NOT EXISTS warehouses (
id INT NOT NULL AUTO_INCREMENT,
code VARCHAR(50) NOT NULL,
name VARCHAR(255) NOT NULL,
active TINYINT(1) NOT NULL DEFAULT 1,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

```
PRIMARY KEY (id),
UNIQUE KEY uq_warehouses_code (code)
```

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_locations (
id INT NOT NULL AUTO_INCREMENT,
warehouse_id INT NOT NULL,
code VARCHAR(50) NOT NULL,
zone_type VARCHAR(30) NOT NULL DEFAULT 'storage',
active TINYINT(1) NOT NULL DEFAULT 1,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

```
PRIMARY KEY (id),
UNIQUE KEY uq_location_code (warehouse_id, code),
KEY idx_location_warehouse (warehouse_id),

CONSTRAINT fk_location_warehouse
    FOREIGN KEY (warehouse_id)
    REFERENCES warehouses(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
```

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_balances (
id INT NOT NULL AUTO_INCREMENT,
product_sku VARCHAR(50) NOT NULL,
location_id INT NOT NULL,
stock_status VARCHAR(30) NOT NULL DEFAULT 'available',
on_hand INT NOT NULL DEFAULT 0,
reserved INT NOT NULL DEFAULT 0,
updated_at TIMESTAMP NOT NULL
DEFAULT CURRENT_TIMESTAMP
ON UPDATE CURRENT_TIMESTAMP,

```
PRIMARY KEY (id),
UNIQUE KEY uq_inventory_balance (
    product_sku,
    location_id,
    stock_status
),

CONSTRAINT fk_inventory_product
    FOREIGN KEY (product_sku)
    REFERENCES products(sku)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

CONSTRAINT fk_inventory_location
    FOREIGN KEY (location_id)
    REFERENCES warehouse_locations(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
```

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
id INT NOT NULL AUTO_INCREMENT,
movement_type VARCHAR(30) NOT NULL,
product_sku VARCHAR(50) NOT NULL,
qty INT NOT NULL,
from_location_id INT NULL,
to_location_id INT NULL,
reference_code VARCHAR(100) NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

```
PRIMARY KEY (id),
KEY idx_movement_product_date (product_sku, created_at),
KEY idx_movement_reference (reference_code),

CONSTRAINT fk_movement_product
    FOREIGN KEY (product_sku)
    REFERENCES products(sku)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

CONSTRAINT fk_movement_from_location
    FOREIGN KEY (from_location_id)
    REFERENCES warehouse_locations(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

CONSTRAINT fk_movement_to_location
    FOREIGN KEY (to_location_id)
    REFERENCES warehouse_locations(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
```

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_receipts (
id INT NOT NULL AUTO_INCREMENT,
receipt_code VARCHAR(50) NOT NULL,
partner_id INT NULL,
status VARCHAR(30) NOT NULL DEFAULT 'draft',
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
confirmed_at TIMESTAMP NULL DEFAULT NULL,

```
PRIMARY KEY (id),
UNIQUE KEY uq_receipt_code (receipt_code),
KEY idx_receipt_partner (partner_id),

CONSTRAINT fk_receipt_partner
    FOREIGN KEY (partner_id)
    REFERENCES partners(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
```

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_receipt_items (
id INT NOT NULL AUTO_INCREMENT,
receipt_id INT NOT NULL,
product_sku VARCHAR(50) NOT NULL,
expected_qty INT NOT NULL DEFAULT 0,
received_qty INT NOT NULL DEFAULT 0,
accepted_qty INT NOT NULL DEFAULT 0,

```
PRIMARY KEY (id),
UNIQUE KEY uq_receipt_product (receipt_id, product_sku),

CONSTRAINT fk_receipt_item_receipt
    FOREIGN KEY (receipt_id)
    REFERENCES goods_receipts(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,

CONSTRAINT fk_receipt_item_product
    FOREIGN KEY (product_sku)
    REFERENCES products(sku)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
```

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
