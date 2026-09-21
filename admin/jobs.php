<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Orm\JobTable;
use WebEnot\ImportExcel\Orm\LogTable;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\ServiceFactory;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

if (!Loader::includeModule('webenot.importexcel')) {
    throw new RuntimeException('Module webenot.importexcel is not installed.');
}
if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'W') {
    $APPLICATION->AuthForm('Недостаточно прав для просмотра заданий импорта.');
}

$listId = 'webenot_importexcel_jobs';
$sort = new CAdminSorting($listId, 'ID', 'desc');
$list = new CAdminList($listId, $sort);
$rollbackId = (int) ($_REQUEST['rollback'] ?? 0);
if ($rollbackId > 0 && check_bitrix_sessid()) {
    try {
        $count = ServiceFactory::rollback()->rollback($rollbackId);
        CAdminMessage::ShowMessage(['MESSAGE' => 'Восстановлено записей: ' . $count, 'TYPE' => 'OK']);
    } catch (Throwable $exception) {
        $list->AddGroupError($exception->getMessage(), $rollbackId);
    }
}

$result = JobTable::getList(['order' => [$by => $order]]);
$result = new CAdminResult($result, $listId);
$result->NavStart();
$list->NavText($result->GetNavPrint('Задания'));
$list->AddHeaders([
    ['id' => 'ID', 'content' => 'ID', 'sort' => 'ID', 'default' => true],
    ['id' => 'PROFILE_ID', 'content' => 'Профиль', 'default' => true],
    ['id' => 'STATUS', 'content' => 'Статус', 'default' => true],
    ['id' => 'MODE', 'content' => 'Режим', 'default' => true],
    ['id' => 'ROWS_READ', 'content' => 'Прочитано', 'default' => true],
    ['id' => 'ROWS_ADDED', 'content' => 'Добавлено', 'default' => true],
    ['id' => 'ROWS_UPDATED', 'content' => 'Обновлено', 'default' => true],
    ['id' => 'ROWS_ERRORS', 'content' => 'Ошибок', 'default' => true],
    ['id' => 'CREATED_AT', 'content' => 'Создано', 'sort' => 'CREATED_AT', 'default' => true],
]);

while ($record = $result->NavNext(true, 'f_')) {
    $profile = ProfileTable::getByPrimary((int) $f_PROFILE_ID)->fetch();
    $errorCount = LogTable::getCount(['=JOB_ID' => (int) $f_ID, '=LEVEL' => 'error']);
    $row = $list->AddRow((string) $f_ID, $record);
    $row->AddViewField('PROFILE_ID', htmlspecialcharsbx((string) ($profile['NAME'] ?? ('#' . $f_PROFILE_ID))));
    $row->AddViewField('ROWS_ERRORS', (string) $errorCount);
    $actions = [];
    if ($f_STATUS === JobTable::STATUS_COMPLETED && $f_MODE === 'commit') {
        $actions[] = [
            'ICON' => 'delete',
            'TEXT' => 'Откатить изменения',
            'ACTION' => "if(confirm('Откатить добавленные и обновлённые элементы?')) "
                . $list->ActionRedirect(
                    'webenot_importexcel_jobs.php?rollback=' . (int) $f_ID . '&lang=' . LANGUAGE_ID . '&' . bitrix_sessid_get()
                ),
        ];
    }
    $row->AddActions($actions);
}

$list->AddAdminContextMenu([
    ['TEXT' => 'Запустить импорт', 'LINK' => 'webenot_importexcel_run.php?lang=' . LANGUAGE_ID, 'ICON' => 'btn_new'],
]);
$list->CheckListMode();

$APPLICATION->SetTitle('История заданий импорта');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$list->DisplayList();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
