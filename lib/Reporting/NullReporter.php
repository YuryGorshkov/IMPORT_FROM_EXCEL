<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Reporting;

use WebEnot\ImportExcel\Target\TargetResult;

final class NullReporter implements ReporterInterface
{
    public function log(int $jobId, string $level, int $rowNumber, string $code, string $message, array $context = []): void
    {
    }

    public function change(int $jobId, TargetResult $result): void
    {
    }
}
