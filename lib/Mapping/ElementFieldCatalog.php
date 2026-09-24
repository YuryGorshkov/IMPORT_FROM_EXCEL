<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class ElementFieldCatalog
{
    private const CODES = [
        'NAME',
        'CODE',
        'XML_ID',
        'ACTIVE',
        'SORT',
        'TAGS',
        'DATE_ACTIVE_FROM',
        'DATE_ACTIVE_TO',
        'IBLOCK_SECTION_ID',
        'PREVIEW_TEXT',
        'PREVIEW_TEXT_TYPE',
        'PREVIEW_PICTURE',
        'DETAIL_TEXT',
        'DETAIL_TEXT_TYPE',
        'DETAIL_PICTURE',
    ];

    private const PICTURE_CODES = [
        'PREVIEW_PICTURE',
        'DETAIL_PICTURE',
    ];

    public static function codes(): array
    {
        return self::CODES;
    }

    public static function isSupported(string $code): bool
    {
        return in_array(strtoupper($code), self::CODES, true);
    }

    public static function isPicture(string $code): bool
    {
        return in_array(strtoupper($code), self::PICTURE_CODES, true);
    }
}
