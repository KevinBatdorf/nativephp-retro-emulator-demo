<?php

namespace App\Native;

use App\Support\BundledRoms;
use App\Support\Catalog;
use App\Support\SettingsStore;
use App\Support\Zip;
use Illuminate\View\View;
use KevinBatdorf\Fullscreen\Facades\Fullscreen;
use KevinBatdorf\RetroEmulator\Config\Config;
use KevinBatdorf\RetroEmulator\Config\SystemConfig;
use KevinBatdorf\RetroEmulator\Device;
use KevinBatdorf\RetroEmulator\Emulator as EmulatorHandle;
use KevinBatdorf\RetroEmulator\EmulatorException;
use KevinBatdorf\RetroEmulator\Events\EmulatorError;
use KevinBatdorf\RetroEmulator\Events\EmulatorStarted;
use KevinBatdorf\RetroEmulator\Events\RomPicked;
use KevinBatdorf\RetroEmulator\Events\WindowMetricsChanged;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Platform;

/**
 * Play. Full-height emulator surface with a translucent control
 * overlay on top (stack). The ROM boots imperatively, RETRIED on each #[Poll]
 * tick until the native surface is actually attached — booting on a single
 * fixed tick races the surface lifecycle (worse under immersive/fullscreen) and
 * bricks with "no surface registered".
 *
 * Input model: game buttons use @pressDown/@pressUp on stock pressables, which
 * captures real touch down/up and calls press()/release() — so a button is held
 * only while the finger is down, like a real pad, and lights up on screen while
 * held. The system's controller auto-connects at boot (the plugin defaults
 * port 1 to the core's own pad), so the demo never re-connects — it just reads
 * the port's button names once the core is running.
 *
 * Settings + transport live in one in-place overlay (the ☰ menu). Opening it
 * pauses the game but KEEPS the surface alive — navigating to a separate screen
 * would tear the surface down and kill the running game. Settings apply live
 * wherever the plugin has a runtime setter; true boot-only options (engine,
 * capability boot options) persist and show up in the reboot diff. The menu
 * renders from the loaded engine's capability data — nothing is hardcoded
 * per system.
 */
class PlayScreen extends NativeComponent
{
    public string $id = '';

    public string $rom = '';

    public string $romName = '';

    public string $region = '';

    public string $status = 'loading';

    /** Button names for port 1, as reported by Emulator::ports(). */
    public array $buttons = [];

    /** Game buttons currently held (touch) → true, for the on-screen highlight. */
    public array $held = [];

    /** Transport action most recently fired, for a one-tick press flash. */
    public string $flash = '';

    /**
     * Whether rewind capture is armed. Applies live; history is empty at the
     * moment it's enabled and fills from there.
     */
    public bool $rewindEnabled = false;

    /** Boot-only renderer preset — SNES/GBA only; a change needs a reboot. */
    public bool $accurate = false;

    /** Engine serving this system: '' = default, else a name or BYO core. */
    public string $backend = '';

    /** The one overlay: transport + settings. Game pauses while it's open. */
    public bool $menuOpen = false;

    /** True once loadRom has succeeded — stops the pump() boot-retry loop. */
    public bool $booted = false;

    /** Boot-retry attempts (surface may not be attached on the first ticks). */
    public int $attempts = 0;

    public string $error = '';

    // ── Settings (mirrored into the overlay; persisted via SettingsStore) ──

    public int $dpadThreshold = 33;

    public int $dpadDiagonalRatio = 0;

    public int $luminance = 100;

    public int $saturation = 100;

    public bool $overscan = false;

    public int $volume = 100;

    public int $balance = 0;

    public bool $crt = false;

    /** Per-system toggles (deepBlackBoost, colour emulation, …) → bool. */
    public array $toggles = [];

    /** Wire config the running core actually booted with; empty until then. */
    public array $bootedConfig = [];

    /** Engine resolved for the running boot ('' until first successful boot). */
    public string $bootedBackend = '';

    /** Engines serving this system, from systems(); Catalog fallback off-device. */
    public array $backendOptions = [];

    /** Per-engine capability objects for this system, cached on menu open. */
    public array $systemCaps = [];

