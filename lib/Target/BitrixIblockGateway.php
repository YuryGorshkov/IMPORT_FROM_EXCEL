<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;
use WebEnot\ImportExcel\Mapping\ElementFieldCatalog;
use WebEnot\ImportExcel\Mapping\MappedRow;
use WebEnot\ImportExcel\Mapping\SectionFieldCatalog;

final class BitrixIblockGateway implements IblockGatewayInterface
{
    private readonly BitrixImageResolver $imageResolver;
    private readonly FilePropertyValueResolver $filePropertyValueResolver;
    private readonly ElementCodeGenerator $sectionCodeGenerator;
    private array $updatedSectionHashes = [];
    private array $propertyDefinitionsByIblock = [];

    public function __construct(
        ?BitrixImageResolver $imageResolver = null,
        ?ElementCodeGenerator $sectionCodeGenerator = null
    ) {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The Bitrix IBlock module is required.');
        }
        $this->imageResolver = $imageResolver ?? new BitrixImageResolver();
        $this->filePropertyValueResolver = new FilePropertyValueResolver($this->imageResolver);
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
            $propertyType = strtoupper((string) ($definition['property_type'] ?? 'S'));
            if (!in_array($propertyType, ['S', 'N', 'L', 'F', 'E', 'G'], true)) {
                $propertyType = 'S';
            }
            if ($existing) {
                $existingType = strtoupper((string) ($existing['PROPERTY_TYPE'] ?? 'S'));
                $existingMultiple = ($existing['MULTIPLE'] ?? 'N') === 'Y';
                if (
                    ($definition['create_if_missing'] ?? true) !== false
                    && ($existingType !== $propertyType
                        || $existingMultiple !== (bool) ($definition['multiple'] ?? false))
                ) {
                    throw new \RuntimeException(sprintf(
                        'Property %s already exists in IBlock %d with another type or multiplicity.',
                        $code,
                        $iblockId
                    ));
                }
                continue;
            }
            if (($definition['create_if_missing'] ?? true) === false) {
                throw new \RuntimeException(sprintf(
                    'Selected property %s no longer exists in IBlock %d.',
                    $code,
                    $iblockId
                ));
            }

            $sectionProperty = ($definition['property_show_edit'] ?? true) ? 'Y' : 'N';
            $smartFilter = ($definition['property_smart_filter'] ?? false) ? 'Y' : 'N';
            if (
                \CIBlock::GetArrayByID($iblockId, 'SECTION_PROPERTY') !== 'Y'
                && ($sectionProperty === 'N' || $smartFilter === 'Y')
            ) {
                $iblock = new \CIBlock();
                if (!$iblock->Update($iblockId, ['SECTION_PROPERTY' => 'Y'])) {
                    throw new \RuntimeException(sprintf(
                        'Unable to enable section property settings for IBlock %d: %s',
                        $iblockId,
                        $iblock->LAST_ERROR
                    ));
                }
            }

