<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;

final class IblockCreator
{
    public function __construct(private readonly HeaderNormalizer $normalizer)
    {
    }

    public function suggestCode(string $name): string
    {
        return strtolower($this->normalizer->normalize($name, 'catalog'));
    }

    public function create(string $name, string $typeId, string $code = '', string $siteId = ''): int
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The Bitrix IBlock module is required.');
        }

        $name = trim($name);
        $typeId = trim($typeId);
        if ($name === '') {
            throw new \InvalidArgumentException('IBlock name is required.');
        }
        if ($typeId === '' || !\CIBlockType::GetByID($typeId)->Fetch()) {
            throw new \InvalidArgumentException('A valid IBlock type is required.');
        }

        $baseCode = $this->suggestCode($code !== '' ? $code : $name);
        $code = $this->uniqueCode($baseCode);
        $siteId = trim($siteId) ?: (string) \CSite::GetDefSite();
        if ($siteId === '') {
            throw new \RuntimeException('A default site was not found.');
        }

        $iblock = new \CIBlock();
        $id = (int) $iblock->Add([
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'CODE' => $code,
            'IBLOCK_TYPE_ID' => $typeId,
            'SITE_ID' => [$siteId],
            'SORT' => 500,
            'VERSION' => 1,
            'WORKFLOW' => 'N',
            'BIZPROC' => 'N',
            'GROUP_ID' => ['2' => 'R'],
            'LIST_PAGE_URL' => '#SITE_DIR#/#IBLOCK_CODE#/',
            'SECTION_PAGE_URL' => '#SITE_DIR#/#IBLOCK_CODE#/#SECTION_CODE#/',
            'DETAIL_PAGE_URL' => '#SITE_DIR#/#IBLOCK_CODE#/#SECTION_CODE#/#ELEMENT_CODE#/',
        ]);
        if ($id < 1) {
            throw new \RuntimeException('Unable to create IBlock: ' . (string) $iblock->LAST_ERROR);
        }

        return $id;
    }

    private function uniqueCode(string $baseCode): string
    {
        $code = $baseCode;
        $suffix = 2;
        while (\CIBlock::GetList([], ['=CODE' => $code])->Fetch()) {
            $tail = '_' . $suffix++;
            $code = substr($baseCode, 0, 50 - strlen($tail)) . $tail;
        }

        return $code;
    }
}
