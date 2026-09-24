<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Security;

final class UploadStorage
{
    private const TOKEN_PATTERN = '/\A[a-f0-9]{32}\.(?:xlsx|xls|ods|csv)\z/';

    public function __construct(
        private readonly string $directory,
        private readonly SourceFilePolicy $policy,
    ) {
    }

    public function store(array $uploadedFile): string
    {
        $error = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed with code ' . $error . '.');
        }

        $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('The source was not received through a valid HTTP upload.');
        }

        $originalName = basename((string) ($uploadedFile['name'] ?? 'source.xlsx'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $target = $this->newTarget($extension);
        if (!move_uploaded_file($tmpName, $target)) {
            throw new \RuntimeException('Unable to move the uploaded file into import storage.');
        }

        try {
            return $this->policy->validate($target);
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        }
    }

    public function transfer(string $token, self $destination): string
    {
        $source = $this->resolve($token);
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $target = $destination->newTarget($extension);
        if (!@rename($source, $target)) {
            if (!copy($source, $target)) {
                throw new \RuntimeException('Unable to transfer the temporary upload.');
            }
            @unlink($source);
        }

        try {
            return $destination->policy->validate($target);
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        }
    }

    public function token(string $path): string
    {
        $path = $this->policy->validate($path);
        $directory = realpath($this->directory);
        if ($directory === false || dirname($path) !== $directory) {
            throw new \InvalidArgumentException('The upload is outside the expected storage directory.');
        }

        $token = basename($path);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('The upload token is invalid.');
        }

        return $token;
    }

    public function resolve(string $token, int $maxAgeSeconds = 3600): string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('The upload token is invalid.');
        }

        $directory = realpath($this->directory);
        $path = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $token);
        if ($path === false || dirname($path) !== $directory) {
            throw new \RuntimeException('The temporary upload was not found.');
        }

        $modifiedAt = filemtime($path);
        if ($modifiedAt === false || $modifiedAt < time() - max(60, $maxAgeSeconds)) {
            @unlink($path);
            throw new \RuntimeException('The temporary upload has expired.');
        }

        return $this->policy->validate($path);
    }

    public function remove(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return;
        }

        $directory = realpath($this->directory);
        $path = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $token);
        if ($path !== false && dirname($path) === $directory && is_file($path)) {
            @unlink($path);
        }
    }

    public function cleanupExpired(int $maxAgeSeconds = 3600): void
    {
        $directory = realpath($this->directory);
        if ($directory === false) {
            return;
        }

        $threshold = time() - max(60, $maxAgeSeconds);
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match(self::TOKEN_PATTERN, $name) !== 1) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $modifiedAt = filemtime($path);
            if ($modifiedAt !== false && $modifiedAt < $threshold) {
                @unlink($path);
            }
        }
    }

    private function protectDirectory(string $directory): void
    {
        $accessFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($accessFile)) {
            file_put_contents($accessFile, "Require all denied\nDeny from all\n");
        }
        $indexFile = $directory . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($indexFile)) {
            file_put_contents($indexFile, "<?php\nhttp_response_code(404);\n");
        }
    }

    private function newTarget(string $extension): string
    {
        $targetDirectory = rtrim($this->directory, '/\\');
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Unable to create the protected import directory.');
        }
        $this->protectDirectory($targetDirectory);

        return $targetDirectory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.' . $extension;
    }
}
