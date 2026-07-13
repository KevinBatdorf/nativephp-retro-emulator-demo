<?php

namespace App\Native;

use App\Support\BundledRoms;
use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\Device;
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

    public bool $swapped = false;

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
        $emu->connectDevice(1, Device::Gamepad);   // controllers are explicit now
        $this->swapped = false;

        $this->current = 'sfc';
        $this->status = 'sfc — alttp.sfc';
    }

    public function toggleSwap(): void
    {
        $this->swapped = ! $this->swapped;
        Emulator::surface('showcase')->getDevice(1)->remap(
            $this->swapped ? ['a' => 'b', 'b' => 'a'] : [],
        );
        $this->status = $this->swapped ? 'port 1: A/B swapped' : 'port 1: default mapping';
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
        $emu->connectDevice(1, Device::Gamepad);   // controllers are explicit now
        $this->swapped = false;

        $this->current = $system;
        $this->status = "{$system} — ".basename($rom);
    }

    public function render(): View
    {
        return view('systems');
    }
}
