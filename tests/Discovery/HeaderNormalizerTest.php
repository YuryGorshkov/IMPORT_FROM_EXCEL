<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;

final class HeaderNormalizerTest extends TestCase
{
    public function testRussianPropertyNameBecomesUpperSnakeCase(): void
    {
        self::assertSame('MOSHCHNOST_DVIGATELYA', (new HeaderNormalizer())->normalize('Мощность двигателя'));
    }

    public function testEverySourceHeaderGetsItsOwnLiteralLatinCode(): void
    {
        $normalizer = new HeaderNormalizer();

        self::assertSame('ARTIKUL', $normalizer->normalize('Артикул'));
        self::assertSame('ARTSRIKUL', $normalizer->normalize('Артсрикул'));
        self::assertSame('GOVNOTIKUL', $normalizer->normalize('Говнотикул'));
    }

    public function testPunctuationAndRepeatedSpacesAreCollapsed(): void
    {
        self::assertSame('NOMINALNOE_NAPRYAZHENIE_V', (new HeaderNormalizer())->normalize(' Номинальное  напряжение, В '));
    }

    public function testNumericPrefixGetsSafePrefix(): void
    {
        self::assertSame('FIELD_100_NAGRUZKA', (new HeaderNormalizer())->normalize('100% нагрузка'));
    }
}
