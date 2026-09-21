<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Discovery;

final class HeaderNormalizer
{
    private const TRANSLITERATION = [
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'E',
        'Ж' => 'ZH', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
        'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
        'Ф' => 'F', 'Х' => 'KH', 'Ц' => 'TS', 'Ч' => 'CH', 'Ш' => 'SH', 'Щ' => 'SHCH',
        'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'YU', 'Я' => 'YA',
    ];

    public function normalize(string $header, string $fallback = 'COLUMN'): string
    {
        $header = mb_strtoupper(trim($header), 'UTF-8');
        $header = strtr($header, self::TRANSLITERATION);
        $header = preg_replace('/[^A-Z0-9]+/u', '_', $header) ?? '';
        $header = trim(preg_replace('/_+/', '_', $header) ?? '', '_');
        if ($header === '') {
            $header = strtoupper($fallback);
        }
        if (preg_match('/^[0-9]/', $header)) {
            $header = 'FIELD_' . $header;
        }
        return substr($header, 0, 50);
    }
}
