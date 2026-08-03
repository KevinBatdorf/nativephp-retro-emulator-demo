<?php

namespace App\Native;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\SharedValue;

/**
 * Side-by-side <native:dpad> variations, for judging the look by eye.
 *
 * No emulator surface here on purpose: the pads resolve directions natively and
 * find no renderer to press, so they draw and highlight without driving a core.
 */
class DpadGalleryScreen extends NativeComponent
{
    /**
     * The pads integrate these natively and the ball's translate binds to them.
     * Animating from a PHP tick instead is not just chunky: republishing the
     * tree every 80ms trips a Compose snapshot race in the host and crash-loops
     * the app.
     */
    public SharedValue $ballX;

    public SharedValue $ballY;

    /** Directions the steering pads report held, e.g. "Up,Right". */
    public string $heldDirections = '';

    public function mount(): void
    {
        // Start it in open space; at 0,0 it sits under the Back button.
        $this->ballX = SharedValue::make(150);
        $this->ballY = SharedValue::make(120);
    }

    /** Fires only when the held set changes — the readout, not the animation. */
    public function steer(string $directions = ''): void
    {
        $this->heldDirections = $directions;
    }

    /**
     * Each row is [heading, [[label, attrs], …]] where attrs are the element's
     * own props. Only the varied prop is set, so every other value shown is the
     * element's default.
     *
     * @return array<int, array{0: string, 1: array<int, array{0: string, 1: array<string, mixed>}>}>
     */
    public function variations(): array
    {
        return [
            ['thickness — arm width, % of the shorter side', [
                ['20', ['thickness' => 20]],
                ['36 (default)', []],
                ['50', ['thickness' => 50]],
                ['60', ['thickness' => 60]],
            ]],
            ['radius — corner rounding, % of arm width', [
                ['0 square', ['radius' => 0]],
                ['12', ['radius' => 12]],
                ['28 (default)', []],
                ['50 round tip', ['radius' => 50]],
            ]],
            ['colour', [
                ['default', []],
                ['solid slate', ['color' => '#FF334155', 'activeColor' => '#FF94A3B8']],
                ['faint', ['color' => '#33FFFFFF', 'activeColor' => '#99FFFFFF']],
                ['amber', ['color' => '#66F59E0B', 'activeColor' => '#FFFBBF24']],
            ]],
            ['diagonals — false snaps to one axis, for a 4-way game', [
                ['true (default)', []],
                ['false', ['diagonals' => 'false']],
                ['false + square', ['diagonals' => 'false', 'radius' => 0]],
                ['ratio 80 (not a lock)', ['diagonalRatio' => 80]],
            ]],
            ['combinations', [
                ['thin + square', ['thickness' => 22, 'radius' => 0]],
                ['fat + round', ['thickness' => 55, 'radius' => 50]],
                ['classic pad', ['thickness' => 40, 'radius' => 8, 'color' => '#FF3F3F46']],
                ['minimal', ['thickness' => 26, 'radius' => 50, 'color' => '#40FFFFFF']],
            ]],
        ];
    }

    public function leave(): void
    {
        $this->back();
    }

    public function render(): View
    {
        return view('dpad-gallery', ['rows' => $this->variations()]);
    }
}
