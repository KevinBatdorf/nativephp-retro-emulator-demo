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
 * bricks with "no surface registered". Device connection + button discovery run
 * on the EmulatorStarted event, once the core is actually running.
 *
 * Input model: game buttons use @pressDown/@pressUp on stock pressables,
 * which captures real touch down/up and calls press()/release() — so a button
 * is held only while the finger is down, like a real pad. Transport controls
 * stay taps: toggles (pause / fast-forward / rewind) show a persistent active
 * state; one-shot actions flash for a tick on press.
 */
class PlayScreen extends NativeComponent
{
    public string $id = '';

    public string $rom = '';

    public string $romName = '';

    public string $region = '';

    public string $status = 'loading';

    public string $device = 'Gamepad';

    /** Button names for port 1, as reported by Emulator::ports(). */
    public array $buttons = [];

    /** Transport action most recently fired, for a one-tick press flash. */
    public string $flash = '';

    public bool $fastForward = false;

    public bool $rewinding = false;

    /** Transport drawer visibility — controls stay minimal until asked for. */
    public bool $menuOpen = false;

    /** True once loadRom has succeeded — stops the pump() boot-retry loop. */
    public bool $booted = false;

    /** Boot-retry attempts (surface may not be attached on the first ticks). */
    public int $attempts = 0;

    public string $error = '';

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
        $this->device = SettingsStore::system($this->id)['device'] ?? 'Gamepad';

        if ($this->rom === '') {
            $this->error = 'No ROM supplied.';
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
     * Core is running now — safe to connect the controller and read its buttons.
     * Falls back to the static per-system button set if the port read hiccups.
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
            $emu->connectDevice(1, Device::tryFrom($this->device) ?? Device::Gamepad);
            $this->buttons = $emu->ports()[0]['buttons'] ?? Catalog::buttons($this->id);
            $this->region = $emu->region();

            if (SettingsStore::system($this->id)['crt'] !== 'off'
                && (bool) SettingsStore::global()['crt']
                && SettingsStore::crtPreset() === null) {
                Dialog::toast('CRT filter requested but no .slangp preset is bundled.');
            }
        } catch (\Throwable) {
            $this->buttons = Catalog::buttons($this->id);
        }
    }

    // ── Input (Controller handle) ───────────────────

    /** Finger down on a game button (via @pressDown). */
    public function press(string $button): void
    {
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

    // ── Transport / showcase extras ─────────────────

    public function toggleMenu(): void
    {
        $this->menuOpen = ! $this->menuOpen;
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

    public function bumpSpeed(float $delta): void
    {
        $this->flash = $delta < 0 ? 'Spd−' : 'Spd+';
        $g = SettingsStore::global();
        $speed = max(0.25, min(4.0, round(((float) $g['speed']) + $delta, 2)));
        SettingsStore::setGlobal('speed', $speed);
        $this->guard(fn () => $this->emu()->setSpeed($speed), "Speed {$speed}×");
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

    /** Back to the ROM picker — stop the core, restore immersive, pop. */
    public function leave(): void
    {
        $this->guard(fn () => $this->emu()->stop());
        $this->back()->transition(Transition::None);
    }

    public function openRomSettings(): void
    {
        $this->navigate("/play/{$this->id}/settings", ['rom' => $this->rom])->transition(Transition::None);
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
        return view('play', [
            'groups' => Catalog::groupButtons($this->buttons),
        ]);
    }
}
