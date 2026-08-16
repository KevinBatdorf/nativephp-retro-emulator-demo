<?php

use App\Conformance\ConformanceRunner;
use KevinBatdorf\RetroEmulator\Buttons\SfcButton;
use KevinBatdorf\RetroEmulator\Events\EmulatorPaused;
use KevinBatdorf\RetroEmulator\Events\EmulatorStarted;
use KevinBatdorf\RetroEmulator\Events\MemoryChanged;
use KevinBatdorf\RetroEmulator\Events\MemoryRead;

/**
 * A fake native layer implementing the documented bridge contract: success
 * responses are bare data JSON, category-A programmer errors are
 * {"status":"error","code":...}, and category-B operational outcomes return
 * {"status":"failed","code":...} alongside an EmulatorError event.
 *
 * Modelled on the SNES paths of ares_jni.cpp / EmulatorFunctions.kt, so a
 * contract change on the native side surfaces here as a failing step.
 */
class FakeNative
{
    /** Logical port ceiling — EmulatorState::kMaxPorts. */
    private const MAX_PORTS = 5;

    /** Physical controller ports on the SNES — core_sfc.cpp `.ports`. */
    private const PHYSICAL_PORTS = 2;

    /** @var list<array{function: string, payload: array}> */
    public array $calls = [];

    /** @var array<string, callable(array): ?string> */
    public array $overrides = [];

    private string $status = 'stopped';

    private bool $systemLoaded = false;

    /** @var array<int, int> */
    private array $memory = [];

    /** @var array<string, true> */
    private array $cheats = [];

    private bool $rewindEnabled = false;

    private bool $rewinding = false;

    /** What the last LoadSystem staged — sfc reads it back as `accuracy`. */
    private bool $pixelAccuracy = false;

    /** Physical port => ares peripheral name. */
    private array $connected = [];

    /** Logical port => [lowercased emulated button => source button]. */
    private array $remap = [];

    /** Logical port => [button name => true] for the buttons held. */
    private array $pressed = [];

    /** Slot number => whole-memory snapshot, standing in for the slot files. */
    private array $slots = [];

    /** @var ?array{slot: int, state: array} */
    private ?array $undoSave = null;

    private ?array $undoLoad = null;

    public function __invoke(string $function, string $json): ?string
    {
        $payload = json_decode($json, true) ?? [];
        $this->calls[] = ['function' => $function, 'payload' => $payload];

        if (isset($this->overrides[$function])) {
            return ($this->overrides[$function])($payload);
        }

        return match ($function) {
            'Emulator.GetSystems' => json_encode(['systems' => [
                [
                    'id' => 'sfc', 'name' => 'SNES / Super Famicom',
                    'supported' => true, 'stable' => true, 'biosRequired' => false,
                    'backends' => ['ares'],
                    'capabilities' => [
                        'ares' => [
                            'videoSettings' => true, 'rumble' => true, 'serialize' => true,
                            'cheats' => true, 'memoryAccess' => true, 'slottedMedia' => true,
                            'multitap' => true, 'mouse' => true,
                            'toggles' => ['deepBlackBoost'],
                            'bootOptions' => ['pixelAccuracy'],
                        ],
                    ],
                ],
            ]]),
            'Emulator.GetStatus' => $this->getStatus(),
            'Emulator.GetRegion' => json_encode(['region' => 'NTSC']),
            'Emulator.GetInputDevices' => json_encode(['devices' => []]),
            'Emulator.GetEngineOptions' => json_encode(['options' => []]),
            'Emulator.GetPressedButtons' => $this->getPressedButtons($payload),
            'Emulator.LoadSystem' => $this->loadSystem($payload),
            'Emulator.GetPorts' => $this->getPorts(),
            'Emulator.ConnectDevice' => $this->connectDevice($payload),
            'Emulator.SetInputMapping' => $this->setInputMapping($payload),
            'Emulator.PressButton' => $this->pressButton($payload, 'pressed'),
            'Emulator.ReleaseButton' => $this->pressButton($payload, 'released'),
            'Emulator.SetAxis' => $this->setAxis($payload),
            'Emulator.AimAt' => $this->aimAt($payload),
            'Emulator.StageSlot' => $this->stageSlot($payload),
            'Emulator.LoadRom' => $this->transition('running'),
            'Emulator.Pause' => $this->transition('paused'),
            'Emulator.Resume' => $this->transition('running'),
            'Emulator.Stop' => $this->transition('stopped'),
            'Emulator.StateSave' => $this->stateSave($payload),
            'Emulator.StateLoad' => $this->stateLoad($payload),
            'Emulator.UndoStateSave' => $this->undoStateSave(),
            'Emulator.UndoStateLoad' => $this->undoStateLoad(),
            'Emulator.ReadMemory' => json_encode([
                'address' => $payload['address'],
                'bytes' => array_map(
                    fn (int $i) => $this->memory[$payload['address'] + $i] ?? 0,
                    range(0, ($payload['length'] ?? 1) - 1),
                ),
            ]),
            'Emulator.SetVideo' => $this->setVideo($payload),
            'Emulator.SetAudio' => $this->setAudio($payload),
            'Emulator.WriteMemory' => $this->write($payload),
            'Emulator.Configure' => $this->configure($payload),
            'Emulator.ToggleRewind' => $this->toggleRewind(),
            'Emulator.PickRom' => isset($payload['destination'])
                ? json_encode(['status' => 'picking'])
                : $this->error('INVALID_PARAMETERS', 'destination directory is required'),
            'Emulator.Rewind' => $this->rewindJump($payload),
            'Emulator.SetShader' => $this->setShader($payload),
            'Emulator.SetRumble' => json_encode([
                'status' => ($payload['enabled'] ?? false) ? 'enabled' : 'disabled',
                'hasVibrator' => true,
            ]),
            'Emulator.AddCheat' => $this->addCheat($payload),
            'Emulator.RemoveCheat' => $this->removeCheat($payload),
            'Emulator.ClearCheats' => $this->clearCheats(),
            default => '{}',
        };
    }