    /** Runtime introspection for the dev view, cached when the menu opens. */
    public array $devState = [];

    /** Newest-first save states for this system+ROM: [['slot','at'], …] max 3. */
    public array $saves = [];

    /** Boot-only option values keyed by capability field (pixelAccuracy, rawAudio). */
    public array $bootOpts = [];

    /** Live speed multiplier (0.25-4.0). */
    public float $speed = 1.0;

    /** Audio bench: engine to force, '' for the app's stored setting. */
    public string $benchEngine = '';

    /** Audio bench: each engine's raw sound instead of the plugin default. */
    public bool $benchRaw = false;

    /** Horizontal safe-area insets in dp — nonzero only beside the island in landscape. */
    public int $safeLeft = 0;

    public int $safeRight = 0;

    public function navTitle(): string
    {
        return $this->romName !== '' ? $this->romName : 'Play';
    }

    public function mount(): void
    {
        // Immersive here too — booting straight to /play (start-url) skips
        // HomeScreen::mount(), so without this the status bar/notch clips the top bar.
        Fullscreen::enter();

        $metrics = Emulator::windowMetrics();
        $this->safeLeft = $metrics['left'];
        $this->safeRight = $metrics['right'];

        $this->id = (string) $this->param('id');
        $this->rom = (string) $this->data('rom');
        $this->benchEngine = (string) ($this->data('engine') ?? '');
        $this->benchRaw = (bool) ($this->data('raw') ?? false);

        // Home taps navigate here with no ROM in nav data — every system boots
        // its bundled homebrew game.
        if ($this->rom === '') {
            $this->rom = (string) (BundledRoms::forSystem($this->id) ?? '');
        }

        $this->romName = $this->rom !== '' ? basename($this->rom) : '';

        if ($this->rom === '') {
            $this->error = 'No ROM supplied.';
        }

        $this->hydrateSettings();
    }

    private function hydrateSettings(): void
    {
        $g = SettingsStore::global();
        $this->dpadThreshold = (int) $g['dpadThreshold'];
        $this->dpadDiagonalRatio = (int) $g['dpadDiagonalRatio'];
        $this->luminance = (int) $g['luminance'];
        $this->saturation = (int) $g['saturation'];
        $this->overscan = (bool) $g['overscan'];
        $this->volume = (int) $g['volume'];
        $this->balance = (int) $g['balance'];
        $this->crt = (bool) $g['crt'];
        $this->rewindEnabled = (bool) $g['rewind'];
        $this->speed = (float) $g['speed'];
        $this->accurate = SettingsStore::accurateFor($this->id);

        $s = SettingsStore::system($this->id);
        $this->backend = (string) ($s['backend'] ?? '');
        $this->bootOpts = [
            'pixelAccuracy' => SettingsStore::accurateFor($this->id),
            'rawAudio' => (bool) ($s['rawAudio'] ?? false),
        ];
        $this->saves = SettingsStore::savesFor($this->id, $this->romName);
        $this->toggles = [];
        foreach (array_keys(Catalog::toggles($this->id)) as $field) {
            $this->toggles[$field] = isset($s[$field])
                ? (bool) $s[$field]
                : Catalog::toggleDefault($this->id, $field);
        }
    }

    private function emu(): EmulatorHandle
    {
        return Emulator::surface('play');
    }

    /** The typed config the ROM boots with (global ⊕ per-system). */
    private function config(): SystemConfig|Config|array
    {
        return SettingsStore::configFor($this->id, $this->benchOverrides());
    }

    /**
     * The audio bench passes a profile so one tap boots a known combination;
     * an empty profile leaves the app's stored settings alone.
     */
    private function benchOverrides(): array
    {
        return $this->benchEngine === '' ? [] : [
            'backend' => $this->benchEngine,
            'rawAudio' => $this->benchRaw,
        ];
    }

    /** The boot config as the wire array (enums flattened, nulls dropped). */
    private function configArray(): array
    {
        $config = $this->config();
        $array = is_object($config) && method_exists($config, 'toArray')
            ? $config->toArray()
            : (array) $config;

        return json_decode(json_encode($array) ?: '[]', true) ?: [];
    }

