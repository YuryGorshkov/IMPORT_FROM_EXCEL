<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Mapping\MappingException;
use WebEnot\ImportExcel\Mapping\MappingValidator;

final class MappingValidatorTest extends TestCase
{
    #[DataProvider('supportedSystemFields')]
    public function testAcceptsSupportedSystemFields(string $target): void
    {
        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => $target],
        ]);

        self::addToAssertionCount(1);
    }

    public function testRejectsArbitrarySystemField(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Unsupported element field "FIELD:ARTIKUL"');

        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => 'FIELD:ARTIKUL'],
        ]);
    }

    public function testAcceptsSectionLevels(): void
    {
        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => 'SECTION:1'],
            ['column' => 'B', 'target' => 'SECTION:2'],
        ]);

        self::addToAssertionCount(1);
    }

    public function testRejectsSectionLevelAboveLimit(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Unsupported section level');

        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => 'SECTION:11'],
        ]);
    }

    public function testRejectsNamedHierarchyTogetherWithRawSectionId(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('cannot be combined');

        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => 'FIELD:IBLOCK_SECTION_ID'],
            ['column' => 'B', 'target' => 'SECTION:1'],
        ]);
    }

    public static function supportedSystemFields(): array
    {
        return [
            'preview text' => ['FIELD:PREVIEW_TEXT'],
            'detail text' => ['FIELD:DETAIL_TEXT'],
            'preview picture' => ['FIELD:PREVIEW_PICTURE'],
            'detail picture' => ['FIELD:DETAIL_PICTURE'],
        ];
    }
}
