<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Target;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;
use WebEnot\ImportExcel\Target\ElementCodeGenerator;

final class ElementCodeGeneratorTest extends TestCase
{
    public function testGeneratesBitrixStyleCodeFromRussianName(): void
    {
        $generator = new ElementCodeGenerator(new HeaderNormalizer());

        self::assertSame(
            'rukav-promyshlennyy-chem-master',
            $generator->generate('Рукав промышленный CHEM MASTER')
        );
    }

    public function testKeepsNumericArticleWithoutPropertyPrefix(): void
    {
        $generator = new ElementCodeGenerator(new HeaderNormalizer());

        self::assertSame('1sn-5-dlp', $generator->generate('1SN-5-DLP'));
    }
}