    /**
     * Stored settings that differ from what the running core booted with —
     * these are the changes only a reboot can pick up, since live setters
     * sync the snapshot as they apply. Key → ['from' => …, 'to' => …].
     */
    public function pendingReboot(): array
    {
        if ($this->bootedConfig === []) {
            return [];
        }

        $next = $this->configArray();
        $pending = [];

        foreach (array_unique([...array_keys($this->bootedConfig), ...array_keys($next)]) as $key) {
            $from = $this->bootedConfig[$key] ?? null;
            $to = $next[$key] ?? null;
            if ($from !== $to) {
                $pending[$key] = ['from' => $from, 'to' => $to];
            }
        }

        return $pending;
    }

    /** After a live setter lands, the running core matches the store again. */
    private function syncBooted(string ...$keys): void
    {
        if ($this->bootedConfig === []) {
            return;
        }

        $next = $this->configArray();
        foreach ($keys as $key) {
            if (array_key_exists($key, $next)) {
                $this->bootedConfig[$key] = $next[$key];
            } else {
                unset($this->bootedConfig[$key]);
            }
        }
    }

    /**
     * Stage system + load ROM, retried each tick until the native surface is
     * attached (a fresh surface isn't ready on the very first tick, especially
     * under immersive mode). Device + buttons come later, on EmulatorStarted.
     */
    #[Poll(400)]
    public function pump(): void
    {
        if ($this->booted || $this->rom === '') {
            return;
        }

        $this->attempts++;

        try {
            $this->emu()
                ->loadSystem($this->id, $this->config())
                ->loadRom($this->rom);

            $this->booted = true;
            $this->error = '';
            $this->bootedConfig = $this->configArray();
            // Read back from the core — a config preferring an absent
            // engine lands on ares, and every gate must follow the landing.
            $this->bootedBackend = $this->emu()->backend()
                ?: SettingsStore::effectiveBackend($this->id, $this->benchOverrides());
        } catch (EmulatorException $e) {
            // Surface not attached yet — keep retrying for a few seconds before
            // surfacing the failure.
            if ($this->attempts >= 12) {
                $this->error = $e->getMessage();
            }
        }
    }

    /**
     * Core is running now — read port 1's buttons. The plugin auto-connects
     * each system's own default pad at boot and ports() reports it, so no
     * explicit connectDevice is needed. Falls back to the static per-system
     * set if the port read is empty or hiccups, so controls always render.
     */
    #[On(WindowMetricsChanged::class)]
    public function onWindowMetricsChanged(
        int $width = 0,
        int $height = 0,
        int $top = 0,
        int $bottom = 0,
        int $left = 0,
        int $right = 0,
    ): void {
        $this->safeLeft = $left;
        $this->safeRight = $right;
    }

    #[On(EmulatorStarted::class)]
    public function onStarted(string $surface = '', string $system = '', string $romPath = ''): void
    {
        if ($surface !== 'play') {
            return;
        }

        $this->status = 'running';

        try {
            $emu = $this->emu();
            $buttons = $emu->ports()[0]['buttons'] ?? [];
            $this->buttons = $buttons !== [] ? $buttons : Catalog::buttons($this->id);
            $this->region = $emu->region();
        } catch (\Throwable) {
            $this->buttons = Catalog::buttons($this->id);
        }
    }

    // ── Input (Controller handle) ───────────────────

    /** Finger down on a game button (via @pressDown). */
    public function press(string $button): void
    {
        $this->held[$button] = true;

        $this->guard(fn () => $this->emu()->getDevice(1)->setButtons([$button => true]));
    }

    /** Finger up / cancel on a game button (via @pressUp). */
    public function release(string $button): void
    {
        unset($this->held[$button]);

        $this->guard(fn () => $this->emu()->getDevice(1)->setButtons([$button => false]));
    }

    /** Clear the one-tick transport flash; land the timed transport bursts. */
    #[Poll(200)]
    public function inputTick(): void
    {
        if ($this->flash !== '') {
            $this->flash = '';
        }

    }

    // ── Overlay (transport + settings, game paused) ─────────

