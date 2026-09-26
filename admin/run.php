<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use WebEnot\ImportExcel\Admin\AdminUi;
use WebEnot\ImportExcel\Domain\ImportProfile;
use WebEnot\ImportExcel\Import\SheetSelector;
use WebEnot\ImportExcel\Orm\JobTable;
use WebEnot\ImportExcel\Orm\LogTable;
use WebEnot\ImportExcel\Orm\ProfileTable;
use WebEnot\ImportExcel\ServiceFactory;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

if (!Loader::includeModule('webenot.importexcel')) {
    throw new RuntimeException((string) Loc::getMessage('WIE_RUN_MODULE_ERROR'));
}
if ($APPLICATION->GetGroupRight('webenot.importexcel') < 'W') {
    $APPLICATION->AuthForm((string) Loc::getMessage('WIE_RUN_ACCESS_DENIED'));
}

function webenotImportExcelProcessJob(int $jobId): array
{
    $deadline = microtime(true) + 12.0;
    do {
        $job = ServiceFactory::jobs()->acquire($jobId);
        $profile = ServiceFactory::profiles()->get((int) $job['PROFILE_ID']);
        $recordChanges = $job['MODE'] === 'dry_run'
            || ServiceFactory::rollbackManager()->isSaved($jobId);
        $chunk = ServiceFactory::runner($recordChanges)->runChunk(
            $profile,
            (string) $job['SOURCE_PATH'],
            (string) $job['SHEET'] ?: null,
            $jobId,
            (int) $job['CURSOR_ROW'],
            $job['MODE'] === 'dry_run'
        );
        ServiceFactory::jobs()->progress(
            $jobId,
            $chunk,
            ServiceFactory::reader()->totalRows(
                (string) $job['SOURCE_PATH'],
                (string) $job['SHEET'] ?: null
            )
        );
        if ($chunk->finished) {
            break;
        }
    } while (microtime(true) < $deadline);

    $result = JobTable::getByPrimary($jobId)->fetch();
    if (!$result) {
        throw new RuntimeException((string) Loc::getMessage('WIE_RUN_JOB_NOT_FOUND'));
    }

    return $result;
}

function webenotImportExcelFormatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' Б';
    }
    $units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'ТБ') {
            return number_format($value, $value >= 10 ? 1 : 2, ',', ' ') . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' Б';
}

function webenotImportExcelVerifiedJob(int $jobId): array
{
    $job = JobTable::getByPrimary($jobId)->fetch();
    if (!$job || $job['MODE'] !== 'dry_run' || $job['STATUS'] !== JobTable::STATUS_COMPLETED) {
        throw new RuntimeException((string) Loc::getMessage('WIE_RUN_VERIFICATION_REQUIRED'));
    }

    return $job;
}

function webenotImportExcelSourceFromRequest(string &$sourceToken): array
{
    $storage = ServiceFactory::uploads();
    $uploadError = (int) ($_FILES['source']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_OK) {
        $previousToken = $sourceToken;
        $sourcePath = $storage->store($_FILES['source']);
        $sourceToken = $storage->token($sourcePath);
        if ($previousToken !== '' && $previousToken !== $sourceToken) {
            $storage->remove($previousToken);
        }
        return [$sourcePath, true];
    }
    if ($uploadError === UPLOAD_ERR_NO_FILE && $sourceToken !== '') {
        try {
            return [$storage->resolve($sourceToken, 86400), false];
        } catch (Throwable $exception) {
            $sourceToken = '';
            throw $exception;
        }
    }
    if ($uploadError !== UPLOAD_ERR_NO_FILE) {
        return [$storage->store($_FILES['source']), true];
    }

    throw new InvalidArgumentException((string) Loc::getMessage('WIE_RUN_FILE_REQUIRED'));
}

