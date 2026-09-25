<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Discovery;

use WebEnot\ImportExcel\Reader\ReaderInterface;
use WebEnot\ImportExcel\Mapping\SectionPath;

final class ColumnDiscoveryService
{
    private const SYSTEM_FIELDS = [
        'NAME' => 'NAME',
        'NAZVANIE' => 'NAME',
        'NAIMENOVANIE' => 'NAME',
        'XML_ID' => 'XML_ID',
        'XMLID' => 'XML_ID',
        'VNESHNIY_KOD' => 'XML_ID',
        'CODE' => 'CODE',
        'KOD' => 'CODE',
        'SIMVOLNYY_KOD' => 'CODE',
        'ACTIVE' => 'ACTIVE',
        'AKTIVNOST' => 'ACTIVE',
        'SORT' => 'SORT',
        'SORTIROVKA' => 'SORT',
    ];

    public function __construct(
        private readonly ReaderInterface $reader,
        private readonly HeaderNormalizer $normalizer,
    ) {
    }

    public function discover(string $path, ?string $sheet, int $headerRow = 1, array $options = []): array
    {
        $options['header_row'] = $headerRow;
        $rows = iterator_to_array($this->reader->read($path, $sheet, $headerRow, 1, $options), false);
        if ($rows === []) {
            throw new \RuntimeException(sprintf('Header row %d is empty.', $headerRow));
        }

        $usedCodes = [];
        $columns = [];
        foreach ($rows[0]->cells as $column => $rawHeader) {
            $label = trim((string) $rawHeader);
            if ($label === '') {
                continue;
            }
            $baseCode = $this->normalizer->normalize($label, 'COLUMN_' . $column);
            $code = $baseCode;
            $suffix = 2;
            while (isset($usedCodes[$code])) {
                $suffixText = '_' . $suffix++;
                $code = substr($baseCode, 0, 50 - strlen($suffixText)) . $suffixText;
            }
            $usedCodes[$code] = true;
            $systemField = self::SYSTEM_FIELDS[$baseCode] ?? null;
            $sectionLevel = SectionPath::levelFromHeaderCode($baseCode);
            $isCustomProperty = $sectionLevel === null && $systemField === null;
            $isImageProperty = $isCustomProperty && $this->isImageHeader($baseCode);
            $columns[] = [
                'column' => $column,
                'label' => $label,
                'code' => $code,
                'target' => $sectionLevel !== null
                    ? SectionPath::target($sectionLevel, 'NAME')
                    : ($systemField !== null ? 'FIELD:' . $systemField : 'PROPERTY:' . $code),
                'required' => $systemField === 'NAME',
                'transforms' => [['type' => 'trim']],
            ] + ($isCustomProperty
                ? [
                    'property_type' => $isImageProperty ? 'F' : 'S',
                    'multiple' => $isImageProperty && $this->isGalleryHeader($baseCode),
                ]
                : []);
        }

        if ($columns === []) {
            throw new \RuntimeException(sprintf('Header row %d does not contain named columns.', $headerRow));
        }
        return $columns;
    }

    private function isImageHeader(string $code): bool
    {
        return preg_match('/(?:^|_)(?:KARTINK|IZOBRAZH|FOTO|PHOTO|IMAGE|PICTURE|GALERE)(?:[A-Z]*)(?:_|$)/', $code) === 1;
    }

    private function isGalleryHeader(string $code): bool
    {
        return preg_match('/(?:GALERE|GALLERY|MORE_PHOTO|FOTOGALERE)/', $code) === 1;
    }
}
