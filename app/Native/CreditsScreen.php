<?php

namespace App\Native;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Bundled-game credits and licenses, in-app. This screen is a license
 * obligation, not decoration: Tobu Tobu Girl's CC-BY assets require visible
 * attribution, and uCity's GPL source offer has to travel next to the binary
 * (GPLv3 §6(d)) — resources/roms/license.txt alone is buried inside the
 * bundle. Keep entries in sync with that file; it stays the canonical record.
 */
class CreditsScreen extends NativeComponent
{
    /** @return array<int, array{game: string, by: string, license: string, url: string, note?: string}> */
    public function games(): array
    {
        return [
            ['game' => 'Super Tilt Bro. (NES)', 'by' => 'Sylvain Gadrat (sgadrat)', 'license' => 'WTFPL',
                'url' => 'github.com/sgadrat/super-tilt-bro'],
            ['game' => 'Space Rescue Squad (SNES)', 'by' => 'Marcus Rowe (undisbeliever), music by KungFuFurby', 'license' => 'zlib',
                'url' => 'github.com/undisbeliever/space-rescue-squad'],
            ['game' => 'Rex Runner GB (Game Boy)', 'by' => 'The Void (etdv-thevoid)', 'license' => 'MIT',
                'url' => 'github.com/etdv-thevoid/rex-runner-gb'],
            ['game' => 'uCity v1.3 (Game Boy Color)', 'by' => 'Antonio Niño Díaz (AntonioND / SkyLyrac)', 'license' => 'GPLv3+ (media CC BY-SA 4.0)',
                'url' => 'codeberg.org/SkyLyrac/ucity',
                'note' => 'Unmodified release binary. Complete corresponding source: the v1.3 tag at codeberg.org/SkyLyrac/ucity (mirror: github.com/AntonioND/ucity). Full offer in resources/roms/license.txt.'],
            ['game' => 'Miniplanets (Genesis)', 'by' => 'Javier Degirolmo (Sik)', 'license' => 'zlib',
                'url' => 'github.com/sikthehedgehog/miniplanets'],
            ['game' => 'Blind Jump (GBA)', 'by' => 'Evan Bowman', 'license' => 'MIT',
                'url' => 'github.com/evanbowman/blind-jump-portable'],
        ];
    }

    /** @return array<int, array{name: string, detail: string}> */
    public function components(): array
    {
        return [
            ['name' => 'ares emulator', 'detail' => 'ISC — ares-emu.net. Emulation by the ares team, Near et al.'],
            ['name' => 'retro-emulator plugin', 'detail' => 'MIT — third-party notices ship with the plugin package.'],
            ['name' => 'crt-lottes shader', 'detail' => 'Public domain — Timothy Lottes, via libretro slang-shaders.'],
            ['name' => 'Cult-of-GBA BIOS', 'detail' => 'MIT — DenSinH and fleroviux. GBA boots on this open firmware.'],
        ];
    }

    public function leave(): void
    {
        $this->back();
    }

    public function render(): View
    {
        return view('credits', [
            'games' => $this->games(),
            'components' => $this->components(),
        ]);
    }
}