    /** Toggle the overlay; pause while open, resume when closed. */
    public function toggleMenu(): void
    {
        $this->menuOpen = ! $this->menuOpen;

        if ($this->menuOpen) {
            $this->refreshEngineData();
        }

        if ($this->status === 'loading') {
            return;
        }

        if ($this->menuOpen) {
            $this->guard(fn () => $this->emu()->pause());
            $this->status = 'paused';
        } else {
            $this->guard(fn () => $this->emu()->resume());
            $this->status = 'running';
        }
    }

    /**
     * Cache this system's engine list + capability objects while the menu is
     * open — systems() is a bridge call, too heavy for every Poll render.
     */
    private function refreshEngineData(): void
    {
        $this->refreshDevState();

        foreach (Emulator::systems() as $entry) {
            if (($entry['id'] ?? '') !== $this->id) {
                continue;
            }

            $this->backendOptions = SettingsStore::availableBackends($this->id);
            $this->systemCaps = $entry['capabilities'] ?? [];

            // Capabilities can surface toggles the mount-time hydrate didn't
            // know (GBA's ares-only pair); pull their stored values in.
            $s = SettingsStore::system($this->id);
            foreach (array_keys($this->toggleRows()) as $field) {
                $this->toggles[$field] ??= (bool) ($s[$field] ?? false);
            }

            return;
        }

        $this->backendOptions = Catalog::backends($this->id);
        $this->systemCaps = [];
    }

    /**
     * What the core is actually doing, as opposed to what the config asked
     * for — accuracy() reads back the renderer the boot really bound, and
     * region() the region the ROM resolved to.
     */
    private function refreshDevState(): void
    {
        try {
            $emu = $this->emu();
            $devices = array_values(array_filter(array_column($emu->ports(), 'device')));

            $this->devState = [
                'status' => $emu->status()->value,
                'engine' => $this->bootedBackend !== '' ? $this->bootedBackend : '(not booted)',
                'accuracy bound' => $emu->accuracy()?->value ?? '(single renderer)',
                'region resolved' => $emu->region() ?: '(none)',
                'devices' => $devices === [] ? '(none)' : implode(', ', $devices),
            ];
        } catch (\Throwable) {
            $this->devState = [];
        }
    }

    /**
     * Config rows for the dev view, grouped and diff-annotated: pending keys
     * render "from → to" so the dump never claims a value the running core
     * doesn't have.
     *
     * @return array<string, list<array{key: string, display: string, pending: bool}>>
     */
    private function devRows(array $pending): array
    {
        $next = $this->configArray();
        $rows = ['system' => [], 'video' => [], 'audio' => [], 'runtime' => []];

        foreach (array_unique([...array_keys($this->bootedConfig), ...array_keys($next)]) as $key) {
            $group = match (true) {
                in_array($key, ['backend', 'region', 'pixelAccuracy', 'rawAudio', 'bootAnimation',
                    'biosPath', 'colorEmulation', 'interframeBlending', 'deepBlackBoost'], true) => 'system',
                in_array($key, ['luminance', 'saturation', 'overscan', 'colorBleed',
                    'shader', 'output', 'fixedScale', 'aspectCorrection'], true) => 'video',
                in_array($key, ['volume', 'balance'], true) => 'audio',
                default => 'runtime',
            };

            $rows[$group][] = [
                'key' => $key,
                'display' => isset($pending[$key])
                    ? $this->devValue($pending[$key]['from']).' → '.$this->devValue($pending[$key]['to'])
                    : $this->devValue($this->bootedConfig[$key] ?? $next[$key] ?? null),
                'pending' => isset($pending[$key]),
            ];
        }

        return array_filter($rows);
    }

    private function devValue(mixed $v): string
    {
        return match (true) {
            $v === null => 'default',
            is_bool($v) => $v ? 'true' : 'false',
            is_string($v) && str_contains($v, '/') => basename($v),
            is_scalar($v) => (string) $v,
            default => json_encode($v) ?: '?',
        };
    }

    /** The engine whose declared capabilities the menu renders: booted truth, else the boot-pending pick. */
    private function currentEngine(): string
    {
        if ($this->bootedBackend !== '') {
            return $this->bootedBackend;
        }

        return $this->backend !== '' ? $this->backend : $this->defaultEngine();
    }

