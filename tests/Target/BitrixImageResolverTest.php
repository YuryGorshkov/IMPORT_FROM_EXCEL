<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Target;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Target\BitrixImageResolver;

final class BitrixImageResolverTest extends TestCase
{
    #[DataProvider('invalidSources')]
    public function testRejectsUnsupportedSources(mixed $source): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BitrixImageResolver())->resolve($source);
    }

    public static function invalidSources(): array
    {
        return [
            'empty value' => [''],
            'local arbitrary path' => ['C:\\temp\\picture.jpg'],
            'unsupported protocol' => ['ftp://example.test/picture.jpg'],
            'prepared file array' => [['tmp_name' => '/tmp/picture.jpg']],
        ];
    }
}
