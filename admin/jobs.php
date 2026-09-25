<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use WebEnot\ImportExcel\Admin\AdminUi;
use WebEnot\ImportExcel\Orm\JobTable;
use WebEnot\ImportExcel\Orm\LogTable;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\ServiceFactory;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

if (!Loader::includeModule('webenot.importexcel')) {
    throw new RuntimeException((string) Loc::getMessage('WIE_JOBS_MODULE_ERROR'));
}
if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'W') {
    $APPLICATION->AuthForm((string) Loc::getMessage('WIE_JOBS_ACCESS_DENIED'));
}

$listId = 'webenot_importexcel_jobs';
$sort = new CAdminSorting($listId, 'ID', 'desc');
$list = new CAdminList($listId, $sort);
$rollbackMessage = '';
$rollbackId = (int) ($_REQUEST['rollback'] ?? 0);
if ($rollbackId > 0 && check_bitrix_sessid()) {
    try {
        $count = ServiceFactory::rollback()->rollback($rollbackId);
        $rollbackMessage = (string) Loc::getMessage('WIE_JOBS_ROLLBACK_DONE', ['#COUNT#' => (string) $count]);
    } catch (Throwable $exception) {
        $list->AddGroupError($exception->getMessage(), $rollbackId);
    }
}

$profileNames = [];
foreach (ProfileTable::getList(['select' => ['ID', 'NAME']])->fetchAll() as $profile) {
    $profileNames[(int) $profile['ID']] = (string) $profile['NAME'];
}

$statusLabels = [
    JobTable::STATUS_NEW => ['WIE_JOBS_STATUS_NEW', ''],
    JobTable::STATUS_RUNNING => ['WIE_JOBS_STATUS_RUNNING', 'info'],
    JobTable::STATUS_COMPLETED => ['WIE_JOBS_STATUS_COMPLETED', 'success'],
    JobTable::STATUS_FAILED => ['WIE_JOBS_STATUS_FAILED', 'danger'],
    JobTable::STATUS_CANCELLED => ['WIE_JOBS_STATUS_CANCELLED', 'warning'],
    JobTable::STATUS_ROLLED_BACK => ['WIE_JOBS_STATUS_ROLLED_BACK', 'warning'],
];

$result = JobTable::getList(['order' => [$by => $order]]);
$result = new CAdminResult($result, $listId);
$result->NavStart();
$list->NavText($result->GetNavPrint((string) Loc::getMessage('WIE_JOBS_NAV')));
$list->AddHeaders([
    ['id' => 'ID', 'content' => 'ID', 'sort' => 'ID', 'default' => true],
    ['id' => 'PROFILE_ID', 'content' => Loc::getMessage('WIE_JOBS_PROFILE'), 'default' => true],
    ['id' => 'STATUS', 'content' => Loc::getMessage('WIE_JOBS_STATUS'), 'default' => true],
    ['id' => 'MODE', 'content' => Loc::getMessage('WIE_JOBS_MODE'), 'default' => true],
    ['id' => 'ROWS_READ', 'content' => Loc::getMessage('WIE_JOBS_READ'), 'default' => true],
    ['id' => 'ROWS_ADDED', 'content' => Loc::getMessage('WIE_JOBS_ADDED'), 'default' => true],
    ['id' => 'ROWS_UPDATED', 'content' => Loc::getMessage('WIE_JOBS_UPDATED'), 'default' => true],
    ['id' => 'ROWS_SKIPPED', 'content' => Loc::getMessage('WIE_JOBS_SKIPPED'), 'default' => true],
    ['id' => 'ROWS_ERRORS', 'content' => Loc::getMessage('WIE_JOBS_ERRORS'), 'default' => true],
    ['id' => 'CREATED_AT', 'content' => Loc::getMessage('WIE_JOBS_CREATED'), 'sort' => 'CREATED_AT', 'default' => true],
]);

while ($record = $result->NavNext(true, 'f_')) {
    $errorCount = LogTable::getCount(['=JOB_ID' => (int) $f_ID, '=LEVEL' => 'error']);
    $row = $list->AddRow((string) $f_ID, $record);
    $profileName = $profileNames[(int) $f_PROFILE_ID] ?? ('ID ' . (int) $f_PROFILE_ID);
    $row->AddViewField('PROFILE_ID', '<strong>' . htmlspecialcharsbx($profileName) . '</strong>');
    [$statusMessage, $statusTone] = $statusLabels[(string) $f_STATUS] ?? ['WIE_JOBS_STATUS_UNKNOWN', ''];
    $row->AddViewField('STATUS', AdminUi::badge((string) Loc::getMessage($statusMessage), $statusTone));
    $row->AddViewField(
        'MODE',
        AdminUi::badge(
            (string) Loc::getMessage($f_MODE === 'dry_run' ? 'WIE_JOBS_MODE_DRY' : 'WIE_JOBS_MODE_COMMIT'),
            $f_MODE === 'dry_run' ? 'info' : 'success'
        )
    );
    $row->AddViewField('ROWS_ERRORS', $errorCount > 0 ? AdminUi::badge((string) $errorCount, 'danger') : '0');
    $actions = [];
    if ($f_STATUS === JobTable::STATUS_COMPLETED && $f_MODE === 'commit') {
        $actions[] = [
            'ICON' => 'delete',
            'TEXT' => Loc::getMessage('WIE_JOBS_ROLLBACK'),
            'ACTION' => "if(confirm('" . CUtil::JSEscape((string) Loc::getMessage('WIE_JOBS_ROLLBACK_CONFIRM')) . "')) "
                . $list->ActionRedirect(
                    'webenot_importexcel_jobs.php?rollback=' . (int) $f_ID . '&lang=' . LANGUAGE_ID . '&' . bitrix_sessid_get()
                ),
        ];
    }
    $row->AddActions($actions);
}

$list->AddAdminContextMenu([
    [
        'TEXT' => Loc::getMessage('WIE_JOBS_RUN'),
        'LINK' => 'webenot_importexcel_run.php?lang=' . LANGUAGE_ID,
        'ICON' => 'btn_new',
    ],
]);
$list->CheckListMode();

$APPLICATION->SetTitle((string) Loc::getMessage('WIE_JOBS_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
AdminUi::renderStyles();
if ($rollbackMessage !== '') {
    CAdminMessage::ShowMessage(['MESSAGE' => $rollbackMessage, 'TYPE' => 'OK']);
}
?>
<div class="wie-list-intro">
    <strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_JOBS_INTRO_TITLE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_JOBS_INTRO_HINT')); ?></strong>
    <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_JOBS_INTRO')) ?>
</div>
<?php
$list->DisplayList();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