function webenotImportExcelValidateStructure(
    ImportProfile $profile,
    string $sourcePath,
    ?string $sheet
): void {
    $options = $profile->options;
    $options['preview_start_row'] = $profile->headerRow();
    $headerRows = ServiceFactory::reader()->preview($sourcePath, $sheet, 1, $options);
    $headerCells = (array) ($headerRows[0]['cells'] ?? []);
    $differences = ServiceFactory::sourceStructureValidator()->differences($profile->mapping, $headerCells);
    if ($differences === []) {
        return;
    }

    $details = [];
    foreach (array_slice($differences, 0, 5) as $difference) {
        $actual = (string) $difference['actual'];
        $details[] = (string) Loc::getMessage('WIE_RUN_STRUCTURE_COLUMN', [
            '#COLUMN#' => (string) $difference['column'],
            '#EXPECTED#' => (string) $difference['expected'],
            '#ACTUAL#' => $actual !== '' ? $actual : (string) Loc::getMessage('WIE_RUN_STRUCTURE_EMPTY'),
        ]);
    }

    throw new InvalidArgumentException((string) Loc::getMessage('WIE_RUN_STRUCTURE_MISMATCH', [
        '#DETAILS#' => implode('; ', $details),
    ]));
}

$errors = [];
$job = null;
$jobId = (int) ($_REQUEST['job_id'] ?? 0);
$selectedProfileId = (int) ($_REQUEST['profile_id'] ?? 0);
$sourceToken = trim((string) ($_REQUEST['source_token'] ?? ''));
$selectedSheet = trim((string) ($_REQUEST['sheet'] ?? ''));
$availableSheets = [];
$shouldProcess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        if (isset($_POST['inspect_file']) || isset($_POST['check_file'])) {
            $selectedProfileId = (int) ($_POST['profile_id'] ?? 0);
            $profileRecord = ProfileTable::getByPrimary($selectedProfileId)->fetch();
            if (!$profileRecord || $profileRecord['ACTIVE'] !== 'Y') {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_RUN_PROFILE_REQUIRED'));
            }
            $profile = ServiceFactory::profiles()->get($selectedProfileId);
            [$sourcePath, $uploadedNewSource] = webenotImportExcelSourceFromRequest($sourceToken);
            $availableSheets = ServiceFactory::reader()->sheets($sourcePath);
            $preferredSheet = trim((string) ($profile->sourceConfig['sheet'] ?? ''));
            $selectedSheet = (new SheetSelector())->select(
                $availableSheets,
                $uploadedNewSource ? '' : $selectedSheet,
                $preferredSheet
            );

            if (isset($_POST['check_file'])) {
                if ($selectedSheet === '') {
                    throw new InvalidArgumentException((string) Loc::getMessage('WIE_RUN_SHEET_REQUIRED'));
                }
                webenotImportExcelValidateStructure($profile, $sourcePath, $selectedSheet);

                $jobId = ServiceFactory::jobs()->create(
                    $selectedProfileId,
                    $sourcePath,
                    $selectedSheet,
                    true,
                    (int) $USER->GetID()
                );
                $shouldProcess = true;
            }
        } elseif (isset($_POST['commit_verified'])) {
            $verified = webenotImportExcelVerifiedJob((int) ($_POST['verified_job_id'] ?? 0));
            $saveRollback = (string) ($_POST['save_rollback'] ?? '');
            if (!in_array($saveRollback, ['Y', 'N'], true)) {
                throw new InvalidArgumentException((string) Loc::getMessage('WIE_RUN_ROLLBACK_CHOICE_REQUIRED'));
            }
            $selectedProfileId = (int) $verified['PROFILE_ID'];
            $jobId = ServiceFactory::jobs()->create(
                $selectedProfileId,
                (string) $verified['SOURCE_PATH'],
                (string) $verified['SHEET'] ?: null,
                false,
                (int) $USER->GetID()
            );
            $shouldProcess = true;
            if ($saveRollback === 'Y') {
                ServiceFactory::rollbackManager()->saveFromPreview($jobId, (int) $verified['ID']);
            } else {
                ServiceFactory::rollbackManager()->disableForJob($jobId, (int) $verified['ID']);
            }
        } elseif (isset($_POST['continue_job'])) {
            $jobId = (int) ($_POST['job_id'] ?? 0);
            $current = JobTable::getByPrimary($jobId)->fetch();
            if (!$current || !in_array($current['STATUS'], [JobTable::STATUS_NEW, JobTable::STATUS_RUNNING], true)) {
                throw new RuntimeException((string) Loc::getMessage('WIE_RUN_JOB_NOT_CONTINUABLE'));
            }
            $selectedProfileId = (int) $current['PROFILE_ID'];
            $shouldProcess = true;
        }

        if ($shouldProcess && $jobId > 0) {
            $job = webenotImportExcelProcessJob($jobId);
        }
    } catch (Throwable $exception) {
        if ($shouldProcess && $jobId > 0) {
            ServiceFactory::jobs()->fail($jobId, $exception);
            $job = JobTable::getByPrimary($jobId)->fetch() ?: null;
        }
        $errors[] = $exception->getMessage();
    }
} elseif ($jobId > 0) {
    $job = JobTable::getByPrimary($jobId)->fetch() ?: null;
    if ($job) {
        $selectedProfileId = (int) $job['PROFILE_ID'];
    }
}

