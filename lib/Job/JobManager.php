<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Job;

use Bitrix\Main\Type\DateTime;
use WebEnot\ImportExcel\Domain\ImportResult;
use WebEnot\ImportExcel\Orm\JobTable;

final class JobManager
{
    public function create(int $profileId, string $sourcePath, ?string $sheet, bool $dryRun, int $userId = 0): int
    {
        $result = JobTable::add([
            'PROFILE_ID' => $profileId,
            'STATUS' => JobTable::STATUS_NEW,
            'MODE' => $dryRun ? 'dry_run' : 'commit',
            'SOURCE_PATH' => $sourcePath,
            'SHEET' => (string) $sheet,
            'CREATED_BY' => $userId,
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
        return (int) $result->getId();
    }

    public function acquire(int $jobId): array
    {
        $job = JobTable::getByPrimary($jobId)->fetch();
        if (!$job) {
            throw new \RuntimeException(sprintf('Import job %d was not found.', $jobId));
        }
        if (in_array($job['STATUS'], [JobTable::STATUS_COMPLETED, JobTable::STATUS_CANCELLED, JobTable::STATUS_ROLLED_BACK], true)) {
            throw new \RuntimeException(sprintf('Import job %d is already closed.', $jobId));
        }

        $token = bin2hex(random_bytes(16));
        $fields = [
            'STATUS' => JobTable::STATUS_RUNNING,
            'LOCK_TOKEN' => $token,
            'LOCKED_AT' => new DateTime(),
        ];
        if (empty($job['STARTED_AT'])) {
            $fields['STARTED_AT'] = new DateTime();
        }
        JobTable::update($jobId, $fields);
        $job['LOCK_TOKEN'] = $token;
        return $job;
    }

    public function progress(int $jobId, ImportResult $result, int $totalRows): void
    {
        $job = JobTable::getByPrimary($jobId)->fetch();
        if (!$job) {
            throw new \RuntimeException(sprintf('Import job %d was not found.', $jobId));
        }
        $fields = [
            'CURSOR_ROW' => $result->lastRow,
            'TOTAL_ROWS' => $totalRows,
            'ROWS_READ' => (int) $job['ROWS_READ'] + $result->read,
            'ROWS_ADDED' => (int) $job['ROWS_ADDED'] + $result->added,
            'ROWS_UPDATED' => (int) $job['ROWS_UPDATED'] + $result->updated,
            'ROWS_SKIPPED' => (int) $job['ROWS_SKIPPED'] + $result->skipped,
            'ROWS_ERRORS' => (int) $job['ROWS_ERRORS'] + $result->errors,
            'LOCK_TOKEN' => '',
            'LOCKED_AT' => null,
        ];
        if ($result->finished) {
            $fields['STATUS'] = JobTable::STATUS_COMPLETED;
            $fields['FINISHED_AT'] = new DateTime();
        }
        JobTable::update($jobId, $fields);
    }

    public function fail(int $jobId, \Throwable $exception): void
    {
        JobTable::update($jobId, [
            'STATUS' => JobTable::STATUS_FAILED,
            'ERROR_MESSAGE' => mb_substr($exception->getMessage(), 0, 65535),
            'LOCK_TOKEN' => '',
            'LOCKED_AT' => null,
            'FINISHED_AT' => new DateTime(),
        ]);
    }
}
