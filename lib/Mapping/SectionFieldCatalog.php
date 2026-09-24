<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class SectionFieldCatalog
{
    private const FIELDS = [
        'ID',
        'NAME',
        'CODE',
        'XML_ID',
        'ACTIVE',
        'SORT',
        'DESCRIPTION',
        'DESCRIPTION_TYPE',
        'PICTURE',
        'DETAIL_PICTURE',
    ];

    public static function codes(): array
    {
        return self::FIELDS;
    }

    public static function isSupported(string $code): bool
    {
        return in_array(strtoupper($code), self::FIELDS, true);
    }

    public static function isPicture(string $code): bool
    {
        return in_array(strtoupper($code), ['PICTURE', 'DETAIL_PICTURE'], true);
    }
}
