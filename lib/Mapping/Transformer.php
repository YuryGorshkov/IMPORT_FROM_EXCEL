<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class Transformer
{
    public function apply(mixed $value, array $pipeline): mixed
    {
        foreach ($pipeline as $step) {
            if (!is_array($step) || !isset($step['type'])) {
                throw new MappingException('Each transform must be an object with a type.');
            }
            $value = $this->applyStep($value, $step);
        }

        return $value;
    }

    private function applyStep(mixed $value, array $step): mixed
    {
        $type = strtolower((string) $step['type']);
        return match ($type) {
            'trim' => is_string($value) ? trim($value) : $value,
            'lower' => is_string($value) ? mb_strtolower($value) : $value,
            'upper' => is_string($value) ? mb_strtoupper($value) : $value,
            'replace' => str_replace((string) ($step['search'] ?? ''), (string) ($step['replace'] ?? ''), (string) $value),
            'prepend' => (string) ($step['value'] ?? '') . (string) $value,
            'append' => (string) $value . (string) ($step['value'] ?? ''),
            'number' => $this->toNumber($value, $step),
            'boolean' => $this->toBoolean($value, $step),
            'date' => $this->toDate($value, $step),
            'split' => $this->split($value, $step),
            'join' => is_array($value) ? implode((string) ($step['separator'] ?? ', '), $value) : $value,
            'default' => $this->isEmpty($value) ? ($step['value'] ?? null) : $value,
            default => throw new MappingException(sprintf('Unsupported transform "%s".', $type)),
        };
    }

    private function toNumber(mixed $value, array $step): int|float|null
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        $decimal = (string) ($step['decimal_separator'] ?? ',');
        $thousands = (string) ($step['thousands_separator'] ?? ' ');
        $normalized = str_replace([$thousands, $decimal], ['', '.'], trim((string) $value));
        if (!is_numeric($normalized)) {
            throw new MappingException(sprintf('Value "%s" is not numeric.', (string) $value));
        }
        $precision = isset($step['precision']) ? max(0, (int) $step['precision']) : null;
        $number = (float) $normalized;
        return $precision === null ? $number : round($number, $precision);
    }

    private function toBoolean(mixed $value, array $step): string
    {
        $truthy = array_map('mb_strtolower', (array) ($step['true_values'] ?? ['1', 'y', 'yes', 'да', 'true']));
        return in_array(mb_strtolower(trim((string) $value)), $truthy, true) ? 'Y' : 'N';
    }

    private function toDate(mixed $value, array $step): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable((string) $value);
        } catch (\Throwable $exception) {
            throw new MappingException(sprintf('Value "%s" is not a valid date.', (string) $value), 0, $exception);
        }
        return $date->format((string) ($step['format'] ?? 'd.m.Y H:i:s'));
    }

    private function split(mixed $value, array $step): array
    {
        if ($this->isEmpty($value)) {
            return [];
        }
        $separator = (string) ($step['separator'] ?? ',');
        if ($separator === '') {
            throw new MappingException('Split separator cannot be empty.');
        }
        return array_values(array_filter(array_map('trim', explode($separator, (string) $value)), static fn(string $item): bool => $item !== ''));
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