    /**
     * Non-default-gamepad SNES devices, mirroring the plugin's
     * system_catalog.cpp extraDevices. A multitap is a container: no inputs
     * of its own, `block` logical ports.
     *
     * @return array<string, array{buttons: list<string>, axes: list<string>, block?: int}>
     */
    private function deviceTable(): array
    {
        return [
            'Mouse' => ['buttons' => ['Left', 'Right'], 'axes' => ['X', 'Y']],
            'Super Multitap' => ['buttons' => [], 'axes' => [], 'block' => 4],
        ];
    }

    /** @return list<string> */
    private function padButtons(): array
    {
        return array_map(fn (SfcButton $case) => $case->value, SfcButton::cases());
    }

    /** @return list<string> */
    private function supportedDevices(): array
    {
        return ['Gamepad', ...array_keys($this->deviceTable())];
    }

    /** @return ?array{buttons: list<string>, axes: list<string>, block?: int} */
    private function descriptorFor(string $device): ?array
    {
        if ($device === 'Gamepad') {
            return ['buttons' => $this->padButtons(), 'axes' => []];
        }

        return $this->deviceTable()[$device] ?? null;
    }

    /**
     * Logical ports in fan-out order, as ares_jni.cpp's buildPortsJson() emits
     * them: a multitap expands to `block` gamepads, every other device takes one.
     *
     * @return list<array{port: int, physical: int, device: ?string, buttons: list<string>, axes: list<string>, supported: list<string>}>
     */
    private function logicalPorts(): array
    {
        $ports = [];
        $logical = 1;

        for ($physical = 1; $physical <= self::PHYSICAL_PORTS && $logical <= self::MAX_PORTS; $physical++) {
            // An unregistered port 1 still reports the system pad — the native
            // effectiveDeviceName() fallback.
            $name = $this->connected[$physical] ?? ($physical === 1 ? 'Gamepad' : null);
            $block = $name === null ? 1 : ($this->deviceTable()[$name]['block'] ?? 1);
            $fanOut = $block > 1;

            for ($i = 0; $i < $block && $logical <= self::MAX_PORTS; $i++, $logical++) {
                $device = $fanOut ? 'Gamepad' : $name;
                $descriptor = $device === null ? null : $this->descriptorFor($device);
                $ports[] = [
                    'port' => $logical,
                    'physical' => $physical,
                    'device' => $descriptor === null ? null : $device,
                    'buttons' => $descriptor['buttons'] ?? [],
                    'axes' => $descriptor['axes'] ?? [],
                    'supported' => $this->supportedDevices(),
                ];
            }
        }

        return $ports;
    }

    private function logicalPort(int $port): ?array
    {
        foreach ($this->logicalPorts() as $entry) {
            if ($entry['port'] === $port) {
                return $entry['device'] === null ? null : $entry;
            }
        }

        return null;
    }

