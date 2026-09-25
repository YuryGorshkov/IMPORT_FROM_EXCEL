<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Import;

final class SheetSelector
{
    public function select(array $availableSheets, string $requested = '', string $preferred = ''): string
    {
        $availableSheets = array_values(array_filter(
            array_map(static fn(mixed $sheet): string => trim((string) $sheet), $availableSheets),
            static fn(string $sheet): bool => $sheet !== ''
        ));

        if ($requested !== '' && in_array($requested, $availableSheets, true)) {
            return $requested;
        }
        if ($preferred !== '' && in_array($preferred, $availableSheets, true)) {
            return $preferred;
        }

        return count($availableSheets) === 1 ? $availableSheets[0] : '';
    }
}
