<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Mapping\TargetNotation;

final class TargetNotationTest extends TestCase
{
    public function testFormatsBitrixWriteTargets(): void
    {
        self::assertSame(
            '$arFields["PROPERTY_VALUES"]["ARTIKUL"]',
            TargetNotation::write('PROPERTY:ARTIKUL')
        );
        self::assertSame('$arFields["NAME"]', TargetNotation::write('FIELD:NAME'));
        self::assertSame('$arSection["DETAIL_PICTURE"]', TargetNotation::write('SECTION:2:DETAIL_PICTURE'));
    }

    public function testFormatsBitrixFilterTargets(): void
    {
        self::assertSame('$arFilter["PROPERTY_ARTIKUL"]', TargetNotation::filter('PROPERTY:ARTIKUL'));
        self::assertSame('$arFilter["XML_ID"]', TargetNotation::filter('FIELD:XML_ID'));
    }
}
