<?php

namespace App\Native;

use App\Support\Catalog;
use App\Support\SettingsStore;
use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\Device;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Facades\Dialog;

/**
 * Play → ROM Settings. The per-system scope: region, per-system toggles (SFC
 * deepBlackBoost, GB colour emulation…), the peripheral device selector, and a
 * CRT override (inherit/on/off) layered over the global CRT toggle. Persisted
 * per system id; reset returns to defaults.
 *
 * "Apply & reboot" replaces the Play screen with a fresh boot so the new config
 * takes effect immediately (region/toggles need a reload).
 */
class RomSettingsScreen extends NativeComponent
{
    public string $id = '';

    public string $rom = '';

    public string $region = '';

    public string $device = 'Gamepad';

    public string $crt = 'inherit';

    /** deepBlackBoost / colorEmulation / … → current bool value. */
    public array $toggles = [];

    public function navTitle(): string
    {
        return 'ROM Settings';
    }

    public function mount(): void
    {
        $this->id = (string) $this->param('id');
        $this->rom = (string) $this->data('rom');
        $this->hydrate();
    }

    private function hydrate(): void
    {
        $s = SettingsStore::system($this->id);
        $this->region = $s['region'] ?? '';
        $this->device = $s['device'] ?? 'Gamepad';
        $this->crt = $s['crt'] ?? 'inherit';

        $this->toggles = [];
        foreach (Catalog::toggles($this->id) as $field => $label) {
            // Untouched toggles display the NATIVE default (weave/Expansion
            // Pak run ON when unset), not a blanket false.
            $this->toggles[$field] = isset($s[$field])
                ? (bool) $s[$field]
                : Catalog::toggleDefault($this->id, $field);
        }
    }

    public function setRegion(string $value): void
    {
        $this->region = $value === '(auto)' ? '' : $value;
        SettingsStore::setSystem($this->id, 'region', $this->region);
    }

    public function setDevice(string $value): void
    {
        $this->device = $value;
        SettingsStore::setSystem($this->id, 'device', $value);
    }

    public function setCrt(string $value): void
    {
        $this->crt = $value;
        SettingsStore::setSystem($this->id, 'crt', $value);
    }

    public function setToggle(string $field, bool $on): void
    {
        $this->toggles[$field] = $on;
        SettingsStore::setSystem($this->id, $field, $on);
    }

    public function resetDefaults(): void
    {
        SettingsStore::resetSystem($this->id);
        $this->hydrate();
        Dialog::toast('Reset to defaults');
    }

    public function applyAndReboot(): void
    {
        if ($this->rom === '') {
            $this->back()->transition(Transition::None);

            return;
        }

        $this->replace("/play/{$this->id}", ['rom' => $this->rom])->transition(Transition::None);
    }

    /** Devices this system accepts on port 1, from Emulator::ports(). */
    private function deviceOptions(): array
    {
        try {
            $ports = Emulator::surface('play')->ports();
            $supported = $ports[0]['supported'] ?? [];

            if ($supported !== []) {
                return $supported;
            }
        } catch (\Throwable) {
            // Surface not staged — fall back to the full device set.
        }

        return array_map(fn (Device $d) => $d->value, Device::cases());
    }

    public function render(): View
    {
        $regions = Catalog::regions($this->id);

        return view('rom-settings', [
            'regionOptions' => $regions === [] ? [] : ['(auto)', ...$regions],
            'deviceOptions' => $this->deviceOptions(),
            'toggleLabels' => Catalog::toggles($this->id),
        ]);
    }
}
