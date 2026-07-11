<?php

namespace App\Support;

/**
 * The freely-licensed homebrew test ROMs ship inside the app bundle under
 * resources/roms (device storage starts empty — the bundle skips storage/).
 * Seed them into storage on first use so no adb push is ever needed.
 */
class BundledRoms
{
    public const SYSTEMS = [
        'sfc' => 'helloworld.sfc',
        'fc' => 'nestest.nes',
        'gb' => 'dmg-acid2.gb',
        'md' => 'helloworld.md',
    ];

    public static function path(string $filename): ?string
    {
        $target = storage_path('app/roms/'.$filename);

        if (file_exists($target)) {
            return $target;
        }

        $source = base_path('resources/roms/'.$filename);

        if (! file_exists($source)) {
            return null;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }

        copy($source, $target);

        return $target;
    }

    public static function forSystem(string $system): ?string
    {
        $filename = self::SYSTEMS[$system] ?? null;

        return $filename === null ? null : self::path($filename);
    }
}
