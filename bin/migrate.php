<?php
/**
 * Áp dụng migration cho database ứng dụng (mặc định DB_NAME) và database test (DB_TEST_NAME).
 *
 * Chạy bằng tài khoản root (DB_ROOT_PASS) vì cần CREATE TRIGGER khi binlog bật,
 * CREATE DATABASE và GRANT cho DB test. Biến root chỉ có trong service `migrate`.
 *
 * Cách dùng:
 *   php bin/migrate.php                 # migrate DB_NAME rồi DB_TEST_NAME
 *   php bin/migrate.php --db=login_test # chỉ migrate một DB
 *   php bin/migrate.php --status        # xem trạng thái, không áp dụng
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Migrator;

$options = getopt('', ['db:', 'status']);

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: '3306';
$rootUser = getenv('DB_ROOT_USER') ?: 'root';
$rootPass = (string) getenv('DB_ROOT_PASS');
$appUser = getenv('DB_USER') ?: 'app_user';
$appDb = getenv('DB_NAME') ?: 'login';
$testDb = getenv('DB_TEST_NAME') ?: 'login_test';

if ($rootPass === '') {
    fwrite(STDERR, "Thiếu DB_ROOT_PASS. Chạy qua: docker compose run --rm migrate\n");
    exit(1);
}

$databases = isset($options['db']) ? [(string) $options['db']] : [$appDb, $testDb];

try {
    $root = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $rootUser, $rootPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Không kết nối được MySQL bằng root: ' . $e->getMessage() . "\n");
    exit(1);
}

$exitCode = 0;
foreach ($databases as $db) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
        fwrite(STDERR, "Tên database không hợp lệ: {$db}\n");
        exit(1);
    }
    echo "== Database `{$db}`\n";
    $root->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $root->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO " . $root->quote($appUser) . "@'%'");
    $root->exec("USE `{$db}`");

    $migrator = new Migrator($root, $db, dirname(__DIR__));
    try {
        if (isset($options['status'])) {
            foreach ($migrator->status() as $row) {
                printf("  %-28s %s\n", $row['version'], $row['applied_at'] ?? 'CHƯA ÁP DỤNG');
            }
            continue;
        }
        $applied = $migrator->migrate(function (string $msg): void {
            echo "  {$msg}\n";
        });
        echo $applied === 0 ? "  Không có migration mới.\n" : "  Đã áp dụng {$applied} migration.\n";
        $problems = $migrator->verify();
        if ($problems) {
            $exitCode = 1;
            foreach ($problems as $p) {
                fwrite(STDERR, "  [KIỂM TRA LỖI] {$p}\n");
            }
        } else {
            echo "  Kiểm tra toàn vẹn: OK\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '  Migration thất bại: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
exit($exitCode);
