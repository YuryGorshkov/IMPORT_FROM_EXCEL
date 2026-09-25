<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Import;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Import\SheetSelector;

final class SheetSelectorTest extends TestCase
{
    public function testKeepsExplicitUserChoice(): void
    {
        self::assertSame(
            'Каталог 2',
            (new SheetSelector())->select(['Каталог 1', 'Каталог 2'], 'Каталог 2', 'Каталог 1')
        );
    }

    public function testUsesProfileSheetWhenItExists(): void
    {
        self::assertSame(
            'Каталог 1',
            (new SheetSelector())->select(['Каталог 1', 'Каталог 2'], '', 'Каталог 1')
        );
    }

    public function testSelectsTheOnlySheetAutomatically(): void
    {
        self::assertSame('Лист1', (new SheetSelector())->select(['Лист1']));
    }

    public function testDoesNotSilentlyChooseFirstOfSeveralSheets(): void
    {
        self::assertSame('', (new SheetSelector())->select(['Каталог 1', 'Каталог 2']));
    }
}
