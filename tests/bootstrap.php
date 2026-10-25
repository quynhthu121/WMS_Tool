<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$dbName = getenv('DB_NAME') ?: '';
if (!str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "Từ chối chạy test trên database '{$dbName}'. Test chỉ chạy trên DB có hậu tố _test.\n");
    exit(1);
}
