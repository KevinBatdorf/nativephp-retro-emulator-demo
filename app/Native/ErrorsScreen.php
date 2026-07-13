<?php

namespace App\Native;

use App\Support\BundledRoms;
use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\EmulatorException;
use KevinBatdorf\RetroEmulator\Events\EmulatorError;
use KevinBatdorf\RetroEmulator\Events\EmulatorStarted;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

/**
 * Proves both error channels on device: a programmer error throws
 * EmulatorException synchronously (the fluent-command fix), while an
 * operational failure arrives as an EmulatorError event. Boots from a poll,
 * not mount, so the surface is registered first.
 */
class ErrorsScreen extends NativeComponent
{
    public string $threw = 'probing…';

    public string $event = 'waiting…';

    #[Poll(1000)]
    public function probe(): void
    {
        if ($this->threw !== 'probing…') {
            return;
        }

        // Category A: a bad system is a programmer error — it throws here and
        // now, instead of the fluent call swallowing the native response.
        try {
            Emulator::surface('err')->loadSystem('bogus');
            $this->threw = 'NO THROW (bug)';
        } catch (EmulatorException $e) {
            $this->threw = $e->errorCode->value;
        }

        // Boot a real ROM so the category-B probe has a running core.
        Emulator::surface('err')->loadSystem('sfc')->loadRom(BundledRoms::forSystem('sfc'));
    }

    #[On(EmulatorStarted::class)]
    public function onStarted(): void
    {
        // Category B: a malformed cheat is an operational outcome — it comes
        // back as an EmulatorError event, never a throw.
        Emulator::surface('err')->addCheat('not-a-cheat');
    }

    #[On(EmulatorError::class)]
    public function onError(string $surface = '', string $code = '', string $message = ''): void
    {
        $this->event = $code;
    }

    public function render(): View
    {
        return view('errors');
    }
}
