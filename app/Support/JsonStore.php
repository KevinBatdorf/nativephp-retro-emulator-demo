<?php

namespace App\Support;

/**
 * Dead-simple JSON-file persistence under storage/app/demo. On-device storage
 * starts empty and survives app restarts, so a flat file is all the demo needs
 * to remember settings and ROM folders between launches.
 */
class JsonStore
{
    private static function path(string $name): string
    {
        $dir = storage_path('app/demo');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return "{$dir}/{$name}.json";
    }

    public static function read(string $name): array
    {
        $path = self::path($name);

        if (! file_exists($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    public static function write(string $name, array $data): void
    {
        file_put_contents(self::path($name), json_encode($data, JSON_PRETTY_PRINT));
    }
}
