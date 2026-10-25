<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSRF token theo phiên. Mọi form POST chèn Csrf::field(); index.php gọi Csrf::verifyRequest()
 * trước khi nạp module.
 */
final class Csrf
{
    private const KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function isValid(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION[self::KEY]) && hash_equals($_SESSION[self::KEY], $token);
    }

    /** Dừng request POST không có token hợp lệ. */
    public static function verifyRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        if (!self::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            exit('Phiên làm việc đã hết hạn hoặc yêu cầu không hợp lệ (CSRF). Hãy tải lại trang và thử lại.');
        }
    }
}
