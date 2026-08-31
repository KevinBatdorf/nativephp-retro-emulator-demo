<?php

namespace App\Support;

use KevinBatdorf\RetroEmulator\Accuracy;
use KevinBatdorf\RetroEmulator\Config\Config;
use KevinBatdorf\RetroEmulator\Config\FcConfig;
use KevinBatdorf\RetroEmulator\Config\GbaConfig;
use KevinBatdorf\RetroEmulator\Config\GbConfig;
use KevinBatdorf\RetroEmulator\Config\MdConfig;
use KevinBatdorf\RetroEmulator\Config\SfcConfig;
use KevinBatdorf\RetroEmulator\Config\SystemConfig;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use KevinBatdorf\RetroEmulator\Region;
use KevinBatdorf\RetroEmulator\Shaders;
use Native\Mobile\Platform;

/**
 * The two-scope settings model from the plan. Global settings apply on every
 * boot; per-system settings (region, per-system toggles, device, CRT override)
 * are keyed by system id and layered on top. Both persist to JSON and both
 * reset to a documented default set.
 *
 * configFor() is the single place that turns stored settings into the plugin's
 * strongly-typed SystemConfig object handed to Emulator::loadSystem().
 */
class SettingsStore
{
    private const GLOBAL_FILE = 'settings-global';

    private const SYSTEM_FILE = 'settings-system';

    public const REWIND_BUFFER_SECONDS = 10;

    public const GLOBAL_DEFAULTS = [
        'luminance' => 100,
        'saturation' => 100,
        'overscan' => false,
        'volume' => 100,
        'balance' => 0,
        'speed' => 1.0,
        'crt' => false,      // "Apply CRT filter" — one toggle, not a shader picker
        'rumble' => false,
        // Capture serializes a full save state every 10 frames for as long as a
        // game runs, so it costs CPU even when nobody rewinds.
        'rewind' => false,
        // Touch-pad feel, as percentages so the overlay's sliders can carry
        // them; the dpad element takes fractions.
        'dpadThreshold' => 33,
        // Higher = harder to hit diagonals (cleaner cardinals); 0 = free
        // diagonals, the dpad element's own default.
        'dpadDiagonalRatio' => 0,
    ];

    public const SYSTEM_DEFAULTS = [
        'region' => '',      // '' = auto (resolved from the ROM)
        'device' => 'Gamepad',
        'crt' => 'inherit',  // inherit | on | off
        'backend' => '',     // '' = default engine; a bundled engine or BYO core name
        // per-system toggles (deepBlackBoost, colorEmulation, …) merge in as needed
    ];

    // ── Global scope ────────────────────────────────

    public static function global(): array
    {
        return array_merge(self::GLOBAL_DEFAULTS, JsonStore::read(self::GLOBAL_FILE));
    }

    public static function setGlobal(string $key, mixed $value): void
    {
        $data = JsonStore::read(self::GLOBAL_FILE);
        $data[$key] = $value;
        JsonStore::write(self::GLOBAL_FILE, $data);
    }

    public static function resetGlobal(): void
    {
        JsonStore::write(self::GLOBAL_FILE, []);
    }

    // ── Per-system scope ────────────────────────────

    public static function system(string $id): array
    {
        $all = JsonStore::read(self::SYSTEM_FILE);

        return array_merge(self::SYSTEM_DEFAULTS, $all[$id] ?? []);
    }

    public static function setSystem(string $id, string $key, mixed $value): void
    {
        $all = JsonStore::read(self::SYSTEM_FILE);
        $all[$id] ??= [];
        $all[$id][$key] = $value;
        JsonStore::write(self::SYSTEM_FILE, $all);
    }

    public static function resetSystem(string $id): void
    {
        $all = JsonStore::read(self::SYSTEM_FILE);
        unset($all[$id]);
        JsonStore::write(self::SYSTEM_FILE, $all);
    }

    /**
     * Boot-only renderer preset, stored per system since only SNES/GBA have a
     * second renderer. Falls back to the legacy global key so an older
     * on-device settings file keeps its choice.
     */
    public static function accurateFor(string $id): bool
    {
        $s = JsonStore::read(self::SYSTEM_FILE)[$id] ?? [];

        return (bool) ($s['accurate'] ?? JsonStore::read(self::GLOBAL_FILE)['accurate'] ?? false);
    }

