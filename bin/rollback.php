<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is available only in CLI mode.\n");
    exit(1);
}

$options = getopt('', ['document-root:', 'job:']);
$documentRoot = rtrim((string) ($options['document-root'] ?? ''), '/\\');
$jobId = (int) ($options['job'] ?? 0);
if ($documentRoot === '' || $jobId < 1) {
    fwrite(STDERR, "Usage: php bin/rollback.php --document-root=/var/www/site --job=123\n");
    exit(2);
}

$_SERVER['DOCUMENT_ROOT'] = $documentRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_CRONTAB', true);
require_once $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use WebEnot\ImportExcel\ServiceFactory;

if (!Loader::includeModule('webenot.importexcel')) {
    fwrite(STDERR, "Module webenot.importexcel is not installed.\n");
    exit(3);
}

try {
    $count = ServiceFactory::rollback()->rollback($jobId);
    fwrite(STDOUT, "Restored records: {$count}\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(4);
}
