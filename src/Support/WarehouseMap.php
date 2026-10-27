<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sơ đồ kho trực quan: 7 location xếp theo luồng hàng
 * (ngoài kho) → RECEIVING → A-01 / B-01 → PICK-STG → PACK-01 → OUT-STG → DOCK → (ra khỏi kho).
 * Màu ô theo trạng thái; khi có trạng thái trước đó, ô thay đổi được viền nổi và hiện chênh lệch.
 */
final class WarehouseMap
{
    /** Thứ tự cột theo luồng; A-01 và B-01 xếp chồng trong cùng một cột. */
    private const COLUMNS = [['RECEIVING'], ['A-01', 'B-01'], ['PICK-STG'], ['PACK-01'], ['OUT-STG'], ['DOCK']];

    /**
     * @param array<string, array<string, mixed>> $snapshot InventoryQuery::locationSnapshot()
     * @param array<string, array{on_hand:int, reserved:int, locked:bool}>|null $previous trạng thái trước bước vừa chạy
     */
    public static function render(array $snapshot, ?array $previous = null): string
    {
        $in = $out = 0;
        if ($previous !== null) {
            $totalDelta = array_sum(array_column($snapshot, 'on_hand')) - array_sum(array_column($previous, 'on_hand'));
            $receivingDelta = ($snapshot['RECEIVING']['on_hand'] ?? 0) - ($previous['RECEIVING']['on_hand'] ?? 0);
            $outboundDrop = ($previous['OUT-STG']['on_hand'] ?? 0) + ($previous['DOCK']['on_hand'] ?? 0)
                - ($snapshot['OUT-STG']['on_hand'] ?? 0) - ($snapshot['DOCK']['on_hand'] ?? 0);
            $in = $totalDelta > 0 && $receivingDelta > 0 ? $receivingDelta : 0;
            $out = $totalDelta < 0 && $outboundDrop > 0 ? min(-$totalDelta, $outboundDrop) : 0;
        }

        $html = '<div class="wmap" role="group" aria-label="Sơ đồ kho theo luồng hàng">';
        $html .= '<div class="wmap-flow">';
        $html .= self::ghost('Nhà cung cấp', '(ngoài kho)', $in > 0 ? '+' . View::n($in) . ' nhập' : null, 'in');
        foreach (self::COLUMNS as $codes) {
            $html .= '<div class="wmap-arrow" aria-hidden="true">→</div>';
            $html .= '<div class="wmap-col">';
            foreach ($codes as $code) {
                if (isset($snapshot[$code])) {
                    $html .= self::tile($snapshot[$code], $previous[$code] ?? null);
                }
            }
            $html .= '</div>';
        }
        $html .= '<div class="wmap-arrow" aria-hidden="true">→</div>';
        $html .= self::ghost('Khách hàng', '(ra khỏi kho)', $out > 0 ? '−' . View::n($out) . ' xuất' : null, 'out');
        $html .= '</div>';
        $html .= '<div class="wmap-legend">'
            . '<span><i class="sw sw-store"></i>Kệ lưu trữ có hàng – bán được</span>'
            . '<span><i class="sw sw-stage"></i>Khu nhận / khu chờ có hàng – chưa bán được</span>'
            . '<span><i class="sw sw-locked"></i>Bị khóa phân bổ (đang kiểm kê)</span>'
            . '<span><span class="wmap-pill">giữ N</span> đang giữ cho đơn</span>'
            . ($previous !== null ? '<span><i class="sw sw-changed"></i>Ô vừa thay đổi ở bước trước</span>' : '')
            . '</div></div>';
        return $html;
    }

    private static function tile(array $loc, ?array $prev): string
    {
        $onHand = (int) $loc['on_hand'];
        $reserved = (int) $loc['reserved'];
        $storage = $loc['zone_type'] === 'storage';
        $classes = ['wmap-tile', $storage ? 'is-storage' : 'is-staging'];
        if ($onHand > 0) {
            $classes[] = 'has-stock';
        }
        if ($loc['locked']) {
            $classes[] = 'is-locked';
        }
        $delta = null;
        if ($prev !== null && ($prev['on_hand'] !== $onHand || $prev['reserved'] !== $reserved || $prev['locked'] !== $loc['locked'])) {
            $classes[] = 'is-changed';
            $d = $onHand - $prev['on_hand'];
            if ($d !== 0) {
                $delta = '<span class="wmap-delta ' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '−') . View::n(abs($d)) . '</span>';
            }
        }

        $skuLines = '';
        $title = [];
        foreach ($loc['skus'] as $sku => $qty) {
            $title[] = "{$sku}: {$qty}";
        }
        if ($loc['skus'] && count($loc['skus']) <= 2) {
            foreach ($loc['skus'] as $sku => $qty) {
                $skuLines .= '<div class="wmap-sku">' . View::e($sku) . ' · ' . View::n($qty) . '</div>';
            }
        } elseif ($loc['skus']) {
            $skuLines = '<div class="wmap-sku">' . count($loc['skus']) . ' SKU</div>';
        }

        $badges = '';
        if ($storage) {
            $badges .= '<span class="wmap-avail">bán được ' . View::n($onHand - $reserved) . '</span>';
        }
        if ($reserved > 0) {
            $badges .= '<span class="wmap-pill">giữ ' . View::n($reserved) . '</span>';
        }
        if ($loc['locked']) {
            $badges .= '<span class="wmap-lock">khóa</span>';
        }

        return '<div class="' . implode(' ', $classes) . '" title="' . View::e($title ? implode("\n", $title) : 'Trống') . '">'
            . '<div class="wmap-head"><span class="wmap-code">' . View::e($loc['code']) . '</span>' . ($delta ?? '') . '</div>'
            . '<div class="wmap-qty">' . View::n($onHand) . '</div>'
            . '<div class="wmap-name">' . View::e($loc['name']) . '</div>'
            . $skuLines
            . ($badges !== '' ? '<div class="wmap-badges">' . $badges . '</div>' : '')
            . '</div>';
    }

    private static function ghost(string $name, string $sub, ?string $flow, string $kind): string
    {
        return '<div class="wmap-ghost ' . ($flow !== null ? 'is-active ' : '') . 'ghost-' . $kind . '">'
            . '<div class="wmap-name">' . View::e($name) . '</div>'
            . '<div class="wmap-sku">' . View::e($sub) . '</div>'
            . ($flow !== null ? '<div class="wmap-flowqty">' . View::e($flow) . '</div>' : '')
            . '</div>';
    }
}