    private function getPressedButtons(array $payload): string
    {
        $port = $payload['port'] ?? 1;
        $entry = $this->logicalPort($port);

        return json_encode([
            'port' => $port,
            'buttons' => $entry === null ? [] : array_values(array_filter(
                $entry['buttons'],
                fn (string $button) => isset($this->pressed[$port][$button]),
            )),
        ]);
    }

    private function loadSystem(array $payload): string
    {
        $this->systemLoaded = true;
        // Boot-only, like the real bridge: LoadSystem is the only writer.
        $this->pixelAccuracy = (bool) ($payload['config']['pixelAccuracy'] ?? false);

        return json_encode(['status' => 'loaded']);
    }

    private function getStatus(): string
    {
        $payload = ['status' => $this->status];

        // The real readback needs a bound core: present only once a ROM
        // booted, reporting what the boot bound (sfc exposes the choice).
        if ($this->systemLoaded && in_array($this->status, ['running', 'paused'], true)) {
            $payload['accuracy'] = $this->pixelAccuracy ? 'accurate' : 'performance';
        }

        return json_encode($payload);
    }

    private function getPorts(): string
    {
        if (! $this->systemLoaded) {
            return $this->error('SYSTEM_NOT_LOADED', 'Call LoadSystem before GetPorts');
        }

        $ports = array_map(
            fn (array $p) => array_diff_key($p, ['physical' => null]),
            $this->logicalPorts(),
        );

        return json_encode(['ports' => $ports]);
    }

    private function connectDevice(array $payload): string
    {
        if (! $this->systemLoaded) {
            return $this->error('SYSTEM_NOT_LOADED', 'no system is loaded');
        }

        $port = $payload['port'] ?? null;
        if (! is_int($port) || $port < 1 || $port > self::PHYSICAL_PORTS) {
            return $this->error('INVALID_PARAMETERS', 'invalid port for this system');
        }

        $device = $payload['device'] ?? '';
        if ($device === '') {
            unset($this->connected[$port]);

            return json_encode(['status' => 'connected', 'port' => $port, 'device' => '', 'ports' => [$port]]);
        }

        if (! in_array($device, $this->supportedDevices(), true)) {
            return $this->error('UNSUPPORTED_DEVICE', "device not supported: {$device}");
        }

        $this->connected[$port] = $device;
        $ports = array_values(array_map(
            fn (array $p) => $p['port'],
            array_filter($this->logicalPorts(), fn (array $p) => $p['physical'] === $port),
        ));

        return json_encode(['status' => 'connected', 'port' => $port, 'device' => $device, 'ports' => $ports]);
    }

    private function setInputMapping(array $payload): string
    {
        $port = $payload['port'] ?? null;
        if (! is_int($port)) {
            return $this->error('INVALID_PARAMETERS', 'port is required');
        }

        $entry = $this->logicalPort($port);
        if ($entry === null) {
            return $this->error('INVALID_PARAMETERS', 'no controller registered on this port');
        }

        $mappings = $payload['mappings'] ?? null;
        if (! is_array($mappings)) {
            return $this->error('INVALID_PARAMETERS', 'mappings map is required');
        }

        // Validate the whole batch first so a bad entry leaves the remap intact.
        $resolved = [];
        foreach ($mappings as $emulated => $source) {
            foreach ([$emulated, $source] as $name) {
                if ($this->bitForButtonName($entry['buttons'], (string) $name) === null) {
                    return $this->error('UNKNOWN_BUTTON', "Unknown button: {$name}");
                }
            }
            $resolved[strtolower((string) $emulated)] = $source;
        }

        if ($resolved === []) {
            unset($this->remap[$port]);
        } else {
            $this->remap[$port] = [...$this->remap[$port] ?? [], ...$resolved];
        }

        return json_encode(['status' => 'mapped', 'count' => count($resolved)]);
    }

    private function bitForButtonName(array $buttons, string $name): ?string
    {
        foreach ($buttons as $button) {
            if (strtolower($button) === strtolower($name)) {
                return $button;
            }
        }

        return null;
    }

