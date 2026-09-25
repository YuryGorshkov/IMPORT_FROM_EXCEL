<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class MappingValidator
{
    private const TARGET_PATTERN = '/^(FIELD|PROPERTY):[A-Z][A-Z0-9_]*$|^SECTION:[1-9][0-9]*(?::[A-Z][A-Z0-9_]*)?$/';

    public function validate(array $mapping): void
    {
        if ($mapping === []) {
            throw new MappingException('The mapping cannot be empty.');
        }

        $targets = [];
        foreach ($mapping as $index => $rule) {
            if (!is_array($rule)) {
                throw new MappingException(sprintf('Mapping rule %d must be an object.', $index + 1));
            }
            $column = strtoupper((string) ($rule['column'] ?? ''));
            $target = strtoupper((string) ($rule['target'] ?? ''));
            if (!preg_match('/^[A-Z]{1,3}$/', $column)) {
                throw new MappingException(sprintf('Invalid source column in rule %d.', $index + 1));
            }
            if (!preg_match(self::TARGET_PATTERN, $target)) {
                throw new MappingException(sprintf('Invalid target "%s" in rule %d.', $target, $index + 1));
            }
            if (str_starts_with($target, 'FIELD:') && !ElementFieldCatalog::isSupported(substr($target, 6))) {
                throw new MappingException(sprintf('Unsupported element field "%s" in rule %d.', $target, $index + 1));
            }
            if (str_starts_with($target, 'PROPERTY:')) {
                $propertyType = strtoupper((string) ($rule['property_type'] ?? 'S'));
                if (!in_array($propertyType, ['S', 'N', 'L', 'F', 'E', 'G'], true)) {
                    throw new MappingException(sprintf(
                        'Unsupported property type "%s" in rule %d.',
                        $propertyType,
                        $index + 1
                    ));
                }
            }
            if (str_starts_with($target, 'SECTION:') && !SectionPath::isTarget($target)) {
                throw new MappingException(sprintf('Unsupported section field "%s" in rule %d.', $target, $index + 1));
            }
            if (isset($targets[$target])) {
                throw new MappingException(sprintf('Target "%s" is mapped more than once.', $target));
            }
            $targets[$target] = true;
        }
        if (isset($targets['FIELD:IBLOCK_SECTION_ID'])) {
            foreach (array_keys($targets) as $target) {
                if (str_starts_with($target, 'SECTION:')) {
                    throw new MappingException(
                        'FIELD:IBLOCK_SECTION_ID cannot be combined with a named section hierarchy.'
                    );
                }
            }
        }
    }
}
