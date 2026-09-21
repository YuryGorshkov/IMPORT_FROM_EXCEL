<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Reader;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final class ChunkReadFilter implements IReadFilter
{
    private int $startRow = 1;
    private int $endRow = 1;
    private int $headerRow = 1;

    public function configure(int $startRow, int $limit, int $headerRow = 1): void
    {
        $this->startRow = max(1, $startRow);
        $this->endRow = $this->startRow + max(1, $limit) - 1;
        $this->headerRow = max(1, $headerRow);
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row === $this->headerRow || ($row >= $this->startRow && $row <= $this->endRow);
    }
}
