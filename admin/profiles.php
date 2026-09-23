<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use WebEnot\ImportExcel\Admin\AdminUi;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\ServiceFactory;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

if (!Loader::includeModule('webenot.importexcel')) {
    throw new RuntimeException((string) Loc::getMessage('WIE_PROFILES_MODULE_ERROR'));
}
if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'W') {
    $APPLICATION->AuthForm((string) Loc::getMessage('WIE_PROFILES_ACCESS_DENIED'));
}
Loader::includeModule('iblock');

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

$iblockNames = [];
if (class_exists(CIBlock::class)) {
    $iblockResult = CIBlock::GetList(['NAME' => 'ASC'], []);
    while ($iblock = $iblockResult->Fetch()) {
        $iblockNames[(int) $iblock['ID']] = (string) $iblock['NAME'];
    }
}

$result = ProfileTable::getList(['order' => [$by => $order]]);
$result = new CAdminResult($result, $listId);
$result->NavStart();
$list->NavText($result->GetNavPrint((string) Loc::getMessage('WIE_PROFILES_NAV')));
$list->AddHeaders([
    ['id' => 'ID', 'content' => 'ID', 'sort' => 'ID', 'default' => true],
    ['id' => 'NAME', 'content' => Loc::getMessage('WIE_PROFILES_NAME'), 'sort' => 'NAME', 'default' => true],
    ['id' => 'TARGET_ID', 'content' => Loc::getMessage('WIE_PROFILES_TARGET'), 'default' => true],
    ['id' => 'ACTIVE', 'content' => Loc::getMessage('WIE_PROFILES_ACTIVE'), 'default' => true],
    ['id' => 'UPDATED_AT', 'content' => Loc::getMessage('WIE_PROFILES_UPDATED'), 'sort' => 'UPDATED_AT', 'default' => true],
]);

while ($record = $result->NavNext(true, 'f_')) {
    $editUrl = 'webenot_importexcel_profile_edit.php?ID=' . (int) $f_ID . '&lang=' . LANGUAGE_ID;
    $row = $list->AddRow((string) $f_ID, $record, $editUrl);
    $row->AddViewField('NAME', '<a href="' . htmlspecialcharsbx($editUrl) . '"><strong>' . htmlspecialcharsbx($f_NAME) . '</strong></a>');
    $targetName = $iblockNames[(int) $f_TARGET_ID] ?? (string) Loc::getMessage('WIE_PROFILES_TARGET_UNKNOWN');
    $row->AddViewField('TARGET_ID', htmlspecialcharsbx($targetName) . ' <span style="color:#7a8991">#' . (int) $f_TARGET_ID . '</span>');
    $row->AddViewField(
        'ACTIVE',
        AdminUi::badge(
            $f_ACTIVE === 'Y' ? (string) Loc::getMessage('WIE_PROFILES_STATUS_ACTIVE') : (string) Loc::getMessage('WIE_PROFILES_STATUS_INACTIVE'),
            $f_ACTIVE === 'Y' ? 'success' : ''
        )
    );
    $row->AddActions([
        [
            'ICON' => 'edit',
            'TEXT' => Loc::getMessage('WIE_PROFILES_EDIT'),
            'ACTION' => $list->ActionRedirect($editUrl),
        ],
        [
            'ICON' => 'delete',
            'TEXT' => Loc::getMessage('WIE_PROFILES_DELETE'),
            'ACTION' => "if(confirm('" . CUtil::JSEscape((string) Loc::getMessage('WIE_PROFILES_DELETE_CONFIRM')) . "')) "
                . $list->ActionRedirect(
                    'webenot_importexcel_profiles.php?delete=' . (int) $f_ID . '&lang=' . LANGUAGE_ID . '&' . bitrix_sessid_get()
                ),
        ],
    ]);
}

$list->AddAdminContextMenu([
    [
        'TEXT' => Loc::getMessage('WIE_PROFILES_CREATE'),
        'LINK' => 'webenot_importexcel_profile_edit.php?lang=' . LANGUAGE_ID,
        'ICON' => 'btn_new',
    ],
    ['TEXT' => Loc::getMessage('WIE_PROFILES_RUN'), 'LINK' => 'webenot_importexcel_run.php?lang=' . LANGUAGE_ID],
]);
$list->CheckListMode();

$APPLICATION->SetTitle((string) Loc::getMessage('WIE_PROFILES_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
AdminUi::renderStyles();
?>
<div class="wie-list-intro">
    <strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILES_INTRO_TITLE')) ?></strong>
    <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_PROFILES_INTRO')) ?>
</div>
<?php
$list->DisplayList();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
