<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

final class SectionPathResult
{
    public function __construct(
        public readonly ?int $sectionId,
        public readonly array $createdIds = [],
        public readonly array $beforeSnapshots = [],
    ) {
    }
}
