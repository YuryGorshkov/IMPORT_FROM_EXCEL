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

    public function testAcceptsFileProperty(): void
    {
        (new MappingValidator())->validate([[
            'column' => 'A',
            'target' => 'PROPERTY:MORE_PHOTO',
            'property_type' => 'F',
            'multiple' => true,
        ]]);

        self::addToAssertionCount(1);
    }

    public function testRejectsUnsupportedPropertyType(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Unsupported property type');

        (new MappingValidator())->validate([[
            'column' => 'A',
            'target' => 'PROPERTY:PHOTO',
            'property_type' => 'PHP',
        ]]);
    }

    public function testAcceptsSectionLevels(): void
    {
        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => 'SECTION:1:NAME'],
            ['column' => 'B', 'target' => 'SECTION:1:CODE'],
            ['column' => 'C', 'target' => 'SECTION:2:NAME'],
        ]);

        self::addToAssertionCount(1);
    }

    public function testRejectsSectionLevelAboveLimit(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Unsupported section field');

        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => 'SECTION:11:NAME'],
        ]);
    }

    public function testRejectsUnsupportedSectionField(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Unsupported section field');

        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => 'SECTION:1:UNKNOWN'],
        ]);
    }

    public function testRejectsNamedHierarchyTogetherWithRawSectionId(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('cannot be combined');

        (new MappingValidator())->validate([
            ['column' => 'A', 'target' => 'FIELD:IBLOCK_SECTION_ID'],
            ['column' => 'B', 'target' => 'SECTION:1:NAME'],
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
