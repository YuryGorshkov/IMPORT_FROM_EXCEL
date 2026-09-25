<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Domain\Row;
use WebEnot\ImportExcel\Mapping\MappingValidator;
use WebEnot\ImportExcel\Mapping\RowMapper;
use WebEnot\ImportExcel\Mapping\Transformer;

final class RowMapperTest extends TestCase
{
    public function testMapsFieldsAndProperties(): void
    {
        $mapper = new RowMapper(new Transformer(), new MappingValidator());
        $mapped = $mapper->map(new Row(2, [
            'A' => ' X-1 ',
            'B' => ' Дизель ',
            'C' => ' Гидравлические рукава ',
            'D' => ' Длинные гидравлические рукава ',
        ]), [
            ['column' => 'A', 'target' => 'PROPERTY:ARTIKUL', 'property_type' => 'S', 'multiple' => false, 'required' => true, 'transforms' => [['type' => 'trim']]],
            ['column' => 'B', 'target' => 'FIELD:NAME', 'required' => true, 'transforms' => [['type' => 'trim']]],
            ['column' => 'C', 'target' => 'SECTION:1:NAME', 'transforms' => [['type' => 'trim']]],
            ['column' => 'D', 'target' => 'SECTION:2:NAME', 'transforms' => [['type' => 'trim']]],
        ]);
        self::assertSame('X-1', $mapped->properties['ARTIKUL']);
        self::assertSame(
            ['property_type' => 'S', 'multiple' => false],
            $mapped->propertyDefinitions['ARTIKUL']
        );
        self::assertSame('Дизель', $mapped->fields['NAME']);
        self::assertSame(['NAME' => 'Гидравлические рукава'], $mapped->sections[1]);
        self::assertSame(['NAME' => 'Длинные гидравлические рукава'], $mapped->sections[2]);
    }

    public function testMapsSeveralFieldsOfTheSameSectionLevel(): void
    {
        $mapper = new RowMapper(new Transformer(), new MappingValidator());
        $mapped = $mapper->map(new Row(2, [
            'A' => 'Рукава',
            'B' => 'hoses',
            'C' => 'Описание раздела',
        ]), [
            ['column' => 'A', 'target' => 'SECTION:1:NAME'],
            ['column' => 'B', 'target' => 'SECTION:1:CODE'],
            ['column' => 'C', 'target' => 'SECTION:1:DESCRIPTION'],
        ]);

        self::assertSame([
            'NAME' => 'Рукава',
            'CODE' => 'hoses',
            'DESCRIPTION' => 'Описание раздела',
        ], $mapped->sections[1]);
    }

    public function testKeepsFilePropertyDefinitionWithTheMappedValue(): void
    {
        $mapper = new RowMapper(new Transformer(), new MappingValidator());
        $mapped = $mapper->map(new Row(2, [
            'A' => "https://cdn.example.test/one.jpg\nhttps://cdn.example.test/two.jpg",
        ]), [[
            'column' => 'A',
            'target' => 'PROPERTY:MORE_PHOTO',
            'property_type' => 'F',
            'multiple' => true,
        ]]);

        self::assertSame('F', $mapped->propertyDefinitions['MORE_PHOTO']['property_type']);
        self::assertTrue($mapped->propertyDefinitions['MORE_PHOTO']['multiple']);
    }

    public function testUsesExcelHyperlinkForImageDestination(): void
    {
        $mapper = new RowMapper(new Transformer(), new MappingValidator());
        $mapped = $mapper->map(new Row(
            2,
            ['A' => 'Открыть изображение'],
            ['A' => 'https://cdn.example.test/photo.png']
        ), [[
            'column' => 'A',
            'target' => 'FIELD:DETAIL_PICTURE',
        ]]);

        self::assertSame('https://cdn.example.test/photo.png', $mapped->fields['DETAIL_PICTURE']);
    }

    public function testNormalizesTypedBitrixDestinations(): void
    {
        $mapper = new RowMapper(new Transformer(), new MappingValidator());
        $mapped = $mapper->map(new Row(2, [
            'A' => '500',
            'B' => '2026-09-25 12:30:00',
            'C' => '12,5',
        ]), [
            ['column' => 'A', 'target' => 'FIELD:SORT'],
            ['column' => 'B', 'target' => 'FIELD:DATE_ACTIVE_FROM'],
            ['column' => 'C', 'target' => 'PROPERTY:PRICE', 'property_type' => 'N'],
        ]);

        self::assertSame(500, $mapped->fields['SORT']);
        self::assertSame('25.09.2026 12:30:00', $mapped->fields['DATE_ACTIVE_FROM']);
        self::assertSame(12.5, $mapped->properties['PRICE']);
    }
}
