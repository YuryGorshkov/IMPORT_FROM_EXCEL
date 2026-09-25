<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class TargetNotation
{
    public static function write(string $target): string
    {
        $target = strtoupper(trim($target));

        if (str_starts_with($target, 'PROPERTY:')) {
            return sprintf('$arFields["PROPERTY_VALUES"]["%s"]', substr($target, 9));
        }

        if (str_starts_with($target, 'FIELD:')) {
            return sprintf('$arFields["%s"]', substr($target, 6));
        }

        $section = SectionPath::parseTarget($target);
        if ($section !== null) {
            return sprintf('$arSection["%s"]', $section['field']);
        }

        return $target;
    }

    public static function filter(string $target): string
    {
        $target = strtoupper(trim($target));

        if (str_starts_with($target, 'PROPERTY:')) {
            return sprintf('$arFilter["PROPERTY_%s"]', substr($target, 9));
        }

        if (str_starts_with($target, 'FIELD:')) {
            return sprintf('$arFilter["%s"]', substr($target, 6));
        }

        return $target;
    }
}
