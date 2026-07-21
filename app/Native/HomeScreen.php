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

    /**
     * ICEBOX (v2): N64 is hidden from the demo. ares' accuracy N64 core costs
     * ~a full CPU core at speed — measured 60fps but enough sustained heat
     * that the Thor's fan runs at full even after exit (stored chassis heat,
     * 65°C at zero CPU). The plugin still ships the core; the demo doesn't
     * offer it until the cost story changes.
     */
    private const ICEBOXED = ['n64'];

    public function render(): View
    {
        $systems = array_values(array_filter(
            Emulator::systems(),
            fn ($s) => ($s['supported'] ?? false) && ! in_array($s['id'], self::ICEBOXED, true),
        ));

        return view('home', ['systems' => $systems]);
    }
}
