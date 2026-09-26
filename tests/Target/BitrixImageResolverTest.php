<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Target;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Target\BitrixImageResolver;

final class BitrixImageResolverTest extends TestCase
{
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }
    }

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

    public function testDownloadsAndDetectsRemoteImageByItsContent(): void
    {
        $resolver = new BitrixImageResolver(function (string $url, string $path, int $maxBytes): void {
            self::assertSame('https://cdn.example.test/product?id=42', $url);
            self::assertGreaterThan(0, $maxBytes);
            file_put_contents($path, $this->png());
        });

        $resolved = $resolver->resolve('https://cdn.example.test/product?id=42');
        $this->temporaryPaths[] = $resolved['temporary_path'];

        self::assertSame('product.png', $resolved['file']['name']);
        self::assertSame('image/png', $resolved['file']['type']);
        self::assertSame('iblock', $resolved['file']['MODULE_ID']);
        self::assertGreaterThan(0, $resolved['file']['size']);
    }

    public function testRetriesTransientRemoteDownloadFailure(): void
    {
        $attempts = 0;
        $resolver = new BitrixImageResolver(function (string $url, string $path) use (&$attempts): void {
            ++$attempts;
            if ($attempts < 3) {
                throw new \RuntimeException('Temporary CDN failure.');
            }

            file_put_contents($path, $this->png());
        });

        $resolved = $resolver->resolve('https://cdn.example.test/retry-picture');
        $this->temporaryPaths[] = $resolved['temporary_path'];

        self::assertSame(3, $attempts);
        self::assertSame('retry-picture.png', $resolved['file']['name']);
        self::assertSame('image/png', $resolved['file']['type']);
    }

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
    }
}
