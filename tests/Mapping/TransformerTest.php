<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Mapping\MappingException;
use WebEnot\ImportExcel\Mapping\Transformer;

final class TransformerTest extends TestCase
{
    public function testSafePipeline(): void
    {
        $value = (new Transformer())->apply(' 12 345,67 ', [
            ['type' => 'trim'],
            ['type' => 'number', 'precision' => 2],
        ]);
        self::assertSame(12345.67, $value);
    }

    public function testArbitraryCodeCannotBeExecuted(): void
    {
        $this->expectException(MappingException::class);
        (new Transformer())->apply('value', [['type' => 'php', 'code' => 'system("id")']]);
    }
}
