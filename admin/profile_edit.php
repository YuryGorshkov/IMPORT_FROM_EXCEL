<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use WebEnot\ImportExcel\Admin\AdminUi;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;
use WebEnot\ImportExcel\Discovery\HeaderRowDetector;
use WebEnot\ImportExcel\Mapping\MappingChoice;
use WebEnot\ImportExcel\Mapping\MappingValidator;
use WebEnot\ImportExcel\Mapping\SectionFieldCatalog;
use WebEnot\ImportExcel\Mapping\SectionPath;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\ServiceFactory;
use WebEnot\ImportExcel\Support\Json;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

if (!Loader::includeModule('webenot.importexcel')) {
    throw new RuntimeException((string) Loc::getMessage('WIE_PROFILE_MODULE_ERROR'));
}
if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'W') {
    $APPLICATION->AuthForm((string) Loc::getMessage('WIE_PROFILE_ACCESS_DENIED'));
}
if (!Loader::includeModule('iblock')) {
    throw new RuntimeException((string) Loc::getMessage('WIE_PROFILE_IBLOCK_ERROR'));
}

/**
 * Convert the friendly mapping table back to the domain mapping format.
 */
function webenotImportExcelMappingFromRequest(array $rows): array
{
    $mapping = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['enabled'])) {
            continue;
        }

        $column = strtoupper(trim((string) ($row['column'] ?? '')));
        $choice = strtoupper(trim((string) ($row['choice'] ?? '')));
        if (array_key_exists('choice', $row) && $choice === '') {
            $mapping[] = [
                'column' => $column,
                'label' => trim((string) ($row['label'] ?? '')),
                'code' => '',
                'target' => '',
                'unconfirmed' => true,
                'required' => isset($row['required']),
                'transforms' => [['type' => 'trim']],
            ];
            continue;
        }
        if ($choice !== '') {
            $decodedChoice = MappingChoice::decode(
                $choice,
                (string) ($row['property_code'] ?? ''),
                (string) ($row['property_type'] ?? 'S'),
                ($row['property_multiple'] ?? 'N') === 'Y'
            );
            $destination = $decodedChoice['target'];
            $targetCode = $decodedChoice['code'];
            $targetType = str_starts_with($destination, 'PROPERTY:') ? 'PROPERTY' : strtok($destination, ':');
        } else {
            $destination = strtoupper(trim((string) ($row['destination'] ?? '')));
        }
        if ($destination === '') {
            $legacyType = strtoupper(trim((string) ($row['target_type'] ?? 'PROPERTY')));
            $legacyCode = strtoupper(trim((string) (
                $row['field_code'] ?? $row['property_code'] ?? $row['target_code'] ?? ''
            )));
            $destination = $legacyType . ':' . $legacyCode;
        }
        if ($choice === '') {
            if ($destination === 'PROPERTY') {
                $targetType = 'PROPERTY';
                $targetCode = strtoupper(trim((string) ($row['property_code'] ?? '')));
                $destination = 'PROPERTY:' . $targetCode;
            } else {
                $targetType = strtok($destination, ':');
                $targetCode = str_starts_with($destination, 'SECTION:')
                    ? (string) (SectionPath::fieldFromTarget($destination) ?? '')
                    : (string) substr($destination, strlen($targetType) + 1);
            }
        }

        $transforms = json_decode((string) ($row['transforms_json'] ?? '[]'), true);
        $rule = [
            'column' => $column,
            'label' => trim((string) ($row['label'] ?? '')),
            'code' => $targetCode,
            'target' => $destination,
            'required' => isset($row['required']),
            'transforms' => is_array($transforms) ? $transforms : [['type' => 'trim']],
        ];
        if ($targetType === 'PROPERTY') {
            if ($choice !== '') {
                $rule['property_type'] = $decodedChoice['property_type'];
                $rule['multiple'] = $decodedChoice['multiple'];
                $rule['create_if_missing'] = $decodedChoice['create_if_missing'];
                if ($decodedChoice['create_if_missing']) {
                    $propertyName = trim((string) ($row['property_name'] ?? ''));
                    if ($propertyName === '') {
                        throw new InvalidArgumentException(
                            (string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_NAME_REQUIRED')
                        );
                    }
                    if (mb_strlen($propertyName) > 100) {
                        throw new InvalidArgumentException(
                            (string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_NAME_TOO_LONG')
                        );
                    }
                    $rule['property_name'] = $propertyName;
                    $rule['property_active'] = ($row['property_active'] ?? 'N') === 'Y';
                    $rule['property_sort'] = max(0, (int) ($row['property_sort'] ?? 500));
                    $rule['property_is_required'] = ($row['property_is_required'] ?? 'N') === 'Y';
                    $rule['property_searchable'] = ($row['property_searchable'] ?? 'N') === 'Y';
                    $rule['property_filtrable'] = ($row['property_filtrable'] ?? 'N') === 'Y';
                    $rule['property_smart_filter'] = ($row['property_smart_filter'] ?? 'N') === 'Y';
                    $rule['property_with_description'] = ($row['property_with_description'] ?? 'N') === 'Y';
                    $rule['property_multiple_count'] = max(1, (int) ($row['property_multiple_count'] ?? 5));
                    $rule['property_hint'] = trim((string) ($row['property_hint'] ?? ''));
                    $rule['property_show_edit'] = ($row['property_show_edit'] ?? 'N') === 'Y';
                    $displayType = strtoupper(trim((string) ($row['property_display_type'] ?? 'F')));
                    $rule['property_display_type'] = in_array($displayType, ['F', 'K', 'P'], true)
                        ? $displayType
                        : 'F';
                    $rule['property_display_expanded'] = ($row['property_display_expanded'] ?? 'N') === 'Y';
                    $rule['property_filter_hint'] = trim((string) ($row['property_filter_hint'] ?? ''));
                    $rule['property_row_count'] = max(1, (int) ($row['property_row_count'] ?? 1));
                    $rule['property_col_count'] = max(1, (int) ($row['property_col_count'] ?? 30));
                    $rule['property_default_value'] = (string) ($row['property_default_value'] ?? '');
                    $rule['property_show_list'] = ($row['property_show_list'] ?? 'N') === 'Y';
                    $rule['property_show_detail'] = ($row['property_show_detail'] ?? 'N') === 'Y';
                    $rule['property_link_iblock_id'] = max(
                        0,
                        (int) ($row['property_link_iblock_id'] ?? 0)
                    );
                }
            } else {
                $propertyType = strtoupper(trim((string) ($row['property_type'] ?? 'S')));
                $rule['property_type'] = in_array($propertyType, ['S', 'F'], true) ? $propertyType : 'S';
                $rule['multiple'] = isset($row['multiple']) && $row['multiple'] === 'Y';
            }
        }

        $defaultJson = (string) ($row['default_json'] ?? '');
        if ($defaultJson !== '') {
            $rule['default'] = json_decode($defaultJson, true);
        }
        $mapping[] = $rule;
    }

    return $mapping;
}

function webenotImportExcelPreviewValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (!is_scalar($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
}

$id = (int) ($_REQUEST['ID'] ?? 0);
$record = $id > 0 ? ProfileTable::getByPrimary($id)->fetch() : null;
if ($id > 0 && !$record) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError((string) Loc::getMessage('WIE_PROFILE_NOT_FOUND'));
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$form = [
    'name' => (string) ($record['NAME'] ?? ''),
    'target_id' => (int) ($record['TARGET_ID'] ?? 0),
    'active' => ($record['ACTIVE'] ?? 'Y') === 'Y',
    'mapping' => $record ? Json::decode((string) $record['MAPPING']) : [],
    'source_config' => $record ? Json::decode((string) $record['SOURCE_CONFIG']) : [],
    'options' => $record ? Json::decode((string) $record['OPTIONS']) : [
        'header_row' => 1,
        'start_row' => 2,
        'chunk_size' => 200,
        'unique_target' => 'FIELD:XML_ID',
        'write_mode' => 'upsert',
        'element_code_source' => 'name',
        'auto_create_properties' => true,
        'stop_on_error' => false,
        'delimiter' => ';',
        'encoding' => 'UTF-8',
    ],
];
$form['mapping'] = SectionPath::upgradeLegacyMapping($form['mapping']);
$errors = [];
$sheet = trim((string) ($_POST['sheet'] ?? ($form['source_config']['sheet'] ?? '')));
$previewToken = trim((string) ($_POST['preview_token'] ?? ''));
$previewStartRow = max(1, (int) ($_POST['preview_start_row'] ?? 1));
$previewRows = [];
$previewSheets = [];
$previewColumns = [];
$previewColumnsTruncated = false;
$previewLimit = 15;
$targetMode = (string) ($_POST['target_mode'] ?? 'existing');
$newIblockName = trim((string) ($_POST['new_iblock_name'] ?? ''));
$newIblockType = trim((string) ($_POST['new_iblock_type'] ?? 'catalog'));
$newIblockCode = trim((string) ($_POST['new_iblock_code'] ?? ''));
$createdIblock = false;
$elementFieldMessages = [
    'NAME' => 'WIE_PROFILE_FIELD_NAME',
    'CODE' => 'WIE_PROFILE_FIELD_CODE',
    'XML_ID' => 'WIE_PROFILE_FIELD_XML_ID',
    'ACTIVE' => 'WIE_PROFILE_FIELD_ACTIVE',
    'SORT' => 'WIE_PROFILE_FIELD_SORT',
    'TAGS' => 'WIE_PROFILE_FIELD_TAGS',
    'DATE_ACTIVE_FROM' => 'WIE_PROFILE_FIELD_DATE_ACTIVE_FROM',
    'DATE_ACTIVE_TO' => 'WIE_PROFILE_FIELD_DATE_ACTIVE_TO',
    'IBLOCK_SECTION_ID' => 'WIE_PROFILE_FIELD_SECTION',
    'PREVIEW_TEXT' => 'WIE_PROFILE_FIELD_PREVIEW_TEXT',
    'PREVIEW_TEXT_TYPE' => 'WIE_PROFILE_FIELD_PREVIEW_TEXT_TYPE',
    'PREVIEW_PICTURE' => 'WIE_PROFILE_FIELD_PREVIEW_PICTURE',
    'DETAIL_TEXT' => 'WIE_PROFILE_FIELD_DETAIL_TEXT',
    'DETAIL_TEXT_TYPE' => 'WIE_PROFILE_FIELD_DETAIL_TEXT_TYPE',
    'DETAIL_PICTURE' => 'WIE_PROFILE_FIELD_DETAIL_PICTURE',
];
$elementFieldGroups = [
    'WIE_PROFILE_GROUP_ELEMENT' => [
        'NAME', 'XML_ID', 'CODE',
    ],
    'WIE_PROFILE_GROUP_TEXTS' => [
        'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE',
    ],
    'WIE_PROFILE_GROUP_IMAGES' => ['PREVIEW_PICTURE', 'DETAIL_PICTURE'],
    'WIE_PROFILE_GROUP_SERVICE_FIELDS' => [
        'ACTIVE', 'SORT', 'TAGS', 'DATE_ACTIVE_FROM', 'DATE_ACTIVE_TO',
    ],
];
$sectionFieldMessages = [
    'ID' => 'WIE_PROFILE_SECTION_FIELD_ID',
    'NAME' => 'WIE_PROFILE_SECTION_FIELD_NAME',
    'CODE' => 'WIE_PROFILE_SECTION_FIELD_CODE',
    'XML_ID' => 'WIE_PROFILE_SECTION_FIELD_XML_ID',
    'ACTIVE' => 'WIE_PROFILE_SECTION_FIELD_ACTIVE',
    'SORT' => 'WIE_PROFILE_SECTION_FIELD_SORT',
    'DESCRIPTION' => 'WIE_PROFILE_SECTION_FIELD_DESCRIPTION',
    'DESCRIPTION_TYPE' => 'WIE_PROFILE_SECTION_FIELD_DESCRIPTION_TYPE',
    'PICTURE' => 'WIE_PROFILE_SECTION_FIELD_PICTURE',
    'DETAIL_PICTURE' => 'WIE_PROFILE_SECTION_FIELD_DETAIL_PICTURE',
];
$propertyTypeMessages = [
    'S' => 'WIE_PROFILE_PROPERTY_TYPE_STRING',
    'N' => 'WIE_PROFILE_PROPERTY_TYPE_NUMBER',
    'L' => 'WIE_PROFILE_PROPERTY_TYPE_LIST',
    'F' => 'WIE_PROFILE_PROPERTY_TYPE_FILE',
    'E' => 'WIE_PROFILE_PROPERTY_TYPE_ELEMENT',
    'G' => 'WIE_PROFILE_PROPERTY_TYPE_SECTION',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['target_id'] = (int) ($_POST['target_id'] ?? 0);
    $form['active'] = isset($_POST['active']);
    $headerRow = max(1, (int) ($_POST['header_row'] ?? 1));
    $writeMode = (string) ($_POST['write_mode'] ?? 'upsert');
    if (!in_array($writeMode, ['upsert', 'insert', 'update'], true)) {
        $writeMode = 'upsert';
    }
    $elementCodeSource = (string) ($_POST['element_code_source'] ?? 'name');
    if (!in_array($elementCodeSource, ['name', 'unique', 'none'], true)) {
        $elementCodeSource = 'name';
    }
    $form['options'] = [
        'header_row' => $headerRow,
        'start_row' => max($headerRow + 1, (int) ($_POST['start_row'] ?? ($headerRow + 1))),
        'chunk_size' => min(1000, max(10, (int) ($_POST['chunk_size'] ?? 200))),
        'unique_target' => strtoupper(trim((string) ($_POST['unique_target'] ?? 'FIELD:XML_ID'))),
        'write_mode' => $writeMode,
        'element_code_source' => $elementCodeSource,
        'auto_create_properties' => isset($_POST['auto_create_properties']),
        'stop_on_error' => isset($_POST['stop_on_error']),
        'delimiter' => (string) ($_POST['delimiter'] ?? ';'),
        'encoding' => trim((string) ($_POST['encoding'] ?? 'UTF-8')) ?: 'UTF-8',
    ];
    $form['source_config']['sheet'] = $sheet;
    if (array_key_exists('mapping_rows', $_POST)) {
        $form['mapping'] = webenotImportExcelMappingFromRequest((array) $_POST['mapping_rows']);
    }

    try {
        if (isset($_POST['preview']) || isset($_POST['discover'])) {
            $previewStorage = ServiceFactory::previewUploads();
            $previewStorage->cleanupExpired();
            $uploadError = (int) ($_FILES['sample']['error'] ?? UPLOAD_ERR_NO_FILE);
            $uploadedNewPreview = false;
            if ($uploadError === UPLOAD_ERR_OK) {
                if ($previewToken !== '') {
                    $previewStorage->remove($previewToken);
                }
                $samplePath = $previewStorage->store($_FILES['sample']);
                $previewToken = $previewStorage->token($samplePath);
                $uploadedNewPreview = true;
            } elseif ($uploadError === UPLOAD_ERR_NO_FILE && $previewToken !== '') {
                $samplePath = $previewStorage->resolve($previewToken);
            } elseif ($uploadError !== UPLOAD_ERR_NO_FILE) {
                $samplePath = $previewStorage->store($_FILES['sample']);
            } else {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_PROFILE_PREVIEW_FILE_REQUIRED'));
            }

            $previewSheets = ServiceFactory::reader()->sheets($samplePath);
            if ($sheet === '' || !in_array($sheet, $previewSheets, true)) {
                $sheet = (string) ($previewSheets[0] ?? '');
            }
            $previewOptions = $form['options'];
            $previewOptions['preview_start_row'] = $previewStartRow;
            $previewRows = ServiceFactory::reader()->preview(
                $samplePath,
                $sheet !== '' ? $sheet : null,
                $previewLimit,
                $previewOptions
            );
            if ($previewRows === []) {
                throw new RuntimeException((string) Loc::getMessage('WIE_PROFILE_PREVIEW_EMPTY'));
            }

            $previewRowNumbers = array_map(
                static fn(array $row): int => (int) $row['row'],
                $previewRows
            );
            if (
                $uploadedNewPreview
                || !array_key_exists('header_row', $_POST)
                || !in_array($headerRow, $previewRowNumbers, true)
            ) {
                $headerRow = (new HeaderRowDetector())->detect($previewRows, $previewStartRow);
            }
            $form['options']['header_row'] = $headerRow;
            $form['options']['start_row'] = $headerRow + 1;

            if (isset($_POST['discover'])) {
                if (!in_array($headerRow, $previewRowNumbers, true)) {
                    throw new InvalidArgumentException(
                        (string) Loc::getMessage('WIE_PROFILE_PREVIEW_HEADER_REQUIRED')
                    );
                }
                $form['mapping'] = ServiceFactory::discovery()->discover(
                    $samplePath,
                    $sheet !== '' ? $sheet : null,
                    $headerRow,
                    $form['options']
                );
                $hasUnique = array_filter(
                    $form['mapping'],
                    static fn(array $rule): bool => $rule['target'] === $form['options']['unique_target']
                );
                if ($hasUnique === []) {
                    $candidates = array_values(array_filter(
                        $form['mapping'],
                        static fn(array $rule): bool => str_starts_with($rule['target'], 'PROPERTY:')
                    ));
                    $form['options']['unique_target'] = (string) (($candidates[0] ?? $form['mapping'][0])['target']);
                }
            }
        } elseif (isset($_POST['save']) || isset($_POST['save_and_run'])) {
            $unconfirmedRule = current(array_filter(
                $form['mapping'],
                static fn(array $rule): bool => !empty($rule['unconfirmed'])
                    || trim((string) ($rule['target'] ?? '')) === ''
            ));
            if (is_array($unconfirmedRule)) {
                throw new InvalidArgumentException(str_replace(
                    ['#COLUMN#', '#LABEL#'],
                    [
                        (string) ($unconfirmedRule['column'] ?? ''),
                        (string) ($unconfirmedRule['label'] ?? ''),
                    ],
                    (string) Loc::getMessage('WIE_PROFILE_MAPPING_VALUE_REQUIRED')
                ));
            }
            $mappingTargets = array_values(array_filter(
                array_column($form['mapping'], 'target'),
                static fn(string $target): bool => !SectionPath::isTarget($target)
            ));
            if (!in_array($form['options']['unique_target'], $mappingTargets, true) && $mappingTargets !== []) {
                $form['options']['unique_target'] = (string) $mappingTargets[0];
            }
            if ($form['name'] === '') {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_PROFILE_NAME_REQUIRED'));
            }
            if ($form['mapping'] === []) {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_PROFILE_MAPPING_REQUIRED_ERROR'));
            }
            (new MappingValidator())->validate($form['mapping']);
            if ($targetMode === 'new') {
                if ($newIblockName === '' || $newIblockType === '') {
                    throw new InvalidArgumentException(
                        (string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_REQUIRED')
                    );
                }
                $form['target_id'] = ServiceFactory::iblockCreator()->create(
                    $newIblockName,
                    $newIblockType,
                    $newIblockCode
                );
                $createdIblock = true;
                $targetMode = 'existing';
            } elseif ($form['target_id'] < 1) {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_PROFILE_TARGET_REQUIRED'));
            }
            $form['source_config']['sheet'] = $sheet;
            $id = ServiceFactory::profiles()->save([
                'id' => $id,
                'name' => $form['name'],
                'target_id' => $form['target_id'],
                'active' => $form['active'],
                'mapping' => $form['mapping'],
                'options' => $form['options'],
                'source_config' => $form['source_config'],
            ]);
            if (isset($_POST['save_and_run'])) {
                $query = [
                    'profile_id' => $id,
                    'lang' => LANGUAGE_ID,
                    'profile_saved' => 'Y',
                ];
                if ($createdIblock) {
                    $query['iblock_created'] = 'Y';
                }
                if ($previewToken !== '') {
                    $sourcePath = ServiceFactory::previewUploads()->transfer(
                        $previewToken,
                        ServiceFactory::uploads()
                    );
                    $query['source_token'] = ServiceFactory::uploads()->token($sourcePath);
                    $previewToken = '';
                }
                header('Location: webenot_importexcel_run.php?' . http_build_query($query), true, 302);
                exit;
            }
            if ($previewToken !== '') {
                ServiceFactory::previewUploads()->remove($previewToken);
            }
            $query = ['ID' => $id, 'lang' => LANGUAGE_ID, 'saved' => 'Y'];
            if ($createdIblock) {
                $query['iblock_created'] = 'Y';
            }
            header('Location: webenot_importexcel_profile_edit.php?' . http_build_query($query), true, 302);
            exit;
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

foreach ($previewRows as $previewRow) {
    foreach (array_keys((array) ($previewRow['cells'] ?? [])) as $column) {
        $previewColumns[(string) $column] = true;
    }
}
$previewColumnNames = array_keys($previewColumns);
$previewColumnsTruncated = count($previewColumnNames) > 30;
$previewColumns = array_slice($previewColumnNames, 0, 30);

$iblocks = [];
$iblockResult = CIBlock::GetList(['IBLOCK_TYPE_ID' => 'ASC', 'NAME' => 'ASC'], []);
while ($iblock = $iblockResult->Fetch()) {
    $iblocks[] = $iblock;
}
$iblockPropertyOptions = [];
$propertyResult = CIBlockProperty::GetList(['SORT' => 'ASC', 'NAME' => 'ASC'], []);
while ($property = $propertyResult->Fetch()) {
    $iblockId = (int) ($property['IBLOCK_ID'] ?? 0);
    $propertyCode = strtoupper(trim((string) ($property['CODE'] ?? '')));
    $propertyType = strtoupper(trim((string) ($property['PROPERTY_TYPE'] ?? 'S')));
    if (
        $iblockId < 1
        || preg_match('/^[A-Z][A-Z0-9_]*$/', $propertyCode) !== 1
        || !isset($propertyTypeMessages[$propertyType])
    ) {
        continue;
    }

    $multiple = ($property['MULTIPLE'] ?? 'N') === 'Y';
    $typeLabel = (string) Loc::getMessage($propertyTypeMessages[$propertyType]);
    if ($multiple) {
        $typeLabel .= ', ' . (string) Loc::getMessage('WIE_PROFILE_PROPERTY_MULTIPLE_SHORT');
    }
    $propertyLabel = trim((string) ($property['NAME'] ?? '')) ?: $propertyCode;
    $optionLabel = sprintf('%s [%s] · %s', $propertyLabel, $propertyCode, $typeLabel);
    if (($property['ACTIVE'] ?? 'Y') !== 'Y') {
        $optionLabel .= ' · ' . (string) Loc::getMessage('WIE_PROFILE_PROPERTY_INACTIVE');
    }

    $iblockPropertyOptions[$iblockId][$propertyCode] = [
        'value' => MappingChoice::existingProperty($propertyCode, $propertyType, $multiple),
        'label' => $optionLabel,
        'name' => $propertyLabel,
        'code' => $propertyCode,
    ];
}
$hasSectionMapping = (bool) array_filter(
    $form['mapping'],
    static fn(array $rule): bool => SectionPath::isTarget((string) ($rule['target'] ?? ''))
);
$iblockTypes = [];
$iblockTypeResult = CIBlockType::GetList(['SORT' => 'ASC', 'ID' => 'ASC']);
while ($iblockType = $iblockTypeResult->Fetch()) {
    $typeId = (string) $iblockType['ID'];
    $typeLanguage = CIBlockType::GetByIDLang($typeId, LANGUAGE_ID, true);
    $iblockTypes[$typeId] = (string) ($typeLanguage['NAME'] ?? $typeId);
}
if (!isset($iblockTypes[$newIblockType])) {
    $newIblockType = (string) (array_key_first($iblockTypes) ?? '');
}

$mappingSourceCodes = [];
$usedPropertyCodes = [];
$headerNormalizer = new HeaderNormalizer();
foreach ($form['mapping'] as $index => $rule) {
    $baseCode = $headerNormalizer->normalize(
        (string) ($rule['label'] ?? ''),
        'COLUMN_' . (string) ($rule['column'] ?? $index + 1)
    );
    $propertyCode = $baseCode;
    $suffix = 2;
    while (isset($usedPropertyCodes[$propertyCode])) {
        $tail = '_' . $suffix++;
        $propertyCode = substr($baseCode, 0, 50 - strlen($tail)) . $tail;
    }
    $mappingSourceCodes[$index] = $propertyCode;
    $usedPropertyCodes[$propertyCode] = true;
}

$selectedIblockProperties = $iblockPropertyOptions[(int) $form['target_id']] ?? [];
$iblockPropertyOptionsForJs = [];
foreach ($iblockPropertyOptions as $iblockId => $options) {
    $iblockPropertyOptionsForJs[(string) $iblockId] = array_values($options);
}
$iblockPropertyOptionsJson = json_encode(
    $iblockPropertyOptionsForJs,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$uniqueTargets = [];
foreach ($form['mapping'] as $index => $rule) {
    $target = (string) ($rule['target'] ?? '');
    if ($target !== '' && !SectionPath::isTarget($target)) {
        $uniqueTargets[$target] = [
            'label' => (string) ($rule['label'] ?? $target),
            'source_code' => (string) ($mappingSourceCodes[$index] ?? ''),
        ];
    }
}
$currentUniqueTarget = (string) ($form['options']['unique_target'] ?? 'FIELD:XML_ID');
if (!isset($uniqueTargets[$currentUniqueTarget])) {
    $currentUniqueTarget = (string) (array_key_first($uniqueTargets) ?? '');
}

$title = $id > 0
    ? (string) Loc::getMessage('WIE_PROFILE_TITLE_EDIT')
    : (string) Loc::getMessage('WIE_PROFILE_TITLE_NEW');
$APPLICATION->SetTitle($title);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

AdminUi::renderStyles();
if (($_GET['saved'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage(['MESSAGE' => Loc::getMessage('WIE_PROFILE_SAVED'), 'TYPE' => 'OK']);
}
if (($_GET['iblock_created'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage(['MESSAGE' => Loc::getMessage('WIE_PROFILE_IBLOCK_CREATED'), 'TYPE' => 'OK']);
}
foreach ($errors as $error) {
    CAdminMessage::ShowMessage(['MESSAGE' => $error, 'TYPE' => 'ERROR']);
}
?>
<div class="wie-page">
    <p class="wie-intro"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_INTRO')) ?></p>
    <div class="wie-steps">
        <div class="wie-step"><span class="wie-step-number">1</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_STEP_1')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_STEP_1_TEXT')) ?></span></div></div>
        <div class="wie-step"><span class="wie-step-number">2</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_STEP_2')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_STEP_2_TEXT')) ?></span></div></div>
        <div class="wie-step"><span class="wie-step-number">3</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_STEP_3')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_STEP_3_TEXT')) ?></span></div></div>
    </div>

    <form method="post" enctype="multipart/form-data">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="ID" value="<?= $id ?>">

        <section class="wie-section">
            <h2><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SECTION_MAIN')) ?></h2>
            <p class="wie-section-hint"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SECTION_MAIN_TEXT')) ?></p>
            <div class="wie-grid">
                <div class="wie-field">
                    <label class="wie-label" for="wie-name"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NAME')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_NAME_HINT')); ?></label>
                    <input id="wie-name" name="name" required value="<?= htmlspecialcharsbx($form['name']) ?>" placeholder="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NAME_PLACEHOLDER')) ?>">
                </div>
                <div class="wie-field">
                    <label class="wie-label" for="wie-target"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_TARGET')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_TARGET_HINT')); ?></label>
                    <input id="wie-target-mode" type="hidden" name="target_mode" value="<?= htmlspecialcharsbx($targetMode) ?>">
                    <div class="wie-target-picker">
                        <select id="wie-target" name="target_id">
                            <option value=""><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_TARGET_CHOOSE')) ?></option>
                            <?php foreach ($iblocks as $iblock) :
                                $iblockId = (int) $iblock['ID'];
                                $iblockLabel = (string) $iblock['NAME'] . ' · ' . (string) $iblock['IBLOCK_TYPE_ID'] . ' · ID ' . $iblockId;
                                $supportsNestedUrl = str_contains(
                                    (string) ($iblock['SECTION_PAGE_URL'] ?? ''),
                                    '#SECTION_CODE_PATH#'
                                ) && str_contains(
                                    (string) ($iblock['DETAIL_PAGE_URL'] ?? ''),
                                    '#SECTION_CODE_PATH#'
                                );
                                if (($iblock['ACTIVE'] ?? 'Y') !== 'Y') {
                                    $iblockLabel .= ' · ' . (string) Loc::getMessage('WIE_PROFILE_TARGET_INACTIVE');
                                }
                                ?>
                                <option value="<?= $iblockId ?>" data-nested-url="<?= $supportsNestedUrl ? 'Y' : 'N' ?>"<?= $form['target_id'] === $iblockId ? ' selected' : '' ?>><?= htmlspecialcharsbx($iblockLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button id="wie-create-target" class="wie-secondary wie-button-nowrap" type="button"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_TARGET_CREATE')) ?></button>
                    </div>
                    <?php if ($hasSectionMapping) : ?>
                        <div class="wie-url-warning" data-url-warning hidden>
                            <strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_TARGET_URL_WARNING_TITLE')) ?></strong>
                            <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_TARGET_URL_WARNING_TEXT')) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div id="wie-new-target" class="wie-field wie-field-wide wie-new-target"<?= $targetMode === 'new' ? '' : ' hidden' ?>>
                    <div class="wie-new-target-head">
                        <div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_TITLE')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_TEXT')) ?></span></div>
                        <button id="wie-cancel-target" class="wie-link-button" type="button"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_CANCEL')) ?></button>
                    </div>
                    <div class="wie-grid">
                        <div class="wie-field">
                            <label class="wie-label" for="wie-new-target-name"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_NAME')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_NAME_HINT')); ?></label>
                            <input id="wie-new-target-name" name="new_iblock_name" value="<?= htmlspecialcharsbx($newIblockName) ?>" placeholder="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_NAME_PLACEHOLDER')) ?>">
                        </div>
                        <div class="wie-field">
                            <label class="wie-label" for="wie-new-target-type"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_TYPE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_TYPE_HINT')); ?></label>
                            <select id="wie-new-target-type" name="new_iblock_type">
                                <?php foreach ($iblockTypes as $typeId => $typeName) : ?>
                                    <option value="<?= htmlspecialcharsbx($typeId) ?>"<?= $newIblockType === $typeId ? ' selected' : '' ?>><?= htmlspecialcharsbx($typeName . ' · ' . $typeId) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wie-field wie-field-wide">
                            <label class="wie-label" for="wie-new-target-code"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_CODE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_CODE_HINT')); ?></label>
                            <input id="wie-new-target-code" name="new_iblock_code" value="<?= htmlspecialcharsbx($newIblockCode) ?>" placeholder="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_TARGET_CODE_PLACEHOLDER')) ?>">
                        </div>
                    </div>
                </div>
                <div class="wie-field wie-field-wide">
                    <label class="wie-check"><input name="active" type="checkbox" value="Y"<?= $form['active'] ? ' checked' : '' ?>><span class="wie-check-text"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_ACTIVE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_ACTIVE_HINT')); ?></span></label>
                </div>
            </div>
        </section>

        <div id="wie-property-dialog" class="wie-modal" hidden aria-hidden="true">
            <div class="wie-modal-backdrop" data-property-dialog-cancel></div>
            <div class="wie-modal-window" role="dialog" aria-modal="true" aria-labelledby="wie-property-dialog-title">
                <div class="wie-modal-head">
                    <div>
                        <h2 id="wie-property-dialog-title"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_TITLE')) ?></h2>
                        <p id="wie-property-dialog-source"></p>
                    </div>
                    <button class="wie-modal-close" type="button" data-property-dialog-cancel aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_CANCEL')) ?>">×</button>
                </div>
                <div class="wie-modal-body">
                    <div id="wie-property-dialog-error" class="wie-property-error" hidden></div>
                    <div class="wie-grid">
                        <div class="wie-field">
                            <label class="wie-label" for="wie-property-type"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_TYPE')) ?></label>
                            <select id="wie-property-type">
                                <option value="S"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PROPERTY_TYPE_STRING')) ?></option>
                                <option value="N"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PROPERTY_TYPE_NUMBER')) ?></option>
                                <option value="L"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PROPERTY_TYPE_LIST')) ?></option>
                                <option value="F"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PROPERTY_TYPE_FILE')) ?></option>
                                <option value="E"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PROPERTY_TYPE_ELEMENT')) ?></option>
                                <option value="G"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PROPERTY_TYPE_SECTION')) ?></option>
                            </select>
                        </div>
                        <div class="wie-field">
                            <label class="wie-label" for="wie-property-sort"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_SORT')) ?></label>
                            <input id="wie-property-sort" type="number" min="0" value="500">
                        </div>
                        <div class="wie-field">
                            <label class="wie-label" for="wie-property-name"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_NAME')) ?></label>
                            <input id="wie-property-name" type="text" maxlength="100">
                        </div>
                        <div class="wie-field">
                            <label class="wie-label" for="wie-property-code"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_CODE')) ?></label>
                            <input id="wie-property-code" class="wie-property-code-edit" type="text" maxlength="50" autocomplete="off">
                            <p class="wie-field-note"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_CODE_NOTE')) ?></p>
                        </div>
                        <div id="wie-property-link-wrap" class="wie-field wie-field-wide" hidden>
                            <label class="wie-label" for="wie-property-link-iblock"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_LINK_IBLOCK')) ?></label>
                            <select id="wie-property-link-iblock">
                                <option value="0"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_LINK_CURRENT')) ?></option>
                                <?php foreach ($iblocks as $iblock) : ?>
                                    <option value="<?= (int) $iblock['ID'] ?>"><?= htmlspecialcharsbx((string) $iblock['NAME'] . ' · ID ' . (int) $iblock['ID']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wie-field wie-field-wide">
                            <label class="wie-label" for="wie-property-hint"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_HINT')) ?></label>
                            <input id="wie-property-hint" type="text" maxlength="255">
                        </div>
                    </div>
                    <div class="wie-property-flags">
                        <label><input id="wie-property-active" type="checkbox" checked> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_ACTIVE')) ?></span></label>
                        <label><input id="wie-property-multiple" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_MULTIPLE')) ?></span></label>
                        <label><input id="wie-property-is-required" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_IS_REQUIRED')) ?></span></label>
                        <label><input id="wie-property-searchable" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_SEARCHABLE')) ?></span></label>
                        <label><input id="wie-property-filtrable" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_FILTRABLE')) ?></span></label>
                        <label><input id="wie-property-with-description" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_WITH_DESCRIPTION')) ?></span></label>
                        <label><input id="wie-property-show-edit" type="checkbox" checked> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_SHOW_EDIT')) ?></span></label>
                    </div>
                    <p class="wie-field-note"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_REQUIRED_NOTE')) ?></p>
                    <details class="wie-property-advanced">
                        <summary><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_ADVANCED')) ?></summary>
                        <div class="wie-grid">
                            <div class="wie-field">
                                <label class="wie-label" for="wie-property-multiple-count"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_MULTIPLE_COUNT')) ?></label>
                                <input id="wie-property-multiple-count" type="number" min="1" value="5">
                            </div>
                            <div class="wie-field">
                                <label class="wie-label" for="wie-property-default-value"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_DEFAULT_VALUE')) ?></label>
                                <input id="wie-property-default-value" type="text">
                            </div>
                            <div class="wie-field">
                                <label class="wie-label"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_FIELD_SIZE')) ?></label>
                                <div class="wie-property-size">
                                    <input id="wie-property-row-count" type="number" min="1" value="1" aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_ROWS')) ?>">
                                    <span>×</span>
                                    <input id="wie-property-col-count" type="number" min="1" value="30" aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_COLUMNS')) ?>">
                                </div>
                            </div>
                            <div class="wie-field wie-property-smart-settings">
                                <label class="wie-label" for="wie-property-display-type"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_DISPLAY_TYPE')) ?></label>
                                <select id="wie-property-display-type">
                                    <option value="F"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_DISPLAY_CHECKBOXES')) ?></option>
                                    <option value="K"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_DISPLAY_RADIO')) ?></option>
                                    <option value="P"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_DISPLAY_SELECT')) ?></option>
                                </select>
                            </div>
                            <div class="wie-field wie-field-wide wie-property-smart-settings">
                                <label class="wie-label" for="wie-property-filter-hint"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_FILTER_HINT')) ?></label>
                                <textarea id="wie-property-filter-hint" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="wie-property-flags">
                            <label><input id="wie-property-smart-filter" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_SMART_FILTER')) ?></span></label>
                            <label class="wie-property-smart-settings"><input id="wie-property-display-expanded" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_DISPLAY_EXPANDED')) ?></span></label>
                            <label><input id="wie-property-show-list" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_SHOW_LIST')) ?></span></label>
                            <label><input id="wie-property-show-detail" type="checkbox"> <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_SHOW_DETAIL')) ?></span></label>
                        </div>
                    </details>
                </div>
                <div class="wie-modal-actions">
                    <button id="wie-property-dialog-save" class="wie-primary" type="button"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_SAVE')) ?></button>
                    <button class="wie-secondary" type="button" data-property-dialog-cancel><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_CANCEL')) ?></button>
                </div>
            </div>
        </div>

        <section class="wie-section">
            <h2><span class="wie-section-number">1.</span> <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SECTION_DISCOVERY')) ?></h2>
            <p class="wie-section-hint"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SECTION_DISCOVERY_TEXT')) ?></p>
            <div class="wie-discovery">
                <input type="hidden" name="preview_token" value="<?= htmlspecialcharsbx($previewToken) ?>">
                <div class="wie-grid">
                    <div class="wie-field wie-field-wide">
                        <label class="wie-label" for="wie-sample"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SAMPLE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_SAMPLE_HINT')); ?></label>
                        <input id="wie-sample" name="sample" type="file" accept=".xlsx,.xls,.ods,.csv">
                        <?php if ($previewToken !== '') : ?>
                            <p class="wie-field-note wie-file-ready"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_FILE_READY')) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($previewRows === []) : ?>
                    <div class="wie-actions">
                        <button class="wie-primary" type="submit" name="preview" value="Y" formnovalidate><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW')) ?></button>
                        <span class="wie-field-note"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_NOTE')) ?></span>
                    </div>
                <?php else : ?>
                    <div class="wie-preview-toolbar">
                        <div class="wie-field">
                            <label class="wie-label" for="wie-sheet"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SHEET')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_SHEET_HINT')); ?></label>
                            <select id="wie-sheet" name="sheet">
                                <?php foreach ($previewSheets as $sheetName) : ?>
                                    <option value="<?= htmlspecialcharsbx($sheetName) ?>"<?= $sheet === $sheetName ? ' selected' : '' ?>><?= htmlspecialcharsbx($sheetName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wie-field">
                            <label class="wie-label" for="wie-preview-start"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_START')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_PREVIEW_START_HINT')); ?></label>
                            <input id="wie-preview-start" name="preview_start_row" type="number" min="1" value="<?= $previewStartRow ?>">
                        </div>
                        <div class="wie-preview-refresh">
                            <button class="wie-secondary" type="submit" name="preview" value="Y" formnovalidate><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_REFRESH')) ?></button>
                        </div>
                    </div>

                    <div class="wie-preview-head">
                        <div>
                            <strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_TITLE')) ?></strong>
                            <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_TEXT')) ?></span>
                        </div>
                        <span id="wie-selected-row" class="wie-badge wie-badge-info" data-template="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_SELECTED')) ?>"><?= htmlspecialcharsbx(str_replace('#ROW#', (string) $form['options']['header_row'], (string) Loc::getMessage('WIE_PROFILE_PREVIEW_SELECTED'))) ?></span>
                    </div>
                    <div class="wie-preview-wrap">
                        <table class="wie-preview-table">
                            <thead><tr>
                                <th class="wie-preview-row-number"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_ROW')) ?></th>
                                <?php foreach ($previewColumns as $column) : ?>
                                    <th><?= htmlspecialcharsbx($column) ?></th>
                                <?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($previewRows as $previewRow) :
                                $rowNumber = (int) $previewRow['row'];
                                $selected = $rowNumber === (int) $form['options']['header_row'];
                                ?>
                                <tr class="wie-preview-row<?= $selected ? ' wie-preview-row-selected' : '' ?>" data-preview-row="<?= $rowNumber ?>">
                                    <td class="wie-preview-row-number">
                                        <label>
                                            <input type="radio" name="header_row" value="<?= $rowNumber ?>"<?= $selected ? ' checked' : '' ?>>
                                            <strong><?= $rowNumber ?></strong>
                                        </label>
                                    </td>
                                    <?php foreach ($previewColumns as $column) :
                                        $cellValue = webenotImportExcelPreviewValue($previewRow['cells'][$column] ?? null);
                                        ?>
                                        <td title="<?= htmlspecialcharsbx($cellValue) ?>"><?= $cellValue !== '' ? htmlspecialcharsbx($cellValue) : '<span class="wie-preview-empty">—</span>' ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($previewColumnsTruncated) : ?>
                        <p class="wie-field-note"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_PREVIEW_COLUMNS_TRUNCATED')) ?></p>
                    <?php endif; ?>
                    <div class="wie-actions">
                        <button class="wie-primary" type="submit" name="discover" value="Y" formnovalidate><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_DISCOVER_SELECTED')) ?></button>
                        <span class="wie-field-note"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_DISCOVER_NOTE')) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="wie-section">
            <h2><span class="wie-section-number">2.</span> <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SECTION_MAPPING')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_SECTION_MAPPING_HINT')); ?></h2>
            <p class="wie-section-hint"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SECTION_MAPPING_TEXT')) ?></p>
            <?php if ($form['mapping'] === []) : ?>
                <div class="wie-empty"><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_EMPTY')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_EMPTY_TEXT')) ?></span></div>
            <?php else : ?>
                <div class="wie-status-help" role="note">
                    <strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_HELP_TITLE')) ?></strong>
                    <p><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_HELP_TEXT')) ?></p>
                    <div class="wie-status-help-list">
                        <div class="wie-status-help-item">
                            <span class="wie-mapping-status wie-mapping-status-recognized"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_RECOGNIZED')) ?></span>
                            <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_RECOGNIZED_HELP')) ?></span>
                        </div>
                        <div class="wie-status-help-item">
                            <span class="wie-mapping-status wie-mapping-status-required"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_REQUIRED')) ?></span>
                            <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_REQUIRED_HELP')) ?></span>
                        </div>
                        <div class="wie-status-help-item">
                            <span class="wie-mapping-status wie-mapping-status-configured"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_CONFIGURED')) ?></span>
                            <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_CONFIGURED_HELP')) ?></span>
                        </div>
                    </div>
                </div>
                <div class="wie-mapping-wrap">
                    <table class="wie-mapping">
                        <thead><tr>
                            <th class="wie-col-use"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_USE')) ?></th>
                            <th class="wie-col-source"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_COLUMN')) ?></th>
                            <th><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_HEADER')) ?></th>
                            <th class="wie-col-status"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS')) ?></th>
                            <th><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_TARGET')) ?></th>
                            <th class="wie-col-code"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_SOURCE_CODE')) ?></th>
                            <th class="wie-col-required"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_REQUIRED')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($form['mapping'] as $index => $rule) :
                            $choice = MappingChoice::fromRule($rule);
                            $sourceCode = (string) ($mappingSourceCodes[$index] ?? 'COLUMN_' . ($index + 1));
                            $ruleTarget = strtoupper((string) ($rule['target'] ?? ''));
                            if (str_starts_with($ruleTarget, 'PROPERTY:')) {
                                $existingPropertyCode = substr($ruleTarget, 9);
                                if (isset($selectedIblockProperties[$existingPropertyCode])) {
                                    $choice = (string) $selectedIblockProperties[$existingPropertyCode]['value'];
                                } elseif (!empty($rule['unconfirmed'])) {
                                    $sourceName = mb_strtolower(trim((string) ($rule['label'] ?? '')));
                                    $nameMatches = array_values(array_filter(
                                        $selectedIblockProperties,
                                        static fn(array $property): bool => mb_strtolower(
                                            trim((string) ($property['name'] ?? ''))
                                        ) === $sourceName
                                    ));
                                    if (count($nameMatches) === 1) {
                                        $choice = (string) $nameMatches[0]['value'];
                                    }
                                }
                            }
                            $isAutomaticChoice = $choice !== ''
                                && (!empty($rule['auto_selected']) || !empty($rule['unconfirmed']));
                            $isNewProperty = $choice === MappingChoice::PROPERTY_NEW;
                            $newPropertyCode = $isNewProperty && str_starts_with($ruleTarget, 'PROPERTY:')
                                ? substr($ruleTarget, 9)
                                : $sourceCode;
                            $newPropertyName = trim((string) ($rule['property_name'] ?? $rule['label'] ?? ''));
                            $newPropertyType = strtoupper((string) ($rule['property_type'] ?? 'S'));
                            if (!in_array($newPropertyType, ['S', 'N', 'L', 'F', 'E', 'G'], true)) {
                                $newPropertyType = 'S';
                            }
                            $newPropertyActive = !array_key_exists('property_active', $rule)
                                || (bool) $rule['property_active'];
                            $transformsJson = json_encode($rule['transforms'] ?? [['type' => 'trim']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $defaultJson = array_key_exists('default', $rule)
                                ? json_encode($rule['default'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : '';
                            ?>
                            <tr>
                                <td class="wie-col-use"><input type="checkbox" name="mapping_rows[<?= (int) $index ?>][enabled]" value="Y" checked aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_USE')) ?>"></td>
                                <td><strong><?= htmlspecialcharsbx((string) ($rule['column'] ?? '')) ?></strong><input type="hidden" name="mapping_rows[<?= (int) $index ?>][column]" value="<?= htmlspecialcharsbx((string) ($rule['column'] ?? '')) ?>"></td>
                                <td><span class="wie-source-label"><?= htmlspecialcharsbx((string) ($rule['label'] ?? '')) ?></span><input type="hidden" name="mapping_rows[<?= (int) $index ?>][label]" value="<?= htmlspecialcharsbx((string) ($rule['label'] ?? '')) ?>"></td>
                                <td class="wie-col-status"><span class="wie-mapping-status" data-recognized="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_RECOGNIZED')) ?>" data-required="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_REQUIRED')) ?>" data-configured="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_STATUS_CONFIGURED')) ?>"></span></td>
                                <td>
                                    <select class="wie-mapping-choice" name="mapping_rows[<?= (int) $index ?>][choice]" aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_TARGET_TYPE')) ?>" data-auto-choice="<?= $isAutomaticChoice ? htmlspecialcharsbx($choice) : '' ?>" required>
                                        <option value=""<?= $choice === '' ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_SELECT_VALUE')) ?></option>
                                        <optgroup data-existing-properties label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_GROUP_EXISTING_PROPERTIES')) ?>">
                                            <?php foreach ($selectedIblockProperties as $propertyOption) : ?>
                                                <option value="<?= htmlspecialcharsbx((string) $propertyOption['value']) ?>"<?= $choice === $propertyOption['value'] ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) $propertyOption['label']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <optgroup label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_GROUP_NEW_PROPERTIES')) ?>">
                                            <option value="<?= MappingChoice::PROPERTY_NEW ?>"<?= $isNewProperty ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_PROPERTY_NEW')) ?></option>
                                        </optgroup>
                                        <?php foreach ($elementFieldGroups as $groupMessage => $fieldCodes) : ?>
                                            <optgroup label="<?= htmlspecialcharsbx((string) Loc::getMessage($groupMessage)) ?>">
                                                <?php foreach ($fieldCodes as $fieldCode) : ?>
                                                    <option value="FIELD:<?= $fieldCode ?>"<?= $choice === 'FIELD:' . $fieldCode ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage($elementFieldMessages[$fieldCode])) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                        <?php for ($level = 1; $level <= SectionPath::MAX_LEVEL; $level++) : ?>
                                            <optgroup label="<?= htmlspecialcharsbx(sprintf((string) Loc::getMessage('WIE_PROFILE_SECTION_LEVEL_GROUP'), $level)) ?>">
                                                <?php foreach (SectionFieldCatalog::codes() as $sectionFieldCode) :
                                                    $sectionDestination = SectionPath::target($level, $sectionFieldCode);
                                                    ?>
                                                    <option value="<?= htmlspecialcharsbx($sectionDestination) ?>"<?= $choice === $sectionDestination ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage($sectionFieldMessages[$sectionFieldCode])) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endfor; ?>
                                    </select>
                                    <input type="hidden" name="mapping_rows[<?= (int) $index ?>][transforms_json]" value="<?= htmlspecialcharsbx((string) $transformsJson) ?>">
                                    <input type="hidden" name="mapping_rows[<?= (int) $index ?>][default_json]" value="<?= htmlspecialcharsbx((string) $defaultJson) ?>">
                                </td>
                                <td class="wie-col-code" data-source-code="<?= htmlspecialcharsbx($sourceCode) ?>">
                                    <button class="wie-property-settings" type="button"<?= $isNewProperty ? '' : ' hidden' ?>>
                                        <strong class="wie-property-settings-code"><?= htmlspecialcharsbx($newPropertyCode) ?></strong>
                                        <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_CONFIGURE')) ?></span>
                                    </button>
                                    <span class="wie-property-not-used"<?= $isNewProperty ? ' hidden' : '' ?>>—</span>
                                    <input type="hidden" data-property-setting="code" name="mapping_rows[<?= (int) $index ?>][property_code]" value="<?= htmlspecialcharsbx($newPropertyCode) ?>">
                                    <input type="hidden" data-property-setting="name" name="mapping_rows[<?= (int) $index ?>][property_name]" value="<?= htmlspecialcharsbx($newPropertyName) ?>">
                                    <input type="hidden" data-property-setting="type" name="mapping_rows[<?= (int) $index ?>][property_type]" value="<?= htmlspecialcharsbx($newPropertyType) ?>">
                                    <input type="hidden" data-property-setting="active" name="mapping_rows[<?= (int) $index ?>][property_active]" value="<?= $newPropertyActive ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="sort" name="mapping_rows[<?= (int) $index ?>][property_sort]" value="<?= (int) ($rule['property_sort'] ?? 500) ?>">
                                    <input type="hidden" data-property-setting="multiple" name="mapping_rows[<?= (int) $index ?>][property_multiple]" value="<?= !empty($rule['multiple']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="is_required" name="mapping_rows[<?= (int) $index ?>][property_is_required]" value="<?= !empty($rule['property_is_required']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="searchable" name="mapping_rows[<?= (int) $index ?>][property_searchable]" value="<?= !empty($rule['property_searchable']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="filtrable" name="mapping_rows[<?= (int) $index ?>][property_filtrable]" value="<?= !empty($rule['property_filtrable']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="smart_filter" name="mapping_rows[<?= (int) $index ?>][property_smart_filter]" value="<?= !empty($rule['property_smart_filter']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="with_description" name="mapping_rows[<?= (int) $index ?>][property_with_description]" value="<?= !empty($rule['property_with_description']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="multiple_count" name="mapping_rows[<?= (int) $index ?>][property_multiple_count]" value="<?= max(1, (int) ($rule['property_multiple_count'] ?? 5)) ?>">
                                    <input type="hidden" data-property-setting="hint" name="mapping_rows[<?= (int) $index ?>][property_hint]" value="<?= htmlspecialcharsbx((string) ($rule['property_hint'] ?? '')) ?>">
                                    <input type="hidden" data-property-setting="show_edit" name="mapping_rows[<?= (int) $index ?>][property_show_edit]" value="<?= !array_key_exists('property_show_edit', $rule) || !empty($rule['property_show_edit']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="display_type" name="mapping_rows[<?= (int) $index ?>][property_display_type]" value="<?= htmlspecialcharsbx((string) ($rule['property_display_type'] ?? 'F')) ?>">
                                    <input type="hidden" data-property-setting="display_expanded" name="mapping_rows[<?= (int) $index ?>][property_display_expanded]" value="<?= !empty($rule['property_display_expanded']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="filter_hint" name="mapping_rows[<?= (int) $index ?>][property_filter_hint]" value="<?= htmlspecialcharsbx((string) ($rule['property_filter_hint'] ?? '')) ?>">
                                    <input type="hidden" data-property-setting="row_count" name="mapping_rows[<?= (int) $index ?>][property_row_count]" value="<?= max(1, (int) ($rule['property_row_count'] ?? 1)) ?>">
                                    <input type="hidden" data-property-setting="col_count" name="mapping_rows[<?= (int) $index ?>][property_col_count]" value="<?= max(1, (int) ($rule['property_col_count'] ?? 30)) ?>">
                                    <input type="hidden" data-property-setting="default_value" name="mapping_rows[<?= (int) $index ?>][property_default_value]" value="<?= htmlspecialcharsbx((string) ($rule['property_default_value'] ?? '')) ?>">
                                    <input type="hidden" data-property-setting="show_list" name="mapping_rows[<?= (int) $index ?>][property_show_list]" value="<?= !empty($rule['property_show_list']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="show_detail" name="mapping_rows[<?= (int) $index ?>][property_show_detail]" value="<?= !empty($rule['property_show_detail']) ? 'Y' : 'N' ?>">
                                    <input type="hidden" data-property-setting="link_iblock_id" name="mapping_rows[<?= (int) $index ?>][property_link_iblock_id]" value="<?= (int) ($rule['property_link_iblock_id'] ?? 0) ?>">
                                </td>
                                <td class="wie-col-required"><input type="checkbox" name="mapping_rows[<?= (int) $index ?>][required]" value="Y"<?= !empty($rule['required']) ? ' checked' : '' ?> aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_REQUIRED')) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="wie-field-note"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_NOTE')) ?></p>
            <?php endif; ?>
        </section>

        <section class="wie-section">
            <h2><span class="wie-section-number">3.</span> <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SECTION_RULES')) ?></h2>
            <p class="wie-section-hint"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SECTION_RULES_TEXT')) ?></p>
            <div class="wie-grid">
                <div class="wie-field">
                    <label class="wie-label" for="wie-unique"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_UNIQUE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_UNIQUE_HINT')); ?></label>
                    <select id="wie-unique" name="unique_target" required>
                        <?php foreach ($uniqueTargets as $target => $uniqueTarget) : ?>
                            <option value="<?= htmlspecialcharsbx($target) ?>"<?= $currentUniqueTarget === $target ? ' selected' : '' ?>><?= htmlspecialcharsbx($uniqueTarget['label'] . ' · ' . $uniqueTarget['source_code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wie-field">
                    <label class="wie-label" for="wie-mode"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_WRITE_MODE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_WRITE_MODE_HINT')); ?></label>
                    <select id="wie-mode" name="write_mode">
                        <?php foreach (['upsert' => 'WIE_PROFILE_MODE_UPSERT', 'insert' => 'WIE_PROFILE_MODE_INSERT', 'update' => 'WIE_PROFILE_MODE_UPDATE'] as $value => $message) : ?>
                            <option value="<?= $value ?>"<?= ($form['options']['write_mode'] ?? 'upsert') === $value ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage($message)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wie-field">
                    <label class="wie-label" for="wie-element-code-source"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_ELEMENT_CODE_SOURCE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_ELEMENT_CODE_SOURCE_HINT')); ?></label>
                    <select id="wie-element-code-source" name="element_code_source">
                        <?php foreach (['name' => 'WIE_PROFILE_ELEMENT_CODE_NAME', 'unique' => 'WIE_PROFILE_ELEMENT_CODE_UNIQUE', 'none' => 'WIE_PROFILE_ELEMENT_CODE_NONE'] as $value => $message) : ?>
                            <option value="<?= $value ?>"<?= ($form['options']['element_code_source'] ?? 'name') === $value ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage($message)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wie-field">
                    <label class="wie-label" for="wie-start-row"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_START_ROW')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_START_ROW_HINT')); ?></label>
                    <input id="wie-start-row" name="start_row" type="number" min="2" value="<?= (int) $form['options']['start_row'] ?>">
                </div>
                <div class="wie-field">
                    <label class="wie-label" for="wie-chunk"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_CHUNK')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_CHUNK_HINT')); ?></label>
                    <input id="wie-chunk" name="chunk_size" type="number" min="10" max="1000" value="<?= (int) $form['options']['chunk_size'] ?>">
                </div>
                <div class="wie-field">
                    <label class="wie-check"><input name="auto_create_properties" type="checkbox" value="Y"<?= ($form['options']['auto_create_properties'] ?? true) ? ' checked' : '' ?>><span class="wie-check-text"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_AUTO_PROPERTIES')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_AUTO_PROPERTIES_HINT')); ?></span></label>
                </div>
                <div class="wie-field">
                    <label class="wie-check"><input name="stop_on_error" type="checkbox" value="Y"<?= ($form['options']['stop_on_error'] ?? false) ? ' checked' : '' ?>><span class="wie-check-text"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_STOP_ERROR')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_STOP_ERROR_HINT')); ?></span></label>
                </div>
            </div>
            <details class="wie-tech">
                <summary><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_CSV_ADVANCED')) ?></summary>
                <div class="wie-tech-content">
                    <div class="wie-grid">
                        <div class="wie-field">
                            <label class="wie-label" for="wie-delimiter"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_DELIMITER')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_DELIMITER_HINT')); ?></label>
                            <input id="wie-delimiter" name="delimiter" maxlength="4" value="<?= htmlspecialcharsbx((string) ($form['options']['delimiter'] ?? ';')) ?>">
                        </div>
                        <div class="wie-field">
                            <label class="wie-label" for="wie-encoding"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_ENCODING')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_ENCODING_HINT')); ?></label>
                            <select id="wie-encoding" name="encoding">
                                <?php foreach (['UTF-8', 'Windows-1251', 'ISO-8859-1'] as $encoding) : ?>
                                    <option value="<?= $encoding ?>"<?= ($form['options']['encoding'] ?? 'UTF-8') === $encoding ? ' selected' : '' ?>><?= $encoding ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </details>
        </section>

        <div class="wie-actions">
            <button class="wie-primary" type="submit" name="save_and_run" value="Y"><?= htmlspecialcharsbx((string) Loc::getMessage($previewToken !== '' ? 'WIE_PROFILE_SAVE_AND_CHECK' : 'WIE_PROFILE_SAVE_AND_RUN')) ?></button>
            <button class="wie-secondary" type="submit" name="save" value="Y"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SAVE_ONLY')) ?></button>
            <a class="wie-secondary" href="webenot_importexcel_profiles.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_BACK')) ?></a>
        </div>
    </form>
