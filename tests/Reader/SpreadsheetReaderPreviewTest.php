<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Reader;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Reader\SpreadsheetReader;
use WebEnot\ImportExcel\Security\SourceFilePolicy;

final class SpreadsheetReaderPreviewTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webenot_' . bin2hex(random_bytes(8)) . '.xlsx';
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Каталог');
        $sheet->fromArray([
            ['Прайс-лист поставщика'],
            [],
            ['Артикул', 'Название', 'Цена'],
            ['A-1', 'Товар', 1250],
        ]);
        $book->createSheet()->setTitle('Остатки');
        (new Xlsx($book))->save($this->file);
        $book->disconnectWorksheets();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testPreviewStartsAtRequestedRowAndKeepsEmptyRows(): void
    {
        $reader = new SpreadsheetReader(new SourceFilePolicy());
        $rows = $reader->preview($this->file, 'Каталог', 4, [
            'header_row' => 3,
            'preview_start_row' => 1,
        ]);

        self::assertSame([1, 2, 3, 4], array_column($rows, 'row'));
        self::assertSame('Артикул', $rows[2]['cells']['A']);
    }

    public function testListsWorkbookSheets(): void
    {
        $reader = new SpreadsheetReader(new SourceFilePolicy());

        self::assertSame(['Каталог', 'Остатки'], $reader->sheets($this->file));
    }
}