    /** BYO cores this app packages in resources/emulator-cores (Android loads them; iOS cannot). */
    public static function packagedCores(string $id): array
    {
        // No glob: a directory wildcard in the pattern matches nothing on the PHP 8.4 Android runtime.
        $files = [];
        $root = resource_path('emulator-cores/android');

        foreach (is_dir($root) ? (scandir($root) ?: []) : [] as $abi) {
            if (! str_starts_with($abi, '.') && is_dir("{$root}/{$abi}")) {
                $files = [...$files, ...(scandir("{$root}/{$abi}") ?: [])];
            }
        }

        return array_values(array_filter(
            (array) (config('retro-emulator.backends')[$id] ?? []),
            fn ($engine) => is_string($engine) && array_any(
                $files,
                fn ($f) => str_starts_with($f, "{$engine}_libretro") && str_ends_with($f, '.so'),
            ),
        ));
    }

    /** Engines a boot can land on here: bridge claimants, plus packaged cores off iOS. */
    public static function availableBackends(string $id): array
    {
        $claimants = [];
        foreach (self::systemsCached() as $entry) {
            if (($entry['id'] ?? '') === $id) {
                $claimants = $entry['backends'] ?? [];
                break;
            }
        }

        $packaged = Platform::current() === Platform::IOS ? [] : self::packagedCores($id);

        return array_values(array_unique([...$claimants, ...$packaged]));
    }

    /** Config-named engines this platform cannot load (iOS: the packaged Android cores). */
    public static function unavailableBackends(string $id): array
    {
        return array_values(array_diff(self::packagedCores($id), self::availableBackends($id)));
    }

    /**
     * What a no-choice boot lands on — the same skip-absent-engines walk the
     * native layer runs, ending on ares. Prediction only: after a boot the
     * truth is Emulator::backend().
     */
    public static function defaultEngine(string $id): string
    {
        $available = self::availableBackends($id);

        foreach ((array) (config('retro-emulator.backends')[$id] ?? []) as $engine) {
            if ($available === [] || in_array($engine, $available, true)) {
                return (string) $engine;
            }
        }

        return 'ares';
    }

    /**
     * The engine a boot of $id will resolve to: explicit per-system choice,
     * else the first AVAILABLE config preference, else the ares floor.
     */
    public static function effectiveBackend(string $id, array $overrides = []): string
    {
        $s = array_merge(self::system($id), $overrides);

        return ($s['backend'] ?? '') ?: self::defaultEngine($id);
    }

    /**
     * Save-state bookkeeping per system+ROM: newest-first [['slot','at'], …],
     * capped at three. The plugin owns the state files; this only remembers
     * which named slots exist and when they were written.
     */
    public static function savesFor(string $id, string $rom): array
    {
        return array_values(JsonStore::read('saves')["{$id}:{$rom}"] ?? []);
    }

    public static function recordSave(string $id, string $rom, string $slot): array
    {
        $all = JsonStore::read('saves');
        $key = "{$id}:{$rom}";
        $list = array_values(array_filter(
            $all[$key] ?? [],
            fn ($entry) => ($entry['slot'] ?? '') !== $slot,
        ));
        array_unshift($list, ['slot' => $slot, 'at' => time()]);
        $all[$key] = array_slice($list, 0, 3);
        JsonStore::write('saves', $all);

        return $all[$key];
    }

    /** Memoized Emulator::systems() — capability lookups run per render. */
    private static ?array $systemsCache = null;

    /** An empty answer is a bridge that wasn't ready — never memoize it. */
    private static function systemsCached(): array
    {
        if (self::$systemsCache === null || self::$systemsCache === []) {
            self::$systemsCache = Emulator::systems();
        }

        return self::$systemsCache;
    }

    /**
     * One engine's capability object for a system, [] when unknown
     * (off-device, or an engine that doesn't serve the system).
     */
    public static function capabilitiesFor(string $id, string $backend): array
    {
        foreach (self::systemsCached() as $entry) {
            if (($entry['id'] ?? '') === $id) {
                return $entry['capabilities'][$backend] ?? [];
            }
        }

        return [];
    }

    private static function videoSettingsAllowed(string $id, array $overrides): bool
    {
        $backend = self::effectiveBackend($id, $overrides);
        $caps = self::capabilitiesFor($id, $backend);

        // Off-device / unknown pair: ares is the only engine that ships
        // engine-side picture knobs, so the name is the honest fallback.
        return $caps === []
            ? $backend === 'ares'
            : (bool) ($caps['videoSettings'] ?? false);
    }

    /**
     * Whether the effective engine accepts enabling $field — enabling an
     * unsupported toggle at boot throws UNSUPPORTED_OPTION, so stored trues
     * from another engine's session must not reach this boot's config.
     */
    private static function toggleAllowed(string $id, array $overrides, string $field): bool
    {
        $caps = self::capabilitiesFor($id, self::effectiveBackend($id, $overrides));

        return $caps === []
            || in_array($field, $caps['toggles'] ?? [], true);
    }

