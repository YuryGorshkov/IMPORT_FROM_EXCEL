<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Discovery;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Discovery\ColumnDiscoveryService;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;
use WebEnot\ImportExcel\Reader\SpreadsheetReader;
use WebEnot\ImportExcel\Security\SourceFilePolicy;

final class ColumnDiscoveryServiceTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webenot_' . bin2hex(random_bytes(8)) . '.xlsx';
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['Название', 'Раздел 1-го уровня', 'Раздел 2-го уровня', 'Мощность двигателя', 'Мощность двигателя'],
            ['ДГУ 100', 'Генераторы', 'Дизельные генераторы', 100, 110],
        ]);
        (new Xlsx($book))->save($this->file);
        $book->disconnectWorksheets();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testDiscoversSystemFieldAndUniquePropertyCodes(): void
    {
        $reader = new SpreadsheetReader(new SourceFilePolicy());
        $service = new ColumnDiscoveryService($reader, new HeaderNormalizer());
        $mapping = $service->discover($this->file, null);

        self::assertSame('FIELD:NAME', $mapping[0]['target']);
        self::assertSame('SECTION:1:NAME', $mapping[1]['target']);
        self::assertSame('SECTION:2:NAME', $mapping[2]['target']);
        self::assertSame('PROPERTY:MOSHCHNOST_DVIGATELYA', $mapping[3]['target']);
        self::assertSame('PROPERTY:MOSHCHNOST_DVIGATELYA_2', $mapping[4]['target']);
        self::assertSame('Мощность двигателя', $mapping[3]['label']);
    }
}
