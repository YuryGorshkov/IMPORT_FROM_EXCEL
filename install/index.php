<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use WebEnot\ImportExcel\Orm\ChangeTable;
use WebEnot\ImportExcel\Orm\JobTable;
use WebEnot\ImportExcel\Orm\LogTable;
use WebEnot\ImportExcel\Orm\ProfileTable;

Loc::loadMessages(__FILE__);

class webenot_importexcel extends CModule
{
    public const MODULE_ID = 'webenot.importexcel';

    public $MODULE_ID = self::MODULE_ID;
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI = 'https://github.com/YuryGorshkov';

    public function __construct()
    {
        $version = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'] ?? '0.0.0';
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? '';
        $this->MODULE_NAME = Loc::getMessage('WEBENOT_IMPORTEXCEL_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('WEBENOT_IMPORTEXCEL_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('WEBENOT_IMPORTEXCEL_PARTNER_NAME');
    }

    public function DoInstall(): void
    {
        global $APPLICATION;

        if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
            $APPLICATION->ThrowException(Loc::getMessage('WEBENOT_IMPORTEXCEL_DEPENDENCY_ERROR'));
            return;
        }

        if (!ModuleManager::isModuleInstalled(self::MODULE_ID)) {
            ModuleManager::registerModule(self::MODULE_ID);
        }

        require_once dirname(__DIR__) . '/include.php';
        $this->installDb();
        $this->installFiles();
    }

    public function DoUninstall(): void
    {
        global $APPLICATION, $step;
        $step = (int) $step;

        if ($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('WEBENOT_IMPORTEXCEL_UNINSTALL_TITLE'),
                __DIR__ . '/unstep1.php'
            );
            return;
        }

        if (!check_bitrix_sessid()) {
            return;
        }

        if (($_REQUEST['savedata'] ?? 'N') !== 'Y') {
            require_once dirname(__DIR__) . '/include.php';
            $this->uninstallDb();
        }

        $this->uninstallFiles();
        ModuleManager::unRegisterModule(self::MODULE_ID);
    }

    public function installDb(): void
    {
        $connection = Application::getConnection();
        foreach ([ProfileTable::class, JobTable::class, LogTable::class, ChangeTable::class] as $tableClass) {
            $tableName = $tableClass::getTableName();
            if (!$connection->isTableExists($tableName)) {
                $tableClass::getEntity()->createDbTable();
            }
        }
    }

    public function uninstallDb(): void
    {
        $connection = Application::getConnection();
        foreach ([ChangeTable::class, LogTable::class, JobTable::class, ProfileTable::class] as $tableClass) {
            $tableName = $tableClass::getTableName();
            if ($connection->isTableExists($tableName)) {
                $connection->dropTable($tableName);
            }
        }
    }

    public function installFiles(): void
    {
        CopyDirFiles(__DIR__ . '/admin', Application::getDocumentRoot() . '/bitrix/admin', true, true);
    }

    public function uninstallFiles(): void
    {
        DeleteDirFiles(__DIR__ . '/admin', Application::getDocumentRoot() . '/bitrix/admin');
    }
}
