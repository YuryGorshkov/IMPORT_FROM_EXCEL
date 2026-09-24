<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use WebEnot\ImportExcel\Mapping\MappedRow;

interface IblockGatewayInterface
{
    public function ensureProperties(int $iblockId, array $definitions): void;

    public function find(int $iblockId, string $uniqueTarget, mixed $value): ?array;

    public function uniqueCode(int $iblockId, string $baseCode, int $excludeElementId = 0): string;

    public function resolveSectionPath(int $iblockId, array $names, bool $create): SectionPathResult;

    public function snapshot(int $elementId): array;

    public function add(int $iblockId, MappedRow $row): int;

    public function update(int $elementId, MappedRow $row): void;

    public function delete(int $elementId): void;

    public function restore(int $elementId, array $snapshot): void;

    public function deleteSectionsIfEmpty(array $sectionIds): void;
}
