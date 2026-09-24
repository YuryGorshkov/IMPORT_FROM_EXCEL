<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Target;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;
use WebEnot\ImportExcel\Domain\ImportProfile;
use WebEnot\ImportExcel\Mapping\MappedRow;
use WebEnot\ImportExcel\Target\ElementCodeGenerator;
use WebEnot\ImportExcel\Target\IblockTarget;
use WebEnot\ImportExcel\Tests\Support\CodeCaptureGateway;

final class IblockTargetTest extends TestCase
{
    public function testGeneratesCodeFromName(): void
    {
        $target = $this->target($gateway = new CodeCaptureGateway());
        $result = $target->apply(
            $this->profile('name'),
            $this->row('ART-1', 'Насос дизельный'),
            true
        );

        self::assertSame('nasos-dizelnyy', $result->after['fields']['CODE']);
        self::assertSame('nasos-dizelnyy', $gateway->lastBaseCode);
    }

    public function testGeneratesCodeFromUniqueArticle(): void
    {
        $target = $this->target(new CodeCaptureGateway());
        $result = $target->apply(
            $this->profile('unique'),
            $this->row('1SN-5-DLP', 'Насос дизельный'),
            true
        );

        self::assertSame('1sn-5-dlp', $result->after['fields']['CODE']);
    }

    public function testDoesNotReplaceExistingCode(): void
    {
        $gateway = new CodeCaptureGateway();
        $gateway->existing = ['ID' => 42];
        $gateway->snapshots[42] = [
            'fields' => ['ID' => 42, 'CODE' => 'manual-code'],
            'properties' => [],
        ];
        $target = $this->target($gateway);
        $result = $target->apply(
            $this->profile('name'),
            $this->row('ART-1', 'Новое название'),
            true
        );

        self::assertArrayNotHasKey('CODE', $result->after['fields']);
        self::assertSame('', $gateway->lastBaseCode);
    }

    public function testResolvesNestedSectionPathWithoutCreatingItDuringDryRun(): void
    {
        $gateway = new CodeCaptureGateway();
        $target = $this->target($gateway);
        $row = new MappedRow(
            2,
            ['NAME' => 'Рукав'],
            ['ARTIKUL' => 'ART-1'],
            [],
            [
                1 => ['NAME' => 'Гидравлические рукава', 'CODE' => 'hoses'],
                2 => ['NAME' => 'Длинные гидравлические рукава'],
            ]
        );

        $result = $target->apply($this->profile('name'), $row, true);

        self::assertSame([
            'iblock_id' => 5,
            'levels' => [
                ['NAME' => 'Гидравлические рукава', 'CODE' => 'hoses'],
                ['NAME' => 'Длинные гидравлические рукава'],
            ],
            'create' => false,
        ], $gateway->sectionPaths[0]);
        self::assertSame(
            [
                ['NAME' => 'Гидравлические рукава', 'CODE' => 'hoses'],
                ['NAME' => 'Длинные гидравлические рукава'],
            ],
            $result->after['sections']
        );
    }

    public function testKeepsCreatedSectionIdsForRollbackAfterRealImport(): void
    {
        $gateway = new CodeCaptureGateway();
        $target = $this->target($gateway);
        $row = new MappedRow(
            2,
            ['NAME' => 'Рукав'],
            ['ARTIKUL' => 'ART-1'],
            [],
            [
                1 => ['NAME' => 'Гидравлические рукава'],
                2 => ['NAME' => 'Длинные гидравлические рукава'],
            ]
        );

        $result = $target->apply($this->profile('name'), $row, false);

        self::assertSame([70, 77], $result->after['created_section_ids']);
    }

    private function target(CodeCaptureGateway $gateway): IblockTarget
    {
        return new IblockTarget($gateway, new ElementCodeGenerator(new HeaderNormalizer()));
    }

    private function profile(string $codeSource): ImportProfile
    {
        return new ImportProfile(
            1,
            'Тест',
            5,
            [['column' => 'A', 'target' => 'PROPERTY:ARTIKUL']],
            ['unique_target' => 'PROPERTY:ARTIKUL', 'element_code_source' => $codeSource]
        );
    }

    private function row(string $article, string $name): MappedRow
    {
        return new MappedRow(2, ['NAME' => $name], ['ARTIKUL' => $article], []);
    }
}
