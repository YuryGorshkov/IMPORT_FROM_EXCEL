<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use WebEnot\ImportExcel\Discovery\HeaderNormalizer;

final class ElementCodeGenerator
{
    public function __construct(private readonly HeaderNormalizer $normalizer)
    {
    }

    public function generate(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $startsWithDigit = preg_match('/^[0-9]/', $value) === 1;
        $normalized = $this->normalizer->normalize($value, 'ITEM');
        if ($startsWithDigit) {
            $normalized = preg_replace('/^FIELD_/', '', $normalized) ?? $normalized;
        }

        return strtolower(str_replace('_', '-', $normalized));
    }
}
