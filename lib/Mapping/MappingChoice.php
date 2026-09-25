<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Mapping;

final class MappingChoice
{
    public const PROPERTY_VALUE = 'PROPERTY:S:0';
    public const PROPERTY_MULTIPLE = 'PROPERTY:S:1';
    public const PROPERTY_FILE = 'PROPERTY:F:0';
    public const PROPERTY_FILE_MULTIPLE = 'PROPERTY:F:1';

    public static function fromRule(array $rule): string
    {
        $target = strtoupper(trim((string) ($rule['target'] ?? '')));
        if (!str_starts_with($target, 'PROPERTY:')) {
            return $target;
        }

        $type = strtoupper((string) ($rule['property_type'] ?? 'S')) === 'F' ? 'F' : 'S';
        $multiple = !empty($rule['multiple']) ? '1' : '0';

        return 'PROPERTY:' . $type . ':' . $multiple;
    }

    /**
     * @return array{target: string, code: string, property_type?: string, multiple?: bool}
     */
    public static function decode(string $choice, string $propertyCode): array
    {
        $choice = strtoupper(trim($choice));
        if (preg_match('/^PROPERTY:([SF]):([01])$/', $choice, $matches) === 1) {
            $propertyCode = strtoupper(trim($propertyCode));
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $propertyCode) !== 1) {
                throw new MappingException('Property code must contain only A-Z, digits and underscore.');
            }

            return [
                'target' => 'PROPERTY:' . $propertyCode,
                'code' => $propertyCode,
                'property_type' => $matches[1],
                'multiple' => $matches[2] === '1',
            ];
        }

        if (preg_match('/^FIELD:([A-Z][A-Z0-9_]*)$/', $choice, $matches) === 1) {
            if (!ElementFieldCatalog::isSupported($matches[1])) {
                throw new MappingException(sprintf('Unsupported element field %s.', $matches[1]));
            }

            return ['target' => $choice, 'code' => $matches[1]];
        }

        $section = SectionPath::parseTarget($choice);
        if ($section !== null) {
            return ['target' => $choice, 'code' => $section['field']];
        }

        throw new MappingException(sprintf('Unsupported mapping choice %s.', $choice));
    }
}
