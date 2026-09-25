<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

use WebEnot\ImportExcel\Domain\Row;

final class RowMapper
{
    private readonly BitrixValueNormalizer $valueNormalizer;

    public function __construct(
        private readonly Transformer $transformer,
        private readonly MappingValidator $validator,
        ?BitrixValueNormalizer $valueNormalizer = null,
    ) {
        $this->valueNormalizer = $valueNormalizer ?? new BitrixValueNormalizer();
    }

    public function map(Row $row, array $mapping): MappedRow
    {
        $this->validator->validate($mapping);
        $fields = [];
        $properties = [];
        $sections = [];
        $propertyDefinitions = [];

        foreach ($mapping as $rule) {
            $column = strtoupper((string) $rule['column']);
            $target = strtoupper((string) $rule['target']);
            $value = $row->cell($column);
            if ($this->isImageTarget($target, $rule) && $row->link($column) !== null) {
                $value = $row->link($column);
            }
            if (($value === null || $value === '') && array_key_exists('default', $rule)) {
                $value = $rule['default'];
            }
            $value = $this->transformer->apply($value, (array) ($rule['transforms'] ?? []));

            [$scope, $name] = explode(':', $target, 2);
            if ($scope === 'PROPERTY') {
                $value = $this->valueNormalizer->property(
                    (string) ($rule['property_type'] ?? 'S'),
                    $value
                );
            } elseif ($scope === 'SECTION') {
                $sectionTarget = SectionPath::parseTarget($target);
                if ($sectionTarget === null) {
                    throw new MappingException(sprintf('Invalid section target %s.', $target));
                }
                $value = $this->valueNormalizer->section($sectionTarget['field'], $value);
            } else {
                $value = $this->valueNormalizer->element($name, $value);
            }
            if (($rule['required'] ?? false) && ($value === null || $value === '' || $value === [])) {
                throw new MappingException(sprintf('Column %s is required for %s.', $column, $target));
            }

            if ($scope === 'PROPERTY') {
                $properties[$name] = $value;
                $propertyDefinitions[$name] = [
                    'property_type' => strtoupper((string) ($rule['property_type'] ?? 'S')),
                    'multiple' => (bool) ($rule['multiple'] ?? false),
                ];
            } elseif ($scope === 'SECTION') {
                $sections[$sectionTarget['level']][$sectionTarget['field']] = $value;
            } else {
                $fields[$name] = $value;
            }
        }

        return new MappedRow(
            $row->number,
            $fields,
            $properties,
            $row->cells,
            $sections,
            $propertyDefinitions
        );
    }

    private function isImageTarget(string $target, array $rule): bool
    {
        if (str_starts_with($target, 'PROPERTY:')) {
            return strtoupper((string) ($rule['property_type'] ?? 'S')) === 'F';
        }
        if (str_starts_with($target, 'FIELD:')) {
            return ElementFieldCatalog::isPicture(substr($target, 6));
        }
        if (str_starts_with($target, 'SECTION:')) {
            $parsed = SectionPath::parseTarget($target);
            return $parsed !== null && SectionFieldCatalog::isPicture($parsed['field']);
        }

        return false;
    }
}
