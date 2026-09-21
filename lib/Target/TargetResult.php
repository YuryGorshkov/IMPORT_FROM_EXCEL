<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

final class TargetResult
{
    public function __construct(
        public readonly string $action,
        public readonly int $entityId = 0,
        public readonly array $before = [],
        public readonly array $after = [],
    ) {
    }
}
