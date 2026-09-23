<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use WebEnot\ImportExcel\Admin\AdminUi;
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
        $targetType = strtoupper(trim((string) ($row['target_type'] ?? 'PROPERTY')));
        $targetCode = strtoupper(trim((string) ($row['target_code'] ?? '')));
        if (!in_array($targetType, ['FIELD', 'PROPERTY'], true)) {
            $targetType = 'PROPERTY';
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

        $defaultJson = (string) ($row['default_json'] ?? '');
        if ($defaultJson !== '') {
            $rule['default'] = json_decode($defaultJson, true);
        }
        $mapping[] = $rule;
    }

    return $mapping;
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
    'options' => $record ? Json::decode((string) $record['OPTIONS']) : [
        'header_row' => 1,
        'start_row' => 2,
        'chunk_size' => 200,
        'unique_target' => 'FIELD:XML_ID',
        'write_mode' => 'upsert',
        'auto_create_properties' => true,
        'stop_on_error' => false,
        'delimiter' => ';',
        'encoding' => 'UTF-8',
    ],
];
$errors = [];
$sheet = trim((string) ($_POST['sheet'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['target_id'] = (int) ($_POST['target_id'] ?? 0);
    $form['active'] = isset($_POST['active']);
    $headerRow = max(1, (int) ($_POST['header_row'] ?? 1));
    $writeMode = (string) ($_POST['write_mode'] ?? 'upsert');
    if (!in_array($writeMode, ['upsert', 'insert', 'update'], true)) {
        $writeMode = 'upsert';
    }
    $form['options'] = [
        'header_row' => $headerRow,
        'start_row' => max($headerRow + 1, (int) ($_POST['start_row'] ?? ($headerRow + 1))),
        'chunk_size' => min(1000, max(10, (int) ($_POST['chunk_size'] ?? 200))),
        'unique_target' => strtoupper(trim((string) ($_POST['unique_target'] ?? 'FIELD:XML_ID'))),
        'write_mode' => $writeMode,
        'auto_create_properties' => isset($_POST['auto_create_properties']),
        'stop_on_error' => isset($_POST['stop_on_error']),
        'delimiter' => (string) ($_POST['delimiter'] ?? ';'),
        'encoding' => trim((string) ($_POST['encoding'] ?? 'UTF-8')) ?: 'UTF-8',
    ];

    try {
        if (isset($_POST['discover'])) {
            $samplePath = ServiceFactory::uploads()->store($_FILES['sample'] ?? []);
            try {
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
            } finally {
                @unlink($samplePath);
            }
        } elseif (isset($_POST['save'])) {
            $form['mapping'] = webenotImportExcelMappingFromRequest((array) ($_POST['mapping_rows'] ?? []));
            $mappingTargets = array_column($form['mapping'], 'target');
            if (!in_array($form['options']['unique_target'], $mappingTargets, true) && $mappingTargets !== []) {
                $form['options']['unique_target'] = (string) $mappingTargets[0];
            }
            if ($form['name'] === '') {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_PROFILE_NAME_REQUIRED'));
            }
            if ($form['target_id'] < 1) {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_PROFILE_TARGET_REQUIRED'));
            }
            if ($form['mapping'] === []) {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_PROFILE_MAPPING_REQUIRED_ERROR'));
            }
            $id = ServiceFactory::profiles()->save([
                'id' => $id,
                'name' => $form['name'],
                'target_id' => $form['target_id'],
                'active' => $form['active'],
                'mapping' => $form['mapping'],
                'options' => $form['options'],
            ]);
            header(
                'Location: webenot_importexcel_profile_edit.php?ID=' . $id . '&lang=' . LANGUAGE_ID . '&saved=Y',
                true,
                302
            );
            exit;
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

$iblocks = [];
$iblockResult = CIBlock::GetList(['IBLOCK_TYPE_ID' => 'ASC', 'NAME' => 'ASC'], []);
while ($iblock = $iblockResult->Fetch()) {
    $iblocks[] = $iblock;
}

$uniqueTargets = [];
foreach ($form['mapping'] as $rule) {
    $target = (string) ($rule['target'] ?? '');
    if ($target !== '') {
        $uniqueTargets[$target] = (string) ($rule['label'] ?? $target);
    }
}
$currentUniqueTarget = (string) ($form['options']['unique_target'] ?? 'FIELD:XML_ID');
if ($currentUniqueTarget !== '' && !isset($uniqueTargets[$currentUniqueTarget])) {
    $uniqueTargets[$currentUniqueTarget] = $currentUniqueTarget;
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
                    <select id="wie-target" name="target_id" required>
                        <option value=""><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_TARGET_CHOOSE')) ?></option>
                        <?php foreach ($iblocks as $iblock) :
                            $iblockId = (int) $iblock['ID'];
                            $iblockLabel = (string) $iblock['NAME'] . ' · ' . (string) $iblock['IBLOCK_TYPE_ID'] . ' · #' . $iblockId;
                            if (($iblock['ACTIVE'] ?? 'Y') !== 'Y') {
                                $iblockLabel .= ' · ' . (string) Loc::getMessage('WIE_PROFILE_TARGET_INACTIVE');
                            }
                            ?>
                            <option value="<?= $iblockId ?>"<?= $form['target_id'] === $iblockId ? ' selected' : '' ?>><?= htmlspecialcharsbx($iblockLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="wie-grid">
                    <div class="wie-field wie-field-wide">
                        <label class="wie-label" for="wie-sample"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SAMPLE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_SAMPLE_HINT')); ?></label>
                        <input id="wie-sample" name="sample" type="file" accept=".xlsx,.xls,.ods,.csv">
                    </div>
                    <div class="wie-field">
                        <label class="wie-label" for="wie-sheet"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SHEET')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_SHEET_HINT')); ?></label>
                        <input id="wie-sheet" name="sheet" value="<?= htmlspecialcharsbx($sheet) ?>" placeholder="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SHEET_PLACEHOLDER')) ?>">
                    </div>
                    <div class="wie-field">
                        <label class="wie-label" for="wie-header-row"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_HEADER_ROW')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_PROFILE_HEADER_ROW_HINT')); ?></label>
                        <input id="wie-header-row" name="header_row" type="number" min="1" value="<?= (int) $form['options']['header_row'] ?>">
                    </div>
                </div>
                <div class="wie-actions">
                    <button class="wie-primary" type="submit" name="discover" value="Y"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_DISCOVER')) ?></button>
                    <span class="wie-field-note"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_DISCOVER_NOTE')) ?></span>
                </div>
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
                                        <select name="mapping_rows[<?= (int) $index ?>][target_type]" aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_TARGET_TYPE')) ?>">
                                            <option value="FIELD"<?= $targetType === 'FIELD' ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_FIELD')) ?></option>
                                            <option value="PROPERTY"<?= $targetType !== 'FIELD' ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_PROPERTY')) ?></option>
                                        </select>
                                        <input name="mapping_rows[<?= (int) $index ?>][target_code]" required pattern="[A-Z][A-Z0-9_]*" value="<?= htmlspecialcharsbx($targetCode) ?>" aria-label="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_MAPPING_CODE')) ?>">
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
            <button class="wie-primary" type="submit" name="save" value="Y"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_SAVE')) ?></button>
            <a class="wie-secondary" href="webenot_importexcel_profiles.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILE_BACK')) ?></a>
        </div>
    </form>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
