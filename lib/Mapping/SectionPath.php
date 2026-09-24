<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class SectionPath
{
    public const MAX_LEVEL = 10;

    public static function target(int $level): string
    {
        return 'SECTION:' . $level;
    }

    public static function isTarget(string $target): bool
    {
        return self::levelFromTarget($target) !== null;
    }

    public static function levelFromTarget(string $target): ?int
    {
        if (preg_match('/^SECTION:([1-9][0-9]*)$/', strtoupper($target), $matches) !== 1) {
            return null;
        }

        $level = (int) $matches[1];
        return $level <= self::MAX_LEVEL ? $level : null;
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
            $rule['code'] = (string) $level;
        }
        unset($rule);

        return $mapping;
    }

    /**
     * @return list<string>
     */
    public static function normalize(array $levels): array
    {
        $normalized = [];
        for ($level = 1; $level <= self::MAX_LEVEL; $level++) {
            $value = $levels[$level] ?? $levels[(string) $level] ?? null;
            if ($value !== null && !is_scalar($value)) {
                throw new MappingException(sprintf('Section level %d must contain text.', $level));
            }
            $name = trim((string) $value);
            if ($name === '') {
                for ($next = $level + 1; $next <= self::MAX_LEVEL; $next++) {
                    $nextValue = $levels[$next] ?? $levels[(string) $next] ?? null;
                    if (trim(is_scalar($nextValue) ? (string) $nextValue : '') !== '') {
                        throw new MappingException(sprintf(
                            'Section level %d is filled while level %d is empty.',
                            $next,
                            $level
                        ));
                    }
                }
                break;
            }
            $normalized[] = $name;
        }

        return $normalized;
    }
}
