<?php

namespace App\Native;

use App\Support\BundledRoms;
use App\Support\Catalog;
use App\Support\SettingsStore;
use Illuminate\View\View;
use KevinBatdorf\Fullscreen\Facades\Fullscreen;
use KevinBatdorf\RetroEmulator\Config\Config;
use KevinBatdorf\RetroEmulator\Config\SystemConfig;
use KevinBatdorf\RetroEmulator\Device;
use KevinBatdorf\RetroEmulator\Emulator as EmulatorHandle;
use KevinBatdorf\RetroEmulator\EmulatorException;
use KevinBatdorf\RetroEmulator\Events\EmulatorStarted;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Facades\Dialog;

/**
 * Screen 3 — Play. Full-height emulator surface with a translucent control
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
 * would tear the surface down and kill the running game. Picture/audio apply
 * live; shader + per-system toggles need a fresh boot, offered as "Apply".
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

    public bool $fastForward = false;

    public bool $rewinding = false;

    /** The one overlay: transport + settings. Game pauses while it's open. */
    public bool $menuOpen = false;

    /** True once loadRom has succeeded — stops the pump() boot-retry loop. */
    public bool $booted = false;

    /** Boot-retry attempts (surface may not be attached on the first ticks). */
    public int $attempts = 0;

    public string $error = '';

    // ── Settings (mirrored into the overlay; persisted via SettingsStore) ──

    public int $luminance = 100;

    public int $saturation = 100;

    public int $gamma = 100;

    public bool $overscan = false;

    public int $volume = 100;

    public bool $crt = false;

    /** deepBlackBoost / N64 quality toggles / … → current bool value. */
    public array $toggles = [];

    /** Set when a boot-time setting (shader/toggle) changed since the last boot. */
    public bool $rebootNeeded = false;

    public function navTitle(): string
    {
        return $this->romName !== '' ? $this->romName : 'Play';
    }

    public function mount(): void
    {
        // Immersive here too — booting straight to /play (start-url) skips
        // HomeScreen::mount(), so without this the status bar/notch clips the top bar.
        Fullscreen::enter();

        $this->id = (string) $this->param('id');
        $this->rom = (string) $this->data('rom');

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
        $this->luminance = (int) $g['luminance'];
        $this->saturation = (int) $g['saturation'];
        $this->gamma = (int) $g['gamma'];
        $this->overscan = (bool) $g['overscan'];
        $this->volume = (int) $g['volume'];
        $this->crt = (bool) $g['crt'];

        $s = SettingsStore::system($this->id);
        $this->toggles = [];
        foreach (Catalog::toggles($this->id) as $field => $label) {
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
        return SettingsStore::configFor($this->id);
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
                ->loadRom($this->rom)
                ->configure(['rewind' => true, 'rewindBufferSeconds' => 10]);

            $this->booted = true;
            $this->error = '';
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

        $this->guard(function () use ($button) {
            $pad = $this->emu()->getDevice(1)->setButtons([$button => true]);

            // N64 moves on the analog stick, not the d-pad — overlay d-pad
            // presses also hold the stick at full deflection (see Catalog).
            if ($stick = Catalog::stickSurrogate($this->id)[$button] ?? null) {
                $pad->holdAxis($stick[0], $stick[1]);
            }
        });
    }

    /** Finger up / cancel on a game button (via @pressUp). */
    public function release(string $button): void
    {
        unset($this->held[$button]);

        $this->guard(function () use ($button) {
            $pad = $this->emu()->getDevice(1)->setButtons([$button => false]);

            if ($stick = Catalog::stickSurrogate($this->id)[$button] ?? null) {
                $pad->holdAxis($stick[0], 0);
            }
        });
    }

    /** Clear the one-tick transport action flash. */
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

    public function saveState(): void
    {
        $this->flash = 'Save';
        $this->guard(fn () => $this->emu()->saveState(1), 'Saved to slot 1');
    }

    public function loadState(): void
    {
        $this->flash = 'Load';
        $this->guard(fn () => $this->emu()->loadState(1), 'Loaded slot 1');
    }

    public function undo(): void
    {
        $this->flash = 'Undo';
        $this->guard(fn () => $this->emu()->undoLoadState(), 'Undid last load');
    }

    public function rewind(): void
    {
        $this->rewinding = ! $this->rewinding;
        $this->guard(fn () => $this->emu()->toggleRewind(), $this->rewinding ? 'Rewinding' : 'Rewind off');
    }

    public function toggleFastForward(): void
    {
        $this->fastForward = ! $this->fastForward;
        $this->guard(fn () => $this->emu()->fastForward($this->fastForward));
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

    public function setLuminance(float $v): void
    {
        $this->luminance = (int) round($v);
        SettingsStore::setGlobal('luminance', $this->luminance);
        $this->guard(fn () => $this->emu()->setVideo(luminance: $this->luminance));
    }

    public function setSaturation(float $v): void
    {
        $this->saturation = (int) round($v);
        SettingsStore::setGlobal('saturation', $this->saturation);
        $this->guard(fn () => $this->emu()->setVideo(saturation: $this->saturation));
    }

    public function setGamma(float $v): void
    {
        $this->gamma = (int) round($v);
        SettingsStore::setGlobal('gamma', $this->gamma);
        $this->guard(fn () => $this->emu()->setVideo(gamma: (float) $this->gamma));
    }

    public function setOverscan(bool $on): void
    {
        $this->overscan = $on;
        SettingsStore::setGlobal('overscan', $on);
        $this->guard(fn () => $this->emu()->setVideo(overscan: $on));
    }

    public function setVolume(float $v): void
    {
        $this->volume = (int) round($v);
        SettingsStore::setGlobal('volume', $this->volume);
        $this->guard(fn () => $this->emu()->setVolume($this->volume));
    }

    public function setCrt(bool $on): void
    {
        $this->crt = $on;
        SettingsStore::setGlobal('crt', $on);
        $this->rebootNeeded = true;
    }

    public function setToggle(string $field, bool $on): void
    {
        $this->toggles[$field] = $on;
        SettingsStore::setSystem($this->id, $field, $on);
        $this->rebootNeeded = true;
    }

    public function resetSettings(): void
    {
        SettingsStore::resetGlobal();
        SettingsStore::resetSystem($this->id);
        $this->hydrateSettings();
        $this->rebootNeeded = true;
    }

    /** Re-boot the running game in place to pick up shader / per-system changes. */
    public function applyReboot(): void
    {
        $this->rebootNeeded = false;
        $this->menuOpen = false;
        $this->booted = false;
        $this->attempts = 0;
        $this->status = 'loading';
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
    private function guard(callable $fn, ?string $ok = null): void
    {
        try {
            $fn();
            if ($ok !== null) {
                Dialog::toast($ok);
            }
        } catch (EmulatorException $e) {
            Dialog::toast($e->getMessage());
        }
    }

    public function render(): View
    {
        $config = SettingsStore::configFor($this->id);
        $configArray = is_object($config) && method_exists($config, 'toArray')
            ? $config->toArray()
            : (array) $config;

        return view('play', [
            'groups' => Catalog::groupButtons($this->buttons),
            'toggleLabels' => Catalog::toggles($this->id),
            'controllers' => Emulator::inputDevices(),
            'configJson' => json_encode($configArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);
    }
}
