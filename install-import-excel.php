<?php

declare(strict_types=1);

use Bitrix\Main\ModuleManager;
use Bitrix\Main\Web\HttpClient;

const WEBENOT_INSTALLER_MODULE_ID = 'webenot.importexcel';
const WEBENOT_INSTALLER_ARCHIVE_URL = 'https://github.com/YuryGorshkov/IMPORT_FROM_EXCEL/releases/latest/download/webenot.importexcel.zip';
const WEBENOT_INSTALLER_CHECKSUM_URL = 'https://github.com/YuryGorshkov/IMPORT_FROM_EXCEL/releases/latest/download/webenot.importexcel.zip.sha256';
const WEBENOT_INSTALLER_MAX_ARCHIVE_SIZE = 52_428_800;

$documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$prolog = $documentRoot . '/bitrix/modules/main/include/prolog_admin_before.php';
if ($documentRoot === '' || !is_file($prolog)) {
    http_response_code(500);
    exit('1C-Bitrix was not found. Place this file in the site document root.');
}

require_once $prolog;

global $APPLICATION, $USER;
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Для установки модуля войдите как администратор.');
}

$targetParent = $documentRoot . '/local/modules';
$targetDirectory = $targetParent . '/' . WEBENOT_INSTALLER_MODULE_ID;
$error = null;
$installed = ModuleManager::isModuleInstalled(WEBENOT_INSTALLER_MODULE_ID);
$success = false;
$selfDeleted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    try {
        if (!check_bitrix_sessid()) {
            throw new RuntimeException('Сессия истекла. Обновите страницу и повторите установку.');
        }
        if ($installed) {
            throw new RuntimeException('Модуль уже установлен. Повторная установка не требуется.');
        }
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            throw new RuntimeException('Для модуля требуется PHP 8.1 или новее.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Расширение PHP zip не установлено. Обратитесь к администратору сервера.');
        }
        if (is_dir($targetDirectory) || file_exists($targetDirectory)) {
            throw new RuntimeException('Каталог /local/modules/' . WEBENOT_INSTALLER_MODULE_ID . ' уже существует. Удалите или переименуйте его после проверки.');
        }
        if (!is_dir($targetParent) && !mkdir($targetParent, 0775, true) && !is_dir($targetParent)) {
            throw new RuntimeException('Не удалось создать каталог /local/modules.');
        }
        if (!is_writable($targetParent)) {
            throw new RuntimeException('Каталог /local/modules недоступен для записи.');
        }

        $temporaryRoot = $targetParent . '/.webenot-install-' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryRoot, 0770, true) && !is_dir($temporaryRoot)) {
            throw new RuntimeException('Не удалось создать временный каталог установки.');
        }

        $archivePath = $temporaryRoot . '/module.zip';
        $checksumPath = $temporaryRoot . '/module.sha256';
        try {
            webenotInstallerDownload(WEBENOT_INSTALLER_ARCHIVE_URL, $archivePath);
            webenotInstallerDownload(WEBENOT_INSTALLER_CHECKSUM_URL, $checksumPath);
            webenotInstallerVerifyArchive($archivePath, $checksumPath);
            webenotInstallerExtract($archivePath, $temporaryRoot);

            $extractedDirectory = $temporaryRoot . '/' . WEBENOT_INSTALLER_MODULE_ID;
            if (!is_file($extractedDirectory . '/install/index.php') || !is_file($extractedDirectory . '/vendor/autoload.php')) {
                throw new RuntimeException('Установочный архив повреждён или не содержит готовые зависимости.');
            }
            if (!rename($extractedDirectory, $targetDirectory)) {
                throw new RuntimeException('Не удалось переместить модуль в /local/modules.');
            }
        } finally {
            webenotInstallerRemoveDirectory($temporaryRoot);
        }

        require_once $targetDirectory . '/install/index.php';
        if (!class_exists('webenot_importexcel')) {
            throw new RuntimeException('Класс установщика модуля не найден.');
        }
        $module = new webenot_importexcel();
        $module->DoInstall();
        if (!ModuleManager::isModuleInstalled(WEBENOT_INSTALLER_MODULE_ID)) {
            $exception = $APPLICATION->GetException();
            throw new RuntimeException($exception ? $exception->GetString() : 'Bitrix не зарегистрировал модуль.');
        }

        $installed = true;
        $success = true;
        if (isset($_POST['delete_installer'])) {
            $selfDeleted = @unlink(__FILE__);
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$APPLICATION->SetTitle('Установка модуля «Импорт из Excel»');
require $documentRoot . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($error !== null) {
    CAdminMessage::ShowMessage(['MESSAGE' => 'Установка не выполнена', 'DETAILS' => htmlspecialcharsbx($error), 'TYPE' => 'ERROR']);
}
if ($success) {
    CAdminMessage::ShowMessage([
        'MESSAGE' => 'Модуль успешно установлен',
        'DETAILS' => $selfDeleted
            ? 'Установочный файл удалён. Можно переходить к созданию профиля.'
            : 'Удалите install-import-excel.php из корня сайта после проверки.',
        'TYPE' => 'OK',
    ]);
    ?>
    <a class="adm-btn-save" href="/bitrix/admin/webenot_importexcel_profiles.php?lang=<?= LANGUAGE_ID ?>">Открыть профили импорта</a>
    <?php
} elseif (!$installed) {
    ?>
    <div class="adm-info-message-wrap">
        <div class="adm-info-message">
            <p>Установщик скачает проверенную готовую сборку с GitHub, распакует её в <code>/local/modules/webenot.importexcel</code>, создаст таблицы и зарегистрирует модуль.</p>
            <p>Composer и доступ к SSH не требуются.</p>
        </div>
    </div>
    <form method="post">
        <?= bitrix_sessid_post() ?>
        <label><input type="checkbox" name="delete_installer" value="Y" checked> Удалить этот установщик после успешной установки</label>
        <br><br>
        <button class="adm-btn-save" type="submit" name="install" value="Y">Установить модуль</button>
    </form>
    <?php
} else {
    CAdminMessage::ShowMessage(['MESSAGE' => 'Модуль уже установлен.', 'TYPE' => 'OK']);
    ?>
    <a class="adm-btn-save" href="/bitrix/admin/webenot_importexcel_profiles.php?lang=<?= LANGUAGE_ID ?>">Открыть профили импорта</a>
    <?php
}

require $documentRoot . '/bitrix/modules/main/include/epilog_admin.php';

function webenotInstallerDownload(string $url, string $destination): void
{
    $client = new HttpClient([
        'redirect' => true,
        'redirectMax' => 5,
        'socketTimeout' => 30,
        'streamTimeout' => 180,
        'privateIp' => false,
    ]);
    $client->setHeader('User-Agent', 'WebEnot-ImportExcel-Installer/1.0');
    $client->setHeader('Accept', 'application/octet-stream');
    if (!$client->download($url, $destination) || $client->getStatus() < 200 || $client->getStatus() >= 300) {
        @unlink($destination);
        throw new RuntimeException('Не удалось скачать ' . basename($destination) . ': HTTP ' . $client->getStatus());
    }
}

function webenotInstallerVerifyArchive(string $archivePath, string $checksumPath): void
{
    $size = filesize($archivePath);
    if ($size === false || $size < 1 || $size > WEBENOT_INSTALLER_MAX_ARCHIVE_SIZE) {
        throw new RuntimeException('Размер установочного архива недопустим.');
    }
    $checksum = strtolower(trim((string) file_get_contents($checksumPath)));
    if (!preg_match('/^([a-f0-9]{64})(?:\s|$)/', $checksum, $matches)) {
        throw new RuntimeException('Файл контрольной суммы имеет неверный формат.');
    }
    $actual = hash_file('sha256', $archivePath);
    if (!is_string($actual) || !hash_equals($matches[1], strtolower($actual))) {
        throw new RuntimeException('Контрольная сумма архива не совпадает. Установка остановлена.');
    }
}

function webenotInstallerExtract(string $archivePath, string $temporaryRoot): void
{
    $archive = new ZipArchive();
    if ($archive->open($archivePath) !== true) {
        throw new RuntimeException('Не удалось открыть установочный архив.');
    }
    try {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $archive->getNameIndex($index));
            $parts = explode('/', $name);
            if (
                $name === ''
                || str_contains($name, "\0")
                || str_starts_with($name, '/')
                || ($parts[0] ?? '') !== WEBENOT_INSTALLER_MODULE_ID
                || in_array('..', $parts, true)
            ) {
                throw new RuntimeException('В архиве обнаружен небезопасный путь.');
            }
            if ($archive->getExternalAttributesIndex($index, $opsys, $attributes)) {
                $fileType = ($attributes >> 16) & 0xF000;
                if ($fileType === 0xA000) {
                    throw new RuntimeException('Символические ссылки в установочном архиве запрещены.');
                }
            }
        }
        if (!$archive->extractTo($temporaryRoot)) {
            throw new RuntimeException('Не удалось распаковать модуль.');
        }
    } finally {
        $archive->close();
    }
}

function webenotInstallerRemoveDirectory(string $directory): void
{
    if (!is_dir($directory) || !str_starts_with(basename($directory), '.webenot-install-')) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($directory);
}
