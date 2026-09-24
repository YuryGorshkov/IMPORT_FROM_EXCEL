<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

use WebEnot\ImportExcel\Domain\Row;

final class RowMapper
{
    public function __construct(
        private readonly Transformer $transformer,
        private readonly MappingValidator $validator,
    ) {
    }

    public function map(Row $row, array $mapping): MappedRow
    {
        $this->validator->validate($mapping);
        $fields = [];
        $properties = [];
        $sections = [];

        foreach ($mapping as $rule) {
            $column = strtoupper((string) $rule['column']);
            $target = strtoupper((string) $rule['target']);
            $value = $row->cell($column);
            if (($value === null || $value === '') && array_key_exists('default', $rule)) {
                $value = $rule['default'];
            }
            $value = $this->transformer->apply($value, (array) ($rule['transforms'] ?? []));
            if (($rule['required'] ?? false) && ($value === null || $value === '' || $value === [])) {
                throw new MappingException(sprintf('Column %s is required for %s.', $column, $target));
            }

            [$scope, $name] = explode(':', $target, 2);
            if ($scope === 'PROPERTY') {
                $properties[$name] = $value;
            } elseif ($scope === 'SECTION') {
                $sectionTarget = SectionPath::parseTarget($target);
                if ($sectionTarget === null) {
                    throw new MappingException(sprintf('Invalid section target %s.', $target));
                }
                $sections[$sectionTarget['level']][$sectionTarget['field']] = $value;
            } else {
                $fields[$name] = $value;
            }
        }

        return new MappedRow($row->number, $fields, $properties, $row->cells, $sections);
    }
}
