<?php

namespace App\Native;

use Illuminate\View\View;
use KevinBatdorf\Fullscreen\Facades\Fullscreen;
use KevinBatdorf\RetroEmulator\Facades\Emulator;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen 1 — Home. A grid of the consoles this build actually compiled
 * (Emulator::systems() where supported), tapping through to each system's ROM
 * picker. Bottom bar carries global Settings.
 */
class HomeScreen extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Retro Emulator';
    }

    /**
     * Go immersive (marketplace fullscreen plugin) as the app opens — hides the
     * status + gesture bars app-wide. Immersive is an activity-level state, so
     * setting it here persists across every screen.
     */
    public function mount(): void
    {
        Fullscreen::enter();
    }

    public function render(): View
    {
        $systems = array_values(array_filter(
            Emulator::systems(),
            fn ($s) => $s['supported'] ?? false,
        ));

        return view('home', ['systems' => $systems]);
    }
}
