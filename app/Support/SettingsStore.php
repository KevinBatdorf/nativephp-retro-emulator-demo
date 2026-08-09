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
use KevinBatdorf\RetroEmulator\Region;
use KevinBatdorf\RetroEmulator\Shaders;

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
        'gamma' => 100,      // percent, like luminance/saturation
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

    /**
     * The engine a boot of $id will resolve to: explicit per-system choice,
     * else the app's config preference, else the plugin's ares floor.
     */
    public static function effectiveBackend(string $id, array $overrides = []): string
    {
        $s = array_merge(self::system($id), $overrides);

        return ($s['backend'] ?? '')
            ?: (config('retro-emulator.backends')[$id][0] ?? 'ares');
    }

    // ── Config assembly ─────────────────────────────

    /**
     * Build the plugin SystemConfig for a boot, merging global AV/presentation
     * settings with the per-system scope. The concrete subclass is chosen per
     * system so its extra toggles (SFC deepBlackBoost, GB colour emulation) are
     * carried through; the four compiled systems all have one.
     */
    /**
     * @param  array<string, mixed>  $overrides  A/B bench values that win over
     *                                           stored settings for one boot.
     */
    public static function configFor(string $id, array $overrides = []): SystemConfig|Config|array
    {
        $g = self::global();
        $s = array_merge(self::system($id), $overrides);
        $onAres = self::effectiveBackend($id, $overrides) === 'ares';

        $shared = [
            // Engine-side picture knobs exist only on ares; non-neutral values
            // make a SameBoy/mGBA/libretro boot throw UNSUPPORTED_OPTION, so
            // they neutralize here rather than brick the boot.
            'luminance' => $onAres ? (int) $g['luminance'] : 100,
            'saturation' => $onAres ? (int) $g['saturation'] : 100,
            'gamma' => $onAres ? (int) $g['gamma'] : 100,
            'overscan' => $onAres && (bool) $g['overscan'],
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
                deepBlackBoost: (bool) ($s['deepBlackBoost'] ?? false),
            ),
            'gb', 'gbc' => new GbConfig(
                ...$shared,
                colorEmulation: (bool) ($s['colorEmulation'] ?? false),
                interframeBlending: (bool) ($s['interframeBlending'] ?? false),
                rawAudio: (bool) ($s['rawAudio'] ?? $s['naturalAudio'] ?? false),
            ),
            'fc' => new FcConfig(...$shared, region: $region),
            'md' => new MdConfig(...$shared, region: $region),
            'gba' => new GbaConfig(...$shared, accuracy: $accuracy),
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