    private function pressButton(array $payload, string $status): string
    {
        $entry = $this->logicalPort($payload['port'] ?? 1);
        $button = $payload['button'] ?? null;

        if (! is_string($button)) {
            return $this->error('INVALID_PARAMETERS', 'button is required');
        }
        if ($entry === null || $this->bitForButtonName($entry['buttons'], $button) === null) {
            return $this->error('UNKNOWN_BUTTON', "Unknown button: {$button}");
        }

        $port = $payload['port'] ?? 1;
        if ($status === 'pressed') {
            $this->pressed[$port][$button] = true;
        } else {
            unset($this->pressed[$port][$button]);
        }

        return json_encode(['status' => $status, 'button' => $button]);
    }

    private function setAxis(array $payload): string
    {
        $entry = $this->logicalPort($payload['port'] ?? 1);
        $axis = $payload['axis'] ?? null;

        if (! is_string($axis)) {
            return $this->error('INVALID_PARAMETERS', 'axis is required');
        }
        if ($entry === null || ! in_array($axis, $entry['axes'], true)) {
            return $this->error('INVALID_PARAMETERS', 'invalid parameters');
        }

        return json_encode(['status' => 'ok', 'axis' => $axis, 'value' => $payload['value'] ?? 0]);
    }

    private function aimAt(array $payload): string
    {
        $entry = $this->logicalPort($payload['port'] ?? 1);
        $x = $payload['x'] ?? null;
        $y = $payload['y'] ?? null;

        if (! is_numeric($x) || ! is_numeric($y)) {
            return $this->error('INVALID_PARAMETERS', 'x is required');
        }
        // Light-guns are the only devices exposing both axes.
        if ($entry === null || array_diff(['X', 'Y'], $entry['axes']) !== []) {
            return $this->error('INVALID_PARAMETERS', 'invalid parameters');
        }

        return json_encode(['status' => 'ok', 'x' => $x, 'y' => $y]);
    }

    private function stageSlot(array $payload): string
    {
        $path = $payload['path'] ?? null;
        if (! is_string($path)) {
            return $this->error('INVALID_PARAMETERS', 'path is required');
        }
        if (! is_file($path)) {
            return $this->operationalError('ROM_NOT_FOUND', "slot ROM not found: {$path}");
        }

        return json_encode(['status' => 'staged', 'index' => $payload['index'] ?? 0]);
    }

    private function stateSave(array $payload): string
    {
        $slot = $payload['slot'] ?? 1;

        // The slot's previous file moves aside before the new state lands, so
        // undoStateSave can revert it.
        if (isset($this->slots[$slot])) {
            $this->undoSave = ['slot' => $slot, 'state' => $this->slots[$slot]];
        }
        $this->slots[$slot] = $this->memory;

        return json_encode(['status' => 'saved', 'slot' => $slot, 'path' => "/states/{$slot}.state"]);
    }

    private function stateLoad(array $payload): string
    {
        $slot = $payload['slot'] ?? 1;

        // Snapshot before touching the slot, even when the slot turns out empty.
        $this->undoLoad = $this->memory;

        if (! isset($this->slots[$slot])) {
            return $this->operationalError('SLOT_EMPTY', "No state in slot {$slot}");
        }
        $this->memory = $this->slots[$slot];

        return json_encode(['status' => 'loaded', 'slot' => $slot]);
    }

    private function undoStateSave(): string
    {
        if ($this->undoSave === null) {
            return json_encode(['status' => 'nothing_to_undo']);
        }

        $this->slots[$this->undoSave['slot']] = $this->undoSave['state'];
        $slot = $this->undoSave['slot'];
        $this->undoSave = null;

        return json_encode(['status' => 'undone', 'slot' => $slot]);
    }

    private function undoStateLoad(): string
    {
        if ($this->undoLoad === null) {
            return json_encode(['status' => 'nothing_to_undo']);
        }

        $this->memory = $this->undoLoad;
        $this->undoLoad = null;

        return json_encode(['status' => 'undone']);
    }

    /**
     * The picture/audio knobs are whole percentages; out-of-range values are
     * refused rather than clamped (see the bridges' percent() helpers).
     */
    private function percentError(array $options, string $key, int $min, int $max): ?string
    {
        if (! is_numeric($options[$key] ?? null)) {
            return null;
        }
        $value = (float) $options[$key];

        return $value < $min || $value > $max
            ? $this->error('INVALID_PARAMETERS', "{$key} is a whole percentage ({$min}-{$max}, 100 = unchanged) — got {$value}")
            : null;
    }

