<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;
use WebEnot\ImportExcel\Mapping\ElementFieldCatalog;
use WebEnot\ImportExcel\Mapping\MappedRow;

final class BitrixIblockGateway implements IblockGatewayInterface
{
    private readonly BitrixImageResolver $imageResolver;
    private readonly ElementCodeGenerator $sectionCodeGenerator;

    public function __construct(
        ?BitrixImageResolver $imageResolver = null,
        ?ElementCodeGenerator $sectionCodeGenerator = null
    ) {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The Bitrix IBlock module is required.');
        }
        $this->imageResolver = $imageResolver ?? new BitrixImageResolver();
        $this->sectionCodeGenerator = $sectionCodeGenerator
            ?? new ElementCodeGenerator(new HeaderNormalizer());
    }

    public function ensureProperties(int $iblockId, array $definitions): void
    {
        foreach ($definitions as $definition) {
            $target = strtoupper((string) ($definition['target'] ?? ''));
            if (!str_starts_with($target, 'PROPERTY:')) {
                continue;
            }
            [, $code] = $this->parseTarget($target);
            $existing = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
            if ($existing) {
                continue;
            }

            $propertyType = strtoupper((string) ($definition['property_type'] ?? 'S'));
            if (!in_array($propertyType, ['S', 'N', 'L', 'F', 'E', 'G'], true)) {
                $propertyType = 'S';
            }
            $property = new \CIBlockProperty();
            $id = $property->Add([
                'IBLOCK_ID' => $iblockId,
                'NAME' => (string) ($definition['label'] ?? $code),
                'CODE' => $code,
                'PROPERTY_TYPE' => $propertyType,
                'MULTIPLE' => ($definition['multiple'] ?? false) ? 'Y' : 'N',
                'ACTIVE' => 'Y',
                'SORT' => (int) ($definition['sort'] ?? 500),
            ]);
            if (!$id) {
                throw new \RuntimeException(sprintf('Unable to create property %s: %s', $code, $property->LAST_ERROR));
            }
        }
    }

    public function find(int $iblockId, string $uniqueTarget, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        [$scope, $name] = $this->parseTarget($uniqueTarget);
        $filter = ['IBLOCK_ID' => $iblockId];
        $filter[$scope === 'PROPERTY' ? 'PROPERTY_' . $name : $name] = $value;
        $result = \CIBlockElement::GetList([], $filter, false, ['nTopCount' => 2], ['ID', 'IBLOCK_ID', 'NAME']);
        $matches = [];
        while ($item = $result->Fetch()) {
            $matches[] = $item;
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf('Unique key %s=%s matched more than one element.', $uniqueTarget, (string) $value));
        }
        return $matches[0] ?? null;
    }

    public function uniqueCode(int $iblockId, string $baseCode, int $excludeElementId = 0): string
    {
        $baseCode = trim($baseCode, '-');
        if ($baseCode === '') {
            return '';
        }

        $code = $baseCode;
        $suffix = 2;
        do {
            $filter = ['IBLOCK_ID' => $iblockId, '=CODE' => $code, 'CHECK_PERMISSIONS' => 'N'];
            if ($excludeElementId > 0) {
                $filter['!ID'] = $excludeElementId;
            }
            $exists = (bool) \CIBlockElement::GetList(
                [],
                $filter,
                false,
                ['nTopCount' => 1],
                ['ID']
            )->Fetch();
            if (!$exists) {
                return $code;
            }

            $tail = '-' . $suffix++;
            $code = substr($baseCode, 0, 50 - strlen($tail)) . $tail;
        } while (true);
    }

    public function resolveSectionPath(int $iblockId, array $names, bool $create): SectionPathResult
    {
        $parentId = 0;
        $createdIds = [];
        try {
            foreach ($names as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    throw new \InvalidArgumentException('Section name cannot be empty.');
                }

                $filter = [
                    'IBLOCK_ID' => $iblockId,
                    'SECTION_ID' => $parentId > 0 ? $parentId : false,
                    '=NAME' => $name,
                    'CHECK_PERMISSIONS' => 'N',
                ];
                $result = \CIBlockSection::GetList(['ID' => 'ASC'], $filter, false, ['ID', 'NAME']);
                $match = $result->Fetch();
                if ($match && $result->Fetch()) {
                    throw new \RuntimeException(sprintf(
                        'More than one section named "%s" exists under parent section %d.',
                        $name,
                        $parentId
                    ));
                }
                if ($match) {
                    $parentId = (int) $match['ID'];
                    continue;
                }
                if (!$create) {
                    return new SectionPathResult(null);
                }

                $section = new \CIBlockSection();
                $sectionId = (int) $section->Add([
                    'IBLOCK_ID' => $iblockId,
                    'IBLOCK_SECTION_ID' => $parentId > 0 ? $parentId : false,
                    'NAME' => $name,
                    'ACTIVE' => 'Y',
                    'SORT' => 500,
                    'CODE' => $this->uniqueSectionCode($iblockId, $name),
                ]);
                if ($sectionId < 1) {
                    throw new \RuntimeException('Unable to create IBlock section: ' . $section->LAST_ERROR);
                }
                $createdIds[] = $sectionId;
                $parentId = $sectionId;
            }
        } catch (\Throwable $exception) {
            try {
                $this->deleteSectionsIfEmpty($createdIds);
            } catch (\Throwable) {
            }
            throw $exception;
        }

        return new SectionPathResult($parentId > 0 ? $parentId : null, $createdIds);
    }

    public function snapshot(int $elementId): array
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId],
            false,
            ['nTopCount' => 1],
            [
                'ID',
                'IBLOCK_ID',
                'NAME',
                'ACTIVE',
                'CODE',
                'XML_ID',
                'SORT',
                'TAGS',
                'DATE_ACTIVE_FROM',
                'DATE_ACTIVE_TO',
                'PREVIEW_TEXT',
                'PREVIEW_TEXT_TYPE',
                'PREVIEW_PICTURE',
                'DETAIL_TEXT',
                'DETAIL_TEXT_TYPE',
                'DETAIL_PICTURE',
                'IBLOCK_SECTION_ID',
            ]
        )->GetNext(false, false);
        if (!$element) {
            throw new \RuntimeException(sprintf('IBlock element %d was not found.', $elementId));
        }

        $properties = [];
        $propertyResult = \CIBlockElement::GetProperty((int) $element['IBLOCK_ID'], $elementId, ['sort' => 'asc'], []);
        while ($property = $propertyResult->Fetch()) {
            $code = (string) ($property['CODE'] ?: $property['ID']);
            if (($property['MULTIPLE'] ?? 'N') === 'Y') {
                $properties[$code][] = $property['VALUE'];
            } else {
                $properties[$code] = $property['VALUE'];
            }
        }

        return ['fields' => $element, 'properties' => $properties];
    }

    public function add(int $iblockId, MappedRow $row): int
    {
        $element = new \CIBlockElement();
        $fields = $this->sanitizeFields($row->fields) + ['IBLOCK_ID' => $iblockId];
        if (trim((string) ($fields['NAME'] ?? '')) === '') {
            throw new \RuntimeException('FIELD:NAME is required when a new element is created.');
        }
        [$fields, $temporaryPaths] = $this->preparePictureFields($fields);
        try {
            $id = (int) $element->Add($fields, false, true, true);
            if ($id < 1) {
                throw new \RuntimeException('Unable to add IBlock element: ' . $element->LAST_ERROR);
            }
        } finally {
            $this->cleanupTemporaryFiles($temporaryPaths);
        }
        if ($row->properties !== []) {
            \CIBlockElement::SetPropertyValuesEx($id, $iblockId, $row->properties);
        }
        return $id;
    }

    public function update(int $elementId, MappedRow $row): void
    {
        $snapshot = $this->snapshot($elementId);
        $element = new \CIBlockElement();
        $fields = $this->sanitizeFields($row->fields);
        [$fields, $temporaryPaths] = $this->preparePictureFields($fields);
        try {
            if ($fields !== [] && !$element->Update($elementId, $fields, false, true, true)) {
                throw new \RuntimeException('Unable to update IBlock element: ' . $element->LAST_ERROR);
            }
        } finally {
            $this->cleanupTemporaryFiles($temporaryPaths);
        }
        if ($row->properties !== []) {
            \CIBlockElement::SetPropertyValuesEx($elementId, (int) $snapshot['fields']['IBLOCK_ID'], $row->properties);
        }
    }

    public function delete(int $elementId): void
    {
        if (!\CIBlockElement::Delete($elementId)) {
            throw new \RuntimeException(sprintf('Unable to delete IBlock element %d.', $elementId));
        }
    }

    public function restore(int $elementId, array $snapshot): void
    {
        $fields = (array) ($snapshot['fields'] ?? []);
        $iblockId = (int) ($fields['IBLOCK_ID'] ?? 0);
        unset($fields['ID'], $fields['IBLOCK_ID']);
        $element = new \CIBlockElement();
        [$fields, $temporaryPaths] = $this->preparePictureFields($this->sanitizeFields($fields), true);
        try {
            if (!$element->Update($elementId, $fields, false, true, true)) {
                throw new \RuntimeException('Unable to restore IBlock element: ' . $element->LAST_ERROR);
            }
        } finally {
            $this->cleanupTemporaryFiles($temporaryPaths);
        }
        \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, (array) ($snapshot['properties'] ?? []));
    }

    public function deleteSectionsIfEmpty(array $sectionIds): void
    {
        $sectionIds = array_values(array_unique(array_filter(array_map('intval', $sectionIds))));
        foreach (array_reverse($sectionIds) as $sectionId) {
            $section = \CIBlockSection::GetByID($sectionId)->Fetch();
            if (!$section) {
                continue;
            }
            $hasElements = (bool) \CIBlockElement::GetList(
                [],
                ['SECTION_ID' => $sectionId, 'INCLUDE_SUBSECTIONS' => 'N', 'CHECK_PERMISSIONS' => 'N'],
                false,
                ['nTopCount' => 1],
                ['ID']
            )->Fetch();
            $hasChildren = (bool) \CIBlockSection::GetList(
                [],
                ['SECTION_ID' => $sectionId, 'CHECK_PERMISSIONS' => 'N'],
                false,
                ['ID']
            )->Fetch();
            if (!$hasElements && !$hasChildren && !\CIBlockSection::Delete($sectionId)) {
                throw new \RuntimeException(sprintf('Unable to delete empty IBlock section %d.', $sectionId));
            }
        }
    }

    private function sanitizeFields(array $fields): array
    {
        return array_intersect_key($fields, array_flip(ElementFieldCatalog::codes()));
    }

    private function uniqueSectionCode(int $iblockId, string $name): string
    {
        $baseCode = $this->sectionCodeGenerator->generate($name);
        if ($baseCode === '') {
            $baseCode = 'section';
        }

        $code = $baseCode;
        $suffix = 2;
        while ($this->sectionCodeExists($iblockId, $code)) {
            $tail = '-' . $suffix++;
            $code = substr($baseCode, 0, 50 - strlen($tail)) . $tail;
        }

        return $code;
    }

    private function sectionCodeExists(int $iblockId, string $code): bool
    {
        return (bool) \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, '=CODE' => $code, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['ID']
        )->Fetch();
    }

    /**
     * @return array{0: array, 1: list<string>}
     */
    private function preparePictureFields(array $fields, bool $deleteEmpty = false): array
    {
        $temporaryPaths = [];
        foreach ($fields as $code => $value) {
            if (!ElementFieldCatalog::isPicture((string) $code)) {
                continue;
            }
            if ($value === null || $value === '') {
                if ($deleteEmpty) {
                    $fields[$code] = ['del' => 'Y'];
                } else {
                    unset($fields[$code]);
                }
                continue;
            }

            $resolved = $this->imageResolver->resolve($value);
            $fields[$code] = $resolved['file'];
            if ($resolved['temporary_path'] !== '') {
                $temporaryPaths[] = $resolved['temporary_path'];
            }
        }

        return [$fields, $temporaryPaths];
    }

    private function cleanupTemporaryFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                @unlink($path);
            }
        }
    }

    private function parseTarget(string $target): array
    {
        $target = strtoupper($target);
        if (!preg_match('/^(FIELD|PROPERTY):([A-Z][A-Z0-9_]*)$/', $target, $matches)) {
            throw new \InvalidArgumentException(sprintf('Invalid target "%s".', $target));
        }
        return [$matches[1], $matches[2]];
    }
}
