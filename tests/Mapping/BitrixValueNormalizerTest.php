<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Mapping\BitrixValueNormalizer;
use WebEnot\ImportExcel\Mapping\MappingException;

final class BitrixValueNormalizerTest extends TestCase
{
    private BitrixValueNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new BitrixValueNormalizer();
    }

    public function testNormalizesTypedElementFields(): void
    {
        self::assertSame(250, $this->normalizer->element('SORT', '250'));
        self::assertSame('Y', $this->normalizer->element('ACTIVE', 'да'));
        self::assertSame(
            '25.09.2026 14:30:00',
            $this->normalizer->element('DATE_ACTIVE_FROM', '2026-09-25 14:30:00')
        );
        self::assertSame('html', $this->normalizer->element('DETAIL_TEXT_TYPE', 'HTML'));
    }

    public function testNormalizesSectionAndNumberPropertyValues(): void
    {
        self::assertSame(12, $this->normalizer->section('ID', '12'));
        self::assertSame(3.5, $this->normalizer->property('N', '3,5'));
        self::assertSame('3,5', $this->normalizer->property('S', '3,5'));
    }

    public function testRejectsFractionalSortValue(): void
    {
        $this->expectException(MappingException::class);
        $this->normalizer->element('SORT', '10,5');
    }

    public function testRejectsInvalidDate(): void
    {
        $this->expectException(MappingException::class);
        $this->normalizer->element('DATE_ACTIVE_TO', 'not-a-date');
    }
}
