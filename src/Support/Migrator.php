<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Chạy các file trong database/migrations theo thứ tự tên (NNN_ten.sql hoặc NNN_ten.php).
 * Mỗi migration chỉ chạy một lần; checksum được lưu để phát hiện file đã áp dụng bị sửa.
 * File .php trả về một callable(PDO $pdo, string $database, string $projectRoot): void.
 */
final class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $database,
        private string $projectRoot,
    ) {
    }

    /** @return list<array{version:string, applied_at:?string}> */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $rows = [];
        foreach ($this->files() as $version => $path) {
            $rows[] = ['version' => $version, 'applied_at' => $applied[$version]['applied_at'] ?? null];
        }
        return $rows;
    }

    public function migrate(callable $log): int
    {
        $this->ensureTable();
        $applied = $this->applied();
        $count = 0;
        foreach ($this->files() as $version => $path) {
            // Bỏ \r để checkout CRLF (Windows) và LF cho cùng checksum.
            $checksum = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($path)));
            if (isset($applied[$version])) {
                if ($applied[$version]['checksum'] !== $checksum) {
                    throw new RuntimeException("Migration {$version} đã áp dụng nhưng nội dung file đã bị sửa. Hãy tạo migration mới thay vì sửa file cũ.");
                }
                continue;
            }
            $log("Áp dụng {$version} ...");
            if (str_ends_with($path, '.php')) {
                $fn = require $path;
                $fn($this->pdo, $this->database, $this->projectRoot);
            } else {
                foreach (SqlScript::split((string) file_get_contents($path)) as $stmt) {
                    $this->pdo->exec($stmt);
                }
            }
            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
            $stmt->execute([$version, $checksum]);
            $count++;
        }
        return $count;
    }

    /**
     * Kiểm tra toàn vẹn sau migrate.
     * @return list<string> danh sách vấn đề (rỗng = OK)
     */
    public function verify(): array
    {
        $problems = [];
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'products', 'partners', 'warehouses', 'warehouse_locations', 'inventory_balances', 'stock_movements', 'goods_receipts', 'goods_receipt_items'] as $t) {
            if (!in_array($t, $tables, true)) {
                $problems[] = "Thiếu bảng {$t}";
            }
        }
        $applied = $this->applied();
        if (isset($applied['003_inventory_core'])) {
            $stmt = $this->pdo->prepare("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = 'stock_movements'");
            $stmt->execute([$this->database]);
            $triggers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach (['trg_stock_movements_no_update', 'trg_stock_movements_no_delete'] as $t) {
                if (!in_array($t, $triggers, true)) {
                    $problems[] = "Thiếu trigger {$t}";
                }
            }
            foreach (\App\Service\ReconciliationService::pairMismatches($this->pdo) as $m) {
                $problems[] = sprintf('Lệch sổ %s @ %s: balance %d/%d, ledger %d/%d', $m['product_sku'], $m['location_code'], $m['on_hand'], $m['reserved'], $m['ledger_on_hand'], $m['ledger_reserved']);
            }
        }
        return $problems;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return array<string, array{checksum:string, applied_at:string}> */
    private function applied(): array
    {
        $rows = $this->pdo->query('SELECT version, checksum, applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['version']] = $r;
        }
        return $out;
    }

    /** @return array<string, string> version => path */
    private function files(): array
    {
        $files = glob($this->projectRoot . '/database/migrations/[0-9][0-9][0-9]_*.{sql,php}', GLOB_BRACE) ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            $out[pathinfo($f, PATHINFO_FILENAME)] = $f;
        }
        return $out;
    }
}
