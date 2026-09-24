<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Validation;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Validation\SourceStructureValidator;

final class SourceStructureValidatorTest extends TestCase
{
    public function testAcceptsSameHeadersWithCaseAndWhitespaceDifferences(): void
    {
        $validator = new SourceStructureValidator();
        $mapping = [
            ['column' => 'A', 'label' => 'Артикул'],
            ['column' => 'B', 'label' => 'Название товара'],
        ];

        self::assertSame([], $validator->differences($mapping, [
            'A' => ' АРТИКУЛ ',
            'B' => "Название   товара",
        ]));
    }

    public function testReportsChangedAndMissingColumns(): void
    {
        $validator = new SourceStructureValidator();
        $mapping = [
            ['column' => 'A', 'label' => 'Артикул'],
            ['column' => 'B', 'label' => 'Название'],
        ];
        $differences = $validator->differences($mapping, ['A' => 'Код']);

        self::assertSame('A', $differences[0]['column']);
        self::assertSame('Код', $differences[0]['actual']);
        self::assertSame('B', $differences[1]['column']);
        self::assertSame('', $differences[1]['actual']);
    }
}
