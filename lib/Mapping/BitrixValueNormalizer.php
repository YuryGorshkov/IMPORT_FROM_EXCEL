<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class BitrixValueNormalizer
{
    public function element(string $field, mixed $value): mixed
    {
        $field = strtoupper($field);

        return match ($field) {
            'ACTIVE' => $this->boolean($value, 'Element activity'),
            'SORT' => $this->integer($value, 'Element sort'),
            'DATE_ACTIVE_FROM', 'DATE_ACTIVE_TO' => $this->dateTime($value, $field),
            'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT_TYPE' => $this->textType($value, $field),
            default => $value,
        };
    }

    public function section(string $field, mixed $value): mixed
    {
        $field = strtoupper($field);

        return match ($field) {
            'ID' => $this->positiveInteger($value, 'Section ID'),
            'ACTIVE' => $this->boolean($value, 'Section activity'),
            'SORT' => $this->integer($value, 'Section sort'),
            'DESCRIPTION_TYPE' => $this->textType($value, $field),
            default => $value,
        };
    }

    public function property(string $propertyType, mixed $value): mixed
    {
        return strtoupper($propertyType) === 'N'
            ? $this->number($value, 'Number property')
            : $value;
    }

    private function boolean(mixed $value, string $label): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Y' : 'N';
        }

        $normalized = mb_strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'y', 'yes', 'да', 'д', 'true'], true)) {
            return 'Y';
        }
        if (in_array($normalized, ['0', 'n', 'no', 'нет', 'н', 'false'], true)) {
            return 'N';
        }

        throw new MappingException(sprintf('%s must be Yes/No, Y/N or 1/0.', $label));
    }

    private function integer(mixed $value, string $label): ?int
    {
        $number = $this->number($value, $label);
        if ($number === null) {
            return null;
        }
        if (floor((float) $number) !== (float) $number) {
            throw new MappingException(sprintf('%s must be an integer.', $label));
        }

        return (int) $number;
    }

    private function positiveInteger(mixed $value, string $label): ?int
    {
        $integer = $this->integer($value, $label);
        if ($integer !== null && $integer < 1) {
            throw new MappingException(sprintf('%s must be greater than zero.', $label));
        }

        return $integer;
    }

    private function number(mixed $value, string $label): int|float|null
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', trim((string) $value));
        $normalized = str_replace(',', '.', $normalized);
        if (!is_numeric($normalized)) {
            throw new MappingException(sprintf('%s must contain a number.', $label));
        }

        $number = (float) $normalized;
        return floor($number) === $number ? (int) $number : $number;
    }

    private function dateTime(mixed $value, string $label): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y H:i:s');
        }

        try {
            $date = new \DateTimeImmutable(trim((string) $value));
        } catch (\Throwable $exception) {
            throw new MappingException(sprintf('%s must contain a valid date and time.', $label), 0, $exception);
        }

        return $date->format('d.m.Y H:i:s');
    }

    private function textType(mixed $value, string $label): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $type = mb_strtolower(trim((string) $value));
        if (!in_array($type, ['text', 'html'], true)) {
            throw new MappingException(sprintf('%s must be text or html.', $label));
        }

        return $type;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