    // ── Config assembly ─────────────────────────────

    /**
     * Build the plugin SystemConfig for a boot, merging global AV/presentation
     * settings with the per-system scope. The concrete subclass is chosen per
     * system so its extra toggles (SFC deepBlackBoost, GB colour emulation) are
     * carried through; every compiled system has one.
     *
     * @param  array<string, mixed>  $overrides  A/B bench values that win over
     *                                           stored settings for one boot.
     */
    public static function configFor(string $id, array $overrides = []): SystemConfig|Config|array
    {
        $g = self::global();
        $s = array_merge(self::system($id), $overrides);
        $videoOk = self::videoSettingsAllowed($id, $overrides);
        $tOk = fn (string $field) => self::toggleAllowed($id, $overrides, $field)
            && (bool) ($s[$field] ?? false);

        $shared = [
            // Engines without engine-side picture knobs throw
            // UNSUPPORTED_OPTION on non-neutral values at boot, so they
            // neutralize here rather than brick the boot.
            'luminance' => $videoOk ? (int) $g['luminance'] : 100,
            'saturation' => $videoOk ? (int) $g['saturation'] : 100,
            'overscan' => $videoOk && (bool) $g['overscan'],
            'volume' => (int) $g['volume'],
            'balance' => (int) $g['balance'],
            'speed' => (float) $g['speed'],
            'rumble' => (bool) $g['rumble'],
            'rewind' => (bool) $g['rewind'],
            'rewindBufferSeconds' => $g['rewind'] ? self::REWIND_BUFFER_SECONDS : null,
            'shader' => self::resolveShader($g, $s),
            'backend' => ($s['backend'] ?? '') ?: null,
        ];

        // Only SNES and GBA have a second renderer; the key is noise elsewhere.
        $accuracy = self::accurateFor($id) ? Accuracy::Accurate : Accuracy::Performance;

        $region = self::resolveRegion($id, $s);

        return match ($id) {
            'sfc' => new SfcConfig(
                ...$shared,
                accuracy: $accuracy,
                region: $region,
                deepBlackBoost: $tOk('deepBlackBoost'),
            ),
            'gb', 'gbc' => new GbConfig(
                ...$shared,
                colorEmulation: $tOk('colorEmulation'),
                interframeBlending: $tOk('interframeBlending'),
                rawAudio: (bool) ($s['rawAudio'] ?? $s['naturalAudio'] ?? false),
            ),
            'fc' => new FcConfig(...$shared, region: $region),
            'md' => new MdConfig(...$shared, region: $region),
            'gba' => new GbaConfig(
                ...$shared,
                accuracy: $accuracy,
                colorEmulation: $tOk('colorEmulation'),
                interframeBlending: $tOk('interframeBlending'),
            ),
            default => array_filter(
                [...$shared, 'region' => $region?->value],
                fn ($v) => $v !== null,
            ),
        };
    }

    private static function resolveRegion(string $id, array $s): ?Region
    {
        if (($s['region'] ?? '') === '' || Catalog::regions($id) === []) {
            return null; // auto: resolved from the ROM
        }

        return Region::tryFrom($s['region']);
    }

    /**
     * CRT is a single global toggle; the per-system scope can force it on/off.
     * Returns a .slangp path to apply, or null to clear. When CRT is wanted but
     * no preset is bundled, returns null (PlayScreen surfaces that as a note).
     */
    private static function resolveShader(array $g, array $s): ?string
    {
        $crt = match ($s['crt'] ?? 'inherit') {
            'on' => true,
            'off' => false,
            default => (bool) $g['crt'],
        };

        return $crt ? self::crtPreset() : null;
    }

    /**
     * The CRT preset the toggle applies — a real crt-*.slangp (the bundled
     * public-domain crt-lottes) preferred over any other preset lying around
     * (the /shader dev route's grayscale would otherwise win the scan).
     */
    public static function crtPreset(): ?string
    {
        // Memoized — render paths call this and Shaders::in walks recursively.
        static $scanned = false;
        static $preset = null;

        if ($scanned) {
            return $preset;
        }
        $scanned = true;

        foreach ([
            storage_path('app/shaders'),
            base_path('resources/shaders'),
            '/data/local/tmp/shaders',
        ] as $dir) {
            $found = Shaders::in($dir);

            if ($found === []) {
                continue;
            }

            foreach ($found as $candidate) {
                if (str_starts_with(basename($candidate), 'crt')) {
                    return $preset = $candidate;
                }
            }

            return $preset = $found[0];
        }

        return $preset;
    }
}
