<?php

declare(strict_types=1);

namespace App\Support;

/** Người thực hiện thao tác (lấy từ session hoặc do kịch bản demo/CLI tạo). */
final class Actor
{
    public const SUPERVISOR = 'supervisor';
    public const OPERATOR = 'operator';

    public const ROLE_LABELS = [
        self::SUPERVISOR => 'Giám sát kho',
        self::OPERATOR => 'Nhân viên kho',
    ];

    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $role,
    ) {
    }

    public static function system(string $role = self::SUPERVISOR): self
    {
        return new self(null, 'Kịch bản demo', $role);
    }

    public function isSupervisor(): bool
    {
        return $this->role === self::SUPERVISOR;
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }
}
