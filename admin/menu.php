<?php

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'R') {
    return false;
}

return [
    'parent_menu' => 'global_menu_content',
    'section' => 'webenot_importexcel',
    'sort' => 250,
    'text' => Loc::getMessage('WEBENOT_IMPORTEXCEL_MENU'),
    'title' => Loc::getMessage('WEBENOT_IMPORTEXCEL_MENU_TITLE'),
    'icon' => 'iblock_menu_icon_types',
    'page_icon' => 'iblock_page_icon_types',
    'items_id' => 'menu_webenot_importexcel',
    'items' => [
        [
            'text' => Loc::getMessage('WEBENOT_IMPORTEXCEL_MENU_PROFILES'),
            'url' => 'webenot_importexcel_profiles.php?lang=' . LANGUAGE_ID,
            'more_url' => ['webenot_importexcel_profile_edit.php'],
        ],
        [
            'text' => Loc::getMessage('WEBENOT_IMPORTEXCEL_MENU_RUN'),
            'url' => 'webenot_importexcel_run.php?lang=' . LANGUAGE_ID,
        ],
        [
            'text' => Loc::getMessage('WEBENOT_IMPORTEXCEL_MENU_JOBS'),
            'url' => 'webenot_importexcel_jobs.php?lang=' . LANGUAGE_ID,
        ],
    ],
];
