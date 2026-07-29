<?php

namespace App\Support;

/**
 * Demo-side metadata that the plugin's runtime API does not carry.
 *
 * Emulator::systems() reports id/name/biosRequired/stable/supported only, and
 * ROM file-extensions / region options are not part of any bridge payload — so
 * the demo keeps its own small table. Controller button names and accepted
 * devices are NOT hardcoded here: those come from Emulator::ports() at runtime
 * (see PlayScreen). This class only classifies the runtime button names into
 * overlay groups and supplies the scan/region facts the API can't provide.
 *
 * Extension lists follow ares' accepted media suffixes; region strings mirror
 * KevinBatdorf\RetroEmulator\Region enum values.
 */
class Catalog
{
    /**
     * Well-known ROM filenames to probe by name in the ROM folder. The app
     * sandbox can't LIST some device dirs (e.g. /data/local/tmp) but CAN read a
     * known file path, so probing by name surfaces real, animated games (Zelda,
     * Tetris, Snake) that a directory scan can't discover there.
     */
    public const KNOWN_ROMS = [
        'sfc' => ['alttp.sfc', 'zelda.smc', 'test.sfc'],
        'fc' => ['nesnake2-ntsc.nes', 'nesnake2-pal.nes'],
        'gb' => ['tetris.gb'],
        'md' => [],
    ];

    /** ROM file extensions per system id (lower-case, no dot). */
    public const EXTENSIONS = [
        'fc' => ['nes', 'fc', 'unf', 'unif'],
        'sfc' => ['sfc', 'smc', 'swc', 'fig', 'bs', 'st'],
        'gb' => ['gb'],
        'gbc' => ['gbc'],
        'gba' => ['gba'],
        'md' => ['md', 'gen', 'smd', 'bin'],
    ];

    /**
     * Selectable region strings per system (Region enum values). Empty means
     * the system is region-free (Game Boy) and exposes no region control.
     */
    public const REGIONS = [
        'sfc' => ['NTSC', 'PAL'],
        'fc' => ['NTSC-J', 'NTSC-U', 'PAL'],
        'md' => ['NTSC-J', 'NTSC-U', 'PAL'],
        'gb' => [],
        'gbc' => [],
        'gba' => [],
    ];

    /** D-pad button names, in every system's port. */
    public const DPAD = ['Up', 'Down', 'Left', 'Right'];

    /** System/meta buttons, drawn in the centre strip. */
    public const SYSTEM_BUTTONS = ['Start', 'Select', 'Mode'];

    /** Shoulder buttons, drawn along the top. */
    public const SHOULDER = ['L', 'R'];

    /** Short, grid-friendly console labels (fall back to the systems() name). */
    public const SHORT_NAMES = [
        'fc' => 'NES', 'sfc' => 'SNES', 'gb' => 'Game Boy', 'gbc' => 'GBC',
        'gba' => 'GBA', 'md' => 'Mega Drive',
    ];

    public static function shortName(string $system, string $fallback = ''): string
    {
        return self::SHORT_NAMES[$system] ?? ($fallback !== '' ? $fallback : strtoupper($system));
    }

    public static function extensions(string $system): array
    {
        return self::EXTENSIONS[$system] ?? [];
    }

    public static function knownRoms(string $system): array
    {
        return self::KNOWN_ROMS[$system] ?? [];
    }

    /**
     * Static per-system button set (mirrors the plugin's Button enums), used as
     * the overlay fallback when a live Emulator::ports() read isn't available.
     */
    public const BUTTONS = [
        'sfc' => ['B', 'Y', 'Select', 'Start', 'Up', 'Down', 'Left', 'Right', 'A', 'X', 'L', 'R'],
        'fc' => ['B', 'Select', 'Start', 'Up', 'Down', 'Left', 'Right', 'A'],
        'gb' => ['B', 'Select', 'Start', 'Up', 'Down', 'Left', 'Right', 'A'],
        'gbc' => ['B', 'Select', 'Start', 'Up', 'Down', 'Left', 'Right', 'A'],
        'gba' => ['B', 'Select', 'Start', 'Up', 'Down', 'Left', 'Right', 'A', 'L', 'R'],
        'md' => ['B', 'A', 'Mode', 'Start', 'Up', 'Down', 'Left', 'Right', 'C', 'X', 'Y', 'Z'],
    ];

    public static function buttons(string $system): array
    {
        return self::BUTTONS[$system] ?? ['B', 'A', 'Select', 'Start', 'Up', 'Down', 'Left', 'Right'];
    }

    public static function regions(string $system): array
    {
        // Systems with no explicit table still get a sensible generic set,
        // except the ones we know to be region-free.
        if (array_key_exists($system, self::REGIONS)) {
            return self::REGIONS[$system];
        }

        return ['NTSC-U', 'NTSC-J', 'PAL'];
    }

    /**
     * Split a runtime button list (from Emulator::ports()) into overlay
     * groups. Anything not a d-pad / shoulder / system button is a face
     * button (A, B, X, Y, C, Z…).
     *
     * @param  string[]  $buttons
     * @return array{dpad: string[], face: string[], shoulder: string[], system: string[]}
     */
    public static function groupButtons(array $buttons): array
    {
        $groups = ['dpad' => [], 'face' => [], 'shoulder' => [], 'system' => []];

        foreach ($buttons as $button) {
            if (in_array($button, self::DPAD, true)) {
                $groups['dpad'][] = $button;
            } elseif (in_array($button, self::SHOULDER, true)) {
                $groups['shoulder'][] = $button;
            } elseif (in_array($button, self::SYSTEM_BUTTONS, true)) {
                $groups['system'][] = $button;
            } else {
                $groups['face'][] = $button;
            }
        }

        return $groups;
    }

    /** Per-system boolean toggles beyond the shared config, keyed by config field. */
    public static function toggles(string $system): array
    {
        return match ($system) {
            'sfc' => ['deepBlackBoost' => 'Deep black boost'],
            'gb' => [
                'colorEmulation' => 'Colour emulation',
                'interframeBlending' => 'Interframe blending',
            ],
            default => [],
        };
    }

    /**
     * Native default for a toggle — what the core runs with when the user never
     * touched it, so an untouched toggle reflects reality rather than a
     * blanket "false".
     */
    public static function toggleDefault(string $system, string $field): bool
    {
        return match ([$system, $field]) {
            ['sfc', 'deepBlackBoost'] => false,
            default => false,
        };
    }
}
