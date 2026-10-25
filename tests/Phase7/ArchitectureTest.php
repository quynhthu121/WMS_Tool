<?php

declare(strict_types=1);

namespace Tests\Phase7;

use Tests\Support\DbTestCase;

/** Tiêu chí nghiệm thu 4: chỉ InventoryService ghi tồn; products.qty đã bị loại bỏ. */
final class ArchitectureTest extends DbTestCase
{
    public function testChiInventoryServiceGhiTon(): void
    {
        $root = dirname(__DIR__, 2);
        exec('sh ' . escapeshellarg($root . '/bin/check_writes.sh') . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function testCotProductsQtyDaBiXoa(): void
    {
        $this->assertFalse($this->pdo->query("SHOW COLUMNS FROM products LIKE 'qty'")->fetch());
    }

    public function testKhongNoiNaoAmChiSeedcomSuDungCongCu(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(glob("{$root}/*.php"), glob("{$root}/modules/*.php"), glob("{$root}/views/*.php"), glob("{$root}/docs/*.md"), ["{$root}/README.md"]);
        foreach ($files as $file) {
            if (str_contains($file, 'CLAUDE_CODE_PROMPT')) {
                continue; // prompt gốc nêu "Seedcom WMS" như ví dụ bị cấm
            }
            $text = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/Seedcom\s+WMS|hệ thống (thật )?của Seedcom|Seedcom (đang )?sử dụng (công cụ|WMS Tool)/iu', $text, basename($file));
        }
    }
}
