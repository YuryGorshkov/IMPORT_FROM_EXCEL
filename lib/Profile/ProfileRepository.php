<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Profile;

use Bitrix\Main\Type\DateTime;
use WebEnot\ImportExcel\Domain\ImportProfile;
use WebEnot\ImportExcel\Mapping\MappingValidator;
use WebEnot\ImportExcel\Mapping\SectionPath;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\Support\Json;

final class ProfileRepository
{
    public function __construct(private readonly MappingValidator $mappingValidator)
    {
    }

    public function get(int $id): ImportProfile
    {
        $record = ProfileTable::getByPrimary($id)->fetch();
        if (!$record) {
            throw new \RuntimeException(sprintf('Import profile %d was not found.', $id));
        }
        return $this->hydrate($record);
    }

    public function save(array $data): int
    {
        $mapping = is_string($data['mapping'] ?? null) ? Json::decode($data['mapping']) : (array) ($data['mapping'] ?? []);
        $mapping = SectionPath::upgradeLegacyMapping($mapping);
        $options = is_string($data['options'] ?? null) ? Json::decode($data['options']) : (array) ($data['options'] ?? []);
        $sourceConfig = is_string($data['source_config'] ?? null)
            ? Json::decode($data['source_config'])
            : (array) ($data['source_config'] ?? []);
        $options = $this->normalizeUniqueTarget($options, $mapping);
        $this->mappingValidator->validate($mapping);

        $fields = [
            'NAME' => trim((string) ($data['name'] ?? '')),
            'ACTIVE' => ($data['active'] ?? true) ? 'Y' : 'N',
            'TARGET_TYPE' => 'iblock',
            'TARGET_ID' => (int) ($data['target_id'] ?? 0),
            'SOURCE_CONFIG' => Json::encode($sourceConfig),
            'MAPPING' => Json::encode($mapping),
            'OPTIONS' => Json::encode($options),
            'UPDATED_AT' => new DateTime(),
        ];
        if ($fields['NAME'] === '' || $fields['TARGET_ID'] < 1) {
            throw new \InvalidArgumentException('Profile name and target IBlock are required.');
        }

        $id = (int) ($data['id'] ?? 0);
        $result = $id > 0 ? ProfileTable::update($id, $fields) : ProfileTable::add($fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
        return $id > 0 ? $id : (int) $result->getId();
    }

    public function delete(int $id): void
    {
        $result = ProfileTable::delete($id);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    private function hydrate(array $record): ImportProfile
    {
        $mapping = SectionPath::upgradeLegacyMapping(Json::decode((string) $record['MAPPING']));
        $options = $this->normalizeUniqueTarget(
            Json::decode((string) $record['OPTIONS']),
            $mapping
        );
        return new ImportProfile(
            (int) $record['ID'],
            (string) $record['NAME'],
            (int) $record['TARGET_ID'],
            $mapping,
            $options,
            Json::decode((string) $record['SOURCE_CONFIG']),
        );
    }

    private function normalizeUniqueTarget(array $options, array $mapping): array
    {
        $targets = [];
        foreach ($mapping as $rule) {
            $target = strtoupper((string) ($rule['target'] ?? ''));
            if ($target !== '' && !SectionPath::isTarget($target)) {
                $targets[] = $target;
            }
        }
        $current = strtoupper((string) ($options['unique_target'] ?? ''));
        if (!in_array($current, $targets, true)) {
            $options['unique_target'] = (string) ($targets[0] ?? 'FIELD:XML_ID');
        }

        return $options;
    }
}
