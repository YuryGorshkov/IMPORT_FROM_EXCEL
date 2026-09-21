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
        $mapped = $mapper->map(new Row(2, ['A' => ' X-1 ', 'B' => ' Дизель ']), [
            ['column' => 'A', 'target' => 'PROPERTY:ARTIKUL', 'required' => true, 'transforms' => [['type' => 'trim']]],
            ['column' => 'B', 'target' => 'FIELD:NAME', 'required' => true, 'transforms' => [['type' => 'trim']]],
        ]);
        self::assertSame('X-1', $mapped->properties['ARTIKUL']);
        self::assertSame('Дизель', $mapped->fields['NAME']);
    }
}
