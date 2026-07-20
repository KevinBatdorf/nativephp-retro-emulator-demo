<?php

namespace App\Support;

use KevinBatdorf\RetroEmulator\Config\Config;
use KevinBatdorf\RetroEmulator\Config\FcConfig;
use KevinBatdorf\RetroEmulator\Config\GbConfig;
use KevinBatdorf\RetroEmulator\Config\GbaConfig;
use KevinBatdorf\RetroEmulator\Config\MdConfig;
use KevinBatdorf\RetroEmulator\Config\N64Config;
use KevinBatdorf\RetroEmulator\Config\SfcConfig;
use KevinBatdorf\RetroEmulator\Config\SystemConfig;
use KevinBatdorf\RetroEmulator\InputCapture;
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

    public const GLOBAL_DEFAULTS = [
        'luminance' => 100,
        'saturation' => 100,
        'gamma' => 100,      // percent; sent to setVideo as a float
        'overscan' => false,
        'volume' => 100,
        'balance' => 0,
        'speed' => 1.0,
        'crt' => false,      // "Apply CRT filter" — one toggle, not a shader picker
        'rumble' => false,
    ];

    public const SYSTEM_DEFAULTS = [
        'region' => '',      // '' = auto (resolved from the ROM)
        'device' => 'Gamepad',
        'crt' => 'inherit',  // inherit | on | off
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

    // ── Config assembly ─────────────────────────────

    /**
     * Build the plugin SystemConfig for a boot, merging global AV/presentation
     * settings with the per-system scope. The concrete subclass is chosen per
     * system so its extra toggles (SFC deepBlackBoost, GB colour emulation) are
     * carried through; the four compiled systems all have one.
     */
    public static function configFor(string $id): SystemConfig|Config|array
    {
        $g = self::global();
        $s = self::system($id);

        $shared = [
            // Window-level pad capture: the Thor's built-in controls (and any
            // paired BT pad) drive the game regardless of view focus.
            'inputCapture' => InputCapture::Global,
            'luminance' => (int) $g['luminance'],
            'saturation' => (int) $g['saturation'],
            'gamma' => (float) $g['gamma'],
            'overscan' => (bool) $g['overscan'],
            'volume' => (int) $g['volume'],
            'balance' => (int) $g['balance'],
            'speed' => (float) $g['speed'],
            'rumble' => (bool) $g['rumble'],
            'shader' => self::resolveShader($g, $s),
        ];

        $region = self::resolveRegion($id, $s);

        return match ($id) {
            'sfc' => new SfcConfig(
                ...$shared,
                region: $region,
                deepBlackBoost: (bool) ($s['deepBlackBoost'] ?? false),
            ),
            'gb', 'gbc' => new GbConfig(
                ...$shared,
                colorEmulation: (bool) ($s['colorEmulation'] ?? false),
                interframeBlending: (bool) ($s['interframeBlending'] ?? false),
            ),
            'fc' => new FcConfig(...$shared, region: $region),
            'md' => new MdConfig(...$shared, region: $region),
            'gba' => new GbaConfig(...$shared),
            // Toggles pass null when untouched so the core's desktop-parity
            // defaults stay authoritative (weave + Expansion Pak default ON).
            'n64' => new N64Config(
                ...$shared,
                region: $region,
                supersampling: isset($s['supersampling']) ? (bool) $s['supersampling'] : null,
                disableVideoInterfaceProcessing: isset($s['disableVideoInterfaceProcessing'])
                    ? (bool) $s['disableVideoInterfaceProcessing'] : null,
                weaveDeinterlacing: isset($s['weaveDeinterlacing']) ? (bool) $s['weaveDeinterlacing'] : null,
                homebrewMode: isset($s['homebrewMode']) ? (bool) $s['homebrewMode'] : null,
                expansionPak: isset($s['expansionPak']) ? (bool) $s['expansionPak'] : null,
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
        foreach ([
            storage_path('app/shaders'),
            base_path('resources/shaders'),
            '/data/local/tmp/shaders',
        ] as $dir) {
            $found = Shaders::in($dir);

            if ($found === []) {
                continue;
            }

            foreach ($found as $preset) {
                if (str_starts_with(basename($preset), 'crt')) {
                    return $preset;
                }
            }

            return $found[0];
        }

        return null;
    }
}
