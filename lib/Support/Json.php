<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Support;

use JsonException;

final class Json
{
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Invalid JSON payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('JSON payload must contain an object or an array.');
        }

        return $decoded;
    }
}
