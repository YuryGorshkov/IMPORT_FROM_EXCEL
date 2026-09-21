<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Domain;

final class ImportProfile
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $targetId,
        public readonly array $mapping,
        public readonly array $options = [],
        public readonly array $sourceConfig = [],
    ) {
        if ($targetId < 1) {
            throw new \InvalidArgumentException('Target IBlock ID must be a positive integer.');
        }
        if ($mapping === []) {
            throw new \InvalidArgumentException('At least one mapping rule is required.');
        }
    }

    public function headerRow(): int
    {
        return max(1, (int) ($this->options['header_row'] ?? 1));
    }

    public function startRow(): int
    {
        return max($this->headerRow() + 1, (int) ($this->options['start_row'] ?? 2));
    }

    public function chunkSize(): int
    {
        return min(1000, max(10, (int) ($this->options['chunk_size'] ?? 200)));
    }

    public function uniqueTarget(): string
    {
        return (string) ($this->options['unique_target'] ?? 'FIELD:XML_ID');
    }

    public function writeMode(): string
    {
        $mode = (string) ($this->options['write_mode'] ?? 'upsert');
        return in_array($mode, ['insert', 'update', 'upsert'], true) ? $mode : 'upsert';
    }
}
