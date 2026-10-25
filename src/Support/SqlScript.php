<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tách một file SQL thành từng câu lệnh để chạy qua PDO.
 * Hỗ trợ chỉ thị `DELIMITER` (dùng cho trigger), comment `--`/`#` và chuỗi trong nháy.
 */
final class SqlScript
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        $statements = [];
        $delimiter = ';';
        $buffer = '';

        foreach (preg_split('/\R/', $sql) as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
                $delimiter = $m[1];
                continue;
            }
            $buffer .= $line . "\n";
            while (($pos = self::findDelimiter($buffer, $delimiter)) !== null) {
                $stmt = self::stripComments(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + strlen($delimiter));
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
            }
        }
        $rest = self::stripComments($buffer);
        if ($rest !== '') {
            $statements[] = $rest;
        }
        return $statements;
    }

    private static function findDelimiter(string $buffer, string $delimiter): ?int
    {
        $len = strlen($buffer);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $buffer[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                continue;
            }
            if (($ch === '-' && substr($buffer, $i, 3) === '-- ') || $ch === '#') {
                $nl = strpos($buffer, "\n", $i);
                if ($nl === false) {
                    return null;
                }
                $i = $nl;
                continue;
            }
            if (substr($buffer, $i, strlen($delimiter)) === $delimiter) {
                return $i;
            }
        }
        return null;
    }

    private static function stripComments(string $stmt): string
    {
        return trim(preg_replace('/^\s*(--\s.*|--|#.*)$/m', '', $stmt));
    }
}