            $linkIblockId = max(0, (int) ($definition['property_link_iblock_id'] ?? 0));
            if (in_array($propertyType, ['E', 'G'], true) && $linkIblockId === 0) {
                $linkIblockId = $iblockId;
            }
            $withDescription = in_array($propertyType, ['S', 'N', 'F'], true)
                && (bool) ($definition['property_with_description'] ?? false);
            $displayType = strtoupper((string) ($definition['property_display_type'] ?? 'F'));
            if (!in_array($displayType, ['F', 'K', 'P'], true)) {
                $displayType = 'F';
            }
            $property = new \CIBlockProperty();
            $id = $property->Add([
                'IBLOCK_ID' => $iblockId,
                'NAME' => (string) ($definition['property_name'] ?? $definition['label'] ?? $code),
                'CODE' => $code,
                'PROPERTY_TYPE' => $propertyType,
                'LINK_IBLOCK_ID' => $linkIblockId,
                'MULTIPLE' => ($definition['multiple'] ?? false) ? 'Y' : 'N',
                'ACTIVE' => ($definition['property_active'] ?? true) ? 'Y' : 'N',
                'SORT' => max(0, (int) ($definition['property_sort'] ?? 500)),
                'IS_REQUIRED' => ($definition['property_is_required'] ?? false) ? 'Y' : 'N',
                'SEARCHABLE' => ($definition['property_searchable'] ?? false) ? 'Y' : 'N',
                'FILTRABLE' => ($definition['property_filtrable'] ?? false) ? 'Y' : 'N',
                'WITH_DESCRIPTION' => $withDescription ? 'Y' : 'N',
                'MULTIPLE_CNT' => max(1, (int) ($definition['property_multiple_count'] ?? 5)),
                'HINT' => (string) ($definition['property_hint'] ?? ''),
                'SECTION_PROPERTY' => $sectionProperty,
                'SMART_FILTER' => $smartFilter,
                'DISPLAY_TYPE' => $displayType,
                'DISPLAY_EXPANDED' => ($definition['property_display_expanded'] ?? false) ? 'Y' : 'N',
                'FILTER_HINT' => (string) ($definition['property_filter_hint'] ?? ''),
                'ROW_COUNT' => max(1, (int) ($definition['property_row_count'] ?? 1)),
                'COL_COUNT' => max(1, (int) ($definition['property_col_count'] ?? 30)),
                'DEFAULT_VALUE' => (string) ($definition['property_default_value'] ?? ''),
                'LIST_TYPE' => 'L',
                'FEATURES' => [
                    [
                        'MODULE_ID' => 'iblock',
                        'FEATURE_ID' => 'LIST_PAGE_SHOW',
                        'IS_ENABLED' => ($definition['property_show_list'] ?? false) ? 'Y' : 'N',
                    ],
                    [
                        'MODULE_ID' => 'iblock',
                        'FEATURE_ID' => 'DETAIL_PAGE_SHOW',
                        'IS_ENABLED' => ($definition['property_show_detail'] ?? false) ? 'Y' : 'N',
                    ],
                ],
            ]);
            if (!$id) {
                throw new \RuntimeException(sprintf('Unable to create property %s: %s', $code, $property->LAST_ERROR));
            }
            unset($this->propertyDefinitionsByIblock[$iblockId]);
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

    public function resolveSectionPath(int $iblockId, array $levels, bool $create): SectionPathResult
    {
        $parentId = 0;
        $createdIds = [];
        $beforeSnapshots = [];
        try {
            foreach ($levels as $position => $levelFields) {
                $level = $position + 1;
                $fields = $this->sanitizeSectionFields((array) $levelFields);
                if ($fields === []) {
                    throw new \InvalidArgumentException(sprintf('Section level %d has no fields.', $level));
                }

                $match = $this->findSection($iblockId, $parentId, $fields, $level);
                if ($match) {
                    $parentId = (int) $match['ID'];
                    $updates = $fields;
                    unset($updates['ID']);
                    $hash = hash('sha256', serialize($updates));
                    if (
                        $updates !== []
                        && $this->sectionFieldsNeedUpdate($match, $updates)
                        && ($this->updatedSectionHashes[$parentId] ?? '') !== $hash
                    ) {
                        $beforeSnapshots[] = $this->snapshotSection($parentId, array_keys($updates));
                        if ($create) {
                            $this->updateSection($iblockId, $parentId, $updates);
                            $this->updatedSectionHashes[$parentId] = $hash;
                        }
                    }
                    continue;
                }
                if (!$create) {
                    return new SectionPathResult(null);
                }
                if (isset($fields['ID'])) {
                    throw new \RuntimeException(sprintf(
                        'Section ID %d was not found at level %d under parent section %d.',
                        (int) $fields['ID'],
                        $level,
                        $parentId
                    ));
                }

                $name = trim((string) ($fields['NAME'] ?? ''));
                if ($name === '') {
                    throw new \RuntimeException(sprintf(
                        'Section level %d was not found and cannot be created without the NAME field.',
                        $level
                    ));
                }

                unset($fields['ID']);
                $fields['IBLOCK_ID'] = $iblockId;
                $fields['IBLOCK_SECTION_ID'] = $parentId > 0 ? $parentId : false;
                $fields['ACTIVE'] ??= 'Y';
                $fields['SORT'] ??= 500;
                if (trim((string) ($fields['CODE'] ?? '')) === '') {
                    $fields['CODE'] = $this->uniqueSectionCode($iblockId, $name);
                } else {
                    $this->assertSectionIdentifierAvailable($iblockId, 'CODE', (string) $fields['CODE']);
                }
                if (trim((string) ($fields['XML_ID'] ?? '')) !== '') {
                    $this->assertSectionIdentifierAvailable($iblockId, 'XML_ID', (string) $fields['XML_ID']);
                }

                $section = new \CIBlockSection();
                [$fields, $temporaryPaths] = $this->prepareSectionPictureFields($fields);
                try {
                    $sectionId = (int) $section->Add($fields, true, true, true);
                } finally {
                    $this->cleanupTemporaryFiles($temporaryPaths);
                }
                if ($sectionId < 1) {
                    throw new \RuntimeException('Unable to create IBlock section: ' . $section->LAST_ERROR);
                }
                $createdIds[] = $sectionId;
                $parentId = $sectionId;
            }
        } catch (\Throwable $exception) {
            try {
                $this->restoreSections($beforeSnapshots);
                $this->deleteSectionsIfEmpty($createdIds);
            } catch (\Throwable) {
            }
            throw $exception;
        }

        return new SectionPathResult($parentId > 0 ? $parentId : null, $createdIds, $beforeSnapshots);
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
        $rollbackFiles = [];
        foreach (['PREVIEW_PICTURE', 'DETAIL_PICTURE'] as $fieldCode) {
            $fileId = (int) ($element[$fieldCode] ?? 0);
            if ($fileId > 0) {
                $rollbackFiles[] = [
                    'scope' => 'field',
                    'code' => $fieldCode,
                    'index' => null,
                    'file_id' => $fileId,
                ];
            }
        }
        $propertyResult = \CIBlockElement::GetProperty((int) $element['IBLOCK_ID'], $elementId, ['sort' => 'asc'], []);
        while ($property = $propertyResult->Fetch()) {
            $code = (string) ($property['CODE'] ?: $property['ID']);
            if (($property['MULTIPLE'] ?? 'N') === 'Y') {
                $properties[$code][] = $property['VALUE'];
                $propertyIndex = count($properties[$code]) - 1;
            } else {
                $properties[$code] = $property['VALUE'];
                $propertyIndex = null;
            }
            $fileId = (int) ($property['VALUE'] ?? 0);
            if (($property['PROPERTY_TYPE'] ?? '') === 'F' && $fileId > 0) {
                $rollbackFiles[] = [
                    'scope' => 'property',
                    'code' => $code,
                    'index' => $propertyIndex,
                    'file_id' => $fileId,
                ];
            }
        }

        return [
            'fields' => $element,
            'properties' => $properties,
            '_rollback_files' => $rollbackFiles,
        ];
    }

