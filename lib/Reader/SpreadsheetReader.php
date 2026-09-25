<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Reader;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use WebEnot\ImportExcel\Domain\Row;
use WebEnot\ImportExcel\Security\SourceFilePolicy;

final class SpreadsheetReader implements ReaderInterface
{
    public function __construct(private readonly SourceFilePolicy $filePolicy)
    {
    }

    public function read(string $path, ?string $sheet, int $startRow, int $limit, array $options = []): iterable
    {
        $path = $this->filePolicy->validate($path);
        $headerRow = max(1, (int) ($options['header_row'] ?? 1));
        $filter = new ChunkReadFilter();
        $filter->configure($startRow, $limit, $headerRow);

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadFilter($filter);
        $reader->setReadDataOnly(false);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        if ($reader instanceof Csv) {
            $this->configureCsv($reader, $options);
        }

        $workbook = $reader->load($path);
        try {
            $worksheet = $sheet !== null && $sheet !== '' ? $workbook->getSheetByName($sheet) : $workbook->getSheet(0);
            if ($worksheet === null) {
                throw new \RuntimeException(sprintf('Worksheet "%s" was not found.', (string) $sheet));
            }

            $lastRow = min($worksheet->getHighestDataRow(), $startRow + $limit - 1);
            $lastColumn = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
            for ($rowNumber = $startRow; $rowNumber <= $lastRow; $rowNumber++) {
                $values = [];
                $links = [];
                $hasValue = false;
                for ($column = 1; $column <= $lastColumn; $column++) {
                    $cell = $worksheet->getCell([$column, $rowNumber]);
                    $value = $cell->getValue();
                    if ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }
                    if (is_numeric($value) && Date::isDateTime($cell)) {
                        $value = Date::excelToDateTimeObject((float) $value)->format(DATE_ATOM);
                    }
                    if ($value !== null && $value !== '') {
                        $hasValue = true;
                    }
                    $columnName = Coordinate::stringFromColumnIndex($column);
                    $values[$columnName] = $value;
                    $hyperlink = trim((string) $cell->getHyperlink()->getUrl());
                    if (preg_match('#^https?://#i', $hyperlink) === 1) {
                        $links[$columnName] = $hyperlink;
                    }
                }

                if ($hasValue || ($options['include_empty_rows'] ?? false) === true) {
                    yield new Row($rowNumber, $values, $links);
                }
            }
        } finally {
            $workbook->disconnectWorksheets();
            unset($workbook);
        }
    }

    public function totalRows(string $path, ?string $sheet = null): int
    {
        $path = $this->filePolicy->validate($path);
        $reader = IOFactory::createReaderForFile($path);
        foreach ($reader->listWorksheetInfo($path) as $info) {
            if ($sheet === null || $sheet === '' || $info['worksheetName'] === $sheet) {
                return (int) $info['totalRows'];
            }
        }

        throw new \RuntimeException(sprintf('Worksheet "%s" was not found.', (string) $sheet));
    }

    public function preview(string $path, ?string $sheet = null, int $limit = 20, array $options = []): array
    {
        $startRow = max(1, (int) ($options['preview_start_row'] ?? 1));
        $previewOptions = $options;
        $previewOptions['header_row'] = $startRow;
        $previewOptions['include_empty_rows'] = true;
        $rows = [];
        foreach ($this->read($path, $sheet, $startRow, min(100, max(1, $limit)), $previewOptions) as $row) {
            $rows[] = ['row' => $row->number, 'cells' => $row->cells];
        }
        return $rows;
    }

    public function sheets(string $path): array
    {
        $path = $this->filePolicy->validate($path);
        return array_values(array_map(
            static fn(array $info): string => (string) $info['worksheetName'],
            IOFactory::createReaderForFile($path)->listWorksheetInfo($path)
        ));
    }

    private function configureCsv(Csv $reader, array $options): void
    {
        $reader->setInputEncoding((string) ($options['encoding'] ?? 'UTF-8'));
        $reader->setDelimiter((string) ($options['delimiter'] ?? ';'));
        $reader->setEnclosure((string) ($options['enclosure'] ?? '"'));
        $reader->setEscapeCharacter((string) ($options['escape'] ?? '\\'));
    }
}
