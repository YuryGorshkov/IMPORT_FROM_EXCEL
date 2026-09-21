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

final class ProfileTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_webenot_import_profile';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('NAME'))->configureRequired()->configureSize(255),
            (new BooleanField('ACTIVE'))->configureValues('N', 'Y')->configureDefaultValue('Y'),
            (new StringField('TARGET_TYPE'))->configureRequired()->configureSize(32)->configureDefaultValue('iblock'),
            (new IntegerField('TARGET_ID'))->configureRequired(),
            (new TextField('SOURCE_CONFIG'))->configureDefaultValue('{}'),
            (new TextField('MAPPING'))->configureRequired(),
            (new TextField('OPTIONS'))->configureDefaultValue('{}'),
            (new DatetimeField('CREATED_AT'))->configureDefaultValue(static fn(): DateTime => new DateTime()),
            (new DatetimeField('UPDATED_AT'))->configureDefaultValue(static fn(): DateTime => new DateTime()),
        ];
    }
}
