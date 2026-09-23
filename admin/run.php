<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use WebEnot\ImportExcel\Admin\AdminUi;
use WebEnot\ImportExcel\Orm\JobTable;
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
            ServiceFactory::jobs()->progress(
                $jobId,
                $chunk,
                ServiceFactory::reader()->totalRows((string) $job['SOURCE_PATH'], (string) $job['SHEET'] ?: null)
            );
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
$APPLICATION->SetTitle((string) Loc::getMessage('WIE_RUN_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

AdminUi::renderStyles();
foreach ($errors as $error) {
    CAdminMessage::ShowMessage(['MESSAGE' => $error, 'TYPE' => 'ERROR']);
}
?>
<div class="wie-page">
    <p class="wie-intro"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_INTRO')) ?></p>
    <?php if ($job) :
        $complete = $job['STATUS'] === JobTable::STATUS_COMPLETED;
        $isDryRun = $job['MODE'] === 'dry_run';
        ?>
        <section class="wie-section">
            <h2><?= htmlspecialcharsbx((string) Loc::getMessage($complete ? 'WIE_RUN_COMPLETE' : 'WIE_RUN_IN_PROGRESS')) ?></h2>
            <p class="wie-section-hint"><?= htmlspecialcharsbx((string) Loc::getMessage(
                $complete
                    ? ($isDryRun ? 'WIE_RUN_COMPLETE_DRY_TEXT' : 'WIE_RUN_COMPLETE_COMMIT_TEXT')
                    : 'WIE_RUN_IN_PROGRESS_TEXT'
            )) ?></p>
            <div class="wie-kpis">
                <?php foreach ([
                    'ROWS_READ' => 'WIE_RUN_ROWS_READ',
                    'ROWS_ADDED' => 'WIE_RUN_ROWS_ADDED',
                    'ROWS_UPDATED' => 'WIE_RUN_ROWS_UPDATED',
                    'ROWS_SKIPPED' => 'WIE_RUN_ROWS_SKIPPED',
                    'ROWS_ERRORS' => 'WIE_RUN_ROWS_ERRORS',
                ] as $field => $message) : ?>
                    <div class="wie-kpi<?= $field === 'ROWS_ERRORS' ? ' wie-kpi-error' : '' ?>">
                        <span><?= htmlspecialcharsbx((string) Loc::getMessage($message)) ?></span>
                        <strong><?= (int) $job[$field] ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="wie-actions">
                <?php if (!$complete) : ?>
                    <form method="post">
                        <?= bitrix_sessid_post() ?>
                        <input type="hidden" name="job_id" value="<?= $jobId ?>">
                        <button class="wie-primary" type="submit"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_CONTINUE')) ?></button>
                    </form>
                <?php else : ?>
                    <a class="wie-primary" href="webenot_importexcel_run.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_ANOTHER')) ?></a>
                <?php endif; ?>
                <a class="wie-secondary" href="webenot_importexcel_jobs.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_HISTORY')) ?></a>
            </div>
        </section>
    <?php elseif ($profiles === []) : ?>
        <div class="wie-inline-warning">
            <strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_NO_PROFILES')) ?></strong><br>
            <?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_NO_PROFILES_TEXT')) ?>
        </div>
        <div class="wie-actions"><a class="wie-primary" href="webenot_importexcel_profile_edit.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_CREATE_PROFILE')) ?></a></div>
    <?php else : ?>
        <div class="wie-steps">
            <div class="wie-step"><span class="wie-step-number">1</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_1')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_1_TEXT')) ?></span></div></div>
            <div class="wie-step"><span class="wie-step-number">2</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_2')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_2_TEXT')) ?></span></div></div>
            <div class="wie-step"><span class="wie-step-number">3</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_3')) ?></strong><span><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_STEP_3_TEXT')) ?></span></div></div>
        </div>
        <form method="post" enctype="multipart/form-data">
            <?= bitrix_sessid_post() ?>
            <section class="wie-section">
                <h2><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_FORM_TITLE')) ?></h2>
                <p class="wie-section-hint"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_FORM_TEXT')) ?></p>
                <div class="wie-grid">
                    <div class="wie-field wie-field-wide">
                        <label class="wie-label" for="wie-profile"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_PROFILE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_RUN_PROFILE_HINT')); ?></label>
                        <select id="wie-profile" name="profile_id" required>
                            <option value=""><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_PROFILE_CHOOSE')) ?></option>
                            <?php foreach ($profiles as $profile) : ?>
                                <option value="<?= (int) $profile['ID'] ?>"><?= htmlspecialcharsbx((string) $profile['NAME']) ?> · #<?= (int) $profile['TARGET_ID'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wie-field wie-field-wide">
                        <label class="wie-label" for="wie-source"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_FILE')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_RUN_FILE_HINT')); ?></label>
                        <input id="wie-source" type="file" name="source" accept=".xlsx,.xls,.ods,.csv" required>
                    </div>
                    <div class="wie-field">
                        <label class="wie-label" for="wie-run-sheet"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_SHEET')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_RUN_SHEET_HINT')); ?></label>
                        <input id="wie-run-sheet" name="sheet" placeholder="<?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_SHEET_PLACEHOLDER')) ?>">
                    </div>
                    <div class="wie-field">
                        <label class="wie-check"><input type="checkbox" name="dry_run" value="Y" checked><span class="wie-check-text"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_DRY')) ?><?php ShowJSHint((string) Loc::getMessage('WIE_RUN_DRY_HINT')); ?></span></label>
                    </div>
                </div>
                <div class="wie-safe"><span class="wie-safe-icon">✓</span><div><strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_SAFE_TITLE')) ?></strong><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_SAFE_TEXT')) ?></div></div>
                <div class="wie-actions">
                    <button class="wie-primary" type="submit"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_START')) ?></button>
                    <a class="wie-secondary" href="webenot_importexcel_profiles.php?lang=<?= urlencode(LANGUAGE_ID) ?>"><?= htmlspecialcharsbx((string) Loc::getMessage('WIE_RUN_PROFILES')) ?></a>
                </div>
            </section>
        </form>
    <?php endif; ?>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
