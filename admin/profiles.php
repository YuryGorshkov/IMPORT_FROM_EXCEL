<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\ServiceFactory;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

if (!Loader::includeModule('webenot.importexcel')) {
    throw new RuntimeException('Module webenot.importexcel is not installed.');
}
if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'W') {
    $APPLICATION->AuthForm('Недостаточно прав для управления импортом.');
}

$listId = 'webenot_importexcel_profiles';
$sort = new CAdminSorting($listId, 'ID', 'desc');
$list = new CAdminList($listId, $sort);

if (($id = (int) ($_REQUEST['delete'] ?? 0)) > 0 && check_bitrix_sessid()) {
    try {
        ServiceFactory::profiles()->delete($id);
    } catch (Throwable $exception) {
        $list->AddGroupError($exception->getMessage(), $id);
    }
}

$result = ProfileTable::getList(['order' => [$by => $order]]);
$result = new CAdminResult($result, $listId);
$result->NavStart();
$list->NavText($result->GetNavPrint('Профили'));
$list->AddHeaders([
    ['id' => 'ID', 'content' => 'ID', 'sort' => 'ID', 'default' => true],
    ['id' => 'NAME', 'content' => 'Название', 'sort' => 'NAME', 'default' => true],
    ['id' => 'TARGET_ID', 'content' => 'Инфоблок', 'default' => true],
    ['id' => 'ACTIVE', 'content' => 'Активен', 'default' => true],
    ['id' => 'UPDATED_AT', 'content' => 'Изменён', 'sort' => 'UPDATED_AT', 'default' => true],
]);

while ($record = $result->NavNext(true, 'f_')) {
    $row = $list->AddRow((string) $f_ID, $record, 'webenot_importexcel_profile_edit.php?ID=' . $f_ID . '&lang=' . LANGUAGE_ID);
    $row->AddViewField('NAME', '<a href="webenot_importexcel_profile_edit.php?ID=' . (int) $f_ID . '&lang=' . LANGUAGE_ID . '">' . htmlspecialcharsbx($f_NAME) . '</a>');
    $row->AddViewField('ACTIVE', $f_ACTIVE === 'Y' ? 'Да' : 'Нет');
    $row->AddActions([
        ['ICON' => 'edit', 'TEXT' => 'Изменить', 'ACTION' => $list->ActionRedirect('webenot_importexcel_profile_edit.php?ID=' . (int) $f_ID . '&lang=' . LANGUAGE_ID)],
        ['ICON' => 'delete', 'TEXT' => 'Удалить', 'ACTION' => "if(confirm('Удалить профиль?')) " . $list->ActionRedirect('webenot_importexcel_profiles.php?delete=' . (int) $f_ID . '&lang=' . LANGUAGE_ID . '&' . bitrix_sessid_get())],
    ]);
}

$list->AddAdminContextMenu([
    ['TEXT' => 'Создать профиль', 'LINK' => 'webenot_importexcel_profile_edit.php?lang=' . LANGUAGE_ID, 'ICON' => 'btn_new'],
    ['TEXT' => 'Запустить импорт', 'LINK' => 'webenot_importexcel_run.php?lang=' . LANGUAGE_ID],
]);
$list->CheckListMode();

$APPLICATION->SetTitle('Профили импорта из Excel');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$list->DisplayList();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
