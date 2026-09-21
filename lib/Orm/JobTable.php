<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class JobTable extends DataManager
{
    public const STATUS_NEW = 'new';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public static function getTableName(): string
    {
        return 'b_webenot_import_job';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new IntegerField('PROFILE_ID'))->configureRequired(),
            (new StringField('STATUS'))->configureRequired()->configureSize(24)->configureDefaultValue(self::STATUS_NEW),
            (new StringField('MODE'))->configureRequired()->configureSize(16)->configureDefaultValue('commit'),
            (new StringField('SOURCE_PATH'))->configureRequired()->configureSize(1024),
            (new StringField('SHEET'))->configureSize(255),
            (new IntegerField('CURSOR_ROW'))->configureDefaultValue(0),
            (new IntegerField('TOTAL_ROWS'))->configureDefaultValue(0),
            (new IntegerField('ROWS_READ'))->configureDefaultValue(0),
            (new IntegerField('ROWS_ADDED'))->configureDefaultValue(0),
            (new IntegerField('ROWS_UPDATED'))->configureDefaultValue(0),
            (new IntegerField('ROWS_SKIPPED'))->configureDefaultValue(0),
            (new IntegerField('ROWS_ERRORS'))->configureDefaultValue(0),
            (new IntegerField('CREATED_BY'))->configureDefaultValue(0),
            (new StringField('LOCK_TOKEN'))->configureSize(64),
            (new DatetimeField('LOCKED_AT')),
            (new DatetimeField('STARTED_AT')),
            (new DatetimeField('FINISHED_AT')),
            (new TextField('ERROR_MESSAGE')),
            (new DatetimeField('CREATED_AT'))->configureDefaultValue(static fn(): DateTime => new DateTime()),
        ];
    }
}