$profiles = ProfileTable::getList([
    'filter' => ['=ACTIVE' => 'Y'],
    'order' => ['NAME' => 'ASC'],
])->fetchAll();
if ($selectedProfileId < 1 && count($profiles) === 1) {
    $selectedProfileId = (int) $profiles[0]['ID'];
}

if ($job === null && $sourceToken !== '' && $availableSheets === []) {
    try {
        $sourcePath = ServiceFactory::uploads()->resolve($sourceToken, 86400);
        $availableSheets = ServiceFactory::reader()->sheets($sourcePath);
        $preferredSheet = '';
        if ($selectedProfileId > 0) {
            $profile = ServiceFactory::profiles()->get($selectedProfileId);
            $preferredSheet = trim((string) ($profile->sourceConfig['sheet'] ?? ''));
        }
        $selectedSheet = (new SheetSelector())->select($availableSheets, $selectedSheet, $preferredSheet);
    } catch (Throwable $exception) {
        $sourceToken = '';
        $availableSheets = [];
        $selectedSheet = '';
        $errors[] = $exception->getMessage();
    }
}

$jobProfile = null;
$jobLogs = [];
$rollbackEstimate = null;
$jobRollbackState = 'none';
if ($job) {
    $jobProfile = ProfileTable::getByPrimary((int) $job['PROFILE_ID'])->fetch() ?: null;
    if ((int) $job['ROWS_ERRORS'] > 0) {
        $jobLogs = LogTable::getList([
            'filter' => ['=JOB_ID' => (int) $job['ID'], '=LEVEL' => 'error'],
            'order' => ['ID' => 'ASC'],
            'limit' => 5,
        ])->fetchAll();
    }
    if ($job['MODE'] === 'dry_run' && $job['STATUS'] === JobTable::STATUS_COMPLETED) {
        $rollbackEstimate = ServiceFactory::rollbackManager()->estimate((int) $job['ID']);
    } elseif ($job['MODE'] === 'commit') {
        $jobRollbackState = ServiceFactory::rollbackManager()->state((int) $job['ID']);
    }
}

