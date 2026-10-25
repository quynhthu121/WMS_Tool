<?php
/**
 * Tiến trình con cho test song song: chờ tới mốc thời gian chung rồi reserve.
 * php reserve_worker.php <sku> <location> <qty> <start_at_microtime>
 * In ra "OK" hoặc "FAIL: <lý do>".
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Service\InventoryService;
use App\Support\Db;

[, $sku, $location, $qty, $startAt] = $argv;
$inventory = new InventoryService(Db::connect());
$inventory->location($location); // nạp cache trước khi chờ

while (microtime(true) < (float) $startAt) {
    usleep(500);
}
try {
    $inventory->reserve($sku, $location, (int) $qty, ['code' => 'TEST-CONCURRENT']);
    echo 'OK';
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage();
}
