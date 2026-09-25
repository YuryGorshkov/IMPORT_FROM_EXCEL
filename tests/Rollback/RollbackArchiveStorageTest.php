<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Rollback;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Rollback\RollbackArchiveStorage;
use WebEnot\ImportExcel\Support\Json;

final class RollbackArchiveStorageTest extends TestCase
{
    private string $directory;
    private string $sourceFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wie_rollback_' . bin2hex(random_bytes(5));
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'wie_source_');
        file_put_contents($this->sourceFile, 'image-bytes');
    }

    protected function tearDown(): void
    {
        if (isset($this->sourceFile) && is_file($this->sourceFile)) {
            unlink($this->sourceFile);
        }
        if (isset($this->directory) && is_dir($this->directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->directory);
        }
    }

    public function testEstimatesCopiesAndDeletesUniqueRollbackFiles(): void
    {
        $storage = new RollbackArchiveStorage(
            $this->directory,
            fn(int $fileId): ?array => $fileId === 17
                ? ['path' => $this->sourceFile, 'size' => 11, 'name' => 'before.jpg']
                : null
        );
        $snapshot = [
            'fields' => ['PREVIEW_PICTURE' => 17],
            '_rollback_files' => [
                ['scope' => 'field', 'code' => 'PREVIEW_PICTURE', 'index' => null, 'file_id' => 17],
            ],
            'section_snapshots' => [[
                'fields' => ['PICTURE' => 17],
                '_rollback_files' => [
                    ['scope' => 'field', 'code' => 'PICTURE', 'index' => null, 'file_id' => 17],
                ],
            ]],
        ];
        $changes = [[
            'BEFORE_DATA' => Json::encode($snapshot),
            'AFTER_DATA' => Json::encode(['fields' => []]),
        ]];

        $estimate = $storage->estimate($changes);
        self::assertSame(1, $estimate['file_count']);
        self::assertSame(11, $estimate['file_bytes']);
        self::assertTrue($estimate['complete']);

        $manifest = $storage->create(91, $changes);
        self::assertSame(1, $manifest['file_count']);
        self::assertTrue($storage->isSaved(91));
        self::assertGreaterThanOrEqual(11, $storage->delete(91));
        self::assertFalse($storage->isSaved(91));
    }

    public function testReportsMissingFilesBeforeImport(): void
    {
        $storage = new RollbackArchiveStorage($this->directory, static fn(): ?array => null);
        $changes = [[
            'BEFORE_DATA' => Json::encode([
                '_rollback_files' => [
                    ['scope' => 'field', 'code' => 'DETAIL_PICTURE', 'index' => null, 'file_id' => 44],
                ],
            ]),
            'AFTER_DATA' => '{}',
        ]];

        $estimate = $storage->estimate($changes);
        self::assertFalse($estimate['complete']);
        self::assertSame([44], $estimate['missing_file_ids']);
    }
}
