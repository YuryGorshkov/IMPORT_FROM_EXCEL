<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class LogTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_webenot_import_log';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new IntegerField('JOB_ID'))->configureRequired(),
            (new StringField('LEVEL'))->configureRequired()->configureSize(16),
            (new IntegerField('ROW_NUMBER'))->configureDefaultValue(0),
            (new StringField('CODE'))->configureSize(64),
            (new TextField('MESSAGE'))->configureRequired(),
            (new TextField('CONTEXT'))->configureDefaultValue('{}'),
            (new DatetimeField('CREATED_AT'))->configureDefaultValue(static fn(): DateTime => new DateTime()),
        ];
    }
}
