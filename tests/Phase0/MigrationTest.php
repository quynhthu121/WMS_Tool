<?php

declare(strict_types=1);

namespace Tests\Phase0;

use App\Support\SqlScript;
use Tests\Support\DbTestCase;

final class MigrationTest extends DbTestCase
{
    public function testDangChayTrenDatabaseTest(): void
    {
        $this->assertSame('login_test', $this->pdo->query('SELECT DATABASE()')->fetchColumn());
    }

    public function testBaselineVaRoleDaApDung(): void
    {
        $versions = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('000_baseline', $versions);
        $this->assertContains('001_users_role', $versions);

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['users', 'products', 'partners', 'stock_picking', 'stock_move', 'inventory_audit',
                     'warehouses', 'warehouse_locations', 'inventory_balances', 'stock_movements',
                     'goods_receipts', 'goods_receipt_items'] as $t) {
            $this->assertContains($t, $tables, "Thiếu bảng {$t}");
        }
    }

    public function testCotRoleChiNhanGiaTriHopLe(): void
    {
        $col = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        $this->assertSame('operator', $col['Default']);

        $this->pdo->beginTransaction();
        try {
            $this->expectException(\PDOException::class);
            $this->pdo->exec("INSERT INTO users (user_name, user_password_hash, user_email, role) VALUES ('zz_role', 'x', 'zz@role.test', 'admin')");
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testTachSqlCoDelimiterVaChuoi(): void
    {
        $sql = "-- comment; bỏ qua\nSELECT 'a;b';\nDELIMITER //\nCREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET @a = 1; END//\nDELIMITER ;\nSELECT 2;";
        $parts = SqlScript::split($sql);
        $this->assertCount(3, $parts);
        $this->assertSame("SELECT 'a;b'", $parts[0]);
        $this->assertStringContainsString('SET @a = 1; END', $parts[1]);
    }
}
