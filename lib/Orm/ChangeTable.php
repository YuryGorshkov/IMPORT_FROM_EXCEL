<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class ChangeTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_webenot_import_change';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new IntegerField('JOB_ID'))->configureRequired(),
            (new StringField('ENTITY_TYPE'))->configureRequired()->configureSize(32),
            (new IntegerField('ENTITY_ID'))->configureRequired(),
            (new StringField('ACTION'))->configureRequired()->configureSize(16),
            (new TextField('BEFORE_DATA'))->configureDefaultValue('{}'),
            (new TextField('AFTER_DATA'))->configureDefaultValue('{}'),
            (new BooleanField('ROLLED_BACK'))->configureValues('N', 'Y')->configureDefaultValue('N'),
            (new DatetimeField('CREATED_AT'))->configureDefaultValue(static fn(): DateTime => new DateTime()),
        ];
    }
}