    private function currentCaps(): array
    {
        return $this->systemCaps[$this->currentEngine()] ?? [];
    }

    /**
     * Runtime toggle rows, built from capabilities: the current engine's
     * toggles are live; a toggle only another engine declares renders
     * disabled, named after its engines. Catalog is the off-device fallback.
     *
     * @return array<string, array{label: string, note: string, enabled: bool}>
     */
    private function toggleRows(): array
    {
        if ($this->systemCaps === []) {
            return array_map(
                fn ($meta) => $meta + ['enabled' => true],
                Catalog::toggles($this->id),
            );
        }

        $rows = [];
        foreach ($this->systemCaps as $engine => $caps) {
            foreach ($caps['toggles'] ?? [] as $field) {
                $rows[$field]['label'] = Catalog::TOGGLE_LABELS[$field] ?? $field;
                $rows[$field]['engines'][] = $engine;
            }
        }

        $current = $this->currentEngine();
        foreach ($rows as $field => &$row) {
            $row['enabled'] = in_array($current, $row['engines'], true);
            $row['note'] = $row['enabled'] && count($row['engines']) === count($this->systemCaps)
                ? ''
                : implode(' / ', $row['engines']).' engine only';
            unset($row['engines']);
        }

        return $rows;
    }

    /**
     * Boot-only option rows from capabilities (pixelAccuracy, rawAudio) —
     * rendered only when some engine of this system declares them, disabled
     * when the current engine doesn't. Changes land via the reboot diff.
     *
     * @return array<string, array{label: string, help: string, note: string, enabled: bool, value: bool}>
     */
    private function bootOptionRows(): array
    {
        $labels = [
            'pixelAccuracy' => ['Accurate rendering', 'Dot/cycle renderer — costs CPU'],
            'rawAudio' => ['Raw engine audio', "The engine's own untouched output"],
        ];

        if ($this->systemCaps === []) {
            // Off-device fallback: SNES/GBA are the dual-renderer systems.
            return in_array($this->id, ['sfc', 'gba'], true)
                ? ['pixelAccuracy' => [
                    'label' => $labels['pixelAccuracy'][0],
                    'help' => $labels['pixelAccuracy'][1],
                    'note' => 'ares engine only',
                    'enabled' => true,
                    'value' => (bool) ($this->bootOpts['pixelAccuracy'] ?? false),
                ]]
                : [];
        }

        $rows = [];
        foreach ($this->systemCaps as $engine => $caps) {
            foreach ($caps['bootOptions'] ?? [] as $field) {
                if (! isset($labels[$field])) {
                    continue;
                }
                $rows[$field]['label'] = $labels[$field][0];
                $rows[$field]['help'] = $labels[$field][1];
                $rows[$field]['engines'][] = $engine;
            }
        }

        $current = $this->currentEngine();
        foreach ($rows as $field => &$row) {
            $row['enabled'] = in_array($current, $row['engines'], true);
            $row['note'] = count($row['engines']) === count($this->systemCaps)
                ? ''
                : implode(' / ', $row['engines']).' engine only';
            $row['value'] = (bool) ($this->bootOpts[$field] ?? false);
            unset($row['engines']);
        }

        return $rows;
    }

    /** Engine chips: real engines pressable, platform-missing ones inert and noted. */
    private function engineChips(): array
    {
        $chips = array_map(
            fn ($engine) => ['name' => $engine, 'available' => true],
            $this->backendOptions,
        );

        foreach (SettingsStore::unavailableBackends($this->id) as $engine) {
            $chips[] = ['name' => $engine, 'available' => false];
        }

        return $chips;
    }

    public function togglePause(): void
    {
        if ($this->status === 'paused') {
            $this->guard(fn () => $this->emu()->resume());
            $this->status = 'running';
        } else {
            $this->guard(fn () => $this->emu()->pause());
            $this->status = 'paused';
        }
    }

    /** OS picker, unfiltered — a wrong file fails loudly at load instead. */
    public function pickRom(): void
    {
        $this->guard(fn () => $this->emu()->pickRom(
            storage_path('app/roms/'.$this->id),
        ));
    }

