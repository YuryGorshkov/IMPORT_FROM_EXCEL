<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use WebEnot\ImportExcel\Domain\ImportProfile;
use WebEnot\ImportExcel\Mapping\MappedRow;

final class IblockTarget
{
    public function __construct(private readonly IblockGatewayInterface $gateway)
    {
    }

    public function prepare(ImportProfile $profile, bool $dryRun = false): void
    {
        if (!$dryRun && ($profile->options['auto_create_properties'] ?? true) === true) {
            $this->gateway->ensureProperties($profile->targetId, $profile->mapping);
        }
    }

    public function apply(ImportProfile $profile, MappedRow $row, bool $dryRun): TargetResult
    {
        $uniqueTarget = $profile->uniqueTarget();
        $uniqueValue = $row->value($uniqueTarget);
        if ($uniqueValue === null || $uniqueValue === '') {
            throw new \RuntimeException(sprintf('Unique field %s is empty.', $uniqueTarget));
        }

        $existing = $this->gateway->find($profile->targetId, $uniqueTarget, $uniqueValue);
        $mode = $profile->writeMode();
        if ($existing === null && $mode === 'update') {
            return new TargetResult('skipped');
        }
        if ($existing !== null && $mode === 'insert') {
            return new TargetResult('skipped', (int) $existing['ID']);
        }

        if ($existing === null) {
            if ($dryRun) {
                return new TargetResult('added', 0, [], ['fields' => $row->fields, 'properties' => $row->properties]);
            }
            $id = $this->gateway->add($profile->targetId, $row);
            return new TargetResult('added', $id, [], $this->gateway->snapshot($id));
        }

        $id = (int) $existing['ID'];
        $before = $this->gateway->snapshot($id);
        if ($dryRun) {
            return new TargetResult('updated', $id, $before, ['fields' => $row->fields, 'properties' => $row->properties]);
        }
        $this->gateway->update($id, $row);
        return new TargetResult('updated', $id, $before, $this->gateway->snapshot($id));
    }
}
