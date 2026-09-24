<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Mapping\MappingException;
use WebEnot\ImportExcel\Mapping\SectionPath;

final class SectionPathTest extends TestCase
{
    public function testNormalizesContiguousHierarchy(): void
    {
        self::assertSame(
            ['Гидравлические рукава', 'Длинные гидравлические рукава'],
            SectionPath::normalize([
                1 => ' Гидравлические рукава ',
                2 => ' Длинные гидравлические рукава ',
                3 => '',
            ])
        );
    }

    public function testRejectsGapInHierarchy(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('level 2 is filled while level 1 is empty');

        SectionPath::normalize([1 => '', 2 => 'Длинные гидравлические рукава']);
    }

    public function testUpgradesLegacySectionProperties(): void
    {
        $mapping = SectionPath::upgradeLegacyMapping([
            ['column' => 'B', 'code' => 'RAZDEL_1_GO_UROVNYA', 'target' => 'PROPERTY:RAZDEL_1_GO_UROVNYA'],
            ['column' => 'C', 'code' => 'RAZDEL_2_GO_UROVNYA', 'target' => 'PROPERTY:RAZDEL_2_GO_UROVNYA'],
        ]);

        self::assertSame('SECTION:1', $mapping[0]['target']);
        self::assertSame('SECTION:2', $mapping[1]['target']);
    }

    public function testConvertsUnsupportedLegacyElementFieldToProperty(): void
    {
        $mapping = SectionPath::upgradeLegacyMapping([
            ['column' => 'A', 'code' => 'ARTIKUL', 'target' => 'FIELD:ARTIKUL'],
        ]);

        self::assertSame('PROPERTY:ARTIKUL', $mapping[0]['target']);
    }
}
