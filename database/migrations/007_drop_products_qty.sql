-- 007 – Bỏ cột products.qty (tổng tồn cũ, không có vị trí, không có lịch sử).
-- Nguồn sự thật duy nhất là inventory_balances (giải thích bằng stock_movements).
-- Đã kiểm tra: không còn mã nào đọc/ghi products.qty (bin/check_writes.sh, tests/Phase7/ArchitectureTest).
-- down: ALTER TABLE products ADD COLUMN qty INT NOT NULL DEFAULT 0 AFTER price;

ALTER TABLE products DROP COLUMN qty;
