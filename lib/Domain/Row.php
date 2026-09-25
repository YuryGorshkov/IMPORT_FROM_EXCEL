<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Domain;

final class Row
{
    public function __construct(
        public readonly int $number,
        public readonly array $cells,
        public readonly array $links = [],
    ) {
    }

    public function cell(string $column): mixed
    {
        return $this->cells[strtoupper($column)] ?? null;
    }

    public function link(string $column): ?string
    {
        $link = trim((string) ($this->links[strtoupper($column)] ?? ''));

        return preg_match('#^https?://#i', $link) === 1 ? $link : null;
    }
}
