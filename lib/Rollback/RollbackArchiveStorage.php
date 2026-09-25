<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Rollback;

use WebEnot\ImportExcel\Support\Json;

final class RollbackArchiveStorage
{
    private const MANIFEST = 'manifest.json';

    public function __construct(
        private readonly string $rootDirectory,
        private readonly ?\Closure $fileInfoResolver = null,
    ) {
    }

    public function estimate(array $changes): array
    {
        $fileIds = [];
        $payloadBytes = 0;
        foreach ($changes as $change) {
            $beforeJson = (string) ($change['BEFORE_DATA'] ?? '{}');
            $afterJson = (string) ($change['AFTER_DATA'] ?? '{}');
            $payloadBytes += strlen($beforeJson) + strlen($afterJson);
            $this->collectFileIds(Json::decode($beforeJson), $fileIds);
        }

        $fileBytes = 0;
        $missing = [];
        $files = [];
        foreach (array_keys($fileIds) as $fileId) {
            $info = $this->fileInfo((int) $fileId);
            if ($info === null) {
                $missing[] = (int) $fileId;
                continue;
            }
            $files[(string) $fileId] = $info;
            $fileBytes += $info['size'];
        }

        return [
            'file_count' => count($files),
            'file_bytes' => $fileBytes,
            'payload_bytes' => $payloadBytes,
            'total_bytes' => $fileBytes + $payloadBytes,
            'missing_file_ids' => $missing,
            'complete' => $missing === [],
            'files' => $files,
        ];
    }

    public function create(int $jobId, array $changes): array
    {
        $estimate = $this->estimate($changes);
        if (!$estimate['complete']) {
            throw new \RuntimeException(sprintf(
                'Нельзя надёжно сохранить откат: не найдены файлы Bitrix с ID %s.',
                implode(', ', $estimate['missing_file_ids'])
            ));
        }

        $this->ensureRoot();
        $directory = $this->jobDirectory($jobId);
        if (is_dir($directory)) {
            $this->removeDirectory($directory);
        }
        if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог резервной копии отката.');
        }

        $manifestFiles = [];
        try {
            foreach ($estimate['files'] as $fileId => $info) {
                $extension = strtolower((string) pathinfo($info['name'], PATHINFO_EXTENSION));
                $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.' . $extension : '';
                $archiveName = (int) $fileId . '-' . substr(hash_file('sha256', $info['path']), 0, 12) . $extension;
                $destination = $directory . DIRECTORY_SEPARATOR . $archiveName;
                if (!copy($info['path'], $destination)) {
                    throw new \RuntimeException(sprintf('Не удалось сохранить копию файла Bitrix ID %d.', $fileId));
                }
                $manifestFiles[(string) $fileId] = [
                    'name' => $archiveName,
                    'size' => (int) filesize($destination),
                    'original_name' => $info['name'],
                ];
            }

            $manifest = [
                'job_id' => $jobId,
                'created_at' => date(DATE_ATOM),
                'file_count' => $estimate['file_count'],
                'file_bytes' => $estimate['file_bytes'],
                'payload_bytes' => $estimate['payload_bytes'],
                'total_bytes' => $estimate['total_bytes'],
                'files' => $manifestFiles,
            ];
            if (file_put_contents($directory . DIRECTORY_SEPARATOR . self::MANIFEST, Json::encode($manifest)) === false) {
                throw new \RuntimeException('Не удалось записать описание резервной копии отката.');
            }
        } catch (\Throwable $exception) {
            $this->removeDirectory($directory);
            throw $exception;
        }

