<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Reader;

use WebEnot\ImportExcel\Domain\Row;

interface ReaderInterface
{
    /** @return iterable<Row> */
    public function read(string $path, ?string $sheet, int $startRow, int $limit, array $options = []): iterable;

    public function totalRows(string $path, ?string $sheet = null): int;

    public function preview(string $path, ?string $sheet = null, int $limit = 20, array $options = []): array;

    /** @return list<string> */
    public function sheets(string $path): array;
}
