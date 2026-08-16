<?php

namespace App\Support;

/**
 * Freely-licensed homebrew GAMES that ship inside the app bundle under
 * resources/roms, one per supported system — so a user with no ROMs of their
 * own still has something to play. Seeded into storage on first use so no adb
 * push is ever needed. Credits + licenses live in resources/roms/license.txt.
 */
class BundledRoms
{
    public const SYSTEMS = [
        'fc' => 'super-tilt-bro.nes',        // WTFPL
        'sfc' => 'space-rescue-squad.sfc',   // zlib
        'gb' => 'gb-wordyl.gb',              // GPL-3.0 — see license.txt
        'gbc' => 'tobu-tobu-girl-dx.gbc',    // MIT + CC-BY-4.0 — see license.txt
        'md' => 'miniplanets.bin',           // zlib
        'gba' => 'blind-jump.gba',           // MIT
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

    /** Per-system ROM extensions a dropped-in file may use. */
    public const EXTENSIONS = [
        'fc' => ['nes'],
        'sfc' => ['sfc', 'smc'],
        'gb' => ['gb'],
        'gbc' => ['gbc'],
        'md' => ['bin', 'md', 'gen'],
        'gba' => ['gba'],
    ];

    /**
     * Up to $limit ROMs for a system: everything dropped into
     * storage/app/roms/<system>/ in name order, then the bundled homebrew if
     * there is still room. Values are absolute paths.
     */
    public static function listForSystem(string $system, int $limit = 3): array
    {
        $found = [];
        $dir = storage_path('app/roms/'.$system);

        foreach (self::EXTENSIONS[$system] ?? [] as $ext) {
            foreach (glob($dir.'/*.'.$ext) ?: [] as $path) {
                $found[] = $path;
            }
        }
        sort($found);
        $found = array_slice($found, 0, $limit);

        if (count($found) < $limit) {
            $bundled = self::SYSTEMS[$system] ?? null;
            $path = $bundled === null ? null : self::path($bundled);
            if ($path !== null && ! in_array($path, $found, true)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    public static function forSystem(string $system): ?string
    {
        // A ROM the user dropped into storage/app/roms/<system>/ overrides the
        // bundled homebrew — the demo's "bring your own game" path (no picker
        // UI; push via adb / Files). First match in name order wins.
        $dir = storage_path('app/roms/'.$system);
        foreach (self::EXTENSIONS[$system] ?? [] as $ext) {
            $found = glob($dir.'/*.'.$ext) ?: [];
            if ($found !== []) {
                sort($found);

                return $found[0];
            }
        }

        $filename = self::SYSTEMS[$system] ?? null;

        return $filename === null ? null : self::path($filename);
    }
}
