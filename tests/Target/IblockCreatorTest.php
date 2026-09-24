<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Target;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;
use WebEnot\ImportExcel\Target\IblockCreator;

final class IblockCreatorTest extends TestCase
{
    public function testSuggestsSafeTransliteratedCode(): void
    {
        $creator = new IblockCreator(new HeaderNormalizer());

        self::assertSame('katalog_dizelnykh_generatorov', $creator->suggestCode('Каталог дизельных генераторов'));
    }
}