    private function setVideo(array $payload): string
    {
        $options = $payload['options'] ?? [];

        return $this->percentError($options, 'luminance', 0, 100)
            ?? $this->percentError($options, 'saturation', 0, 100)
            ?? $this->percentError($options, 'gamma', 100, 200)
            ?? '{}';
    }

    private function setAudio(array $payload): string
    {
        $options = $payload['options'] ?? [];

        return $this->percentError($options, 'volume', 0, 100)
            ?? $this->percentError($options, 'balance', -100, 100)
            ?? '{}';
    }

    private function setShader(array $payload): string
    {
        $raw = $payload['path'] ?? null;
        $path = ($raw === null || $raw === 'none' || $raw === '') ? null : $raw;

        if ($path === null) {
            return json_encode(['status' => 'cleared']);
        }
        if (! is_file($path)) {
            return $this->operationalError('SHADER_FAILED', "Failed to load shader preset '{$path}'");
        }

        return json_encode(['status' => 'applied']);
    }

    private function addCheat(array $payload): string
    {
        $code = $payload['code'] ?? '';

        if (! preg_match('/^[0-9A-Fa-f]+:[0-9A-Fa-f]+(\+[0-9A-Fa-f]+:[0-9A-Fa-f]+)*$/', $code)) {
            return $this->operationalError('INVALID_CHEAT', "No valid ADDR:VALUE pairs in '{$code}'");
        }

        $this->cheats[$code] = true;

        return json_encode(['status' => 'added', 'code' => $code]);
    }

    private function removeCheat(array $payload): string
    {
        $code = $payload['code'] ?? '';
        $found = isset($this->cheats[$code]);
        unset($this->cheats[$code]);

        return json_encode(['status' => $found ? 'removed' : 'not_found', 'code' => $code]);
    }

    private function clearCheats(): string
    {
        $this->cheats = [];

        return json_encode(['status' => 'cleared']);
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

        if (array_key_exists('pixelAccuracy', $options)) {
            return $this->error('BOOT_ONLY_OPTION', 'pixelAccuracy can only be set in the LoadSystem config');
        }

        $unknown = array_diff(
            array_keys($options),
            ['speed', 'runAhead', 'rewind', 'rewindBufferSeconds', 'engineOptions'],
        );
        if ($unknown !== []) {
            return $this->error('INVALID_PARAMETERS', 'Configure does not accept '.reset($unknown));
        }

        if (! in_array($options['runAhead'] ?? 0, [0, 1], true)) {
            return $this->error('INVALID_PARAMETERS', 'runAhead must be 0 or 1');
        }

        if (array_key_exists('rewind', $options)) {
            $this->rewindEnabled = (bool) $options['rewind'];
            $this->rewinding = $this->rewindEnabled && $this->rewinding;
        }

        return '{}';
    }

    private function rewindJump(array $payload): string
    {
        if (! $this->rewindEnabled) {
            return $this->error('REWIND_DISABLED', 'rewind capture is off');
        }

        return json_encode(['jumped' => (int) ($payload['seconds'] ?? 10)]);
    }

    private function toggleRewind(): string
    {
        if (! $this->rewindEnabled) {
            return $this->error('REWIND_DISABLED', 'rewind capture is off');
        }

        $this->rewinding = ! $this->rewinding;

        return json_encode(['status' => $this->rewinding ? 'rewinding' : 'playing']);
    }

    /** Category A: a programmer error the PHP wrapper re-raises synchronously. */
    private function error(string $code, string $message): string
    {
        return json_encode(['status' => 'error', 'code' => $code, 'message' => $message, 'data' => []]);
    }

    /**
     * Category B: an operational outcome. The real bridge also dispatches an
     * EmulatorError here; the test harness feeds that event on the runner's wait.
     */
    private function operationalError(string $code, string $message): string
    {
        return json_encode(['status' => 'failed', 'code' => $code, 'message' => $message]);
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

    expect($declared)->toHaveCount(45)
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

it('fails an error step when the function unexpectedly succeeds', function () {
    $native = new FakeNative;
    $native->overrides['Emulator.SetInputMapping'] = fn () => '{}';
    $time = 0.0;
    $runner = makeRunner($native, $time);

    $state = drive($runner, ConformanceRunner::initialState('/roms/test.sfc'));

    $failed = failures($state);
    expect(array_column($failed, 'function'))->toContain('Emulator.SetInputMapping')
        ->and($failed[0]['detail'])->toContain('expected UNKNOWN_BUTTON');
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
