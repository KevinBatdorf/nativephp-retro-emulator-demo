<?php

namespace App\Native;

use App\Support\BundledRoms;
use Illuminate\View\View;
use KevinBatdorf\Fullscreen\Facades\Fullscreen;
use Native\Mobile\Edge\NativeComponent;

/**
 * Audio A/B bench: every button boots one permutation from cold —
 * system (gb, gbc) x ROM x profile (each engine's raw sound vs the plugin's
 * smoothed one). Back out and tap another to compare. Drop ROMs into
 * storage/app/roms/<system>/; bundled homebrew fills any empty slot.
 */
class HomeScreen extends NativeComponent
{
    private const BENCH = ['gb', 'gbc'];

    private const PER_SYSTEM = 3;

    /**
     * raw = the engine's own upstream sound (pedestal and all)
     * ours    = the smoothed sound the plugin ships today
     */
    private const PROFILES = [
        ['ares', true],       // ares, rawAudio
        ['ares', false],      // ares, default
        ['sameboy', true],    // SameBoy, rawAudio
        ['sameboy', false],   // SameBoy, default
    ];

    public function navTitle(): string
    {
        return 'Audio bench';
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

    public function play(string $system, string $rom, string $engine, bool $raw): void
    {
        $this->navigate('/play/'.$system, [
            'rom' => $rom,
            'engine' => $engine,
            'raw' => $raw,
        ]);
    }

    public function render(): View
    {
        $rows = [];

        foreach (self::BENCH as $system) {
            $roms = BundledRoms::listForSystem($system, self::PER_SYSTEM);
            foreach (self::PROFILES as [$engine, $raw]) {
                $rows[] = [
                    'system' => $system,
                    'engine' => $engine,
                    'raw' => $raw,
                    'label' => sprintf(
                        '%s · %s · %s',
                        strtoupper($system),
                        $engine,
                        $raw ? 'raw' : 'default',
                    ),
                    'roms' => $roms,
                ];
            }
        }

        return view('home', ['rows' => $rows]);
    }
}
