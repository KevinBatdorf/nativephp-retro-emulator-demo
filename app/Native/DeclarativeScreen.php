<?php

namespace App\Native;

use App\Support\BundledRoms;
use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\Config\Config;
use Native\Mobile\Edge\NativeComponent;

/**
 * Boot-on-mount proof: the element carries system/:config/:rom, so native
 * stages, boots, and applies the config when the surface mounts — there is no
 * imperative Emulator::surface()/loadSystem()/loadRom() on this screen at all.
 */
class DeclarativeScreen extends NativeComponent
{
    public function render(): View
    {
        return view('declarative', [
            'config' => new Config(volume: 80),
            'rom' => BundledRoms::forSystem('sfc'),
        ]);
    }
}
