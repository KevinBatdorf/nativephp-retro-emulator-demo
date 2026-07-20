<?php

namespace App\Native;

use App\Support\SettingsStore;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen 1 → Settings. The global scope from the plan: video, audio, speed,
 * a single "Apply CRT filter" toggle, rumble, and reset-all-to-defaults. Every
 * change persists immediately (SettingsStore) and is read back on the next
 * boot; there is no running surface on this screen, so nothing applies live.
 */
class SettingsScreen extends NativeComponent
{
    public int $luminance = 100;

    public int $saturation = 100;

    public int $gamma = 100;

    public bool $overscan = false;

    public int $volume = 100;

    public int $balance = 0;

    public float $speed = 1.0;

    public bool $crt = false;

    public bool $rumble = false;

    public function navTitle(): string
    {
        return 'Global Settings';
    }

    public function mount(): void
    {
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
        $this->balance = (int) $g['balance'];
        $this->speed = (float) $g['speed'];
        $this->crt = (bool) $g['crt'];
        $this->rumble = (bool) $g['rumble'];
    }

    public function setLuminance(float $v): void
    {
        $this->luminance = (int) round($v);
        SettingsStore::setGlobal('luminance', $this->luminance);
    }

    public function setSaturation(float $v): void
    {
        $this->saturation = (int) round($v);
        SettingsStore::setGlobal('saturation', $this->saturation);
    }

    public function setGamma(float $v): void
    {
        $this->gamma = (int) round($v);
        SettingsStore::setGlobal('gamma', $this->gamma);
    }

    /** Ranges + step for each stepped value setting. */
    private const RANGES = [
        'luminance' => [0, 100, 5],
        'saturation' => [0, 100, 5],
        'gamma' => [50, 200, 5],
        'volume' => [0, 100, 5],
        'balance' => [-100, 100, 10],
        'speed' => [0.25, 4.0, 0.25],
    ];

    /** −/+ stepper: clamp to range, persist, update the prop. */
    public function bump(string $key, float $delta): void
    {
        if (! isset(self::RANGES[$key])) {
            return;
        }

        [$min, $max] = self::RANGES[$key];
        $val = max($min, min($max, $this->{$key} + $delta));
        $val = $key === 'speed' ? round($val, 2) : (int) round($val);

        $this->{$key} = $val;
        SettingsStore::setGlobal($key, $val);
    }

    public function setOverscan(bool $on): void
    {
        $this->overscan = $on;
        SettingsStore::setGlobal('overscan', $on);
    }

    public function setVolume(float $v): void
    {
        $this->volume = (int) round($v);
        SettingsStore::setGlobal('volume', $this->volume);
    }

    public function setBalance(float $v): void
    {
        $this->balance = (int) round($v);
        SettingsStore::setGlobal('balance', $this->balance);
    }

    public function setSpeed(float $v): void
    {
        $this->speed = round($v, 2);
        SettingsStore::setGlobal('speed', $this->speed);
    }

    public function setCrt(bool $on): void
    {
        $this->crt = $on;
        SettingsStore::setGlobal('crt', $on);
    }

    public function setRumble(bool $on): void
    {
        $this->rumble = $on;
        SettingsStore::setGlobal('rumble', $on);
    }

    public function resetAll(): void
    {
        SettingsStore::resetGlobal();
        $this->hydrate();
    }

    public function render(): View
    {
        return view('settings');
    }
}
