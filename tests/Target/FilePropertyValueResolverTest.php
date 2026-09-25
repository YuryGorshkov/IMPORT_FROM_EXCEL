<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Target;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Target\BitrixImageResolver;
use WebEnot\ImportExcel\Target\FilePropertyValueResolver;

final class FilePropertyValueResolverTest extends TestCase
{
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }
    }

    public function testPreparesOneDownloadedImageForSingleFileProperty(): void
    {
        $resolved = $this->resolver()->resolve('https://cdn.example.test/photo.jpg', false);
        $this->temporaryPaths = $resolved['temporary_paths'];

        self::assertFalse($resolved['empty']);
        self::assertSame('image/png', $resolved['value']['type']);
        self::assertArrayNotHasKey('n0', $resolved['value']);
    }

    public function testPreparesNewlineSeparatedImagesForMultipleProperty(): void
    {
        $resolved = $this->resolver()->resolve(
            "https://cdn.example.test/one.jpg\nhttps://cdn.example.test/two.jpg",
            true
        );
        $this->temporaryPaths = $resolved['temporary_paths'];

        self::assertFalse($resolved['empty']);
        self::assertCount(2, $resolved['value']);
        self::assertSame('image/png', $resolved['value']['n0']['VALUE']['type']);
        self::assertSame('image/png', $resolved['value']['n1']['VALUE']['type']);
    }

    public function testSkipsEmptyFilePropertyValue(): void
    {
        $resolved = $this->resolver()->resolve('', false);

        self::assertTrue($resolved['empty']);
        self::assertSame([], $resolved['temporary_paths']);
    }

    private function resolver(): FilePropertyValueResolver
    {
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );

        return new FilePropertyValueResolver(new BitrixImageResolver(
            static function (string $url, string $path, int $maxBytes) use ($png): void {
                file_put_contents($path, $png);
            }
        ));
    }
}
