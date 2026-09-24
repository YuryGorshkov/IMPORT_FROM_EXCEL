<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Discovery\HeaderRowDetector;

final class HeaderRowDetectorTest extends TestCase
{
    public function testSuggestsStructuredHeaderInsteadOfTitleOrEmptyRow(): void
    {
        $rows = [
            ['row' => 1, 'cells' => ['A' => 'Прайс-лист поставщика', 'B' => null, 'C' => null]],
            ['row' => 2, 'cells' => ['A' => null, 'B' => null, 'C' => null]],
            ['row' => 3, 'cells' => ['A' => 'Артикул', 'B' => 'Название', 'C' => 'Цена']],
            ['row' => 4, 'cells' => ['A' => 'A-1', 'B' => 'Товар', 'C' => 1250]],
        ];

        self::assertSame(3, (new HeaderRowDetector())->detect($rows));
    }

    public function testUsesFallbackForEmptyPreview(): void
    {
        self::assertSame(7, (new HeaderRowDetector())->detect([], 7));
    }
}
