<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Import;

use WebEnot\ImportExcel\Domain\ImportProfile;
use WebEnot\ImportExcel\Domain\ImportResult;
use WebEnot\ImportExcel\Mapping\RowMapper;
use WebEnot\ImportExcel\Reader\ReaderInterface;
use WebEnot\ImportExcel\Reporting\ReporterInterface;
use WebEnot\ImportExcel\Target\IblockTarget;

final class ImportRunner
{
    public function __construct(
        private readonly ReaderInterface $reader,
        private readonly RowMapper $mapper,
        private readonly IblockTarget $target,
        private readonly ReporterInterface $reporter,
    ) {
    }

    public function runChunk(
        ImportProfile $profile,
        string $sourcePath,
        ?string $sheet,
        int $jobId,
        int $cursorRow = 0,
        bool $dryRun = false,
    ): ImportResult {
        $result = new ImportResult();
        $totalRows = $this->reader->totalRows($sourcePath, $sheet);
        $startRow = max($profile->startRow(), $cursorRow > 0 ? $cursorRow + 1 : 0);
        if ($startRow > $totalRows) {
            $result->lastRow = $cursorRow;
            $result->finished = true;
            return $result;
        }

        $this->target->prepare($profile, $dryRun);
        foreach ($this->reader->read($sourcePath, $sheet, $startRow, $profile->chunkSize(), $profile->options) as $row) {
            $result->read++;
            $result->lastRow = $row->number;
            try {
                $mapped = $this->mapper->map($row, $profile->mapping);
                $targetResult = $this->target->apply($profile, $mapped, $dryRun);
                match ($targetResult->action) {
                    'added' => $result->added++,
                    'updated' => $result->updated++,
                    default => $result->skipped++,
                };
                $this->reporter->change($jobId, $targetResult);
            } catch (\Throwable $exception) {
                $result->errors++;
                $this->reporter->log(
                    $jobId,
                    'error',
                    $row->number,
                    'ROW_IMPORT_FAILED',
                    $exception->getMessage(),
                    ['exception' => $exception::class]
                );
                if (($profile->options['stop_on_error'] ?? false) === true) {
                    throw $exception;
                }
            }
        }

        $result->finished = $result->lastRow >= $totalRows || $result->read === 0;
        return $result;
    }
}
