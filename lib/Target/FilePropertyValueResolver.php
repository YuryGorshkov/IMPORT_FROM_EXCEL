<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Target;

final class FilePropertyValueResolver
{
    public function __construct(private readonly BitrixImageResolver $imageResolver)
    {
    }

    /**
     * @return array{value: mixed, temporary_paths: list<string>, empty: bool}
     */
    public function resolve(mixed $value, bool $multiple): array
    {
        $sources = $this->sources($value, $multiple);
        if ($sources === []) {
            return ['value' => null, 'temporary_paths' => [], 'empty' => true];
        }

        $files = [];
        $temporaryPaths = [];
        try {
            foreach ($sources as $source) {
                $resolved = $this->imageResolver->resolve($source);
                $files[] = $resolved['file'];
                if ($resolved['temporary_path'] !== '') {
                    $temporaryPaths[] = $resolved['temporary_path'];
                }
            }
        } catch (\Throwable $exception) {
            foreach ($temporaryPaths as $temporaryPath) {
                @unlink($temporaryPath);
            }
            throw $exception;
        }

        if (!$multiple) {
            return [
                'value' => $files[0],
                'temporary_paths' => $temporaryPaths,
                'empty' => false,
            ];
        }

        $value = [];
        foreach ($files as $index => $file) {
            $value['n' . $index] = ['VALUE' => $file, 'DESCRIPTION' => ''];
        }

        return ['value' => $value, 'temporary_paths' => $temporaryPaths, 'empty' => false];
    }

    /** @return list<mixed> */
    private function sources(mixed $value, bool $multiple): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            $sources = array_values(array_filter(
                $value,
                static fn(mixed $source): bool => $source !== null && trim((string) $source) !== ''
            ));
        } elseif ($multiple) {
            $sources = preg_split('/\R/u', trim((string) $value)) ?: [];
            $sources = array_values(array_filter(
                array_map('trim', $sources),
                static fn(string $source): bool => $source !== ''
            ));
        } else {
            $sources = [$value];
        }

        if (!$multiple && count($sources) > 1) {
            throw new \InvalidArgumentException('A single file property cannot receive more than one image.');
        }

        return $sources;
    }
}
