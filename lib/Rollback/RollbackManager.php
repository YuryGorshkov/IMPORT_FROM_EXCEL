<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Rollback;

use WebEnot\ImportExcel\Orm\ChangeTable;
use WebEnot\ImportExcel\Orm\LogTable;
use WebEnot\ImportExcel\Support\Json;

final class RollbackManager
{
    public function __construct(private readonly RollbackArchiveStorage $storage)
    {
    }

    public function estimate(int $jobId): array
    {
        return $this->storage->estimate($this->changes($jobId));
    }

    public function saveFromPreview(int $jobId, int $previewJobId): array
    {
        $changes = $this->changes($previewJobId);
        $manifest = $this->storage->create($jobId, $changes);
        $this->deleteChanges($previewJobId);
        $this->log($jobId, 'ROLLBACK_SAVED', 'Rollback archive was saved.', $manifest);
        return $manifest;
    }

    public function disableForJob(int $jobId, int $previewJobId): void
    {
        $this->deleteChanges($previewJobId);
        $this->log($jobId, 'ROLLBACK_DISABLED', 'Rollback archive was disabled by the user.');
    }

    public function isSaved(int $jobId): bool
    {
        return $this->storage->isSaved($jobId);
    }

    public function info(int $jobId): ?array
    {
        return $this->storage->info($jobId);
    }

    public function hasChanges(int $jobId): bool
    {
        return ChangeTable::getCount(['=JOB_ID' => $jobId]) > 0;
    }

    public function state(int $jobId): string
    {
        if ($this->storage->isSaved($jobId)) {
            return 'saved';
        }
        $log = LogTable::getList([
            'filter' => ['=JOB_ID' => $jobId, '@CODE' => ['ROLLBACK_DELETED', 'ROLLBACK_DISABLED']],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ])->fetch();
        if (($log['CODE'] ?? '') === 'ROLLBACK_DELETED') {
            return 'deleted';
        }
        if (($log['CODE'] ?? '') === 'ROLLBACK_DISABLED') {
            return 'disabled';
        }
        return $this->hasChanges($jobId) ? 'legacy' : 'none';
    }

    public function delete(int $jobId): array
    {
        $freedBytes = $this->storage->delete($jobId);
        $changeCount = $this->deleteChanges($jobId);
        $this->log($jobId, 'ROLLBACK_DELETED', 'Rollback archive was deleted.', [
            'freed_bytes' => $freedBytes,
            'deleted_changes' => $changeCount,
        ]);
        return ['freed_bytes' => $freedBytes, 'deleted_changes' => $changeCount];
    }

    private function changes(int $jobId): array
    {
        return ChangeTable::getList([
            'filter' => ['=JOB_ID' => $jobId],
            'select' => ['ID', 'BEFORE_DATA', 'AFTER_DATA'],
            'order' => ['ID' => 'ASC'],
        ])->fetchAll();
    }

    private function deleteChanges(int $jobId): int
    {
        $count = 0;
        $result = ChangeTable::getList([
            'filter' => ['=JOB_ID' => $jobId],
            'select' => ['ID'],
        ]);
        while ($change = $result->fetch()) {
            ChangeTable::delete((int) $change['ID']);
            $count++;
        }
        return $count;
    }

    private function log(int $jobId, string $code, string $message, array $context = []): void
    {
        LogTable::add([
            'JOB_ID' => $jobId,
            'LEVEL' => 'info',
            'ROW_NUMBER' => 0,
            'CODE' => $code,
            'MESSAGE' => $message,
            'CONTEXT' => Json::encode($context),
        ]);
    }
}
