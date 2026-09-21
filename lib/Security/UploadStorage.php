<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Security;

final class UploadStorage
{
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
        $targetDirectory = rtrim($this->directory, '/\\');
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Unable to create the protected import directory.');
        }
        $this->protectDirectory($targetDirectory);

        $target = $targetDirectory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.' . $extension;
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
}
