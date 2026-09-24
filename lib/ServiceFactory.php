<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel;

use Bitrix\Main\Application;
use WebEnot\ImportExcel\Discovery\ColumnDiscoveryService;
use WebEnot\ImportExcel\Discovery\HeaderNormalizer;
use WebEnot\ImportExcel\Import\ImportRunner;
use WebEnot\ImportExcel\Import\RollbackService;
use WebEnot\ImportExcel\Job\JobManager;
use WebEnot\ImportExcel\Mapping\MappingValidator;
use WebEnot\ImportExcel\Mapping\RowMapper;
use WebEnot\ImportExcel\Mapping\Transformer;
use WebEnot\ImportExcel\Profile\ProfileRepository;
use WebEnot\ImportExcel\Reader\SpreadsheetReader;
use WebEnot\ImportExcel\Reporting\OrmReporter;
use WebEnot\ImportExcel\Security\SourceFilePolicy;
use WebEnot\ImportExcel\Security\UploadStorage;
use WebEnot\ImportExcel\Target\BitrixIblockGateway;
use WebEnot\ImportExcel\Target\ElementCodeGenerator;
use WebEnot\ImportExcel\Target\IblockCreator;
use WebEnot\ImportExcel\Target\IblockTarget;
use WebEnot\ImportExcel\Validation\SourceStructureValidator;

final class ServiceFactory
{
    public static function filePolicy(): SourceFilePolicy
    {
        return new SourceFilePolicy();
    }

    public static function reader(): SpreadsheetReader
    {
        return new SpreadsheetReader(self::filePolicy());
    }

    public static function uploads(): UploadStorage
    {
        return new UploadStorage(
            Application::getDocumentRoot() . '/upload/webenot.importexcel',
            self::filePolicy()
        );
    }

    public static function previewUploads(): UploadStorage
    {
        return new UploadStorage(
            Application::getDocumentRoot() . '/upload/webenot.importexcel/preview',
            self::filePolicy()
        );
    }

    public static function discovery(): ColumnDiscoveryService
    {
        return new ColumnDiscoveryService(self::reader(), new HeaderNormalizer());
    }

    public static function iblockCreator(): IblockCreator
    {
        return new IblockCreator(new HeaderNormalizer());
    }

    public static function sourceStructureValidator(): SourceStructureValidator
    {
        return new SourceStructureValidator();
    }

    public static function profiles(): ProfileRepository
    {
        return new ProfileRepository(new MappingValidator());
    }

    public static function jobs(): JobManager
    {
        return new JobManager();
    }

    public static function runner(): ImportRunner
    {
        $gateway = new BitrixIblockGateway();
        return new ImportRunner(
            self::reader(),
            new RowMapper(new Transformer(), new MappingValidator()),
            new IblockTarget($gateway, new ElementCodeGenerator(new HeaderNormalizer())),
            new OrmReporter()
        );
    }

    public static function rollback(): RollbackService
    {
        return new RollbackService(new BitrixIblockGateway());
    }
}
