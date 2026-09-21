<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Domain;

final class ImportResult
{
    public int $read = 0;
    public int $added = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public int $errors = 0;
    public int $lastRow = 0;
    public bool $finished = false;

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
