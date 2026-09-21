<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Domain;

final class Row
{
    public function __construct(
        public readonly int $number,
        public readonly array $cells,
    ) {
    }

    public function cell(string $column): mixed
    {
        return $this->cells[strtoupper($column)] ?? null;
    }
}
