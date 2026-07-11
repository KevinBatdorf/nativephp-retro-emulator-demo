<?php

namespace App\Native;

use App\Conformance\ConformanceRunner;
use App\Support\BundledRoms;
use Illuminate\View\View;
use KevinBatdorf\RetroEmulator\Events\EmulatorError;
use KevinBatdorf\RetroEmulator\Events\EmulatorPaused;
use KevinBatdorf\RetroEmulator\Events\EmulatorResumed;
use KevinBatdorf\RetroEmulator\Events\EmulatorStarted;
use KevinBatdorf\RetroEmulator\Events\EmulatorStopped;
use KevinBatdorf\RetroEmulator\Events\MemoryChanged;
use KevinBatdorf\RetroEmulator\Events\MemoryRead;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

/**
 * Runs the full bridge conformance suite automatically on load and renders
 * pass/fail live. Results are also written to
 * storage/app/conformance-results.json for `adb pull`.
 */
class ConformanceScreen extends NativeComponent
{
    public array $state = [];

    public string $headline = 'starting…';

    /**
     * The suite starts on the first poll tick, not mount() — the emulator
     * surface only exists after the first render.
     */
    #[Poll(250)]
    public function pump(): void
    {
        if ($this->state === []) {
            $rom = BundledRoms::path('helloworld.sfc');

            if ($rom === null) {
                $this->headline = 'helloworld.sfc missing from resources/roms — run scripts/fetch_test_roms.sh';

                return;
            }

            $this->state = ConformanceRunner::initialState($rom);
        }

        $this->state = (new ConformanceRunner)->tick($this->state);
        $this->headline = $this->summarize();

        file_put_contents(
            storage_path('app/conformance-results.json'),
            json_encode([
                'finished' => $this->state['finished'],
                'results' => $this->state['results'],
                'events' => $this->state['events'],
            ], JSON_PRETTY_PRINT),
        );
    }

    #[On(EmulatorStarted::class)]
    public function onEmulatorStarted(string $surface = '', string $system = '', string $romPath = ''): void
    {
        $this->record(EmulatorStarted::class, compact('surface', 'system', 'romPath'));
    }

    #[On(EmulatorStopped::class)]
    public function onEmulatorStopped(string $surface = ''): void
    {
        $this->record(EmulatorStopped::class, compact('surface'));
    }

    #[On(EmulatorPaused::class)]
    public function onEmulatorPaused(string $surface = ''): void
    {
        $this->record(EmulatorPaused::class, compact('surface'));
    }

    #[On(EmulatorResumed::class)]
    public function onEmulatorResumed(string $surface = ''): void
    {
        $this->record(EmulatorResumed::class, compact('surface'));
    }

    #[On(MemoryRead::class)]
    public function onMemoryRead(string $surface = '', int $address = 0, array $bytes = []): void
    {
        $this->record(MemoryRead::class, compact('surface', 'address', 'bytes'));
    }

    #[On(MemoryChanged::class)]
    public function onMemoryChanged(string $surface = '', int $address = 0, int $oldValue = 0, int $newValue = 0): void
    {
        $this->record(MemoryChanged::class, compact('surface', 'address', 'oldValue', 'newValue'));
    }

    #[On(EmulatorError::class)]
    public function onEmulatorError(string $surface = '', string $code = '', string $message = ''): void
    {
        $this->record(EmulatorError::class, compact('surface', 'code', 'message'));
    }

    public function render(): View
    {
        $results = $this->state['results'] ?? [];

        return view('conformance', [
            'passed' => count(array_filter($results, fn ($r) => $r['status'] === 'pass')),
            'failed' => array_values(array_filter($results, fn ($r) => $r['status'] === 'fail')),
            'total' => count($results),
            'waitingOn' => $this->state['waiting']['label'] ?? null,
            'finished' => $this->state['finished'] ?? false,
        ]);
    }

    private function record(string $class, array $payload): void
    {
        if ($this->state === []) {
            return;
        }

        $this->state = (new ConformanceRunner)->recordEvent($this->state, $class, $payload);
    }

    private function summarize(): string
    {
        $results = $this->state['results'];
        $failed = count(array_filter($results, fn ($r) => $r['status'] === 'fail'));

        if ($this->state['finished']) {
            return $failed === 0
                ? 'ALL GREEN — '.count($results).' checks passed'
                : "DONE — {$failed} of ".count($results).' checks FAILED';
        }

        return 'running… '.count($results).' checks done'.($failed > 0 ? ", {$failed} failed" : '');
    }
}
