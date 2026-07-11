<?php

use App\Conformance\ConformanceRunner;
use KevinBatdorf\RetroEmulator\Events\EmulatorPaused;
use KevinBatdorf\RetroEmulator\Events\EmulatorStarted;
use KevinBatdorf\RetroEmulator\Events\MemoryChanged;
use KevinBatdorf\RetroEmulator\Events\MemoryRead;

/**
 * A fake native layer implementing the documented bridge contract: success
 * responses are bare data JSON, errors are {"status":"error","code":...},
 * and the v1 de-scoped functions return NOT_IMPLEMENTED.
 */
class FakeNative
{
    /** @var list<array{function: string, payload: array}> */
    public array $calls = [];

    /** @var array<string, callable(array): ?string> */
    public array $overrides = [];

    private string $status = 'stopped';

    /** @var array<int, int> */
    private array $memory = [];

    public function __invoke(string $function, string $json): ?string
    {
        $payload = json_decode($json, true) ?? [];
        $this->calls[] = ['function' => $function, 'payload' => $payload];

        if (isset($this->overrides[$function])) {
            return ($this->overrides[$function])($payload);
        }

        return match ($function) {
            'Emulator.GetSystems' => json_encode(['systems' => [
                ['id' => 'sfc', 'name' => 'SNES / Super Famicom', 'supported' => true, 'stable' => true, 'biosRequired' => false],
            ]]),
            'Emulator.GetStatus' => json_encode(['status' => $this->status]),
            'Emulator.GetRegion' => json_encode(['region' => 'NTSC']),
            'Emulator.GetPorts' => json_encode(['ports' => [
                ['port' => 1, 'buttons' => ['B', 'Y', 'Select', 'Start', 'Up', 'Down', 'Left', 'Right', 'A', 'X', 'L', 'R']],
            ]]),
            'Emulator.LoadRom' => $this->transition('running'),
            'Emulator.Pause' => $this->transition('paused'),
            'Emulator.Resume' => $this->transition('running'),
            'Emulator.Stop' => $this->transition('stopped'),
            'Emulator.ReadMemory' => json_encode([
                'address' => $payload['address'],
                'bytes' => array_map(
                    fn (int $i) => $this->memory[$payload['address'] + $i] ?? 0,
                    range(0, ($payload['length'] ?? 1) - 1),
                ),
            ]),
            'Emulator.WriteMemory' => $this->write($payload),
            'Emulator.Configure' => $this->configure($payload),
            'Emulator.SetShader' => ($payload['path'] ?? null) !== null
                ? $this->notImplemented()
                : '{}',
            'Emulator.SetInputMapping',
            'Emulator.SetRumble',
            'Emulator.AddCheat',
            'Emulator.RemoveCheat' => $this->notImplemented(),
            default => '{}',
        };
    }

    private function transition(string $status): string
    {
        $this->status = $status;

        return '{}';
    }

    private function write(array $payload): string
    {
        foreach ($payload['bytes'] as $i => $byte) {
            $this->memory[$payload['address'] + $i] = $byte;
        }

        return '{}';
    }

    private function configure(array $payload): string
    {
        $options = $payload['options'] ?? [];

        if (($options['runAhead'] ?? 0) !== 0 || ($options['rewind'] ?? false) !== false) {
            return $this->notImplemented();
        }

        return '{}';
    }

    private function notImplemented(): string
    {
        return json_encode(['status' => 'error', 'code' => 'NOT_IMPLEMENTED', 'message' => 'not supported in v1', 'data' => []]);
    }
}

function makeRunner(FakeNative $native, float &$time): ConformanceRunner
{
    return new ConformanceRunner(
        bridge: Closure::fromCallable($native),
        now: function () use (&$time): float {
            return $time;
        },
    );
}

/**
 * Tick the runner to completion, feeding each awaited event the moment the
 * runner starts waiting on it — except $stopAtEvent, which leaves the runner
 * mid-wait so tests can poke at that state.
 */
function drive(ConformanceRunner $runner, array $state, ?string $stopAtEvent = null): array
{
    for ($i = 0; $i < 500 && ! $state['finished']; $i++) {
        $state = $runner->tick($state);
        $waiting = $state['waiting'];

        if ($waiting === null) {
            continue;
        }

        if ($waiting['event'] === $stopAtEvent) {
            return $state;
        }

        $state = $runner->recordEvent($state, $waiting['event'], eventPayload($waiting));
    }

    return $state;
}

