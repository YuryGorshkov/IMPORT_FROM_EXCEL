<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Support;

use WebEnot\ImportExcel\Mapping\MappedRow;
use WebEnot\ImportExcel\Target\IblockGatewayInterface;
use WebEnot\ImportExcel\Target\SectionPathResult;

final class CodeCaptureGateway implements IblockGatewayInterface
{
    public ?array $existing = null;
    public array $snapshots = [];
    public string $lastBaseCode = '';
    public array $sectionPaths = [];
    public array $deletedSectionIds = [];

    public function ensureProperties(int $iblockId, array $definitions): void
    {
    }

    public function find(int $iblockId, string $uniqueTarget, mixed $value): ?array
    {
        return $this->existing;
    }

    public function uniqueCode(int $iblockId, string $baseCode, int $excludeElementId = 0): string
    {
        $this->lastBaseCode = $baseCode;
        return $baseCode;
    }

    public function resolveSectionPath(int $iblockId, array $levels, bool $create): SectionPathResult
    {
        $this->sectionPaths[] = ['iblock_id' => $iblockId, 'levels' => $levels, 'create' => $create];
        return new SectionPathResult($create ? 77 : null, $create ? [70, 77] : []);
    }

    public function snapshot(int $elementId): array
    {
        return $this->snapshots[$elementId] ?? ['fields' => ['ID' => $elementId], 'properties' => []];
    }

    public function add(int $iblockId, MappedRow $row): int
    {
        return 100;
    }

    public function update(int $elementId, MappedRow $row): void
    {
    }

    public function delete(int $elementId): void
    {
    }

    public function restore(int $elementId, array $snapshot): void
    {
    }

    public function deleteSectionsIfEmpty(array $sectionIds): void
    {
        $this->deletedSectionIds = $sectionIds;
    }

    public function restoreSections(array $snapshots): void
    {
    }
}
