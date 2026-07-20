<?php

namespace App\Native;

use App\Support\Catalog;
use App\Support\SettingsStore;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;

/**
 * In-game settings (⚙ from Play) — only the knobs a dev demoing the plugin
 * would reach for: the CRT shader, the live picture/audio controls, and each
 * system's own visible toggles (SFC deep-black boost, N64 quality/VI). Region
 * and controller are NOT here — region auto-resolves from the ROM and the pad
 * auto-connects, so a dropdown for either is just noise.
 *
 * The bottom carries the raw config JSON handed to Emulator::loadSystem — the
 * one "under the hood" view a plugin consumer actually wants to see.
 *
 * Picture/audio changes apply live (setVideo/setAudio on the running surface);
 * the shader + per-system toggles need a fresh boot, so "Apply & reboot" is
 * offered when one of those changed.
 */
class RomSettingsScreen extends NativeComponent
{
    public string $id = '';

    public string $rom = '';

    public int $luminance = 100;

    public int $saturation = 100;

    public int $gamma = 100;

    public bool $overscan = false;

    public int $volume = 100;

    public bool $crt = false;

    /** deepBlackBoost / N64 quality toggles / … → current bool value. */
    public array $toggles = [];

    /** Set when a boot-time setting (shader/toggle) changed since entry. */
    public bool $rebootNeeded = false;

    public function navTitle(): string
    {
        return 'Settings';
    }

    public function mount(): void
    {
        $this->id = (string) $this->param('id');
        $this->rom = (string) $this->data('rom');
        $this->hydrate();
    }

    private function hydrate(): void
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

    private function surface(): ?\KevinBatdorf\RetroEmulator\Emulator
    {
        try {
            return \KevinBatdorf\RetroEmulator\Facades\Emulator::surface('play');
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Live picture / audio (apply immediately) ─────

    public function setLuminance(float $v): void
    {
        $this->luminance = (int) round($v);
        SettingsStore::setGlobal('luminance', $this->luminance);
        $this->surface()?->setVideo(luminance: $this->luminance);
    }

    public function setSaturation(float $v): void
    {
        $this->saturation = (int) round($v);
        SettingsStore::setGlobal('saturation', $this->saturation);
        $this->surface()?->setVideo(saturation: $this->saturation);
    }

    public function setGamma(float $v): void
    {
        $this->gamma = (int) round($v);
        SettingsStore::setGlobal('gamma', $this->gamma);
        $this->surface()?->setVideo(gamma: (float) $this->gamma);
    }

    public function setOverscan(bool $on): void
    {
        $this->overscan = $on;
        SettingsStore::setGlobal('overscan', $on);
        $this->surface()?->setVideo(overscan: $on);
    }

    public function setVolume(float $v): void
    {
        $this->volume = (int) round($v);
        SettingsStore::setGlobal('volume', $this->volume);
        $this->surface()?->setVolume($this->volume);
    }

    // ── Boot-time settings (need a reboot) ───────────

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

    public function resetDefaults(): void
    {
        SettingsStore::resetGlobal();
        SettingsStore::resetSystem($this->id);
        $this->hydrate();
        $this->rebootNeeded = true;
    }

    public function applyAndReboot(): void
    {
        if ($this->rom === '') {
            $this->back()->transition(Transition::None);

            return;
        }

        $this->replace("/play/{$this->id}", ['rom' => $this->rom])->transition(Transition::None);
    }

    public function render(): View
    {
        // The exact array Emulator::loadSystem() receives — the dev's
        // "what's actually sent to the plugin" view.
        $config = SettingsStore::configFor($this->id);
        $configArray = is_object($config) && method_exists($config, 'toArray')
            ? $config->toArray()
            : (array) $config;

        return view('rom-settings', [
            'toggleLabels' => Catalog::toggles($this->id),
            'configJson' => json_encode($configArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);
    }
}
