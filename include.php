<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (class_exists(Loader::class) && method_exists(Loader::class, 'registerNamespace')) {
    Loader::registerNamespace('WebEnot\\ImportExcel', __DIR__ . '/lib');
}
