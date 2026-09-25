<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Mapping\MappingChoice;
use WebEnot\ImportExcel\Mapping\MappingException;

final class MappingChoiceTest extends TestCase
{
    public function testDecodesARegularProperty(): void
    {
        self::assertSame([
            'target' => 'PROPERTY:ARTIKUL',
            'code' => 'ARTIKUL',
            'property_type' => 'S',
            'multiple' => false,
            'create_if_missing' => true,
        ], MappingChoice::decode(MappingChoice::PROPERTY_VALUE, 'artikul'));
    }

    public function testDecodesAMultipleFileProperty(): void
    {
        self::assertSame([
            'target' => 'PROPERTY:GALLERY',
            'code' => 'GALLERY',
            'property_type' => 'F',
            'multiple' => true,
            'create_if_missing' => true,
        ], MappingChoice::decode(MappingChoice::PROPERTY_FILE_MULTIPLE, 'GALLERY'));
    }

    public function testDecodesAnExistingNumberProperty(): void
    {
        $choice = MappingChoice::existingProperty('price', 'N', false);

        self::assertSame('EXISTING_PROPERTY:N:0:PRICE', $choice);
        self::assertSame([
            'target' => 'PROPERTY:PRICE',
            'code' => 'PRICE',
            'property_type' => 'N',
            'multiple' => false,
            'create_if_missing' => false,
        ], MappingChoice::decode($choice, 'IGNORED'));
    }

    public function testDecodesElementAndNestedSectionFields(): void
    {
        self::assertSame(
            ['target' => 'FIELD:PREVIEW_PICTURE', 'code' => 'PREVIEW_PICTURE'],
            MappingChoice::decode('FIELD:PREVIEW_PICTURE', 'IGNORED')
        );
        self::assertSame(
            ['target' => 'SECTION:3:NAME', 'code' => 'NAME'],
            MappingChoice::decode('SECTION:3:NAME', 'IGNORED')
        );
    }

    public function testRejectsAnInvalidPropertyCode(): void
    {
        $this->expectException(MappingException::class);
        MappingChoice::decode(MappingChoice::PROPERTY_VALUE, 'НЕ КОД');
    }

    public function testBuildsAChoiceFromAnExistingRule(): void
    {
        self::assertSame(MappingChoice::PROPERTY_FILE_MULTIPLE, MappingChoice::fromRule([
            'target' => 'PROPERTY:PHOTO',
            'property_type' => 'F',
            'multiple' => true,
        ]));
        self::assertSame('SECTION:2:NAME', MappingChoice::fromRule([
            'target' => 'SECTION:2:NAME',
        ]));
        self::assertSame('EXISTING_PROPERTY:L:1:COLOR', MappingChoice::fromRule([
            'target' => 'PROPERTY:COLOR',
            'property_type' => 'L',
            'multiple' => true,
            'create_if_missing' => false,
        ]));
    }
}
