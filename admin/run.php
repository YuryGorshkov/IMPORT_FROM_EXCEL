<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Orm\JobTable;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\ServiceFactory;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

if (!Loader::includeModule('webenot.importexcel')) {
    throw new RuntimeException('Module webenot.importexcel is not installed.');
}
if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'W') {
    $APPLICATION->AuthForm('Недостаточно прав для запуска импорта.');
}

$errors = [];
$job = null;
$jobId = (int) ($_REQUEST['job_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        if ($jobId < 1) {
            $profileId = (int) ($_POST['profile_id'] ?? 0);
            $sourcePath = ServiceFactory::uploads()->store($_FILES['source'] ?? []);
            $sheet = trim((string) ($_POST['sheet'] ?? '')) ?: null;
            $jobId = ServiceFactory::jobs()->create(
                $profileId,
                $sourcePath,
                $sheet,
                isset($_POST['dry_run']),
                (int) $USER->GetID()
            );
        }

        $deadline = microtime(true) + 12.0;
        do {
            $job = ServiceFactory::jobs()->acquire($jobId);
            $profile = ServiceFactory::profiles()->get((int) $job['PROFILE_ID']);
            $chunk = ServiceFactory::runner()->runChunk(
                $profile,
                (string) $job['SOURCE_PATH'],
                (string) $job['SHEET'] ?: null,
                $jobId,
                (int) $job['CURSOR_ROW'],
                $job['MODE'] === 'dry_run'
            );
            ServiceFactory::jobs()->progress($jobId, $chunk, ServiceFactory::reader()->totalRows((string) $job['SOURCE_PATH'], (string) $job['SHEET'] ?: null));
            if ($chunk->finished) {
                break;
            }
        } while (microtime(true) < $deadline);
        $job = JobTable::getByPrimary($jobId)->fetch();
    } catch (Throwable $exception) {
        if ($jobId > 0) {
            ServiceFactory::jobs()->fail($jobId, $exception);
        }
        $errors[] = $exception->getMessage();
    }
}

$profiles = ProfileTable::getList(['filter' => ['=ACTIVE' => 'Y'], 'order' => ['NAME' => 'ASC']])->fetchAll();
$APPLICATION->SetTitle('Запуск импорта из Excel');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

foreach ($errors as $error) {
    CAdminMessage::ShowMessage(['MESSAGE' => $error, 'TYPE' => 'ERROR']);
}
if ($job) :
    $complete = $job['STATUS'] === JobTable::STATUS_COMPLETED;
    CAdminMessage::ShowMessage([
        'MESSAGE' => $complete ? 'Импорт завершён.' : 'Пакет обработан. Нажмите «Продолжить».',
        'DETAILS' => sprintf(
            'Прочитано: %d; добавлено: %d; обновлено: %d; пропущено: %d; ошибок: %d.',
            $job['ROWS_READ'],
            $job['ROWS_ADDED'],
            $job['ROWS_UPDATED'],
            $job['ROWS_SKIPPED'],
            $job['ROWS_ERRORS']
        ),
        'TYPE' => $complete ? 'OK' : 'PROGRESS',
        'HTML' => true,
    ]);
    if (!$complete) : ?>
        <form method="post"><?= bitrix_sessid_post() ?><input type="hidden" name="job_id" value="<?= $jobId ?>"><input class="adm-btn-save" type="submit" value="Продолжить"></form>
    <?php endif; ?>
<?php else : ?>
    <form method="post" enctype="multipart/form-data">
        <?= bitrix_sessid_post() ?>
        <table class="adm-detail-content-table edit-table">
            <tr><td width="40%">Профиль:</td><td><select name="profile_id" required><option value="">— выберите —</option><?php foreach ($profiles as $profile) :
                ?><option value="<?= (int) $profile['ID'] ?>"><?= htmlspecialcharsbx($profile['NAME']) ?></option><?php
                                                                                                                          endforeach; ?></select></td></tr>
            <tr><td>Файл:</td><td><input type="file" name="source" accept=".xlsx,.xls,.ods,.csv" required></td></tr>
            <tr><td>Лист:</td><td><input name="sheet"> <small>пусто — первый лист</small></td></tr>
            <tr><td>Проверка без записи:</td><td><input type="checkbox" name="dry_run" value="Y" checked></td></tr>
        </table>
        <input class="adm-btn-save" type="submit" value="Запустить">
    </form>
<?php endif; ?>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
