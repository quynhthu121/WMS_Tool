<?php
/**
 * 000 – Baseline.
 *
 * DB đã được docker-entrypoint-initdb.d khởi tạo (đủ 12 bảng) ⇒ chỉ ghi nhận, không chạy lại.
 * DB mới (ví dụ login_test) ⇒ chạy đúng 3 script mà Compose dùng, theo cùng thứ tự.
 */

use App\Support\SqlScript;

return function (PDO $pdo, string $database, string $root): void {
    $expected = ['users', 'products', 'stock_picking', 'stock_move', 'partners', 'inventory_audit',
        'warehouses', 'warehouse_locations', 'inventory_balances', 'stock_movements', 'goods_receipts', 'goods_receipt_items'];
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (!array_diff($expected, $existing)) {
        return;
    }

    $scripts = [
        '_installation/02-create-and-fill-users-table.sql',
        '_installation/04-create-partners-and-inventory-audit.sql',
        'database/schema.sql',
    ];
    foreach ($scripts as $file) {
        $sql = (string) file_get_contents($root . '/' . $file);
        // Script gốc gắn cứng database `login`; chạy trên database đang migrate.
        $sql = preg_replace('/^\s*USE\s+`?login`?\s*;\s*$/mi', '', $sql);
        $sql = str_replace('`login`.', '', $sql);
        foreach (SqlScript::split($sql) as $stmt) {
            $pdo->exec($stmt);
        }
    }
};