    #[On(RomPicked::class)]
    public function onRomPicked(string $surface = '', string $path = ''): void
    {
        if ($surface !== 'play' || $path === '') {
            return;
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip') {
            $path = $this->extractRomZip($path);
            if ($path === null) {
                return;
            }
        }

        $this->rom = $path;
        $this->romName = basename($path);
        $this->saves = SettingsStore::savesFor($this->id, $this->romName);
        $this->applyReboot();
    }

    /**
     * Cores read raw ROMs, so a picked .zip is extracted here: the largest
     * entry wins (ROM sets ship one game plus text files), the zip is
     * removed. Null (with a toast) when nothing could be extracted.
     */
    private function extractRomZip(string $zipPath): ?string
    {
        $dest = Zip::extractLargest($zipPath);
        if ($dest === null) {
            Dialog::toast('Could not extract a ROM from the zip');

            return null;
        }
        unlink($zipPath);

        return $dest;
    }

    /** Operational failures (bad pick, failed save) arrive as events, not throws. */
    #[On(EmulatorError::class)]
    public function onEmulatorError(string $surface = '', string $code = '', string $message = ''): void
    {
        if ($surface !== 'play' || $message === '') {
            return;
        }

        Dialog::toast($message);
    }

    /** One save slot per press, rolling across three named per-ROM slots. */
    public function saveStateNow(): void
    {
        $used = array_column($this->saves, 'slot');
        $slot = '';
        foreach (['a', 'b', 'c'] as $candidate) {
            if (! in_array($candidate, $used, true)) {
                $slot = $candidate;
                break;
            }
        }
        // All three in use: recycle the oldest (list is newest-first).
        $slot = $slot !== '' ? $slot : end($this->saves)['slot'];

        if (! $this->guard(fn () => $this->emu()->saveState("{$this->id}-{$slot}"), 'State saved')) {
            return;
        }

        $this->saves = SettingsStore::recordSave($this->id, $this->romName, $slot);
    }

    public function restoreState(string $slot): void
    {
        if ($this->guard(fn () => $this->emu()->loadState("{$this->id}-{$slot}"), 'State restored')
            && $this->menuOpen) {
            $this->toggleMenu();
        }
    }

    /** Instant: one state-load lands ~10 s back; no playback to wait out. */
    public function rewindBack(): void
    {
        if (! $this->rewindEnabled) {
            return;
        }

        if (! $this->guard(fn () => $this->emu()->rewind(10))) {
            return;
        }

        if ($this->menuOpen) {
            $this->toggleMenu();   // show the earlier frame immediately
        }
    }

    /** Live emulation speed (0.25-4.0); no reboot. */
    public function setSpeedChoice(string $value): void
    {
        $speed = (float) $value;
        if (! $this->guard(fn () => $this->emu()->setSpeed($speed))) {
            return;
        }

        $this->speed = $speed;
        SettingsStore::setGlobal('speed', $speed);
        $this->syncBooted('speed');
    }

    public function screenshot(): void
    {
        $this->flash = 'Shot';
        $path = null;
        $this->guard(function () use (&$path) {
            $path = $this->emu()->screenshot();
        });

        Dialog::toast($path ? 'Screenshot: '.basename($path) : 'Screenshot failed');
    }

    // ── Settings (live picture/audio apply immediately) ─────
    // Sliders bind via native:model.debounce — the updated* hooks fire once
    // per gesture with the property already set.

    // The pad reads these as props; nothing to push to the core.
    public function updatedDpadThreshold(mixed $v): void
    {
        $this->dpadThreshold = (int) round((float) $v);
        SettingsStore::setGlobal('dpadThreshold', $this->dpadThreshold);
    }

    public function updatedDpadDiagonalRatio(mixed $v): void
    {
        $this->dpadDiagonalRatio = (int) round((float) $v);
        SettingsStore::setGlobal('dpadDiagonalRatio', $this->dpadDiagonalRatio);
    }

    public function updatedLuminance(mixed $v): void
    {
        $this->applyVideo('luminance', (int) round((float) $v));
    }

    public function updatedSaturation(mixed $v): void
    {
        $this->applyVideo('saturation', (int) round((float) $v));
    }

    public function setOverscan(bool $on): void
    {
        $this->applyVideo('overscan', $on);
    }

