<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is available only in CLI mode.\n");
    exit(1);
}

$options = getopt('', ['document-root:', 'profile:', 'file:', 'sheet::', 'dry-run']);
$documentRoot = rtrim((string) ($options['document-root'] ?? ''), '/\\');
$profileId = (int) ($options['profile'] ?? 0);
$sourcePath = (string) ($options['file'] ?? '');
$sheet = isset($options['sheet']) && $options['sheet'] !== false ? (string) $options['sheet'] : null;
$dryRun = array_key_exists('dry-run', $options);

if ($documentRoot === '' || $profileId < 1 || $sourcePath === '') {
    fwrite(STDERR, "Usage: php bin/import.php --document-root=/var/www/site --profile=1 --file=/data/import.xlsx [--sheet=Sheet1] [--dry-run]\n");
    exit(2);
}

$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);

$prolog = $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!is_file($prolog)) {
    fwrite(STDERR, "Bitrix prolog was not found under the specified document root.\n");
    exit(2);
}
require_once $prolog;

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\Orm\JobTable;
use WebEnot\ImportExcel\ServiceFactory;

if (!Loader::includeModule('webenot.importexcel')) {
    fwrite(STDERR, "Module webenot.importexcel is not installed.\n");
    exit(3);
}

$sourcePath = ServiceFactory::filePolicy()->validate($sourcePath);
$jobId = ServiceFactory::jobs()->create($profileId, $sourcePath, $sheet, $dryRun);

try {
    do {
        $job = ServiceFactory::jobs()->acquire($jobId);
        $profile = ServiceFactory::profiles()->get((int) $job['PROFILE_ID']);
        $chunk = ServiceFactory::runner()->runChunk(
            $profile,
            (string) $job['SOURCE_PATH'],
            (string) $job['SHEET'] ?: null,
            $jobId,
            (int) $job['CURSOR_ROW'],
            $dryRun
        );
        $totalRows = ServiceFactory::reader()->totalRows($sourcePath, $sheet);
        ServiceFactory::jobs()->progress($jobId, $chunk, $totalRows);
    } while (!$chunk->finished);

    $job = JobTable::getByPrimary($jobId)->fetch();
    fwrite(STDOUT, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit((int) $job['ROWS_ERRORS'] > 0 ? 4 : 0);
} catch (Throwable $exception) {
    ServiceFactory::jobs()->fail($jobId, $exception);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(5);
}