</div>
<script>
(function () {
    var rows = document.querySelectorAll('[data-preview-row]');
    var badge = document.getElementById('wie-selected-row');
    var startRow = document.getElementById('wie-start-row');
    var targetMode = document.getElementById('wie-target-mode');
    var targetSelect = document.getElementById('wie-target');
    var urlWarning = document.querySelector('[data-url-warning]');
    var newTarget = document.getElementById('wie-new-target');
    var createTarget = document.getElementById('wie-create-target');
    var cancelTarget = document.getElementById('wie-cancel-target');
    var propertyOptionsByIblock = <?= $iblockPropertyOptionsJson ?: '{}' ?>;
    var mappingChoices = document.querySelectorAll('.wie-mapping-choice');
    var propertyDialog = document.getElementById('wie-property-dialog');
    var propertyDialogSource = document.getElementById('wie-property-dialog-source');
    var propertyDialogError = document.getElementById('wie-property-dialog-error');
    var propertyDialogSave = document.getElementById('wie-property-dialog-save');
    var propertyType = document.getElementById('wie-property-type');
    var propertyName = document.getElementById('wie-property-name');
    var propertyCode = document.getElementById('wie-property-code');
    var propertySort = document.getElementById('wie-property-sort');
    var propertyLinkIblock = document.getElementById('wie-property-link-iblock');
    var propertyLinkWrap = document.getElementById('wie-property-link-wrap');
    var propertyHint = document.getElementById('wie-property-hint');
    var propertyActive = document.getElementById('wie-property-active');
    var propertyMultiple = document.getElementById('wie-property-multiple');
    var propertyIsRequired = document.getElementById('wie-property-is-required');
    var propertySearchable = document.getElementById('wie-property-searchable');
    var propertyFiltrable = document.getElementById('wie-property-filtrable');
    var propertyWithDescription = document.getElementById('wie-property-with-description');
    var propertyMultipleCount = document.getElementById('wie-property-multiple-count');
    var propertyShowEdit = document.getElementById('wie-property-show-edit');
    var propertySmartFilter = document.getElementById('wie-property-smart-filter');
    var propertyDisplayType = document.getElementById('wie-property-display-type');
    var propertyDisplayExpanded = document.getElementById('wie-property-display-expanded');
    var propertyFilterHint = document.getElementById('wie-property-filter-hint');
    var propertyRowCount = document.getElementById('wie-property-row-count');
    var propertyColCount = document.getElementById('wie-property-col-count');
    var propertyDefaultValue = document.getElementById('wie-property-default-value');
    var propertyShowList = document.getElementById('wie-property-show-list');
    var propertyShowDetail = document.getElementById('wie-property-show-detail');
    var activeMappingSelect = null;
    var previousMappingChoice = '';
    var newPropertyChoice = '<?= MappingChoice::PROPERTY_NEW ?>';
    var getPropertySetting = function (row, key) {
        return row.querySelector('[data-property-setting="' + key + '"]');
    };
    var readPropertySetting = function (row, key, fallback) {
        var input = getPropertySetting(row, key);
        return input && input.value !== '' ? input.value : fallback;
    };
    var writePropertySetting = function (row, key, value) {
        var input = getPropertySetting(row, key);
        if (input) {
            input.value = value;
        }
    };
    var refreshPropertyPresentation = function (select) {
        var row = select.closest('tr');
        if (!row) {
            return;
        }
        var isNewProperty = select.value === newPropertyChoice;
        var button = row.querySelector('.wie-property-settings');
        var empty = row.querySelector('.wie-property-not-used');
        if (button) {
            button.hidden = !isNewProperty;
            var code = button.querySelector('.wie-property-settings-code');
            if (code) {
                code.textContent = readPropertySetting(row, 'code', row.querySelector('.wie-col-code').getAttribute('data-source-code') || '');
            }
        }
        if (empty) {
            empty.hidden = isNewProperty;
        }
        var status = row.querySelector('.wie-mapping-status');
        if (status) {
            var state = select.value === ''
                ? 'required'
                : (select.getAttribute('data-auto-choice') === select.value ? 'recognized' : 'configured');
            status.className = 'wie-mapping-status wie-mapping-status-' + state;
            status.textContent = status.getAttribute('data-' + state) || '';
        }
    };
    var syncPropertyTypeControls = function () {
        if (!propertyType) {
            return;
        }
        var type = propertyType.value;
        propertyLinkWrap.hidden = type !== 'E' && type !== 'G';
        var supportsDescription = type === 'S' || type === 'N' || type === 'F';
        propertyWithDescription.disabled = !supportsDescription;
        if (!supportsDescription) {
            propertyWithDescription.checked = false;
        }
        var supportsSmartFilter = type !== 'F';
        propertySmartFilter.disabled = !supportsSmartFilter;
        if (!supportsSmartFilter) {
            propertySmartFilter.checked = false;
        }
        Array.prototype.forEach.call(document.querySelectorAll('.wie-property-smart-settings'), function (field) {
            field.hidden = !supportsSmartFilter;
        });
    };
    var closePropertyDialog = function (restoreChoice) {
        if (!propertyDialog) {
            return;
        }
        if (restoreChoice && activeMappingSelect && previousMappingChoice !== newPropertyChoice) {
            activeMappingSelect.value = previousMappingChoice;
            activeMappingSelect.setAttribute('data-last-choice', previousMappingChoice);
            refreshPropertyPresentation(activeMappingSelect);
        }
        propertyDialog.hidden = true;
        propertyDialog.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('wie-modal-open');
        activeMappingSelect = null;
    };
    var openPropertyDialog = function (select, oldChoice) {
        var row = select.closest('tr');
        if (!propertyDialog || !row) {
            return;
        }
        activeMappingSelect = select;
        previousMappingChoice = oldChoice || select.getAttribute('data-last-choice') || '';
        var label = row.querySelector('.wie-source-label');
        propertyDialogSource.textContent = '<?= CUtil::JSEscape((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_SOURCE')) ?>'.replace('#NAME#', label ? label.textContent : '');
        propertyType.value = readPropertySetting(row, 'type', 'S');
        propertyName.value = readPropertySetting(row, 'name', label ? label.textContent : '');
        propertyCode.value = readPropertySetting(row, 'code', row.querySelector('.wie-col-code').getAttribute('data-source-code') || '');
        propertySort.value = readPropertySetting(row, 'sort', '500');
        propertyLinkIblock.value = readPropertySetting(row, 'link_iblock_id', '0');
        propertyHint.value = readPropertySetting(row, 'hint', '');
        propertyActive.checked = readPropertySetting(row, 'active', 'Y') === 'Y';
        propertyMultiple.checked = readPropertySetting(row, 'multiple', 'N') === 'Y';
        propertyIsRequired.checked = readPropertySetting(row, 'is_required', 'N') === 'Y';
        propertySearchable.checked = readPropertySetting(row, 'searchable', 'N') === 'Y';
        propertyFiltrable.checked = readPropertySetting(row, 'filtrable', 'N') === 'Y';
        propertyWithDescription.checked = readPropertySetting(row, 'with_description', 'N') === 'Y';
        propertyMultipleCount.value = readPropertySetting(row, 'multiple_count', '5');
        propertyShowEdit.checked = readPropertySetting(row, 'show_edit', 'Y') === 'Y';
        propertySmartFilter.checked = readPropertySetting(row, 'smart_filter', 'N') === 'Y';
        propertyDisplayType.value = readPropertySetting(row, 'display_type', 'F');
        propertyDisplayExpanded.checked = readPropertySetting(row, 'display_expanded', 'N') === 'Y';
        propertyFilterHint.value = readPropertySetting(row, 'filter_hint', '');
        propertyRowCount.value = readPropertySetting(row, 'row_count', '1');
        propertyColCount.value = readPropertySetting(row, 'col_count', '30');
        propertyDefaultValue.value = readPropertySetting(row, 'default_value', '');
        propertyShowList.checked = readPropertySetting(row, 'show_list', 'N') === 'Y';
        propertyShowDetail.checked = readPropertySetting(row, 'show_detail', 'N') === 'Y';
        propertyDialogError.hidden = true;
        propertyDialogError.textContent = '';
        syncPropertyTypeControls();
        propertyDialog.hidden = false;
        propertyDialog.setAttribute('aria-hidden', 'false');
        document.body.classList.add('wie-modal-open');
        propertyName.focus();
    };
    if (propertyType) {
        propertyType.addEventListener('change', syncPropertyTypeControls);
    }
    if (propertySmartFilter) {
        propertySmartFilter.addEventListener('change', syncPropertyTypeControls);
    }
    if (propertyCode) {
        propertyCode.addEventListener('input', function () {
            propertyCode.value = propertyCode.value.toUpperCase();
        });
    }
    if (propertyDialogSave) {
        propertyDialogSave.addEventListener('click', function () {
            if (!activeMappingSelect) {
                return;
            }
            var row = activeMappingSelect.closest('tr');
            var name = propertyName.value.trim();
            var code = propertyCode.value.trim().toUpperCase();
            if (name === '') {
                propertyDialogError.textContent = '<?= CUtil::JSEscape((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_NAME_REQUIRED')) ?>';
                propertyDialogError.hidden = false;
                propertyName.focus();
                return;
            }
            if (!/^[A-Z][A-Z0-9_]*$/.test(code)) {
                propertyDialogError.textContent = '<?= CUtil::JSEscape((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_CODE_INVALID')) ?>';
                propertyDialogError.hidden = false;
                propertyCode.focus();
                return;
            }
            var iblockId = targetSelect && (!targetMode || targetMode.value !== 'new')
                ? targetSelect.value
                : '';
            var duplicate = (propertyOptionsByIblock[iblockId] || []).some(function (property) {
                return property.code === code;
            });
            if (duplicate) {
                propertyDialogError.textContent = '<?= CUtil::JSEscape((string) Loc::getMessage('WIE_PROFILE_NEW_PROPERTY_CODE_EXISTS')) ?>'.replace('#CODE#', code);
                propertyDialogError.hidden = false;
                propertyCode.focus();
                return;
            }
            writePropertySetting(row, 'name', name);
            writePropertySetting(row, 'code', code);
            writePropertySetting(row, 'type', propertyType.value);
            writePropertySetting(row, 'sort', String(Math.max(0, parseInt(propertySort.value, 10) || 0)));
            writePropertySetting(row, 'link_iblock_id', propertyLinkIblock.value || '0');
            writePropertySetting(row, 'hint', propertyHint.value.trim());
            writePropertySetting(row, 'active', propertyActive.checked ? 'Y' : 'N');
            writePropertySetting(row, 'multiple', propertyMultiple.checked ? 'Y' : 'N');
            writePropertySetting(row, 'is_required', propertyIsRequired.checked ? 'Y' : 'N');
            writePropertySetting(row, 'searchable', propertySearchable.checked ? 'Y' : 'N');
            writePropertySetting(row, 'filtrable', propertyFiltrable.checked ? 'Y' : 'N');
            writePropertySetting(row, 'with_description', propertyWithDescription.checked ? 'Y' : 'N');
            writePropertySetting(row, 'multiple_count', String(Math.max(1, parseInt(propertyMultipleCount.value, 10) || 5)));
            writePropertySetting(row, 'show_edit', propertyShowEdit.checked ? 'Y' : 'N');
            writePropertySetting(row, 'smart_filter', propertySmartFilter.checked ? 'Y' : 'N');
            writePropertySetting(row, 'display_type', propertyDisplayType.value);
            writePropertySetting(row, 'display_expanded', propertyDisplayExpanded.checked ? 'Y' : 'N');
            writePropertySetting(row, 'filter_hint', propertyFilterHint.value.trim());
            writePropertySetting(row, 'row_count', String(Math.max(1, parseInt(propertyRowCount.value, 10) || 1)));
            writePropertySetting(row, 'col_count', String(Math.max(1, parseInt(propertyColCount.value, 10) || 30)));
            writePropertySetting(row, 'default_value', propertyDefaultValue.value);
            writePropertySetting(row, 'show_list', propertyShowList.checked ? 'Y' : 'N');
            writePropertySetting(row, 'show_detail', propertyShowDetail.checked ? 'Y' : 'N');
            activeMappingSelect.setAttribute('data-last-choice', newPropertyChoice);
            refreshPropertyPresentation(activeMappingSelect);
            closePropertyDialog(false);
        });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-property-dialog-cancel]'), function (button) {
        button.addEventListener('click', function () {
            closePropertyDialog(true);
        });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && propertyDialog && !propertyDialog.hidden) {
            closePropertyDialog(true);
        }
    });
    var refreshExistingProperties = function (clear) {
        var iblockId = !clear && targetSelect ? targetSelect.value : '';
        var properties = propertyOptionsByIblock[iblockId] || [];
        Array.prototype.forEach.call(mappingChoices, function (select) {
            var group = select.querySelector('optgroup[data-existing-properties]');
            if (!group) {
                return;
            }
            var currentValue = select.value;
            while (group.firstChild) {
                group.removeChild(group.firstChild);
            }
            properties.forEach(function (property) {
                var option = document.createElement('option');
                option.value = property.value;
                option.textContent = property.label;
                group.appendChild(option);
            });
            var currentStillExists = Array.prototype.some.call(select.options, function (option) {
                return option.value === currentValue;
            });
            if (currentStillExists && currentValue !== '') {
                select.value = currentValue;
            } else {
                var row = select.closest('tr');
                var sourceCode = row
                    ? row.querySelector('.wie-col-code').getAttribute('data-source-code') || ''
                    : '';
                var sourceLabel = row && row.querySelector('.wie-source-label')
                    ? row.querySelector('.wie-source-label').textContent.trim().toLocaleLowerCase()
                    : '';
                var exactProperty = properties.find(function (property) {
                    return property.code === sourceCode;
                });
                if (!exactProperty && sourceLabel !== '') {
                    var nameMatches = properties.filter(function (property) {
                        return (property.name || '').trim().toLocaleLowerCase() === sourceLabel;
                    });
                    exactProperty = nameMatches.length === 1 ? nameMatches[0] : null;
                }
                select.value = exactProperty ? exactProperty.value : '';
                select.setAttribute('data-auto-choice', exactProperty ? exactProperty.value : '');
            }
            select.setAttribute('data-last-choice', select.value);
            refreshPropertyPresentation(select);
        });
    };
    Array.prototype.forEach.call(mappingChoices, function (select) {
        var mappingRow = select.closest('tr');
        var useCheckbox = mappingRow ? mappingRow.querySelector('.wie-col-use input[type="checkbox"]') : null;
        var syncMappingRequired = function () {
            select.required = !useCheckbox || useCheckbox.checked;
        };
        if (useCheckbox) {
            useCheckbox.addEventListener('change', syncMappingRequired);
        }
        syncMappingRequired();
        select.setAttribute('data-last-choice', select.value);
        refreshPropertyPresentation(select);
        select.addEventListener('change', function () {
            var oldChoice = select.getAttribute('data-last-choice') || '';
            select.setAttribute('data-auto-choice', '');
            if (select.value === newPropertyChoice) {
                refreshPropertyPresentation(select);
                openPropertyDialog(select, oldChoice);
                return;
            }
            select.setAttribute('data-last-choice', select.value);
            refreshPropertyPresentation(select);
        });
        var settingsButton = select.closest('tr').querySelector('.wie-property-settings');
        if (settingsButton) {
            settingsButton.addEventListener('click', function () {
                openPropertyDialog(select, newPropertyChoice);
            });
        }
    });
    if (targetMode && newTarget && createTarget && cancelTarget) {
        createTarget.addEventListener('click', function () {
            targetMode.value = 'new';
            newTarget.hidden = false;
            refreshExistingProperties(true);
            document.getElementById('wie-new-target-name').focus();
        });
        cancelTarget.addEventListener('click', function () {
            targetMode.value = 'existing';
            newTarget.hidden = true;
            refreshExistingProperties(false);
            document.getElementById('wie-target').focus();
        });
    }
    if (targetSelect) {
        var refreshUrlWarning = function () {
            var option = targetSelect.options[targetSelect.selectedIndex];
            if (urlWarning) {
                urlWarning.hidden = !option || option.value === '' || option.getAttribute('data-nested-url') === 'Y';
            }
        };
        targetSelect.addEventListener('change', function () {
            refreshUrlWarning();
            refreshExistingProperties(false);
        });
        refreshUrlWarning();
    }
    Array.prototype.forEach.call(rows, function (row) {
        var radio = row.querySelector('input[type="radio"][name="header_row"]');
        if (!radio) {
            return;
        }
        row.addEventListener('click', function (event) {
            if (event.target !== radio) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change'));
            }
        });
        radio.addEventListener('change', function () {
            Array.prototype.forEach.call(rows, function (item) {
                item.classList.remove('wie-preview-row-selected');
            });
            row.classList.add('wie-preview-row-selected');
            if (badge) {
                badge.textContent = badge.getAttribute('data-template').replace('#ROW#', radio.value);
            }
            if (startRow) {
                startRow.value = String(parseInt(radio.value, 10) + 1);
            }
        });
    });
}());
</script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