    /**
     * Live picture setter: apply to the running core first, persist + sync
     * the boot snapshot only on success — a failed call must not poison the
     * next boot's config or desync the sliders from reality.
     */
    private function applyVideo(string $key, int|bool $value): void
    {
        $previous = $this->{$key};

        if (! $this->guard(fn () => $this->emu()->setVideo(...[$key => $value]))) {
            // The model sync may have written the new value already.
            $this->{$key} = $previous === $value
                ? SettingsStore::global()[$key]
                : $previous;

            return;
        }

        $this->{$key} = $value;
        SettingsStore::setGlobal($key, $value);
        $this->syncBooted($key);
    }

    public function updatedVolume(mixed $v): void
    {
        $volume = (int) round((float) $v);

        if (! $this->guard(fn () => $this->emu()->setVolume($volume))) {
            $this->volume = (int) SettingsStore::global()['volume'];

            return;
        }

        $this->volume = $volume;
        SettingsStore::setGlobal('volume', $volume);
        $this->syncBooted('volume');
    }

    public function updatedBalance(mixed $v): void
    {
        $balance = (int) round((float) $v);

        if (! $this->guard(fn () => $this->emu()->setBalance($balance))) {
            $this->balance = (int) SettingsStore::global()['balance'];

            return;
        }

        $this->balance = $balance;
        SettingsStore::setGlobal('balance', $balance);
        $this->syncBooted('balance');
    }

    public function setCrt(bool $on): void
    {
        $preset = $on ? SettingsStore::crtPreset() : null;

        if ($on && $preset === null) {
            Dialog::toast('No CRT preset bundled with this build');

            return;
        }

        if (! $this->guard(fn () => $this->emu()->setShader($preset))) {
            return;
        }

        $this->crt = $on;
        SettingsStore::setGlobal('crt', $on);
        $this->syncBooted('shader');
    }

    public function setRewind(bool $on): void
    {
        $ok = $this->guard(fn () => $this->emu()->configure([
            'rewind' => $on,
            'rewindBufferSeconds' => SettingsStore::REWIND_BUFFER_SECONDS,
        ]));

        if (! $ok) {
            return;
        }

        $this->rewindEnabled = $on;
        SettingsStore::setGlobal('rewind', $on);
        $this->syncBooted('rewind', 'rewindBufferSeconds');
    }

    /** Boot-only capability options; a change lands via the reboot diff. */
    public function setBootOption(string $field, bool $on): void
    {
        $row = $this->bootOptionRows()[$field] ?? null;
        if ($row === null) {
            return;
        }
        if ($on && ! $row['enabled']) {
            Dialog::toast("{$row['label']} needs the {$row['note']} — running {$this->currentEngine()}");

            return;
        }

        $this->bootOpts[$field] = $on;
        if ($field === 'pixelAccuracy') {
            $this->accurate = $on;
            SettingsStore::setSystem($this->id, 'accurate', $on);

            return;
        }
        SettingsStore::setSystem($this->id, $field, $on);
    }

    public function setAccurate(bool $on): void
    {
        $this->setBootOption('pixelAccuracy', $on);
    }

    public function selectBackend(string $name): void
    {
        // Picking the engine the app would resolve anyway stores '' so the
        // wire config (and the reboot diff) stays identical to a default boot.
        $this->backend = $name === $this->defaultEngine() ? '' : $name;
        SettingsStore::setSystem($this->id, 'backend', $this->backend);
    }

    /** What a boot with no explicit engine choice resolves to. */
    private function defaultEngine(): string
    {
        return SettingsStore::defaultEngine($this->id);
    }

    public function setToggle(string $field, bool $on): void
    {
        $row = $this->toggleRows()[$field] ?? ['enabled' => true];
        if ($on && ! ($row['enabled'] ?? true)) {
            Dialog::toast(
                ($row['label'] ?? $field)." needs the {$row['note']}".
                " — running {$this->currentEngine()}",
            );

            return;
        }

        // Enabling a toggle the engine lacks throws; the guard toast carries
        // the engine's own message and nothing persists, so the next boot
        // stays clean.
        if (! $this->guard(fn () => $this->emu()->setSystemOptions([$field => $on]))) {
            return;
        }

        $this->toggles[$field] = $on;
        SettingsStore::setSystem($this->id, $field, $on);
        $this->syncBooted($field);
    }

