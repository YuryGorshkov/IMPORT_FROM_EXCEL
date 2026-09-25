<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use WebEnot\ImportExcel\Domain\ImportProfile;
use WebEnot\ImportExcel\Mapping\MappedRow;
use WebEnot\ImportExcel\Mapping\SectionPath;

final class IblockTarget
{
    public function __construct(
        private readonly IblockGatewayInterface $gateway,
        private readonly ElementCodeGenerator $codeGenerator,
    ) {
    }

    public function prepare(ImportProfile $profile, bool $dryRun = false): void
    {
        if ($dryRun) {
            return;
        }

        $definitions = $profile->mapping;
        if (($profile->options['auto_create_properties'] ?? true) !== true) {
            $definitions = array_values(array_filter(
                $definitions,
                static fn(array $definition): bool => ($definition['create_if_missing'] ?? true) === false
            ));
        }
        if ($definitions !== []) {
            $this->gateway->ensureProperties($profile->targetId, $definitions);
        }
    }

    public function apply(ImportProfile $profile, MappedRow $row, bool $dryRun): TargetResult
    {
        $uniqueTarget = $profile->uniqueTarget();
        $uniqueValue = $row->value($uniqueTarget);
        if ($uniqueValue === null || $uniqueValue === '') {
            throw new \RuntimeException(sprintf('Unique field %s is empty.', $uniqueTarget));
        }

        $existing = $this->gateway->find($profile->targetId, $uniqueTarget, $uniqueValue);
        $mode = $profile->writeMode();
        if ($existing === null && $mode === 'update') {
            return new TargetResult('skipped');
        }
        if ($existing !== null && $mode === 'insert') {
            return new TargetResult('skipped', (int) $existing['ID']);
        }

        [$row, $sectionResult] = $this->withSectionPath($profile, $row, !$dryRun);

        if ($existing === null) {
            $row = $this->withElementCode($profile, $row);
            if ($dryRun) {
                return new TargetResult('added', 0, [], $this->previewData($row));
            }
            try {
                $id = $this->gateway->add($profile->targetId, $row);
                $after = $this->gateway->snapshot($id);
            } catch (\Throwable $exception) {
                $this->rollbackSectionChanges($sectionResult);
                throw $exception;
            }
            $after['created_section_ids'] = $sectionResult->createdIds;
            return new TargetResult(
                'added',
                $id,
                ['section_snapshots' => $sectionResult->beforeSnapshots],
                $after
            );
        }

        $id = (int) $existing['ID'];
        $before = $this->gateway->snapshot($id);
        $row = $this->withElementCode(
            $profile,
            $row,
            $id,
            trim((string) ($before['fields']['CODE'] ?? '')) !== ''
        );
        if ($dryRun) {
            return new TargetResult('updated', $id, $before, $this->previewData($row));
        }
        $before['section_snapshots'] = $sectionResult->beforeSnapshots;
        try {
            $this->gateway->update($id, $row);
            $after = $this->gateway->snapshot($id);
        } catch (\Throwable $exception) {
            $this->rollbackSectionChanges($sectionResult);
            throw $exception;
        }
        $after['created_section_ids'] = $sectionResult->createdIds;
        return new TargetResult('updated', $id, $before, $after);
    }

    private function withElementCode(
        ImportProfile $profile,
        MappedRow $row,
        int $elementId = 0,
        bool $existingHasCode = false
    ): MappedRow {
        $fields = $row->fields;
        $mappedCode = trim((string) ($fields['CODE'] ?? ''));
        if ($mappedCode !== '') {
            return $row;
        }
        if ($existingHasCode) {
            unset($fields['CODE']);
            return new MappedRow(
                $row->rowNumber,
                $fields,
                $row->properties,
                $row->raw,
                $row->sections,
                $row->propertyDefinitions
            );
        }

        $source = $profile->elementCodeSource();
        if ($source === 'none') {
            return $row;
        }

        $value = $source === 'unique'
            ? $row->value($profile->uniqueTarget())
            : ($fields['NAME'] ?? null);
        $baseCode = $this->codeGenerator->generate($value);
        if ($baseCode === '') {
            return $row;
        }

        $fields['CODE'] = $this->gateway->uniqueCode($profile->targetId, $baseCode, $elementId);
        return new MappedRow(
            $row->rowNumber,
            $fields,
            $row->properties,
            $row->raw,
            $row->sections,
            $row->propertyDefinitions
        );
    }

    /**
     * @return array{0: MappedRow, 1: SectionPathResult}
     */
    private function withSectionPath(ImportProfile $profile, MappedRow $row, bool $create): array
    {
        $levels = SectionPath::normalize($row->sections);
        if ($levels === []) {
            return [$row, new SectionPathResult(null)];
        }

        $result = $this->gateway->resolveSectionPath($profile->targetId, $levels, $create);
        if ($result->sectionId === null) {
            return [$row, $result];
        }

        $fields = $row->fields;
        $fields['IBLOCK_SECTION_ID'] = $result->sectionId;
        return [
            new MappedRow(
                $row->rowNumber,
                $fields,
                $row->properties,
                $row->raw,
                $row->sections,
                $row->propertyDefinitions
            ),
            $result,
        ];
    }

    private function rollbackSectionChanges(SectionPathResult $result): void
    {
        try {
            $this->gateway->restoreSections($result->beforeSnapshots);
        } catch (\Throwable) {
        }
        try {
            $this->gateway->deleteSectionsIfEmpty($result->createdIds);
        } catch (\Throwable) {
        }
    }

    private function previewData(MappedRow $row): array
    {
        return [
            'fields' => $row->fields,
            'properties' => $row->properties,
            'sections' => SectionPath::normalize($row->sections),
        ];
    }
}
