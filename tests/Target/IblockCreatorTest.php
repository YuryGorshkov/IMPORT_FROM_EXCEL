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

    public function testUsesFullNestedSectionPathInUrls(): void
    {
        $creator = new IblockCreator(new HeaderNormalizer());

        self::assertSame(
            '#SITE_DIR#/#IBLOCK_CODE#/#SECTION_CODE_PATH#/',
            $creator->urlTemplates()['SECTION_PAGE_URL']
        );
        self::assertSame(
            '#SITE_DIR#/#IBLOCK_CODE#/#SECTION_CODE_PATH#/#ELEMENT_CODE#/',
            $creator->urlTemplates()['DETAIL_PAGE_URL']
        );
    }
}
