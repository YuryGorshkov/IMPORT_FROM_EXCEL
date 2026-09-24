<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class MappedRow
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $fields,
        public readonly array $properties,
        public readonly array $raw,
        public readonly array $sections = [],
    ) {
    }

    public function value(string $target): mixed
    {
        [$scope, $name] = array_pad(explode(':', strtoupper($target), 2), 2, '');
        return match ($scope) {
            'PROPERTY' => $this->properties[$name] ?? null,
            'SECTION' => $this->sectionValue($target),
            default => $this->fields[$name] ?? null,
        };
    }

    private function sectionValue(string $target): mixed
    {
        $parsed = SectionPath::parseTarget($target);
        if ($parsed === null) {
            return null;
        }

        $level = $this->sections[$parsed['level']] ?? null;
        if (is_array($level)) {
            return $level[$parsed['field']] ?? null;
        }

        return $parsed['field'] === 'NAME' ? $level : null;
    }
}