        return $manifest;
    }

    public function isSaved(int $jobId): bool
    {
        return is_file($this->jobDirectory($jobId) . DIRECTORY_SEPARATOR . self::MANIFEST);
    }

    public function info(int $jobId): ?array
    {
        $path = $this->jobDirectory($jobId) . DIRECTORY_SEPARATOR . self::MANIFEST;
        if (!is_file($path)) {
            return null;
        }

        try {
            return Json::decode((string) file_get_contents($path));
        } catch (\Throwable) {
            return null;
        }
    }

    public function materialize(int $jobId, array $snapshot): array
    {
        $manifest = $this->info($jobId);
        if ($manifest === null) {
            return $snapshot;
        }

        return $this->materializeSnapshot($jobId, $snapshot, (array) ($manifest['files'] ?? []));
    }

    public function delete(int $jobId): int
    {
        $directory = $this->jobDirectory($jobId);
        if (!is_dir($directory)) {
            return 0;
        }
        $bytes = $this->directorySize($directory);
        $this->removeDirectory($directory);
        return $bytes;
    }

    private function collectFileIds(array $snapshot, array &$fileIds): void
    {
        foreach ((array) ($snapshot['_rollback_files'] ?? []) as $file) {
            $fileId = (int) ($file['file_id'] ?? 0);
            if ($fileId > 0) {
                $fileIds[$fileId] = true;
            }
        }
        foreach ((array) ($snapshot['section_snapshots'] ?? []) as $sectionSnapshot) {
            $this->collectFileIds((array) $sectionSnapshot, $fileIds);
        }
    }

    private function materializeSnapshot(int $jobId, array $snapshot, array $manifestFiles): array
    {
        $multipleProperties = [];
        foreach ((array) ($snapshot['_rollback_files'] ?? []) as $file) {
            $fileId = (int) ($file['file_id'] ?? 0);
            $entry = (array) ($manifestFiles[(string) $fileId] ?? []);
            $name = basename((string) ($entry['name'] ?? ''));
            $path = $name !== '' ? $this->jobDirectory($jobId) . DIRECTORY_SEPARATOR . $name : '';
            if ($fileId < 1 || $path === '' || !is_file($path)) {
                throw new \RuntimeException(sprintf('Файл резервной копии Bitrix ID %d не найден.', $fileId));
            }
            $fileArray = \CFile::MakeFileArray($path);
            if (!is_array($fileArray) || $fileArray === []) {
                throw new \RuntimeException(sprintf('Не удалось подготовить файл отката Bitrix ID %d.', $fileId));
            }
            $fileArray['MODULE_ID'] = 'iblock';
            $scope = (string) ($file['scope'] ?? '');
            $code = (string) ($file['code'] ?? '');
            $index = $file['index'] ?? null;
            if ($scope === 'field') {
                $snapshot['fields'][$code] = $fileArray;
            } elseif ($scope === 'property' && $index === null) {
                $snapshot['properties'][$code] = $fileArray;
            } elseif ($scope === 'property') {
                if (!isset($multipleProperties[$code])) {
                    $snapshot['properties'][$code] = [];
                    $multipleProperties[$code] = true;
                }
                $snapshot['properties'][$code]['n' . (int) $index] = [
                    'VALUE' => $fileArray,
                    'DESCRIPTION' => '',
                ];
            }
        }
        unset($snapshot['_rollback_files']);

        foreach ((array) ($snapshot['section_snapshots'] ?? []) as $index => $sectionSnapshot) {
            $snapshot['section_snapshots'][$index] = $this->materializeSnapshot(
                $jobId,
                (array) $sectionSnapshot,
                $manifestFiles
            );
        }

        return $snapshot;
    }

    private function fileInfo(int $fileId): ?array
    {
        if ($this->fileInfoResolver !== null) {
            $info = ($this->fileInfoResolver)($fileId);
            return is_array($info) ? $info : null;
        }
        $file = \CFile::GetFileArray($fileId);
        if (!is_array($file) || $file === []) {
            return null;
        }
        $source = (string) ($file['SRC'] ?? \CFile::GetPath($fileId));
        $path = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\')
            . DIRECTORY_SEPARATOR
            . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $source), '/\\');
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        return [
            'path' => $realPath,
            'size' => (int) filesize($realPath),
            'name' => (string) ($file['ORIGINAL_NAME'] ?: basename($realPath)),
        ];
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->rootDirectory) && !mkdir($this->rootDirectory, 0770, true) && !is_dir($this->rootDirectory)) {
            throw new \RuntimeException('Не удалось создать хранилище откатов.');
        }
        $accessFile = $this->rootDirectory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($accessFile)) {
            file_put_contents($accessFile, "Require all denied\nDeny from all\n");
        }
        $indexFile = $this->rootDirectory . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($indexFile)) {
            file_put_contents($indexFile, "<?php\nhttp_response_code(404);\n");
        }
    }

    private function jobDirectory(int $jobId): string
    {
        if ($jobId < 1) {
            throw new \InvalidArgumentException('Rollback job ID must be positive.');
        }
        return rtrim($this->rootDirectory, '/\\') . DIRECTORY_SEPARATOR . 'job-' . $jobId;
    }

    private function directorySize(string $directory): int
    {
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $bytes += $item->getSize();
            }
        }
        return $bytes;
    }

    private function removeDirectory(string $directory): void
    {
        $expected = $this->jobDirectory((int) substr(basename($directory), 4));
        if ($directory !== $expected || !is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isFile() || $item->isLink()) {
                if (!unlink($item->getPathname())) {
                    throw new \RuntimeException('Не удалось удалить файл резервной копии отката.');
                }
            } elseif ($item->isDir() && !rmdir($item->getPathname())) {
                throw new \RuntimeException('Не удалось удалить каталог резервной копии отката.');
            }
        }
        if (!rmdir($directory)) {
            throw new \RuntimeException('Не удалось удалить резервную копию отката.');
        }
    }
}