    public function resetSettings(): void
    {
        SettingsStore::resetGlobal();
        SettingsStore::resetSystem($this->id);
        $this->hydrateSettings();

        // Push the fresh defaults to the running core so picture and audio
        // match the sliders immediately; boot-only leftovers (engine, region,
        // accuracy) surface in the reboot diff instead.
        if ($this->guard(fn () => $this->emu()->setVideo(luminance: 100, saturation: 100, overscan: false))) {
            $this->syncBooted('luminance', 'saturation', 'overscan');
        }
        if ($this->guard(fn () => $this->emu()->setVolume(100))) {
            $this->syncBooted('volume');
        }
        if ($this->guard(fn () => $this->emu()->setShader(null))) {
            $this->syncBooted('shader');
        }
        if ($this->guard(fn () => $this->emu()->configure(['rewind' => false]))) {
            $this->syncBooted('rewind', 'rewindBufferSeconds');
        }

        $toggleFields = array_keys(Catalog::toggles($this->id));
        if ($toggleFields !== []
            && $this->guard(fn () => $this->emu()->setSystemOptions(array_fill_keys($toggleFields, false)))) {
            $this->syncBooted(...$toggleFields);
        }
    }

    /** Re-boot the running game in place to pick up boot-only changes. */
    public function applyReboot(): void
    {
        $this->menuOpen = false;
        $this->booted = false;
        $this->attempts = 0;
        $this->status = 'loading';
        $this->bootedConfig = [];
        $this->bootedBackend = '';
        $this->guard(fn () => $this->emu()->stop());
        // pump() will re-stage + re-load on the next tick.
    }

    /** Back to the console picker — stop the core, pop. */
    public function leave(): void
    {
        $this->guard(fn () => $this->emu()->stop());
        $this->back()->transition(Transition::None);
    }

    /** Run a bridge call, turning a thrown EmulatorException into a toast. */
    private function guard(callable $fn, ?string $ok = null): bool
    {
        try {
            $fn();
            if ($ok !== null) {
                Dialog::toast($ok);
            }

            return true;
        } catch (EmulatorException $e) {
            Dialog::toast($e->getMessage());

            return false;
        }
    }

    public function render(): View
    {
        $pending = $this->menuOpen ? $this->pendingReboot() : [];

        return view('play', [
            'groups' => Catalog::groupButtons($this->buttons),
            'toggleRows' => $this->toggleRows(),
            'bootOptionRows' => $this->bootOptionRows(),
            'engineChips' => $this->engineChips(),
            'gates' => $this->featureGates(),
            'controllers' => Emulator::inputDevices(),
            'pending' => $pending,
            'engineSelected' => $this->backend !== '' ? $this->backend : $this->defaultEngine(),
            'devRows' => $this->menuOpen ? $this->devRows($pending) : [],
        ]);
    }

    /**
     * Host-feature visibility from the current engine's capability object:
     * absent capability, absent section. Off-device keeps ares behavior.
     *
     * @return array{picture: bool, pictureNote: string, saves: bool, rumble: bool}
     */
    private function featureGates(): array
    {
        $caps = $this->currentCaps();

        if ($caps === []) {
            $isAres = $this->currentEngine() === 'ares';

            return ['picture' => $isAres, 'pictureNote' => '', 'saves' => true, 'rumble' => true];
        }

        $pictureEngines = [];
        foreach ($this->systemCaps as $engine => $engineCaps) {
            if ($engineCaps['videoSettings'] ?? false) {
                $pictureEngines[] = $engine;
            }
        }

        return [
            'picture' => (bool) ($caps['videoSettings'] ?? false),
            'pictureNote' => $pictureEngines === []
                ? 'No engine of this system has picture controls'
                : implode(' / ', $pictureEngines).' engine only — running '.$this->currentEngine(),
            'saves' => (bool) ($caps['serialize'] ?? false),
            'rumble' => (bool) ($caps['rumble'] ?? false),
        ];
    }
}
