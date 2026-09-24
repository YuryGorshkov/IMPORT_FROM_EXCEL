<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use WebEnot\ImportExcel\Domain\ImportProfile;
use WebEnot\ImportExcel\Mapping\MappedRow;

final class IblockTarget
{
    public function __construct(
        private readonly IblockGatewayInterface $gateway,
        private readonly ElementCodeGenerator $codeGenerator,
    ) {
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
            $row = $this->withElementCode($profile, $row);
            if ($dryRun) {
                return new TargetResult('added', 0, [], ['fields' => $row->fields, 'properties' => $row->properties]);
            }
            $id = $this->gateway->add($profile->targetId, $row);
            return new TargetResult('added', $id, [], $this->gateway->snapshot($id));
        }

        $id = (int) $existing['ID'];
        $before = $this->gateway->snapshot($id);
        $row = $this->withElementCode(
            $profile,
            $row,
            $id,
            trim((string) ($before['fields']['CODE'] ?? '')) !== ''
        );
        if ($dryRun) {
            return new TargetResult('updated', $id, $before, ['fields' => $row->fields, 'properties' => $row->properties]);
        }
        $this->gateway->update($id, $row);
        return new TargetResult('updated', $id, $before, $this->gateway->snapshot($id));
    }

    private function withElementCode(
        ImportProfile $profile,
        MappedRow $row,
        int $elementId = 0,
        bool $existingHasCode = false
    ): MappedRow {
        $fields = $row->fields;
        $mappedCode = trim((string) ($fields['CODE'] ?? ''));
        if ($mappedCode !== '') {
            return $row;
        }
        if ($existingHasCode) {
            unset($fields['CODE']);
            return new MappedRow($row->rowNumber, $fields, $row->properties, $row->raw);
        }

        $source = $profile->elementCodeSource();
        if ($source === 'none') {
            return $row;
        }

        $value = $source === 'unique'
            ? $row->value($profile->uniqueTarget())
            : ($fields['NAME'] ?? null);
        $baseCode = $this->codeGenerator->generate($value);
        if ($baseCode === '') {
            return $row;
        }

        $fields['CODE'] = $this->gateway->uniqueCode($profile->targetId, $baseCode, $elementId);
        return new MappedRow($row->rowNumber, $fields, $row->properties, $row->raw);
    }
}
