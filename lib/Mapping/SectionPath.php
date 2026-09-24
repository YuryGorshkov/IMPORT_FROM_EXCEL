<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class SectionPath
{
    public const MAX_LEVEL = 10;

    public static function target(int $level, string $field = 'NAME'): string
    {
        return 'SECTION:' . $level . ':' . strtoupper($field);
    }

    public static function isTarget(string $target): bool
    {
        return self::parseTarget($target) !== null;
    }

    public static function levelFromTarget(string $target): ?int
    {
        return self::parseTarget($target)['level'] ?? null;
    }

    public static function fieldFromTarget(string $target): ?string
    {
        return self::parseTarget($target)['field'] ?? null;
    }

    /**
     * @return array{level: int, field: string}|null
     */
    public static function parseTarget(string $target): ?array
    {
        if (preg_match('/^SECTION:([1-9][0-9]*)(?::([A-Z][A-Z0-9_]*))?$/', strtoupper($target), $matches) !== 1) {
            return null;
        }

        $level = (int) $matches[1];
        $field = $matches[2] ?? 'NAME';
        if ($level > self::MAX_LEVEL || !SectionFieldCatalog::isSupported($field)) {
            return null;
        }

        return ['level' => $level, 'field' => $field];
    }

    public static function levelFromHeaderCode(string $code): ?int
    {
        $code = strtoupper(trim($code));
        $matchesPattern = preg_match(
            '/^(?:RAZDEL|PODRAZDEL|KATEGORIYA|KATEGORIIA|SECTION)_([1-9][0-9]*)(?:_[A-Z0-9]+)*_(?:UROVNYA|UROVEN|LEVEL)$/',
            $code,
            $matches
        );
        if ($matchesPattern !== 1) {
            return null;
        }

        $level = (int) $matches[1];
        return $level <= self::MAX_LEVEL ? $level : null;
    }

    public static function upgradeLegacyMapping(array $mapping): array
    {
        foreach ($mapping as &$rule) {
            if (!is_array($rule)) {
                continue;
            }
            $target = strtoupper((string) ($rule['target'] ?? ''));
            if (preg_match('/^SECTION:([1-9][0-9]*)$/', $target, $matches) === 1) {
                $rule['target'] = self::target((int) $matches[1]);
                $rule['code'] = 'NAME';
                continue;
            }
            if (str_starts_with($target, 'FIELD:')) {
                $fieldCode = substr($target, 6);
                if (!ElementFieldCatalog::isSupported($fieldCode)) {
                    $rule['target'] = 'PROPERTY:' . $fieldCode;
                    $rule['code'] = $fieldCode;
                }
                continue;
            }
            if (!str_starts_with($target, 'PROPERTY:')) {
                continue;
            }
            $level = self::levelFromHeaderCode(substr($target, 9));
            if ($level === null) {
                continue;
            }
            $rule['target'] = self::target($level);
            $rule['code'] = 'NAME';
        }
        unset($rule);

        return $mapping;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $levels): array
    {
        $normalized = [];
        for ($level = 1; $level <= self::MAX_LEVEL; $level++) {
            $value = $levels[$level] ?? $levels[(string) $level] ?? null;
            if ($value !== null && !is_array($value) && !is_scalar($value)) {
                throw new MappingException(sprintf('Section level %d must contain fields.', $level));
            }
            $fields = is_array($value) ? $value : ['NAME' => $value];
            $section = [];
            foreach ($fields as $field => $fieldValue) {
                $field = strtoupper((string) $field);
                if (!SectionFieldCatalog::isSupported($field)) {
                    continue;
                }
                if ($fieldValue !== null && !is_scalar($fieldValue)) {
                    throw new MappingException(sprintf(
                        'Section level %d field %s must contain a scalar value.',
                        $level,
                        $field
                    ));
                }
                if (is_string($fieldValue)) {
                    $fieldValue = trim($fieldValue);
                }
                if ($fieldValue === null || $fieldValue === '') {
                    continue;
                }
                $section[$field] = $fieldValue;
            }
            if ($section === []) {
                for ($next = $level + 1; $next <= self::MAX_LEVEL; $next++) {
                    $nextValue = $levels[$next] ?? $levels[(string) $next] ?? null;
                    if (self::levelHasValue($nextValue)) {
                        throw new MappingException(sprintf(
                            'Section level %d is filled while level %d is empty.',
                            $next,
                            $level
                        ));
                    }
                }
                break;
            }
            $normalized[] = $section;
        }

        return $normalized;
    }

    private static function levelHasValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $fieldValue) {
                if (is_scalar($fieldValue) && trim((string) $fieldValue) !== '') {
                    return true;
                }
            }
            return false;
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }
}