function eventPayload(array $waiting): array
{
    $expects = $waiting['expects'] ?? [];

    return match (class_basename($waiting['event'])) {
        'EmulatorStarted' => [...$expects, 'surface' => 'main', 'system' => 'sfc', 'romPath' => '/roms/test.sfc'],
        'MemoryRead' => [...$expects, 'surface' => 'main', 'bytes' => [0]],
        'MemoryChanged' => [...$expects, 'surface' => 'main', 'oldValue' => 0, 'newValue' => 0x55],
        default => [...$expects, 'surface' => 'main'],
    };
}

function failures(array $state): array
{
    return array_values(array_filter($state['results'], fn ($r) => $r['status'] === 'fail'));
}

it('passes the full suite against a conforming native layer', function () {
    $native = new FakeNative;
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'));

    expect($state['finished'])->toBeTrue()
        ->and(failures($state))->toBe([])
        ->and(count($state['results']))->toBeGreaterThan(40);
});

it('exercises every bridge function declared in the plugin manifest', function () {
    $native = new FakeNative;
    $time = 0.0;
    $runner = makeRunner($native, $time);

    drive($runner, ConformanceRunner::initialState('/roms/test.sfc'));

    $manifest = json_decode(
        file_get_contents(__DIR__.'/../../vendor/kevinbatdorf/retro-emulator/nativephp.json'),
        true,
    );
    $declared = array_column($manifest['bridge_functions'], 'name');
    $called = array_unique(array_column($native->calls, 'function'));

    expect($declared)->toHaveCount(35)
        ->and(array_values(array_diff($declared, $called)))->toBe([]);
});

it('fails a step when the bridge returns an error', function () {
    $native = new FakeNative;
    $native->overrides['Emulator.Pause'] = fn () => json_encode([
        'status' => 'error', 'code' => 'NO_SURFACE', 'message' => 'surface not registered', 'data' => [],
    ]);
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'));

    $failed = failures($state);
    expect($failed)->not->toBe([])
        ->and($failed[0]['function'])->toBe('Emulator.Pause')
        ->and($failed[0]['detail'])->toContain('NO_SURFACE');
});

it('fails a step when the bridge returns no response', function () {
    $native = new FakeNative;
    $native->overrides['Emulator.Screenshot'] = fn () => null;
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'));

    $failed = failures($state);
    expect(array_column($failed, 'function'))->toContain('Emulator.Screenshot')
        ->and($failed[0]['detail'])->toContain('no response');
});

it('fails a de-scoped step when the function unexpectedly succeeds', function () {
    $native = new FakeNative;
    $native->overrides['Emulator.AddCheat'] = fn () => '{}';
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'));

    $failed = failures($state);
    expect(array_column($failed, 'function'))->toContain('Emulator.AddCheat')
        ->and($failed[0]['detail'])->toContain('expected NOT_IMPLEMENTED');
});

it('fails an event wait on timeout', function () {
    $native = new FakeNative;
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'), stopAtEvent: EmulatorStarted::class);

    expect($state['waiting'])->not->toBeNull();

    $time = 1000.0;
    $state = $runner->tick($state);

    $failed = failures($state);
    expect($failed[0]['detail'])->toContain('timed out waiting for EmulatorStarted');
});

it('ignores a matching event class whose payload misses the expectation', function () {
    $native = new FakeNative;
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'), stopAtEvent: MemoryRead::class);
    $expectedAddress = $state['waiting']['expects']['address'];

    $state = $runner->recordEvent($state, MemoryRead::class, [
        'surface' => 'main', 'address' => $expectedAddress + 1, 'bytes' => [0],
    ]);
    $state = $runner->tick($state);
    expect($state['waiting'])->not->toBeNull();

    $state = $runner->recordEvent($state, MemoryRead::class, [
        'surface' => 'main', 'address' => $expectedAddress, 'bytes' => [0],
    ]);
    $state = $runner->tick($state);
    expect($state['waiting']['event'] ?? null)->not->toBe(MemoryRead::class);
});

it('re-writes the watched byte while waiting for MemoryChanged', function () {
    $native = new FakeNative;
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'), stopAtEvent: MemoryChanged::class);
    $watched = $state['waiting']['expects']['address'];

    $native->calls = [];
    $state = $runner->tick($state);
    $time += 0.1;
    $runner->tick($state);

    $writes = array_filter(
        $native->calls,
        fn ($c) => $c['function'] === 'Emulator.WriteMemory' && $c['payload']['address'] === $watched,
    );
    expect($writes)->not->toBe([]);
});

it('survives JSON serialization of state mid-run', function () {
    $native = new FakeNative;
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'), stopAtEvent: EmulatorPaused::class);

    $state = json_decode(json_encode($state), true);

    $state = drive($runner, $state);
    expect($state['finished'])->toBeTrue()
        ->and(failures($state))->toBe([]);
});