    public function add(int $iblockId, MappedRow $row): int
    {
        $element = new \CIBlockElement();
        $fields = $this->sanitizeFields($row->fields) + ['IBLOCK_ID' => $iblockId];
        if (trim((string) ($fields['NAME'] ?? '')) === '') {
            throw new \RuntimeException('FIELD:NAME is required when a new element is created.');
        }
        [$fields, $temporaryPaths] = $this->preparePictureFields($fields);
        [$properties, $propertyTemporaryPaths] = $this->preparePropertyValues($iblockId, $row);
        $temporaryPaths = array_merge($temporaryPaths, $propertyTemporaryPaths);
        try {
            $id = (int) $element->Add($fields, false, true, true);
            if ($id < 1) {
                throw new \RuntimeException('Unable to add IBlock element: ' . $element->LAST_ERROR);
            }
            if ($properties !== []) {
                \CIBlockElement::SetPropertyValuesEx($id, $iblockId, $properties);
            }
        } finally {
            $this->cleanupTemporaryFiles($temporaryPaths);
        }
        return $id;
    }

    public function update(int $elementId, MappedRow $row): void
    {
        $snapshot = $this->snapshot($elementId);
        $iblockId = (int) $snapshot['fields']['IBLOCK_ID'];
        $element = new \CIBlockElement();
        $fields = $this->sanitizeFields($row->fields);
        [$fields, $temporaryPaths] = $this->preparePictureFields($fields);
        [$properties, $propertyTemporaryPaths] = $this->preparePropertyValues($iblockId, $row);
        $temporaryPaths = array_merge($temporaryPaths, $propertyTemporaryPaths);
        try {
            if ($fields !== [] && !$element->Update($elementId, $fields, false, true, true)) {
                throw new \RuntimeException('Unable to update IBlock element: ' . $element->LAST_ERROR);
            }
            if ($properties !== []) {
                \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, $properties);
            }
        } finally {
            $this->cleanupTemporaryFiles($temporaryPaths);
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

    public function restoreSections(array $snapshots): void
    {
        foreach (array_reverse($snapshots) as $snapshot) {
            $fields = (array) ($snapshot['fields'] ?? []);
            $sectionId = (int) ($fields['ID'] ?? 0);
            $iblockId = (int) ($fields['IBLOCK_ID'] ?? 0);
            if ($sectionId < 1 || $iblockId < 1) {
                continue;
            }
            unset($fields['ID'], $fields['IBLOCK_ID']);
            $this->updateSection($iblockId, $sectionId, $fields, true);
            unset($this->updatedSectionHashes[$sectionId]);
        }
    }

    private function findSection(int $iblockId, int $parentId, array $fields, int $level): ?array
    {
        $filter = [
            'IBLOCK_ID' => $iblockId,
            'SECTION_ID' => $parentId > 0 ? $parentId : false,
            'CHECK_PERMISSIONS' => 'N',
        ];
        $identity = '';
        if (isset($fields['ID'])) {
            $identity = 'ID';
            $filter['ID'] = (int) $fields['ID'];
        } elseif (trim((string) ($fields['XML_ID'] ?? '')) !== '') {
            $identity = 'XML_ID';
            $filter['=XML_ID'] = (string) $fields['XML_ID'];
        } elseif (trim((string) ($fields['CODE'] ?? '')) !== '') {
            $identity = 'CODE';
            $filter['=CODE'] = (string) $fields['CODE'];
        } elseif (trim((string) ($fields['NAME'] ?? '')) !== '') {
            $identity = 'NAME';
            $filter['=NAME'] = (string) $fields['NAME'];
        } else {
            throw new \RuntimeException(sprintf(
                'Section level %d needs ID, XML_ID, CODE or NAME for identification.',
                $level
            ));
        }

        $result = \CIBlockSection::GetList(['ID' => 'ASC'], $filter, false, $this->sectionSelectFields());
        $match = $result->Fetch();
        if ($match && $result->Fetch()) {
            throw new \RuntimeException(sprintf(
                'More than one section matched %s at level %d under parent section %d.',
                $identity,
                $level,
                $parentId
            ));
        }

        return $match ?: null;
    }

    private function snapshotSection(int $sectionId, array $fieldCodes = []): array
    {
        $section = \CIBlockSection::GetList(
            [],
            ['ID' => $sectionId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            $this->sectionSelectFields()
        )->Fetch();
        if (!$section) {
            throw new \RuntimeException(sprintf('IBlock section %d was not found.', $sectionId));
        }

        if ($fieldCodes !== []) {
            $fieldCodes = array_values(array_unique(array_merge(['ID', 'IBLOCK_ID'], $fieldCodes)));
            $section = array_intersect_key($section, array_flip($fieldCodes));
        }

        $rollbackFiles = [];
        foreach (['PICTURE', 'DETAIL_PICTURE'] as $fieldCode) {
            $fileId = (int) ($section[$fieldCode] ?? 0);
            if ($fileId > 0) {
                $rollbackFiles[] = [
                    'scope' => 'field',
                    'code' => $fieldCode,
                    'index' => null,
                    'file_id' => $fileId,
                ];
            }
        }

        return ['fields' => $section, '_rollback_files' => $rollbackFiles];
    }

    private function sectionFieldsNeedUpdate(array $current, array $updates): bool
    {
        foreach ($updates as $code => $value) {
            if (SectionFieldCatalog::isPicture((string) $code) && !is_numeric($value)) {
                return true;
            }
            if ((string) ($current[$code] ?? '') !== (string) $value) {
                return true;
            }
        }

        return false;
    }

    private function updateSection(int $iblockId, int $sectionId, array $fields, bool $restore = false): void
    {
        $fields = $this->sanitizeSectionFields($fields);
        unset($fields['ID']);
        if ($fields === []) {
            return;
        }
        if (isset($fields['CODE'])) {
            $this->assertSectionIdentifierAvailable($iblockId, 'CODE', (string) $fields['CODE'], $sectionId);
        }
        if (isset($fields['XML_ID'])) {
            $this->assertSectionIdentifierAvailable($iblockId, 'XML_ID', (string) $fields['XML_ID'], $sectionId);
        }

        [$fields, $temporaryPaths] = $this->prepareSectionPictureFields($fields, $restore);
        $section = new \CIBlockSection();
        try {
            if (!$section->Update($sectionId, $fields, true, true, true)) {
                throw new \RuntimeException('Unable to update IBlock section: ' . $section->LAST_ERROR);
            }
        } finally {
            $this->cleanupTemporaryFiles($temporaryPaths);
        }
    }

    private function sanitizeSectionFields(array $fields): array
    {
        $fields = array_change_key_case($fields, CASE_UPPER);
        $fields = array_intersect_key($fields, array_flip(SectionFieldCatalog::codes()));
        foreach ($fields as $code => $value) {
            if ($value === null || $value === '') {
                unset($fields[$code]);
            }
        }
        if (isset($fields['ID'])) {
            $fields['ID'] = (int) $fields['ID'];
            if ($fields['ID'] < 1) {
                unset($fields['ID']);
            }
        }
        if (isset($fields['ACTIVE'])) {
            $fields['ACTIVE'] = in_array(strtoupper((string) $fields['ACTIVE']), ['Y', '1', 'YES', 'ДА'], true)
                ? 'Y'
                : 'N';
        }
        if (isset($fields['SORT'])) {
            $fields['SORT'] = (int) $fields['SORT'];
        }
        if (isset($fields['DESCRIPTION_TYPE'])) {
            $fields['DESCRIPTION_TYPE'] = strtolower((string) $fields['DESCRIPTION_TYPE']) === 'html'
                ? 'html'
                : 'text';
        }

        return $fields;
    }

    private function sectionSelectFields(): array
    {
        return array_values(array_unique(array_merge(
            ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID'],
            SectionFieldCatalog::codes()
        )));
    }

    private function assertSectionIdentifierAvailable(
        int $iblockId,
        string $field,
        string $value,
        int $excludeSectionId = 0
    ): void {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $filter = [
            'IBLOCK_ID' => $iblockId,
            '=' . strtoupper($field) => $value,
            'CHECK_PERMISSIONS' => 'N',
        ];
        if ($excludeSectionId > 0) {
            $filter['!ID'] = $excludeSectionId;
        }
        if (\CIBlockSection::GetList([], $filter, false, ['ID'])->Fetch()) {
            throw new \RuntimeException(sprintf(
                'Section %s "%s" is already used in IBlock %d.',
                strtoupper($field),
                $value,
                $iblockId
            ));
        }
    }

    /**
     * @return array{0: array, 1: list<string>}
     */
    private function prepareSectionPictureFields(array $fields, bool $restore = false): array
    {
        $temporaryPaths = [];
        foreach ($fields as $code => $value) {
            if (!SectionFieldCatalog::isPicture((string) $code)) {
                continue;
            }
            if ($restore && is_array($value) && isset($value['tmp_name'])) {
                $fields[$code] = $value;
                continue;
            }
            if ($restore && is_numeric($value)) {
                if ((int) $value < 1) {
                    $fields[$code] = ['del' => 'Y'];
                    continue;
                }
                $path = (string) \CFile::GetPath((int) $value);
                $absolutePath = $path !== '' ? (string) $_SERVER['DOCUMENT_ROOT'] . $path : '';
                if ($absolutePath !== '' && is_file($absolutePath)) {
                    $fields[$code] = \CFile::MakeFileArray($absolutePath);
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
            if (is_array($value) && isset($value['tmp_name'])) {
                $fields[$code] = $value;
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

    /**
     * @return array{0: array, 1: list<string>}
     */
    private function preparePropertyValues(int $iblockId, MappedRow $row): array
    {
        $properties = $row->properties;
        $temporaryPaths = [];
        $definitions = $this->propertyDefinitions($iblockId);
        try {
            foreach ($properties as $code => $value) {
                $normalizedCode = strtoupper((string) $code);
                $definition = $definitions[$normalizedCode] ?? null;
                $expectedType = strtoupper((string) (
                    $row->propertyDefinitions[$normalizedCode]['property_type'] ?? ''
                ));
                if ($definition === null) {
                    if ($expectedType === 'F') {
                        throw new \RuntimeException(sprintf(
                            'File property %s does not exist in IBlock %d.',
                            $normalizedCode,
                            $iblockId
                        ));
                    }
                    continue;
                }

                $actualType = strtoupper((string) ($definition['PROPERTY_TYPE'] ?? ''));
                if ($expectedType === 'F' && $actualType !== 'F') {
                    throw new \RuntimeException(sprintf(
                        'Property %s is configured as an image in the import profile, but it is not a file property in Bitrix.',
                        $normalizedCode
                    ));
                }
                if ($actualType === 'L') {
                    $properties[$code] = $this->prepareListPropertyValue(
                        $definition,
                        $value,
                        (bool) ($row->propertyDefinitions[$normalizedCode]['create_if_missing'] ?? false)
                    );
                    continue;
                }
                if ($actualType !== 'F') {
                    continue;
                }

                $resolved = $this->filePropertyValueResolver->resolve(
                    $value,
                    ($definition['MULTIPLE'] ?? 'N') === 'Y'
                );
                if ($resolved['empty']) {
                    unset($properties[$code]);
                    continue;
                }
                $properties[$code] = $resolved['value'];
                $temporaryPaths = array_merge($temporaryPaths, $resolved['temporary_paths']);
            }
        } catch (\Throwable $exception) {
            $this->cleanupTemporaryFiles($temporaryPaths);
            throw $exception;
        }

        return [$properties, $temporaryPaths];
    }

    private function prepareListPropertyValue(array $definition, mixed $value, bool $createMissing): mixed
    {
        $propertyId = (int) ($definition['ID'] ?? 0);
        $propertyCode = (string) ($definition['CODE'] ?? $propertyId);
        $multiple = ($definition['MULTIPLE'] ?? 'N') === 'Y';
        if ($propertyId < 1) {
            throw new \RuntimeException(sprintf('List property %s has no valid ID.', $propertyCode));
        }

        $values = is_array($value) ? $value : [$value];
        if ($multiple && !is_array($value) && is_string($value)) {
            $values = preg_split('/\r\n|\r|\n/u', $value) ?: [];
        }

        $byId = [];
        $byValue = [];
        $enumResult = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['PROPERTY_ID' => $propertyId]
        );
        while ($enum = $enumResult->Fetch()) {
            $enumId = (int) ($enum['ID'] ?? 0);
            if ($enumId < 1) {
                continue;
            }
            $byId[$enumId] = true;
            $byValue[mb_strtolower(trim((string) ($enum['VALUE'] ?? '')))] = $enumId;
        }

        $resolved = [];
        foreach ($values as $item) {
            if (is_array($item) && array_key_exists('VALUE', $item)) {
                $item = $item['VALUE'];
            }
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $numericId = ctype_digit($item) ? (int) $item : 0;
            if ($numericId > 0 && isset($byId[$numericId])) {
                $resolved[] = $numericId;
                continue;
            }

            $normalizedValue = mb_strtolower($item);
            if (isset($byValue[$normalizedValue])) {
                $resolved[] = $byValue[$normalizedValue];
                continue;
            }
            if (!$createMissing) {
                throw new \RuntimeException(sprintf(
                    'List value "%s" does not exist in property %s.',
                    $item,
                    $propertyCode
                ));
            }

            $enum = new \CIBlockPropertyEnum();
            $enumId = (int) $enum->Add([
                'PROPERTY_ID' => $propertyId,
                'VALUE' => $item,
                'XML_ID' => 'wie-' . substr(sha1($item), 0, 20),
                'SORT' => 500,
                'DEF' => 'N',
            ]);
            if ($enumId < 1) {
                throw new \RuntimeException(sprintf(
                    'Unable to add list value "%s" to property %s.',
                    $item,
                    $propertyCode
                ));
            }
            $byId[$enumId] = true;
            $byValue[$normalizedValue] = $enumId;
            $resolved[] = $enumId;
        }

        $resolved = array_values(array_unique($resolved));
        return $multiple ? $resolved : ($resolved[0] ?? null);
    }

    private function propertyDefinitions(int $iblockId): array
    {
        if (isset($this->propertyDefinitionsByIblock[$iblockId])) {
            return $this->propertyDefinitionsByIblock[$iblockId];
        }

        $definitions = [];
        $result = \CIBlockProperty::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
        while ($property = $result->Fetch()) {
            $code = strtoupper(trim((string) ($property['CODE'] ?? '')));
            if ($code !== '') {
                $definitions[$code] = $property;
            }
        }

        return $this->propertyDefinitionsByIblock[$iblockId] = $definitions;
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
