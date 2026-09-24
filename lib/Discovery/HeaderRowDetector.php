<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Discovery;

final class HeaderRowDetector
{
    public function detect(array $rows, int $fallback = 1): int
    {
        $bestRow = max(1, $fallback);
        $bestScore = PHP_INT_MIN;

        foreach ($rows as $row) {
            $values = array_values(array_filter(
                (array) ($row['cells'] ?? []),
                static fn(mixed $value): bool => $value !== null && trim((string) $value) !== ''
            ));
            if ($values === []) {
                continue;
            }

            $textCount = count(array_filter(
                $values,
                static fn(mixed $value): bool => !is_numeric(trim((string) $value))
            ));
            $numericCount = count($values) - $textCount;
            $uniqueCount = count(array_unique(array_map(
                static fn(mixed $value): string => mb_strtolower(trim((string) $value)),
                $values
            )));
            $score = count($values) * 3 + $textCount * 2 + $uniqueCount - $numericCount;
            if (count($values) === 1) {
                $score -= 4;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = max(1, (int) ($row['row'] ?? $fallback));
            }
        }

        return $bestRow;
    }
}
