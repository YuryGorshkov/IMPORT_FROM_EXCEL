<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Security;

final class SourceFilePolicy
{
    private const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv'];

    public function __construct(private readonly int $maxBytes = 104_857_600)
    {
    }

    public function validate(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
            throw new \InvalidArgumentException('Only local filesystem paths are allowed.');
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new \InvalidArgumentException('The import source is not a readable local file.');
        }
        if (is_link($path)) {
            throw new \InvalidArgumentException('Symbolic links are not allowed as import sources.');
        }

        $size = filesize($realPath);
        if ($size === false || $size <= 0 || $size > $this->maxBytes) {
            throw new \InvalidArgumentException(sprintf('File size must be between 1 and %d bytes.', $this->maxBytes));
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Allowed file extensions: ' . implode(', ', self::EXTENSIONS) . '.');
        }

        return $realPath;
    }
}
