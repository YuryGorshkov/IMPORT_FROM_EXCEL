<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\ServiceFactory;
use WebEnot\ImportExcel\Support\Json;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

if (!Loader::includeModule('webenot.importexcel')) {
    throw new RuntimeException('Module webenot.importexcel is not installed.');
}
if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'W') {
    $APPLICATION->AuthForm('Недостаточно прав для управления импортом.');
}

$id = (int) ($_REQUEST['ID'] ?? 0);
$record = $id > 0 ? ProfileTable::getByPrimary($id)->fetch() : null;
if ($id > 0 && !$record) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError('Профиль не найден.');
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
    ],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['target_id'] = (int) ($_POST['target_id'] ?? 0);
    $form['active'] = isset($_POST['active']);
    $form['options'] = [
        'header_row' => max(1, (int) ($_POST['header_row'] ?? 1)),
        'start_row' => max(2, (int) ($_POST['start_row'] ?? 2)),
        'chunk_size' => min(1000, max(10, (int) ($_POST['chunk_size'] ?? 200))),
        'unique_target' => strtoupper(trim((string) ($_POST['unique_target'] ?? 'FIELD:XML_ID'))),
        'write_mode' => (string) ($_POST['write_mode'] ?? 'upsert'),
        'auto_create_properties' => isset($_POST['auto_create_properties']),
        'stop_on_error' => isset($_POST['stop_on_error']),
        'delimiter' => (string) ($_POST['delimiter'] ?? ';'),
        'encoding' => (string) ($_POST['encoding'] ?? 'UTF-8'),
    ];

    try {
        if (isset($_POST['discover'])) {
            $samplePath = ServiceFactory::uploads()->store($_FILES['sample'] ?? []);
            try {
                $form['mapping'] = ServiceFactory::discovery()->discover(
                    $samplePath,
                    trim((string) ($_POST['sheet'] ?? '')) ?: null,
                    (int) $form['options']['header_row'],
                    $form['options']
                );
                $hasUnique = array_filter($form['mapping'], static fn(array $rule): bool => $rule['target'] === $form['options']['unique_target']);
                if ($hasUnique === []) {
                    $candidate = array_values(array_filter($form['mapping'], static fn(array $rule): bool => str_starts_with($rule['target'], 'PROPERTY:')));
                    $form['options']['unique_target'] = (string) (($candidate[0] ?? $form['mapping'][0])['target']);
                }
            } finally {
                @unlink($samplePath);
            }
        } elseif (isset($_POST['save'])) {
            $form['mapping'] = Json::decode((string) ($_POST['mapping'] ?? '[]'));
            $id = ServiceFactory::profiles()->save([
                'id' => $id,
                'name' => $form['name'],
                'target_id' => $form['target_id'],
                'active' => $form['active'],
                'mapping' => $form['mapping'],
                'options' => $form['options'],
            ]);
            LocalRedirect('webenot_importexcel_profile_edit.php?ID=' . $id . '&lang=' . LANGUAGE_ID . '&saved=Y');
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

$mappingJson = json_encode($form['mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$APPLICATION->SetTitle($id > 0 ? 'Изменение профиля импорта' : 'Новый профиль импорта');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (($_GET['saved'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage(['MESSAGE' => 'Профиль сохранён.', 'TYPE' => 'OK']);
}
foreach ($errors as $error) {
    CAdminMessage::ShowMessage(['MESSAGE' => $error, 'TYPE' => 'ERROR']);
}
?>
<form method="post" enctype="multipart/form-data">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="ID" value="<?= $id ?>">
    <table class="adm-detail-content-table edit-table">
        <tr><td width="40%">Название профиля:</td><td><input name="name" size="50" required value="<?= htmlspecialcharsbx($form['name']) ?>"></td></tr>
        <tr><td>ID инфоблока:</td><td><input name="target_id" type="number" min="1" required value="<?= (int) $form['target_id'] ?>"></td></tr>
        <tr><td>Активен:</td><td><input name="active" type="checkbox" value="Y" <?= $form['active'] ? 'checked' : '' ?>></td></tr>
        <tr class="heading"><td colspan="2">Автоматическое распознавание колонок</td></tr>
        <tr><td>Файл-образец:</td><td><input name="sample" type="file" accept=".xlsx,.xls,.ods,.csv"></td></tr>
        <tr><td>Лист:</td><td><input name="sheet" value="<?= htmlspecialcharsbx((string) ($_POST['sheet'] ?? '')) ?>"> <small>пусто — первый лист</small></td></tr>
        <tr><td>Строка заголовков:</td><td><input name="header_row" type="number" min="1" value="<?= (int) $form['options']['header_row'] ?>"></td></tr>
        <tr><td></td><td><button class="adm-btn" name="discover" value="Y">Распознать колонки и создать коды свойств</button></td></tr>
        <tr class="heading"><td colspan="2">Сопоставление</td></tr>
        <tr><td>JSON-карта:</td><td><textarea name="mapping" rows="18" cols="100"><?= htmlspecialcharsbx((string) $mappingJson) ?></textarea><br><small>Для свойств используется `PROPERTY:KOD_SVOYSTVA`; название колонки хранится в `label`.</small></td></tr>
        <tr><td>Поле уникальности:</td><td><input name="unique_target" size="40" value="<?= htmlspecialcharsbx((string) $form['options']['unique_target']) ?>"></td></tr>
        <tr><td>Первая строка данных:</td><td><input name="start_row" type="number" min="2" value="<?= (int) $form['options']['start_row'] ?>"></td></tr>
        <tr><td>Размер пакета:</td><td><input name="chunk_size" type="number" min="10" max="1000" value="<?= (int) $form['options']['chunk_size'] ?>"></td></tr>
        <tr><td>Режим записи:</td><td><select name="write_mode">
            <?php foreach (['upsert' => 'Добавлять и обновлять', 'insert' => 'Только добавлять', 'update' => 'Только обновлять'] as $value => $label) : ?>
                <option value="<?= $value ?>" <?= $form['options']['write_mode'] === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select></td></tr>
        <tr><td>Автоматически создавать свойства:</td><td><input name="auto_create_properties" type="checkbox" value="Y" <?= ($form['options']['auto_create_properties'] ?? true) ? 'checked' : '' ?>></td></tr>
        <tr><td>Остановиться при первой ошибке:</td><td><input name="stop_on_error" type="checkbox" value="Y" <?= ($form['options']['stop_on_error'] ?? false) ? 'checked' : '' ?>></td></tr>
        <tr><td>Разделитель CSV:</td><td><input name="delimiter" size="3" value="<?= htmlspecialcharsbx((string) ($form['options']['delimiter'] ?? ';')) ?>"></td></tr>
        <tr><td>Кодировка CSV:</td><td><input name="encoding" value="<?= htmlspecialcharsbx((string) ($form['options']['encoding'] ?? 'UTF-8')) ?>"></td></tr>
    </table>
    <input class="adm-btn-save" type="submit" name="save" value="Сохранить профиль">
    <a class="adm-btn" href="webenot_importexcel_profiles.php?lang=<?= LANGUAGE_ID ?>">К списку</a>
</form>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
