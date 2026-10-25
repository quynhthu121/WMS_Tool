<?php

declare(strict_types=1);

namespace Tests\Support;

/** Điểm nối tới các kịch bản dùng chung (bổ sung dần theo phase). */
final class Scenarios
{
    public static function afterInboundRunner(): ?callable
    {
        return class_exists(\App\Service\ScenarioService::class)
            ? [\App\Service\ScenarioService::class, 'runInboundHappyPath']
            : null;
    }
}
