<?php

declare(strict_types=1);

namespace App\Support;

/** Helper hiển thị dùng trong modules/*.php. */
final class View
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $page, array $params = []): string
    {
        return 'index.php?' . http_build_query(['page' => $page] + $params);
    }

    /** Chuyển hướng sau POST (PRG). index.php bật output buffering nên gọi được giữa trang. */
    public static function redirect(string $url): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $url);
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string, message:string}> */
    public static function takeFlash(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }

    /**
     * Chạy một hành động POST: thành công ⇒ flash + redirect; vi phạm nghiệp vụ ⇒ flash lỗi + redirect.
     * Hành động trả về chuỗi thông báo, hoặc [thông báo, url] để chuyển tới trang khác.
     * Lỗi hệ thống không lộ stack trace.
     */
    public static function handle(callable $action, string $redirect): never
    {
        try {
            $message = $action();
            if (is_array($message)) {
                [$message, $redirect] = $message;
            }
            if (is_string($message) && $message !== '') {
                self::flash('success', $message);
            }
        } catch (\App\Service\BusinessRuleException $e) {
            self::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            self::flash('error', 'Lỗi hệ thống, thao tác đã được hủy toàn bộ (rollback). Xem log máy chủ để biết chi tiết.');
        }
        self::redirect($redirect);
    }

    public static function badge(string $text, string $tone = 'neutral'): string
    {
        return '<span class="badge badge-' . self::e($tone) . '">' . self::e($text) . '</span>';
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'CLOSED', 'SHIPPED', 'ADJUSTED', 'CLOSED_MATCH', 'DONE', 'PUTAWAY_DONE' => 'ok',
            'CANCELLED' => 'muted',
            'PENDING_APPROVAL', 'RECOUNT', 'SHORT' => 'warn',
            default => 'info',
        };
    }

    public static function n(int|string|null $value): string
    {
        return number_format((int) $value, 0, ',', '.');
    }

    /** Dấu ✓/✗ cho trang đối soát, stepper, bằng chứng. */
    public static function mark(?bool $ok): string
    {
        if ($ok === null) {
            return '<span class="mark mark-none" title="Chưa có dữ liệu">–</span>';
        }
        return $ok ? '<span class="mark mark-ok" title="Khớp">✓</span>' : '<span class="mark mark-fail" title="Không khớp">✗</span>';
    }
}
