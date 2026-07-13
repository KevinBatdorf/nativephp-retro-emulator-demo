<?php

namespace App\Native;

use App\Support\BundledRoms;
use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use Native\Mobile\Edge\NativeComponent;

/**
 * Human-eyes showcase: boot any compiled system's bundled homebrew ROM
 * in-surface. This is the screen every coverage phase verifies against.
 */
class SystemsScreen extends NativeComponent
{
    public string $current = '';

    public string $status = 'pick a system';

    public function playSfc(): void
    {
        $this->play('sfc');
    }

    public function playFc(): void
    {
        $this->play('fc');
    }

    public function playGb(): void
    {
        $this->play('gb');
    }

    public function playMd(): void
    {
        $this->play('md');
    }

    public function playZelda(): void
    {
        $emu = Emulator::surface('showcase');

        if ($this->current !== '') {
            $emu->stop();
        }

        $emu->loadSystem('sfc')->loadRom('/data/local/tmp/alttp.sfc');

        $this->current = 'sfc';
        $this->status = 'sfc — alttp.sfc';
    }

    private function play(string $system): void
    {
        $rom = BundledRoms::forSystem($system);

        if ($rom === null) {
            $this->status = "no bundled ROM for {$system}";

            return;
        }

        $emu = Emulator::surface('showcase');

        if ($this->current !== '') {
            $emu->stop();
        }

        $emu->loadSystem($system)->loadRom($rom);

        $this->current = $system;
        $this->status = "{$system} — ".basename($rom);
    }

    public function render(): View
    {
        return view('systems');
    }
}
