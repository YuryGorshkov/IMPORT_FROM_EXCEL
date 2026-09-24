<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Mapping\MappedRow;

final class BitrixIblockGateway implements IblockGatewayInterface
{
    public function __construct()
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The Bitrix IBlock module is required.');
        }
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

    public function snapshot(int $elementId): array
    {
        $element = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID', 'NAME', 'ACTIVE', 'CODE', 'XML_ID', 'SORT', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'IBLOCK_SECTION_ID']
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
        $id = (int) $element->Add($fields, false, true, true);
        if ($id < 1) {
            throw new \RuntimeException('Unable to add IBlock element: ' . $element->LAST_ERROR);
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
        if ($fields !== [] && !$element->Update($elementId, $fields, false, true, true)) {
            throw new \RuntimeException('Unable to update IBlock element: ' . $element->LAST_ERROR);
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
        if (!$element->Update($elementId, $this->sanitizeFields($fields), false, true, true)) {
            throw new \RuntimeException('Unable to restore IBlock element: ' . $element->LAST_ERROR);
        }
        \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, (array) ($snapshot['properties'] ?? []));
    }

    private function sanitizeFields(array $fields): array
    {
        $allowed = [
            'NAME', 'ACTIVE', 'CODE', 'XML_ID', 'SORT', 'TAGS', 'DATE_ACTIVE_FROM', 'DATE_ACTIVE_TO',
            'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE', 'IBLOCK_SECTION_ID',
        ];
        return array_intersect_key($fields, array_flip($allowed));
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
