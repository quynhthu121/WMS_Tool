<?php
/**
 * Tạo tài khoản demo và (tùy chọn) nạp preset.
 *
 *   php bin/seed_demo.php                 # tạo/cập nhật giamsat_demo (supervisor), nhanvien_demo (operator)
 *   php bin/seed_demo.php --preset=main   # thêm: reset dữ liệu demo về preset (cần DEMO_RESET_ENABLED=1)
 *
 * Mật khẩu lấy từ DEMO_USER_PASSWORD; không bao giờ ghi mật khẩu vào repo.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\DemoService;
use App\Service\ScenarioService;
use App\Support\Db;

$options = getopt('', ['preset:']);
$pdo = Db::connect();

$password = (string) getenv('DEMO_USER_PASSWORD');
if (strlen($password) < 6) {
    fwrite(STDERR, "Đặt DEMO_USER_PASSWORD (tối thiểu 6 ký tự) trong .env rồi chạy lại.\n");
    exit(1);
}

$users = [
    ['giamsat_demo', 'giamsat_demo@example.invalid', 'supervisor'],
    ['nhanvien_demo', 'nhanvien_demo@example.invalid', 'operator'],
];
$stmt = $pdo->prepare('INSERT INTO users (user_name, user_password_hash, user_email, role) VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE user_password_hash = VALUES(user_password_hash), role = VALUES(role)');
foreach ($users as [$name, $email, $role]) {
    $stmt->execute([$name, password_hash($password, PASSWORD_DEFAULT), $email, $role]);
    echo "Tài khoản {$name} ({$role}) đã sẵn sàng.\n";
}

if (isset($options['preset'])) {
    $demo = new DemoService($pdo, dirname(__DIR__), [ScenarioService::class, 'runInboundHappyPath']);
    $demo->reset((string) $options['preset']);
    echo "Đã reset dữ liệu demo về preset {$options['preset']}.\n";
}
