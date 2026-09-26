<?php

declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!CModule::IncludeModule('iblock')) {
    throw new RuntimeException('Cannot load iblock module');
}

$iblockName = trim((string) ($argv[1] ?? 'Демо-каталог DummyJSON'));
$profileName = trim((string) ($argv[2] ?? 'Демо DummyJSON — полный каталог'));

$iblock = CIBlock::GetList([], ['NAME' => $iblockName])->Fetch();
if (!$iblock) {
    throw new RuntimeException('Demo iblock not found');
}

$iblockId = (int) $iblock['ID'];
$elementCount = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId], [], false, ['ID']);
$sectionCount = 0;
$depths = [];
$sections = CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC'], ['IBLOCK_ID' => $iblockId], false, ['ID', 'NAME', 'DEPTH_LEVEL']);
while ($section = $sections->Fetch()) {
    ++$sectionCount;
    $depth = (int) $section['DEPTH_LEVEL'];
    $depths[$depth] = ($depths[$depth] ?? 0) + 1;
}

$job = null;
$errors = [];
if (\Bitrix\Main\Loader::includeModule('webenot.importexcel')) {
    $profile = \WebEnot\ImportExcel\Orm\ProfileTable::getList([
        'filter' => ['=NAME' => $profileName],
        'order' => ['ID' => 'DESC'],
        'limit' => 1,
    ])->fetch();

    if ($profile) {
        $job = \WebEnot\ImportExcel\Orm\JobTable::getList([
            'filter' => [
                '=PROFILE_ID' => (int) $profile['ID'],
                '!=MODE' => 'dry_run',
            ],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ])->fetch();
    }

    if ($job) {
        $logIterator = \WebEnot\ImportExcel\Orm\LogTable::getList([
            'filter' => ['=JOB_ID' => (int) $job['ID'], '=LEVEL' => 'error'],
            'order' => ['ID' => 'ASC'],
        ]);
        while ($log = $logIterator->fetch()) {
            $errors[] = [
                'row' => (int) $log['ROW_NUMBER'],
                'code' => (string) $log['CODE'],
                'message' => (string) $log['MESSAGE'],
            ];
        }
    }
}

$properties = [];
$propertyIterator = CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
while ($property = $propertyIterator->Fetch()) {
    $properties[] = [
        'CODE' => (string) $property['CODE'],
        'TYPE' => (string) $property['PROPERTY_TYPE'],
        'MULTIPLE' => (string) $property['MULTIPLE'],
    ];
}

$previewCount = 0;
$detailCount = 0;
$galleryCount = 0;
$elements = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId], false, false, ['ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']);
while ($element = $elements->Fetch()) {
    if ((int) $element['PREVIEW_PICTURE'] > 0) {
        ++$previewCount;
    }
    if ((int) $element['DETAIL_PICTURE'] > 0) {
        ++$detailCount;
    }

    $gallery = CIBlockElement::GetProperty($iblockId, (int) $element['ID'], [], ['CODE' => 'GALEREYA_IZOBRAZHENIY']);
    while ($value = $gallery->Fetch()) {
        if ((int) $value['VALUE'] > 0) {
            ++$galleryCount;
        }
    }
}

echo json_encode([
    'iblock_id' => $iblockId,
    'iblock_code' => (string) $iblock['CODE'],
    'elements' => (int) $elementCount,
    'sections' => (int) $sectionCount,
    'section_depths' => $depths,
    'properties' => $properties,
    'preview_images' => $previewCount,
    'detail_images' => $detailCount,
    'gallery_images' => $galleryCount,
    'job' => $job,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
