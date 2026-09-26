<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use Bitrix\Main\Application;
use Bitrix\Main\Web\HttpClient;

final class BitrixImageResolver
{
    private const MAX_BYTES = 15_728_640;

    private const DOWNLOAD_ATTEMPTS = 3;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/tiff' => 'tif',
        'image/avif' => 'avif',
    ];

    public function __construct(private readonly ?\Closure $remoteDownloader = null)
    {
    }

    /**
     * @return array{file: array, temporary_path: string}
     */
    public function resolve(mixed $value): array
    {
        if (is_array($value)) {
            throw new \InvalidArgumentException('Image source must be a scalar value from the imported file.');
        }

        $source = trim((string) $value);
        if ($source === '') {
            throw new \InvalidArgumentException('Image source is empty.');
        }
        if (ctype_digit($source) && (int) $source > 0) {
            return ['file' => $this->makeFileArray((int) $source), 'temporary_path' => ''];
        }
        if (str_starts_with($source, '/upload/')) {
            return ['file' => $this->fromSiteUpload($source), 'temporary_path' => ''];
        }
        if (preg_match('#^https?://#i', $source) === 1) {
            return $this->fromRemoteUrl($source);
        }

        throw new \InvalidArgumentException(
            'Image must be an HTTP/HTTPS URL, a path inside /upload, or an existing Bitrix file ID.'
        );
    }

    private function fromSiteUpload(string $source): array
    {
        $documentRoot = rtrim(Application::getDocumentRoot(), '/\\');
        $uploadRoot = realpath($documentRoot . '/upload');
        $path = realpath($documentRoot . '/' . ltrim($source, '/'));
        if ($uploadRoot === false || $path === false || !is_file($path)) {
            throw new \InvalidArgumentException('The image was not found inside the site upload directory.');
        }
        $prefix = rtrim($uploadRoot, '/\\') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('The image path is outside the site upload directory.');
        }

        return $this->makeFileArray($path);
    }

    /**
     * @return array{file: array, temporary_path: string}
     */
    private function fromRemoteUrl(string $source): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'wie_image_');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Unable to allocate a temporary image file.');
        }

        try {
            $this->download($source, $temporaryPath);
            $size = filesize($temporaryPath);
            if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
                throw new \InvalidArgumentException('The downloaded image is empty or exceeds 15 MB.');
            }
            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) ?: '';
            $extension = self::ALLOWED_MIME_TYPES[$mimeType] ?? '';
            if ($extension === '') {
                throw new \InvalidArgumentException('The downloaded file is not a supported image.');
            }

            $sourceName = rawurldecode(basename((string) parse_url($source, PHP_URL_PATH)));
            $baseName = trim((string) pathinfo($sourceName, PATHINFO_FILENAME));
            $name = ($baseName !== '' ? $baseName : 'image') . '.' . $extension;

            return [
                'file' => [
                    'name' => $name,
                    'type' => $mimeType,
                    'tmp_name' => $temporaryPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $size,
                    'MODULE_ID' => 'iblock',
                ],
                'temporary_path' => $temporaryPath,
            ];
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);
            throw $exception;
        }
    }

    private function makeFileArray(string|int $source): array
    {
        $file = \CFile::MakeFileArray($source);
        if (!is_array($file) || $file === []) {
            throw new \InvalidArgumentException('Unable to prepare the image for Bitrix.');
        }

        $file['MODULE_ID'] = 'iblock';

        return $file;
    }

    private function download(string $source, string $temporaryPath): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::DOWNLOAD_ATTEMPTS; ++$attempt) {
            try {
                if ($this->remoteDownloader !== null) {
                    ($this->remoteDownloader)($source, $temporaryPath, self::MAX_BYTES);
                    return;
                }

                $client = new HttpClient([
                    'socketTimeout' => 10,
                    'streamTimeout' => 20,
                    'redirect' => true,
                    'redirectMax' => 3,
                    'bodyLengthMax' => self::MAX_BYTES,
                    'privateIp' => false,
                    'disableSslVerification' => false,
                ]);
                if (!$client->download($source, $temporaryPath)) {
                    throw new \RuntimeException('Unable to download the image.');
                }
                $status = $client->getStatus();
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(sprintf('Image server returned HTTP status %d.', $status));
                }

                return;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                @unlink($temporaryPath);
                if ($attempt < self::DOWNLOAD_ATTEMPTS && $this->remoteDownloader === null) {
                    usleep(200_000 * $attempt);
                }
            }
        }

        throw new \RuntimeException(
            sprintf('Unable to download the image after %d attempts.', self::DOWNLOAD_ATTEMPTS),
            0,
            $lastException
        );
    }
}
