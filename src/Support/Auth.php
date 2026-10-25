<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/** Người dùng hiện tại (đọc vai trò từ DB mỗi request để đổi vai trò có hiệu lực ngay). */
final class Auth
{
    private static ?Actor $actor = null;

    public static function actor(PDO $pdo): Actor
    {
        if (self::$actor !== null) {
            return self::$actor;
        }
        $id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $stmt = $pdo->prepare('SELECT user_id, user_name, role FROM users WHERE user_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(403);
            exit('Phiên đăng nhập không hợp lệ. Hãy đăng nhập lại.');
        }
        return self::$actor = new Actor((int) $row['user_id'], (string) $row['user_name'], (string) $row['role']);
    }

    /** Dừng nếu người dùng không phải Giám sát (dùng cho trang/thao tác chỉ dành cho Giám sát). */
    public static function requireSupervisor(PDO $pdo): Actor
    {
        $actor = self::actor($pdo);
        if (!$actor->isSupervisor()) {
            View::flash('error', 'Thao tác này cần vai trò Giám sát kho.');
            View::redirect(View::url('dashboard'));
        }
        return $actor;
    }
}
