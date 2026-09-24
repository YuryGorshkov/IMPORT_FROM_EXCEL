<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Validation;

final class SourceStructureValidator
{
    public function differences(array $mapping, array $headerCells): array
    {
        $differences = [];
        foreach ($mapping as $rule) {
            $column = strtoupper(trim((string) ($rule['column'] ?? '')));
            $expected = trim((string) ($rule['label'] ?? ''));
            if ($column === '' || $expected === '') {
                continue;
            }

            $actual = trim((string) ($headerCells[$column] ?? ''));
            if ($this->comparable($actual) !== $this->comparable($expected)) {
                $differences[] = [
                    'column' => $column,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        }

        return $differences;
    }

    private function comparable(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return mb_strtolower($value, 'UTF-8');
    }
}
