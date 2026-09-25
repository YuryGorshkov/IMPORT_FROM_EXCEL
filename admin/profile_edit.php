<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use WebEnot\ImportExcel\Admin\AdminUi;
use WebEnot\ImportExcel\Discovery\HeaderRowDetector;
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
        $destination = strtoupper(trim((string) ($row['destination'] ?? '')));
        if ($destination === '') {
            $legacyType = strtoupper(trim((string) ($row['target_type'] ?? 'PROPERTY')));
            $legacyCode = strtoupper(trim((string) (
                $row['field_code'] ?? $row['property_code'] ?? $row['target_code'] ?? ''
            )));
            $destination = $legacyType . ':' . $legacyCode;
        }
        if ($destination === 'PROPERTY') {
            $targetType = 'PROPERTY';
            $targetCode = strtoupper(trim((string) ($row['property_code'] ?? '')));
        } else {
            [$targetType, $targetCode] = array_pad(explode(':', $destination, 2), 2, '');
        }

        $transforms = json_decode((string) ($row['transforms_json'] ?? '[]'), true);
        $rule = [
            'column' => $column,
            'label' => trim((string) ($row['label'] ?? '')),
            'code' => $targetCode,
            'target' => $targetType . ':' . $targetCode,
            'required' => isset($row['required']),
            'transforms' => is_array($transforms) ? $transforms : [['type' => 'trim']],
        ];
        if ($targetType === 'PROPERTY') {
            $propertyType = strtoupper(trim((string) ($row['property_type'] ?? 'S')));
            $rule['property_type'] = in_array($propertyType, ['S', 'F'], true) ? $propertyType : 'S';
            $rule['multiple'] = isset($row['multiple']);
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

function webenotImportExcelSystemFieldCode(string $destination): string
{
    $parts = explode(':', strtoupper($destination));

    return (string) end($parts);
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
        'NAME', 'CODE', 'XML_ID', 'ACTIVE', 'SORT', 'TAGS',
        'DATE_ACTIVE_FROM', 'DATE_ACTIVE_TO', 'IBLOCK_SECTION_ID',
    ],
    'WIE_PROFILE_GROUP_TEXTS' => [
        'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE',
    ],
    'WIE_PROFILE_GROUP_IMAGES' => ['PREVIEW_PICTURE', 'DETAIL_PICTURE'],
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

$uniqueTargets = [];
foreach ($form['mapping'] as $rule) {
    $target = (string) ($rule['target'] ?? '');
    if ($target !== '' && !SectionPath::isTarget($target)) {
        $uniqueTargets[$target] = (string) ($rule['label'] ?? $target);
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
                                $iblockLabel = (string) $iblock['NAME'] . ' · ' . (string) $iblock['IBLOCK_TYPE_ID'] . ' · #' . $iblockId;
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
                <div class="wie-mapping-wrap">
                    <table class="wie-mapping">
                        <thead><tr>
                            <th class="wie-col-use"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_USE')) ?></th>
                            <th class="wie-col-source"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_COLUMN')) ?></th>
                            <th><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_HEADER')) ?></th>
                            <th><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_TARGET')) ?></th>
                            <th class="wie-col-required"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_REQUIRED')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($form['mapping'] as $index => $rule) :
                            [$targetType, $targetCode] = array_pad(explode(':', (string) ($rule['target'] ?? 'PROPERTY:'), 2), 2, '');
                            $destination = $targetType === 'PROPERTY'
                                ? 'PROPERTY'
                                : $targetType . ':' . $targetCode;
                            $propertyType = strtoupper((string) ($rule['property_type'] ?? 'S')) === 'F' ? 'F' : 'S';
                            $propertyMultiple = (bool) ($rule['multiple'] ?? false);
                            $transformsJson = json_encode($rule['transforms'] ?? [['type' => 'trim']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $defaultJson = array_key_exists('default', $rule)
                                ? json_encode($rule['default'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : '';
                            ?>
                            <tr>
                                <td class="wie-col-use"><input type="checkbox" name="mapping_rows[<?= (int) $index ?>][enabled]" value="Y" checked aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_USE')) ?>"></td>
                                <td><strong><?= htmlspecialcharsbx((string) ($rule['column'] ?? '')) ?></strong><input type="hidden" name="mapping_rows[<?= (int) $index ?>][column]" value="<?= htmlspecialcharsbx((string) ($rule['column'] ?? '')) ?>"></td>
                                <td><input name="mapping_rows[<?= (int) $index ?>][label]" value="<?= htmlspecialcharsbx((string) ($rule['label'] ?? '')) ?>"></td>
                                <td>
                                    <div class="wie-code-control">
                                        <select name="mapping_rows[<?= (int) $index ?>][destination]" data-mapping-destination aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_TARGET_TYPE')) ?>">
                                            <option value="PROPERTY"<?= $destination === 'PROPERTY' ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_PROPERTY')) ?></option>
                                            <?php foreach ($elementFieldGroups as $groupMessage => $fieldCodes) : ?>
                                                <optgroup label="<?= htmlspecialcharsbx((string) Loc::getMessage($groupMessage)) ?>">
                                                    <?php foreach ($fieldCodes as $fieldCode) : ?>
                                                        <option value="FIELD:<?= $fieldCode ?>"<?= $destination === 'FIELD:' . $fieldCode ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage($elementFieldMessages[$fieldCode])) ?></option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                            <?php for ($level = 1; $level <= SectionPath::MAX_LEVEL; $level++) : ?>
                                                <optgroup label="<?= htmlspecialcharsbx(sprintf((string) Loc::getMessage('WIE_PROFILE_SECTION_LEVEL_GROUP'), $level)) ?>">
                                                    <?php foreach (SectionFieldCatalog::codes() as $sectionFieldCode) :
                                                        $sectionDestination = SectionPath::target($level, $sectionFieldCode);
                                                        ?>
                                                        <option value="<?= htmlspecialcharsbx($sectionDestination) ?>"<?= $destination === $sectionDestination ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage($sectionFieldMessages[$sectionFieldCode])) ?></option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endfor; ?>
                                        </select>
                                        <span class="wie-system-code" data-fixed-destination<?= $destination === 'PROPERTY' ? ' hidden' : '' ?>><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_SYSTEM_CODE')) ?></span><code data-destination-code><?= htmlspecialcharsbx(webenotImportExcelSystemFieldCode($destination)) ?></code></span>
                                        <input name="mapping_rows[<?= (int) $index ?>][property_code]" data-property-code required pattern="[A-Z][A-Z0-9_]*" value="<?= htmlspecialcharsbx($targetType === 'PROPERTY' ? $targetCode : '') ?>"<?= $targetType === 'PROPERTY' ? '' : ' hidden disabled' ?> aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_PROPERTY_CODE')) ?>">
                                    </div>
                                    <div class="wie-property-options" data-property-options<?= $destination === 'PROPERTY' ? '' : ' hidden' ?>>
                                        <label>
                                            <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_PROPERTY_TYPE')) ?></span>
                                            <select name="mapping_rows[<?= (int) $index ?>][property_type]" data-property-type<?= $destination === 'PROPERTY' ? '' : ' disabled' ?>>
                                                <option value="S"<?= $propertyType === 'S' ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_PROPERTY_TYPE_VALUE')) ?></option>
                                                <option value="F"<?= $propertyType === 'F' ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_PROPERTY_TYPE_IMAGE')) ?></option>
                                            </select>
                                        </label>
                                        <label class="wie-property-multiple">
                                            <input type="checkbox" name="mapping_rows[<?= (int) $index ?>][multiple]" value="Y" data-property-multiple<?= $propertyMultiple ? ' checked' : '' ?><?= $destination === 'PROPERTY' ? '' : ' disabled' ?>>
                                            <span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_PROPERTY_MULTIPLE')) ?></span>
                                        </label>
                                    </div>
                                    <input type="hidden" name="mapping_rows[<?= (int) $index ?>][transforms_json]" value="<?= htmlspecialcharsbx((string) $transformsJson) ?>">
                                    <input type="hidden" name="mapping_rows[<?= (int) $index ?>][default_json]" value="<?= htmlspecialcharsbx((string) $defaultJson) ?>">
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
                        <?php foreach ($uniqueTargets as $target => $label) : ?>
                            <option value="<?= htmlspecialcharsbx($target) ?>"<?= $currentUniqueTarget === $target ? ' selected' : '' ?>><?= htmlspecialcharsbx($label . ' · ' . $target) ?></option>
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
    var mappingDestinations = document.querySelectorAll('[data-mapping-destination]');
    if (targetMode && newTarget && createTarget && cancelTarget) {
        createTarget.addEventListener('click', function () {
            targetMode.value = 'new';
            newTarget.hidden = false;
            document.getElementById('wie-new-target-name').focus();
        });
        cancelTarget.addEventListener('click', function () {
            targetMode.value = 'existing';
            newTarget.hidden = true;
            document.getElementById('wie-target').focus();
        });
    }
    if (targetSelect && urlWarning) {
        var refreshUrlWarning = function () {
            var option = targetSelect.options[targetSelect.selectedIndex];
            urlWarning.hidden = !option || option.value === '' || option.getAttribute('data-nested-url') === 'Y';
        };
        targetSelect.addEventListener('change', refreshUrlWarning);
        refreshUrlWarning();
    }
    Array.prototype.forEach.call(mappingDestinations, function (destinationSelect) {
        var control = destinationSelect.closest('.wie-code-control');
        var fixedDestination = control.querySelector('[data-fixed-destination]');
        var destinationCode = control.querySelector('[data-destination-code]');
        var propertyCode = control.querySelector('[data-property-code]');
        var propertyOptions = control.parentNode.querySelector('[data-property-options]');
        var propertyType = propertyOptions.querySelector('[data-property-type]');
        var propertyMultiple = propertyOptions.querySelector('[data-property-multiple]');
        var refreshDestination = function () {
            var isProperty = destinationSelect.value === 'PROPERTY';
            fixedDestination.hidden = isProperty;
            propertyCode.hidden = !isProperty;
            propertyCode.disabled = !isProperty;
            propertyOptions.hidden = !isProperty;
            propertyType.disabled = !isProperty;
            propertyMultiple.disabled = !isProperty;
            var destinationParts = destinationSelect.value.split(':');
            destinationCode.textContent = destinationParts[destinationParts.length - 1];
        };
        destinationSelect.addEventListener('change', refreshDestination);
        refreshDestination();
    });
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