$APPLICATION->SetTitle((string) Loc::getMessage('WIE_RUN_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

AdminUi::renderStyles();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['profile_saved'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage(['MESSAGE' => Loc::getMessage('WIE_RUN_PROFILE_SAVED'), 'TYPE' => 'OK']);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['iblock_created'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage(['MESSAGE' => Loc::getMessage('WIE_RUN_IBLOCK_CREATED'), 'TYPE' => 'OK']);
}
if ($job && $job['STATUS'] === JobTable::STATUS_COMPLETED && $job['MODE'] === 'commit') {
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('WIE_RUN_IMPORT_SUCCESS_NOTICE', [
            '#READ#' => (string) $job['ROWS_READ'],
            '#ADDED#' => (string) $job['ROWS_ADDED'],
            '#UPDATED#' => (string) $job['ROWS_UPDATED'],
            '#ERRORS#' => (string) $job['ROWS_ERRORS'],
        ]),
        'TYPE' => 'OK',
    ]);
}
foreach ($errors as $error) {
    CAdminMessage::ShowMessage(['MESSAGE' => $error, 'TYPE' => 'ERROR']);
}
?>
<div class="wie-page">
    <p class="wie-intro"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_INTRO')) ?></p>
    <?php if ($job) :
        $complete = $job['STATUS'] === JobTable::STATUS_COMPLETED;
        $failed = $job['STATUS'] === JobTable::STATUS_FAILED;
        $isDryRun = $job['MODE'] === 'dry_run';
        ?>
        <div class="wie-run-stage">
            <span class="wie-badge <?= $isDryRun ? 'wie-badge-info' : 'wie-badge-success' ?>"><?= htmlspecialcharsbx((string) Loc::getMessage($isDryRun ? 'WIE_RUN_STAGE_CHECK' : 'WIE_RUN_STAGE_IMPORT')) ?></span>
            <strong><?= htmlspecialcharsbx((string) ($jobProfile['NAME'] ?? ('ID ' . (int) $job['PROFILE_ID']))) ?></strong>
            <?php if ((string) $job['SHEET'] !== '') : ?><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_SELECTED_SHEET', ['#SHEET#' => (string) $job['SHEET']])) ?></span><?php endif; ?>
        </div>
        <section class="wie-section wie-result-section">
            <h2><?= htmlspecialcharsbx((string) Loc::getMessage(
                $failed
                    ? 'WIE_RUN_FAILED'
                    : ($complete
                        ? ($isDryRun ? 'WIE_RUN_CHECK_COMPLETE' : 'WIE_RUN_IMPORT_COMPLETE')
                        : 'WIE_RUN_IN_PROGRESS')
            )) ?></h2>
            <p class="wie-section-hint"><?= htmlspecialcharsbx((string) Loc::getMessage(
                $failed
                    ? 'WIE_RUN_FAILED_TEXT'
                    : ($complete
                        ? ($isDryRun
                            ? 'WIE_RUN_CHECK_COMPLETE_TEXT'
                            : ($jobRollbackState === 'saved'
                                ? 'WIE_RUN_IMPORT_COMPLETE_TEXT'
                                : 'WIE_RUN_IMPORT_COMPLETE_NO_ROLLBACK_TEXT'))
                        : 'WIE_RUN_IN_PROGRESS_TEXT')
            )) ?></p>
            <div class="wie-kpis">
                <?php foreach ([
                    'ROWS_READ' => 'WIE_RUN_ROWS_READ',
                    'ROWS_ADDED' => $isDryRun ? 'WIE_RUN_WILL_ADD' : 'WIE_RUN_ROWS_ADDED',
                    'ROWS_UPDATED' => $isDryRun ? 'WIE_RUN_WILL_UPDATE' : 'WIE_RUN_ROWS_UPDATED',
                    'ROWS_SKIPPED' => 'WIE_RUN_ROWS_SKIPPED',
                    'ROWS_ERRORS' => 'WIE_RUN_ROWS_ERRORS',
                ] as $field => $message) : ?>
                    <div class="wie-kpi<?= $field === 'ROWS_ERRORS' ? ' wie-kpi-error' : '' ?>">
                        <span><?= htmlspecialcharsbx((string) Loc::getMessage($message)) ?></span>
                        <strong><?= (int) $job[$field] ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($jobLogs !== []) : ?>
                <div class="wie-error-list">
                    <strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ERROR_LIST')) ?></strong>
                    <ul>
                        <?php foreach ($jobLogs as $log) : ?>
                            <li><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ERROR_ROW', ['#ROW#' => (string) $log['ROW_NUMBER'], '#MESSAGE#' => (string) $log['MESSAGE']])) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($complete && $isDryRun) : ?>
                <div class="wie-safe"><span class="wie-safe-icon">✓</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_READY_TITLE')) ?></strong><?= htmlspecialcharsbx((string) Loc::getMessage((int) $job['ROWS_ERRORS'] > 0 ? 'WIE_RUN_READY_WITH_ERRORS_TEXT' : 'WIE_RUN_READY_TEXT')) ?></div></div>
                <div class="wie-actions">
                    <form class="wie-rollback-choice" method="post">
                        <?= bitrix_sessid_post() ?>
                        <input type="hidden" name="verified_job_id" value="<?= (int) $job['ID'] ?>">
                        <fieldset>
                            <legend><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ROLLBACK_TITLE')) ?></legend>
                            <p><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ROLLBACK_TEXT')) ?></p>
                            <label class="wie-rollback-option">
                                <input type="radio" name="save_rollback" value="Y" required<?= !($rollbackEstimate['complete'] ?? true) ? ' disabled' : '' ?>>
                                <span><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ROLLBACK_SAVE')) ?></strong>
                                    <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ROLLBACK_SIZE', [
                                        '#SIZE#' => webenotImportExcelFormatBytes((int) ($rollbackEstimate['total_bytes'] ?? 0)),
                                        '#FILES#' => (string) ($rollbackEstimate['file_count'] ?? 0),
                                    ])) ?></span>
                            </label>
                            <label class="wie-rollback-option wie-rollback-option-warning">
                                <input type="radio" name="save_rollback" value="N" required>
                                <span><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ROLLBACK_SKIP')) ?></strong>
                                    <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ROLLBACK_SKIP_TEXT')) ?></span>
                            </label>
                            <?php if (!($rollbackEstimate['complete'] ?? true)) : ?>
                                <div class="wie-inline-warning"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ROLLBACK_INCOMPLETE', [
                                    '#COUNT#' => (string) count((array) ($rollbackEstimate['missing_file_ids'] ?? [])),
                                ])) ?></div>
                            <?php endif; ?>
                        </fieldset>
                        <button class="wie-primary wie-primary-large" type="submit" name="commit_verified" value="Y"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_COMMIT')) ?></button>
                    </form>
                    <a class="wie-secondary" href="webenot_importexcel_profile_edit.php?ID=<?= (int) $job['PROFILE_ID'] ?>&lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_EDIT_PROFILE')) ?></a>
                </div>
            <?php elseif (!$complete && !$failed) : ?>
                <div class="wie-actions">
                    <form method="post">
                        <?= bitrix_sessid_post() ?>
                        <input type="hidden" name="job_id" value="<?= (int) $job['ID'] ?>">
                        <button class="wie-primary" type="submit" name="continue_job" value="Y"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_CONTINUE')) ?></button>
                    </form>
                </div>
            <?php else : ?>
                <div class="wie-actions">
                    <a class="wie-primary" href="webenot_importexcel_run.php?profile_id=<?= (int) $job['PROFILE_ID'] ?>&lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage($failed ? 'WIE_RUN_TRY_AGAIN' : 'WIE_RUN_ANOTHER')) ?></a>
                    <a class="wie-secondary" href="webenot_importexcel_jobs.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_HISTORY')) ?></a>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($profiles === []) : ?>
        <div class="wie-inline-warning">
            <strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_NO_PROFILES')) ?></strong><br>
            <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_NO_PROFILES_TEXT')) ?>
        </div>
        <div class="wie-actions"><a class="wie-primary" href="webenot_importexcel_profile_edit.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_CREATE_PROFILE')) ?></a></div>
    <?php else : ?>
        <div class="wie-steps wie-steps-two">
            <div class="wie-step"><span class="wie-step-number">1</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_CHECK')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_CHECK_TEXT')) ?></span></div></div>
            <div class="wie-step"><span class="wie-step-number">2</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_IMPORT')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_IMPORT_TEXT')) ?></span></div></div>
        </div>
        <form id="wie-run-form" method="post" enctype="multipart/form-data">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="source_token" value="<?= htmlspecialcharsbx($sourceToken) ?>">
            <section class="wie-section">
                <h2><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_FORM_TITLE')) ?></h2>
                <p class="wie-section-hint"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_FORM_TEXT')) ?></p>
                <div class="wie-grid">
                    <div class="wie-field wie-field-wide">
                        <label class="wie-label" for="wie-profile"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_PROFILE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_RUN_PROFILE_HINT')); ?></label>
                        <select id="wie-profile" name="profile_id" required>
                            <option value=""><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_PROFILE_CHOOSE')) ?></option>
                            <?php foreach ($profiles as $profile) : ?>
                                <option value="<?= (int) $profile['ID'] ?>"<?= $selectedProfileId === (int) $profile['ID'] ? ' selected' : '' ?>><?= htmlspecialcharsbx((string) $profile['NAME']) ?> · ID <?= (int) $profile['ID'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wie-field wie-field-wide">
                        <label class="wie-label" for="wie-source"><?= htmlspecialcharsbx((string) Loc::getMessage($sourceToken !== '' ? 'WIE_RUN_FILE_REPLACE' : 'WIE_RUN_FILE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_RUN_FILE_HINT')); ?></label>
                        <div class="wie-file-picker">
                            <input class="wie-file-input" id="wie-source" type="file" name="source" accept=".xlsx,.xls,.ods,.csv" aria-describedby="wie-source-name"<?= $sourceToken === '' ? ' required' : '' ?>>
                            <label class="wie-secondary wie-file-select" for="wie-source"><?= htmlspecialcharsbx((string) Loc::getMessage($sourceToken !== '' ? 'WIE_RUN_FILE_BUTTON_REPLACE' : 'WIE_RUN_FILE_BUTTON')) ?></label>
                            <span class="wie-file-name" id="wie-source-name" data-empty="<?= htmlspecialcharsbx((string) Loc::getMessage($sourceToken !== '' ? 'WIE_RUN_FILE_EMPTY_REPLACE' : 'WIE_RUN_FILE_EMPTY')) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage($sourceToken !== '' ? 'WIE_RUN_FILE_EMPTY_REPLACE' : 'WIE_RUN_FILE_EMPTY')) ?></span>
                        </div>
                    </div>
                    <?php if ($sourceToken !== '' && $availableSheets !== []) : ?>
                        <div class="wie-field wie-field-wide wie-sheet-picker">
                            <label class="wie-label" for="wie-sheet"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_SHEET')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_RUN_SHEET_HINT')); ?></label>
                            <select id="wie-sheet" name="sheet" required>
                                <?php if (count($availableSheets) > 1) : ?>
                                    <option value=""><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_SHEET_CHOOSE')) ?></option>
                                <?php endif; ?>
                                <?php foreach ($availableSheets as $sheetName) : ?>
                                    <option value="<?= htmlspecialcharsbx($sheetName) ?>"<?= $selectedSheet === $sheetName ? ' selected' : '' ?>><?= htmlspecialcharsbx($sheetName) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="wie-field-note"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_SHEETS_FOUND', [
                                '#COUNT#' => (string) count($availableSheets),
                            ])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($sourceToken !== '') : ?>
                    <div class="wie-file-carried"><span>✓</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_FILE_CARRIED_TITLE')) ?></strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_FILE_CARRIED_TEXT')) ?></div></div>
                <?php endif; ?>
                <div class="wie-safe"><span class="wie-safe-icon">✓</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_CHECK_SAFE_TITLE')) ?></strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_CHECK_SAFE_TEXT')) ?></div></div>
                <div class="wie-actions">
                    <?php if ($sourceToken !== '' && $availableSheets !== []) : ?>
                        <button class="wie-primary wie-primary-large" type="submit" name="check_file" value="Y"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_CHECK_SHEET')) ?></button>
                    <?php else : ?>
                        <button id="wie-inspect-file" class="wie-primary wie-primary-large" type="submit" name="inspect_file" value="Y"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_INSPECT_FILE')) ?></button>
                    <?php endif; ?>
                    <?php if ($selectedProfileId > 0) : ?>
                        <a class="wie-secondary" href="webenot_importexcel_profile_edit.php?ID=<?= $selectedProfileId ?>&lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_EDIT_PROFILE')) ?></a>
                    <?php else : ?>
                        <a class="wie-secondary" href="webenot_importexcel_profiles.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_PROFILES')) ?></a>
                    <?php endif; ?>
                </div>
            </section>
        </form>
        <script>
        (function () {
            var form = document.getElementById('wie-run-form');
            var file = document.getElementById('wie-source');
            var profile = document.getElementById('wie-profile');
            var inspect = document.getElementById('wie-inspect-file');
            if (!form || !file) {
                return;
            }
            file.addEventListener('change', function () {
                if (!file.files || file.files.length === 0 || !profile || profile.value === '') {
                    return;
                }
                var submitter = inspect;
                if (!submitter) {
                    submitter = document.createElement('button');
                    submitter.type = 'submit';
                    submitter.name = 'inspect_file';
                    submitter.value = 'Y';
                    submitter.hidden = true;
                    form.appendChild(submitter);
                }
                form.requestSubmit(submitter);
            });
        }());
        </script>
    <?php endif; ?>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
