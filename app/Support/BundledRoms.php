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
        'gb' => 'tobu-tobu-girl.gb',         // MIT + CC-BY
        'gbc' => 'tobu-tobu-girl-dx.gbc',    // MIT + CC-BY (color)
        'md' => 'miniplanets.bin',           // zlib
        'gba' => 'butano-fighter.gba',       // zlib
        'n64' => 'gamejam2024.z64',          // MIT — N64brew Game Jam 2024 minigame collection
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
    private const EXTENSIONS = [
        'fc' => ['nes'],
        'sfc' => ['sfc', 'smc'],
        'gb' => ['gb'],
        'gbc' => ['gbc'],
        'md' => ['bin', 'md', 'gen'],
        'gba' => ['gba'],
        'n64' => ['z64', 'n64', 'v64'],
    ];

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
