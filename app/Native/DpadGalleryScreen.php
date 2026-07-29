<?php

namespace App\Native;

use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

/**
 * Side-by-side <native:dpad> variations, for judging the look by eye.
 *
 * No emulator surface here on purpose: the pads resolve directions natively and
 * find no renderer to press, so they draw and highlight without driving a core.
 */
class DpadGalleryScreen extends NativeComponent
{
    /**
     * Ball position and field size in dp — the field is the screen inset by the
     * blade's padding, so these are approximate on purpose; the clamp only has
     * to keep the ball on the visible panel.
     */
    private const FIELD_W = 700;

    private const FIELD_H = 300;

    private const BALL = 24;

    private const STEP = 14;

    public float $ballX = 320;

    public float $ballY = 130;

    /** Directions the pad currently reports held, e.g. "Up,Right". */
    public string $heldDirections = '';

    /**
     * The pad's optional @change callback. It fires only when the held set
     * changes, so the tick below is what actually animates the ball.
     */
    public function steer(string $directions = ''): void
    {
        $this->heldDirections = $directions;
    }

    /**
     * Integrate the held direction into a position. The pad reports changes, not
     * frames, so movement has to come from a tick — and this is PHP, so it moves
     * at the poll rate rather than the pad's own per-frame resolution.
     */
    #[Poll(80)]
    public function driftBall(): void
    {
        if ($this->heldDirections === '') {
            return;
        }

        $held = explode(',', $this->heldDirections);
        $x = $this->ballX + (in_array('Right', $held, true) ? self::STEP : 0)
            - (in_array('Left', $held, true) ? self::STEP : 0);
        $y = $this->ballY + (in_array('Down', $held, true) ? self::STEP : 0)
            - (in_array('Up', $held, true) ? self::STEP : 0);

        $this->ballX = max(0, min(self::FIELD_W - self::BALL, $x));
        $this->ballY = max(0, min(self::FIELD_H - self::BALL, $y));
    }

    /**
     * Each row is [heading, [[label, attrs], …]] where attrs are the element's
     * own props. Only the varied prop is set, so every other value shown is the
     * element's default.
     *
     * @return array<int, array{0: string, 1: array<int, array{0: string, 1: array<string, mixed>}}>
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
