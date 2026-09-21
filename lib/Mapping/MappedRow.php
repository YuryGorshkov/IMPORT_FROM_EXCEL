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
    ) {
    }

    public function value(string $target): mixed
    {
        [$scope, $name] = array_pad(explode(':', strtoupper($target), 2), 2, '');
        return $scope === 'PROPERTY' ? ($this->properties[$name] ?? null) : ($this->fields[$name] ?? null);
    }
}
