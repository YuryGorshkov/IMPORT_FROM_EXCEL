<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Tests\Security;

use PHPUnit\Framework\TestCase;
use WebEnot\ImportExcel\Security\SourceFilePolicy;
use WebEnot\ImportExcel\Security\UploadStorage;

final class UploadStorageTest extends TestCase
{
    private string $directory;
    private string $token;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webenot_preview_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0770, true);
        $this->token = bin2hex(random_bytes(16)) . '.csv';
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . $this->token, "A;B\n1;2\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . DIRECTORY_SEPARATOR . $this->token);
        @rmdir($this->directory);
    }

    public function testResolvesOnlyOpaqueTokensInsideStorage(): void
    {
        $storage = new UploadStorage($this->directory, new SourceFilePolicy());
        $path = realpath($this->directory . DIRECTORY_SEPARATOR . $this->token);

        self::assertSame($this->token, $storage->token((string) $path));
        self::assertSame($path, $storage->resolve($this->token));
    }

    public function testRejectsPathTraversal(): void
    {
        $storage = new UploadStorage($this->directory, new SourceFilePolicy());

        $this->expectException(\InvalidArgumentException::class);
        $storage->resolve('../' . $this->token);
    }

    public function testTransfersTemporaryUploadToAnotherProtectedStorage(): void
    {
        $destinationDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'webenot_import_' . bin2hex(random_bytes(8));
        $source = new UploadStorage($this->directory, new SourceFilePolicy());
        $destination = new UploadStorage($destinationDirectory, new SourceFilePolicy());

        try {
            $path = $source->transfer($this->token, $destination);
            $destinationToken = $destination->token($path);

            self::assertFileDoesNotExist($this->directory . DIRECTORY_SEPARATOR . $this->token);
            self::assertSame($path, $destination->resolve($destinationToken));
            self::assertFileExists($destinationDirectory . DIRECTORY_SEPARATOR . '.htaccess');
        } finally {
            if (isset($path) && is_file($path)) {
                @unlink($path);
            }
            @unlink($destinationDirectory . DIRECTORY_SEPARATOR . '.htaccess');
            @unlink($destinationDirectory . DIRECTORY_SEPARATOR . 'index.php');
            @rmdir($destinationDirectory);
        }
    }
}
