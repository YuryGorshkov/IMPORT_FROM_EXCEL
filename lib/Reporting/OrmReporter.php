<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Reporting;

use WebEnot\ImportExcel\Orm\ChangeTable;
use WebEnot\ImportExcel\Orm\LogTable;
use WebEnot\ImportExcel\Support\Json;
use WebEnot\ImportExcel\Target\TargetResult;

final class OrmReporter implements ReporterInterface
{
    public function __construct(private readonly bool $recordChanges = true)
    {
    }

    public function log(int $jobId, string $level, int $rowNumber, string $code, string $message, array $context = []): void
    {
        LogTable::add([
            'JOB_ID' => $jobId,
            'LEVEL' => $level,
            'ROW_NUMBER' => $rowNumber,
            'CODE' => $code,
            'MESSAGE' => $message,
            'CONTEXT' => Json::encode($context),
        ]);
    }

    public function change(int $jobId, TargetResult $result): void
    {
        if (!$this->recordChanges || !in_array($result->action, ['added', 'updated'], true)) {
            return;
        }
        ChangeTable::add([
            'JOB_ID' => $jobId,
            'ENTITY_TYPE' => 'iblock_element',
            'ENTITY_ID' => $result->entityId,
            'ACTION' => $result->action,
            'BEFORE_DATA' => Json::encode($result->before),
            'AFTER_DATA' => Json::encode($result->after),
        ]);
    }
}
