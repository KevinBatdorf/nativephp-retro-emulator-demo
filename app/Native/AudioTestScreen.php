<?php

namespace App\Native;

use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

/**
 * Ear-check bench for the audio/timing fixes. Boots a music-heavy ROM (ALttP)
 * so both fixes are audible:
 *  - 1.6: "Vol 50" then "Bal L" are two SEPARATE bridge calls — pre-fix the
 *    second reset the first, so it should stay quiet AND pan left.
 *  - 1.7: fast-forward should run 4x without the dynamic-rate-control pitch
 *    wobble (DRC is now gated off while fast-forwarding).
 */
class AudioTestScreen extends NativeComponent
{
    public string $status = 'booting…';

    public bool $ff = false;

    #[Poll(1500)]
    public function boot(): void
    {
        if ($this->status !== 'booting…') {
            return;
        }

        Emulator::surface('audio')->loadSystem('sfc')->loadRom('/data/local/tmp/alttp.sfc');
        $this->status = 'playing — try the buttons';
    }

    public function toggleFf(): void
    {
        $this->ff = ! $this->ff;
        Emulator::surface('audio')->fastForward($this->ff);
        $this->status = $this->ff ? 'FAST-FORWARD (listen: no pitch wobble)' : 'normal speed';
    }

    public function vol50(): void
    {
        Emulator::surface('audio')->setVolume(50);
        $this->status = 'volume → 50 (call 1 of 2)';
    }

    public function balLeft(): void
    {
        Emulator::surface('audio')->setBalance(-100);
        $this->status = 'balance → left — volume should STILL be 50, not reset';
    }

    public function reset(): void
    {
        Emulator::surface('audio')->setVolume(100)->setBalance(0);
        $this->status = 'volume 100, balance center';
    }

    public function render(): View
    {
        return view('audio-test');
    }
}
