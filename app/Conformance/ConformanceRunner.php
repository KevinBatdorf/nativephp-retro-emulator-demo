<?php

namespace App\Conformance;

use Closure;
use KevinBatdorf\RetroEmulator\Buttons\SfcButton;
use KevinBatdorf\RetroEmulator\Events\EmulatorError;
use KevinBatdorf\RetroEmulator\Events\EmulatorPaused;
use KevinBatdorf\RetroEmulator\Events\EmulatorResumed;
use KevinBatdorf\RetroEmulator\Events\EmulatorStarted;
use KevinBatdorf\RetroEmulator\Events\EmulatorStopped;
use KevinBatdorf\RetroEmulator\Events\MemoryChanged;
use KevinBatdorf\RetroEmulator\Events\MemoryRead;

/**
 * Device-side conformance suite for the retro-emulator plugin: drives every
 * bridge function against the live native layer and records pass/fail.
 *
 * All state lives in a plain array (component props serialize between poll
 * ticks), so this class holds no run state — tick() and recordEvent() take
 * the state and return the next one.
 */
class ConformanceRunner
{
    private const SURFACE = 'main';

    /** WRAM the hello-world test ROM leaves untouched. */
    private const SCRATCH_ADDRESS = 0x7E1F00;

    private const WATCHED_ADDRESS = 0x7E1F10;

    private Closure $bridge;

    private Closure $now;

    public function __construct(?Closure $bridge = null, ?Closure $now = null)
    {
        $this->bridge = $bridge ?? static fn (string $function, string $payload): ?string => function_exists('nativephp_call')
            ? nativephp_call($function, $payload)
            : null;
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    /**
     * @return array{romPath: string, stepIndex: int, results: array, waiting: ?array, events: array, finished: bool}
     */
    public static function initialState(string $romPath): array
    {
        return [
            'romPath' => $romPath,
            'stepIndex' => 0,
            'results' => [],
            'waiting' => null,
            'events' => [],
            'finished' => false,
        ];
    }

    public function recordEvent(array $state, string $class, array $payload): array
    {
        $state['events'][] = ['class' => $class, 'payload' => $payload];

        return $state;
    }

    public function tick(array $state): array
    {
        if ($state['finished']) {
            return $state;
        }

        if ($state['waiting'] !== null) {
            $state = $this->settleWait($state);

            if ($state['waiting'] !== null) {
                return $state;
            }
        }

        $steps = $this->steps($state['romPath']);

        while (! $state['finished'] && $state['waiting'] === null) {
            if ($state['stepIndex'] >= count($steps)) {
                $state['finished'] = true;

                break;
            }

            $step = $steps[$state['stepIndex']];
            $state['stepIndex']++;

            $outcome = ($step['run'])();

            if (isset($outcome['wait'])) {
                $state['waiting'] = [
                    ...$outcome['wait'],
                    'label' => $step['label'],
                    'function' => $step['function'],
                    'deadline' => ($this->now)() + $outcome['wait']['timeout'],
                    'since' => count($state['events']),
                ];
            } else {
                $state['results'][] = $outcome;
            }
        }

        return $state;
    }

    private function settleWait(array $state): array
    {
        $waiting = $state['waiting'];

        foreach (array_slice($state['events'], $waiting['since']) as $event) {
            if ($event['class'] !== $waiting['event']) {
                continue;
            }

            $expects = $waiting['expects'] ?? [];

            // Loose compare: JSON payloads may deliver ints as strings.
            if ($expects !== [] && array_intersect_key($event['payload'], $expects) != $expects) {
                continue;
            }

            $state['results'][] = $this->pass($waiting['label'], $waiting['function'], 'event received');
            $state['waiting'] = null;

            return $state;
        }

        if (($this->now)() > $waiting['deadline']) {
            $state['results'][] = $this->fail(
                $waiting['label'],
                $waiting['function'],
                'timed out waiting for '.class_basename($waiting['event']),
            );
            $state['waiting'] = null;

            return $state;
        }

        if (($waiting['poke'] ?? null) !== null) {
            $this->{$waiting['poke']}();
        }

        return $state;
    }

    /**
     * Re-write the watched byte while waiting for MemoryChanged: the native
     * watch takes a silent baseline on its first frame, so a single write can
     * land before the baseline and never register as a change.
     */
    private function toggleWatchedByte(): void
    {
        $value = ((int) (($this->now)() * 10)) % 2 === 0 ? 0x55 : 0xAA;

        $this->call('Emulator.WriteMemory', [
            'surface' => self::SURFACE,
            'address' => self::WATCHED_ADDRESS,
            'bytes' => [$value],
        ]);
    }

    /**
     * @return list<array{label: string, function: string, run: Closure}>
     */
    private function steps(string $romPath): array
    {
        $surface = ['surface' => self::SURFACE];

        return [
            $this->callStep('GetSystems lists sfc as supported', 'Emulator.GetSystems', [], function (?array $r) {
                $systems = $r['systems'] ?? [];
                $sfc = array_values(array_filter($systems, fn ($s) => ($s['id'] ?? null) === 'sfc'));

                if ($sfc === [] || ! ($sfc[0]['supported'] ?? false)) {
                    return 'sfc missing or unsupported: '.json_encode($systems);
                }

                return null;
            }),
            $this->okStep('Boot binds the surface', 'Emulator.Boot', $surface),
            $this->statusStep('Status is stopped before load', 'stopped'),
            // This leg exercises ares-specific surface (accuracy renderers,
            // deepBlackBoost) — pin the engine so the app's preference map
            // can't route sfc to a fetched core underneath the assertions.
            $this->okStep('LoadSystem initialises sfc', 'Emulator.LoadSystem', [
                ...$surface, 'system' => 'sfc', 'config' => ['autoSave' => false, 'backend' => 'ares'],
            ]),
            // Controllers are explicit: register a gamepad so GetPorts
            // reports its buttons. The registration persists across the boot below.
            $this->okStep('ConnectDevice a gamepad on port 1', 'Emulator.ConnectDevice', [
                ...$surface, 'port' => 1, 'device' => 'Gamepad',
            ]),
            $this->callStep('GetPorts reports the connected gamepad buttons', 'Emulator.GetPorts', $surface, function (?array $r) {
                $buttons = $r['ports'][0]['buttons'] ?? [];
                $enum = array_map(fn ($case) => $case->value, SfcButton::cases());
                sort($buttons);
                sort($enum);

                return $buttons === $enum && ($r['ports'][0]['device'] ?? null) === 'Gamepad'
                    ? null
                    : 'port 1 gamepad buttons drifted from SfcButton: '.json_encode($r['ports'] ?? null);
            }),
            $this->okStep('LoadRom accepts the ROM', 'Emulator.LoadRom', [...$surface, 'path' => $romPath]),
            $this->waitStep('EmulatorStarted fires on first frame', 'Emulator.LoadRom', EmulatorStarted::class, timeout: 15),
            $this->statusStep('Status is running after start', 'running'),
            $this->callStep('GetRegion returns a region', 'Emulator.GetRegion', $surface, function (?array $r) {
                return ($r['region'] ?? '') !== '' ? null : 'empty region: '.json_encode($r);
            }),
            $this->callStep('ReadMemory returns bytes', 'Emulator.ReadMemory', [
                ...$surface, 'address' => self::SCRATCH_ADDRESS, 'length' => 2,
            ], function (?array $r) {
                return count($r['bytes'] ?? []) === 2 ? null : 'expected 2 bytes: '.json_encode($r);
            }),
            [
                'label' => 'WriteMemory round-trips',
                'function' => 'Emulator.WriteMemory',
                'run' => function () use ($surface) {
                    $write = $this->call('Emulator.WriteMemory', [
                        ...$surface, 'address' => self::SCRATCH_ADDRESS, 'bytes' => [0xAB],
                    ]);

                    if ($this->isError($write) || $write === null) {
                        return $this->fail('WriteMemory round-trips', 'Emulator.WriteMemory', $this->describe($write));
                    }

                    $read = $this->call('Emulator.ReadMemory', [
                        ...$surface, 'address' => self::SCRATCH_ADDRESS, 'length' => 1,
                    ]);

                    return ($read['bytes'][0] ?? null) == 0xAB
                        ? $this->pass('WriteMemory round-trips', 'Emulator.WriteMemory', 'wrote 0xAB, read it back')
                        : $this->fail('WriteMemory round-trips', 'Emulator.WriteMemory', 'read back '.json_encode($read));
                },
            ],
            $this->okStep('ReadMemoryAsync dispatches', 'Emulator.ReadMemoryAsync', [
                ...$surface, 'address' => self::SCRATCH_ADDRESS, 'length' => 1,
            ]),
            $this->waitStep('MemoryRead event arrives', 'Emulator.ReadMemoryAsync', MemoryRead::class, timeout: 5, expects: [
                'address' => self::SCRATCH_ADDRESS,
            ]),
            $this->okStep('WatchMemory registers a watch', 'Emulator.WatchMemory', [
                ...$surface, 'addresses' => [self::WATCHED_ADDRESS],
            ]),
            $this->waitStep('MemoryChanged fires on change', 'Emulator.WatchMemory', MemoryChanged::class, timeout: 10, expects: [
                'address' => self::WATCHED_ADDRESS,
            ], poke: 'toggleWatchedByte'),
            $this->okStep('UnwatchMemory removes the watch', 'Emulator.UnwatchMemory', [
                ...$surface, 'addresses' => [self::WATCHED_ADDRESS],
            ]),
            $this->okStep('ClearMemoryWatches succeeds', 'Emulator.ClearMemoryWatches', $surface),
            $this->okStep('Pause succeeds', 'Emulator.Pause', $surface),
            $this->waitStep('EmulatorPaused fires', 'Emulator.Pause', EmulatorPaused::class, timeout: 5),
            $this->statusStep('Status is paused', 'paused'),
            $this->okStep('Resume succeeds', 'Emulator.Resume', $surface),
            $this->waitStep('EmulatorResumed fires', 'Emulator.Resume', EmulatorResumed::class, timeout: 5),
            $this->statusStep('Status is running after resume', 'running'),
            $this->okStep('StateSave to slot 1', 'Emulator.StateSave', [...$surface, 'slot' => 1]),
            $this->okStep('StateLoad from slot 1', 'Emulator.StateLoad', [...$surface, 'slot' => 1]),
            [
                // save(0x11) → save(0x22) → undoSave reverts the slot file to
                // the 0x11 state — okStep alone once hid a permanent no-op
                // (nothing wrote the undo files and okStep accepted it).
                'label' => 'UndoStateSave reverts the slot file',
                'function' => 'Emulator.UndoStateSave',
                'run' => function () use ($surface) {
                    $label = 'UndoStateSave reverts the slot file';
                    $fn = 'Emulator.UndoStateSave';
                    $this->call('Emulator.WriteMemory', [...$surface, 'address' => self::SCRATCH_ADDRESS, 'bytes' => [0x11]]);
                    $this->call('Emulator.StateSave', [...$surface, 'slot' => 1]);
                    $this->call('Emulator.WriteMemory', [...$surface, 'address' => self::SCRATCH_ADDRESS, 'bytes' => [0x22]]);
                    $this->call('Emulator.StateSave', [...$surface, 'slot' => 1]);
                    $undo = $this->call($fn, $surface);
                    if ($this->isError($undo) || $undo === null) {
                        return $this->fail($label, $fn, $this->describe($undo));
                    }
                    $this->call('Emulator.StateLoad', [...$surface, 'slot' => 1]);
                    $read = $this->call('Emulator.ReadMemory', [...$surface, 'address' => self::SCRATCH_ADDRESS, 'length' => 1]);

                    return ($read['bytes'][0] ?? null) == 0x11
                        ? $this->pass($label, $fn, 'slot file reverted to the pre-overwrite state')
                        : $this->fail($label, $fn, 'slot loaded '.json_encode($read).' — expected the 0x11 state');
                },
            ],
            [
                // load (state carries 0x11) over live 0x33 → undoLoad restores
                // the pre-load 0x33 state.
                'label' => 'UndoStateLoad restores the pre-load state',
                'function' => 'Emulator.UndoStateLoad',
                'run' => function () use ($surface) {
                    $label = 'UndoStateLoad restores the pre-load state';
                    $fn = 'Emulator.UndoStateLoad';
                    $this->call('Emulator.WriteMemory', [...$surface, 'address' => self::SCRATCH_ADDRESS, 'bytes' => [0x33]]);
                    $this->call('Emulator.StateLoad', [...$surface, 'slot' => 1]);
                    $mid = $this->call('Emulator.ReadMemory', [...$surface, 'address' => self::SCRATCH_ADDRESS, 'length' => 1]);
                    if (($mid['bytes'][0] ?? null) != 0x11) {
                        return $this->fail($label, $fn, 'StateLoad did not restore the 0x11 state: '.json_encode($mid));
                    }
                    $undo = $this->call($fn, $surface);
                    if ($this->isError($undo) || $undo === null) {
                        return $this->fail($label, $fn, $this->describe($undo));
                    }
                    $read = $this->call('Emulator.ReadMemory', [...$surface, 'address' => self::SCRATCH_ADDRESS, 'length' => 1]);

                    return ($read['bytes'][0] ?? null) == 0x33
                        ? $this->pass($label, $fn, 'pre-load state restored')
                        : $this->fail($label, $fn, 'read back '.json_encode($read).' — expected the 0x33 state');
                },
            ],
            $this->okStep('Screenshot captures a frame', 'Emulator.Screenshot', $surface),
            $this->okStep('SetAudio merges volume/balance', 'Emulator.SetAudio', [
                ...$surface, 'options' => ['volume' => 80, 'balance' => 0],
            ]),
            $this->okStep('SetVideo merges options', 'Emulator.SetVideo', [
                ...$surface, 'options' => ['luminance' => 90, 'saturation' => 100],
            ]),
            // The picture knobs are whole percentages. A caller passing ares'
            // 1.0-2.0 gamma exponent instead gets told, rather than a black screen.
            $this->errorStep('SetVideo rejects a gamma passed as a multiplier', 'Emulator.SetVideo', [
                ...$surface, 'options' => ['gamma' => 1],
            ], code: 'INVALID_PARAMETERS'),
            $this->okStep('SetVideo accepts gamma as a percentage', 'Emulator.SetVideo', [
                ...$surface, 'options' => ['gamma' => 150],
            ]),
            $this->okStep('SetVideo restores neutral gamma', 'Emulator.SetVideo', [
                ...$surface, 'options' => ['gamma' => 100],
            ]),
            $this->errorStep('SetAudio rejects an out-of-range volume', 'Emulator.SetAudio', [
                ...$surface, 'options' => ['volume' => 400],
            ], code: 'INVALID_PARAMETERS'),
            $this->okStep('Configure speed 2.0', 'Emulator.Configure', [...$surface, 'options' => ['speed' => 2.0]]),
            $this->okStep('Configure speed back to 1.0', 'Emulator.Configure', [...$surface, 'options' => ['speed' => 1.0]]),
            $this->okStep('Configure runAhead 1 enables', 'Emulator.Configure', [
                ...$surface, 'options' => ['runAhead' => 1],
            ]),
            $this->errorStep('Configure runAhead 2 rejected', 'Emulator.Configure', [
                ...$surface, 'options' => ['runAhead' => 2],
            ], code: 'INVALID_PARAMETERS'),
            $this->okStep('Configure runAhead 0 disables', 'Emulator.Configure', [
                ...$surface, 'options' => ['runAhead' => 0],
            ]),
            $this->errorStep('ToggleRewind requires capture enabled', 'Emulator.ToggleRewind', $surface,
                code: 'REWIND_DISABLED'),
            $this->okStep('Configure rewind enables capture', 'Emulator.Configure', [
                ...$surface, 'options' => ['rewind' => true, 'rewindBufferSeconds' => 10],
            ]),
            $this->rewindRoundTripStep($surface),
            $this->okStep('Configure rewind disables capture', 'Emulator.Configure', [
                ...$surface, 'options' => ['rewind' => false],
            ]),
            // Accuracy is boot-only (it picks the renderer at load); status
            // reads back which renderer the boot actually bound.
            $this->errorStep('Configure pixelAccuracy rejected post-boot', 'Emulator.Configure', [
                ...$surface, 'options' => ['pixelAccuracy' => true],
            ], code: 'BOOT_ONLY_OPTION'),
            $this->callStep('Status reports the performance renderer', 'Emulator.GetStatus', $surface,
                fn (?array $r) => ($r['accuracy'] ?? null) === 'performance'
                    ? null
                    : 'expected accuracy "performance", got '.json_encode($r)),
            $this->okStep('SetSystemOptions merges a per-system toggle', 'Emulator.SetSystemOptions', [
                ...$surface, 'options' => ['deepBlackBoost' => true],
            ]),
            $this->okStep('FastForward on', 'Emulator.FastForward', [...$surface, 'enabled' => true]),
            $this->okStep('FastForward off', 'Emulator.FastForward', [...$surface, 'enabled' => false]),
            // Gamepad already registered on port 1 (persists from the earlier
            // ConnectDevice), so these input steps have a device to drive.
            $this->okStep('SetInputMapping swaps A and B', 'Emulator.SetInputMapping', [
                ...$surface, 'port' => 1, 'mappings' => ['a' => 'b', 'b' => 'a'],
            ]),
            // An unknown button is a category-A programmer error: a synchronous
            // UNKNOWN_BUTTON bridge error (the PHP wrapper re-raises it), not an
            // event — remapping is implemented now.
            $this->errorStep('SetInputMapping rejects an unknown button', 'Emulator.SetInputMapping', [
                ...$surface, 'port' => 1, 'mappings' => ['a' => 'nope'],
            ], code: 'UNKNOWN_BUTTON'),
            $this->okStep('SetInputMapping empty map resets the port', 'Emulator.SetInputMapping', [
                ...$surface, 'port' => 1, 'mappings' => [],
            ]),
            // Device selection + the axis input channel (mouse).
            $this->errorStep('ConnectDevice rejects an unsupported device', 'Emulator.ConnectDevice', [
                ...$surface, 'port' => 1, 'device' => 'Twin Tap',
            ], code: 'UNSUPPORTED_DEVICE'),
            $this->okStep('ConnectDevice a Mouse on port 2', 'Emulator.ConnectDevice', [
                ...$surface, 'port' => 2, 'device' => 'Mouse',
            ]),
            $this->okStep('SetAxis feeds a mouse motion delta', 'Emulator.SetAxis', [
                ...$surface, 'port' => 2, 'axis' => 'X', 'value' => -8,
            ]),
            $this->errorStep('SetAxis rejects an unknown axis', 'Emulator.SetAxis', [
                ...$surface, 'port' => 2, 'axis' => 'Z', 'value' => 1,
            ], code: 'INVALID_PARAMETERS'),
            $this->okStep('PressButton the mouse Left button', 'Emulator.PressButton', [
                ...$surface, 'port' => 2, 'button' => 'Left',
            ]),
            $this->okStep('ReleaseButton the mouse Left button', 'Emulator.ReleaseButton', [
                ...$surface, 'port' => 2, 'button' => 'Left',
            ]),
            // Light-gun: swap the mouse for a Super Scope, aim it, pull trigger.
            $this->okStep('ConnectDevice a Super Scope on port 2', 'Emulator.ConnectDevice', [
                ...$surface, 'port' => 2, 'device' => 'Super Scope',
            ]),
            $this->okStep('AimAt centres the light-gun', 'Emulator.AimAt', [
                ...$surface, 'port' => 2, 'x' => 0.5, 'y' => 0.5,
            ]),
            $this->errorStep('AimAt rejects a device without axes', 'Emulator.AimAt', [
                ...$surface, 'port' => 1, 'x' => 0.5, 'y' => 0.5,
            ], code: 'INVALID_PARAMETERS'),
            $this->okStep('PressButton the Super Scope trigger', 'Emulator.PressButton', [
                ...$surface, 'port' => 2, 'button' => 'Trigger',
            ]),
            // Super Multitap: port 2 fans out to four players → logical ports 2-5.
            $this->callStep('ConnectDevice a Super Multitap fans out to 4 players', 'Emulator.ConnectDevice', [
                ...$surface, 'port' => 2, 'device' => 'Super Multitap',
            ], fn (?array $r) => ($r['ports'] ?? null) === [2, 3, 4, 5]
                ? null
                : 'expected ports [2,3,4,5], got '.json_encode($r['ports'] ?? null)),
            $this->callStep('GetPorts reports five logical players', 'Emulator.GetPorts', $surface, function (?array $r) {
                $ports = array_map(fn ($p) => $p['port'], $r['ports'] ?? []);

                return $ports === [1, 2, 3, 4, 5]
                    ? null
                    : 'expected logical ports 1..5, got '.json_encode($ports);
            }),
            $this->okStep('PressButton multitap player 4', 'Emulator.PressButton', [
                ...$surface, 'port' => 4, 'button' => 'A',
            ]),
            $this->okStep('ReleaseButton multitap player 4', 'Emulator.ReleaseButton', [
                ...$surface, 'port' => 4, 'button' => 'A',
            ]),
            $this->okStep('SetRumble enables forwarding', 'Emulator.SetRumble', [...$surface, 'enabled' => true]),
            $this->callStep('SetRumble disables and reports vibrator', 'Emulator.SetRumble', [
                ...$surface, 'enabled' => false,
            ], fn (?array $r) => ($r['status'] ?? null) === 'disabled' && array_key_exists('hasVibrator', $r ?? [])
                ? null
                : 'expected status=disabled with hasVibrator, got '.json_encode($r)),
            // Category B: a failed preset returns "failed" and dispatches an
            // EmulatorError, so the fluent command still returns cleanly.
            $this->callStep('SetShader reports a bad preset as failed', 'Emulator.SetShader', [
                ...$surface, 'path' => '/data/local/tmp/nonexistent.slangp',
            ], fn (?array $r) => ($r['status'] ?? null) === 'failed' && ($r['code'] ?? null) === 'SHADER_FAILED'
                ? null
                : 'expected failed/SHADER_FAILED, got '.json_encode($r)),
            $this->waitStep('EmulatorError fires for the bad preset', 'Emulator.SetShader', EmulatorError::class, timeout: 5, expects: [
                'code' => 'SHADER_FAILED',
            ]),
            $this->okStep('SetShader null clears', 'Emulator.SetShader', [...$surface, 'path' => null]),
            $this->okStep('AddCheat registers a valid code', 'Emulator.AddCheat', [
                ...$surface, 'code' => '7E1F00:01+7E1F01:FF', 'description' => 'conformance',
            ]),
            // A malformed cheat is an operational outcome (category B): the
            // bridge returns "failed" and dispatches an EmulatorError event,
            // rather than a synchronous bridge error.
            $this->callStep('AddCheat reports a malformed code as failed', 'Emulator.AddCheat', [
                ...$surface, 'code' => 'not-a-cheat', 'description' => 'conformance',
            ], fn (?array $r) => ($r['status'] ?? null) === 'failed' && ($r['code'] ?? null) === 'INVALID_CHEAT'
                ? null
                : 'expected failed/INVALID_CHEAT, got '.json_encode($r)),
            $this->waitStep('EmulatorError fires for the malformed cheat', 'Emulator.AddCheat', EmulatorError::class, timeout: 5, expects: [
                'code' => 'INVALID_CHEAT',
            ]),
            $this->callStep('RemoveCheat removes the active code', 'Emulator.RemoveCheat', [
                ...$surface, 'code' => '7E1F00:01+7E1F01:FF',
            ], fn (?array $r) => ($r['status'] ?? null) === 'removed'
                ? null
                : 'status is '.json_encode($r['status'] ?? null).', expected removed'),
            $this->callStep('RemoveCheat reports unknown codes', 'Emulator.RemoveCheat', [
                ...$surface, 'code' => '7E1F02:00',
            ], fn (?array $r) => ($r['status'] ?? null) === 'not_found'
                ? null
                : 'status is '.json_encode($r['status'] ?? null).', expected not_found'),
            $this->okStep('ClearCheats succeeds', 'Emulator.ClearCheats', $surface),
            [
                // Self-contained: nothing else in the run leaves a button held,
                // so this presses one, reads it back, and releases it.
                'label' => 'GetPressedButtons reports what is held',
                'function' => 'Emulator.GetPressedButtons',
                'run' => function () use ($surface) {
                    $label = 'GetPressedButtons reports what is held';
                    $fn = 'Emulator.GetPressedButtons';
                    $port = [...$surface, 'port' => 1];

                    $idle = $this->call($fn, $port);
                    if ($idle === null || $this->isError($idle)) {
                        return $this->fail($label, $fn, $this->describe($idle));
                    }
                    if (($idle['buttons'] ?? []) !== []) {
                        return $this->fail($label, $fn, 'expected nothing held, got '.json_encode($idle['buttons']));
                    }

                    $this->call('Emulator.PressButton', [...$port, 'button' => 'Start']);
                    $held = $this->call($fn, $port);
                    $this->call('Emulator.ReleaseButton', [...$port, 'button' => 'Start']);

                    return in_array('Start', $held['buttons'] ?? [], true)
                        ? $this->pass($label, $fn, 'reported Start while held')
                        : $this->fail($label, $fn, 'expected Start held, got '.json_encode($held['buttons'] ?? null));
                },
            ],
            $this->callStep('GetInputDevices lists hardware pads', 'Emulator.GetInputDevices', $surface,
                // Empty is a valid answer — the on-screen overlay works either way.
                fn (?array $r) => is_array($r['devices'] ?? null)
                    ? null
                    : 'expected a devices array, got '.json_encode($r)),
            // Staging a real Sufami Turbo slot ROM needs media this app does not
            // bundle, so only the missing-path contract is deterministic here.
            $this->callStep('StageSlot reports a missing slot ROM as failed', 'Emulator.StageSlot', [
                ...$surface, 'index' => 0, 'path' => '/data/local/tmp/nonexistent.st',
            ], fn (?array $r) => ($r['status'] ?? null) === 'failed' && ($r['code'] ?? null) === 'ROM_NOT_FOUND'
                ? null
                : 'expected failed/ROM_NOT_FOUND, got '.json_encode($r)),
            $this->waitStep('EmulatorError fires for the missing slot ROM', 'Emulator.StageSlot', EmulatorError::class, timeout: 5, expects: [
                'code' => 'ROM_NOT_FOUND',
            ]),
            $this->okStep('PressButton Start', 'Emulator.PressButton', [...$surface, 'port' => 1, 'button' => 'Start']),
            $this->okStep('ReleaseButton Start', 'Emulator.ReleaseButton', [...$surface, 'port' => 1, 'button' => 'Start']),
            $this->okStep('SetButtons merges state', 'Emulator.SetButtons', [
                ...$surface, 'port' => 1, 'state' => ['Up' => true],
            ]),
            $this->okStep('SetButtons clears state', 'Emulator.SetButtons', [
                ...$surface, 'port' => 1, 'state' => ['Up' => false],
            ]),
            // Boot-only means a reboot honors it: boot the accurate PPU (its
            // first-ever execution in this plugin), read the binding back from
            // the core, then reboot to the performance default.
            $this->okStep('LoadSystem accepts pixelAccuracy', 'Emulator.LoadSystem', [
                ...$surface, 'system' => 'sfc', 'config' => ['autoSave' => false, 'pixelAccuracy' => true, 'backend' => 'ares'],
            ]),
            $this->okStep('LoadRom reboots under the accurate PPU', 'Emulator.LoadRom', [...$surface, 'path' => $romPath]),
            $this->waitStep('EmulatorStarted fires under the accurate PPU', 'Emulator.LoadRom', EmulatorStarted::class, timeout: 15),
            $this->callStep('Status reports the accurate renderer', 'Emulator.GetStatus', $surface,
                fn (?array $r) => ($r['accuracy'] ?? null) === 'accurate'
                    ? null
                    : 'expected accuracy "accurate", got '.json_encode($r)),
            $this->okStep('LoadSystem restores the performance default', 'Emulator.LoadSystem', [
                ...$surface, 'system' => 'sfc', 'config' => ['autoSave' => false, 'backend' => 'ares'],
            ]),
            $this->okStep('LoadRom reboots under the performance PPU', 'Emulator.LoadRom', [...$surface, 'path' => $romPath]),
            $this->waitStep('EmulatorStarted fires after the reboot back', 'Emulator.LoadRom', EmulatorStarted::class, timeout: 15),
            $this->callStep('Status reports performance after the reboot back', 'Emulator.GetStatus', $surface,
                fn (?array $r) => ($r['accuracy'] ?? null) === 'performance'
                    ? null
                    : 'expected accuracy "performance", got '.json_encode($r)),
            $this->okStep('Stop tears down', 'Emulator.Stop', $surface),
            $this->waitStep('EmulatorStopped fires', 'Emulator.Stop', EmulatorStopped::class, timeout: 5),
            $this->statusStep('Status is stopped after stop', 'stopped'),
        ];
    }

    private function okStep(string $label, string $function, array $payload): array
    {
        return $this->callStep($label, $function, $payload, fn () => null);
    }

    /**
     * Enter and exit rewind playback in one step, back to back. Playback
     * drains history at 5× the capture rate and auto-resumes play when it
     * empties — after that, every toggle re-enters and reports "rewinding",
     * so an exit toggle in a later step races the drain and can never be
     * made deterministic. Within one step the gap is milliseconds; the
     * sleep first banks ~1 s of history so it cannot drain inside that gap.
     */
    private function rewindRoundTripStep(array $surface): array
    {
        $label = 'ToggleRewind enters rewind then returns to play';

        return [
            'label' => $label,
            'function' => 'Emulator.ToggleRewind',
            'run' => function () use ($label, $surface) {
                usleep(1_000_000);

                $enter = $this->call('Emulator.ToggleRewind', $surface);
                if ($enter === null || $this->isError($enter)) {
                    return $this->fail($label, 'Emulator.ToggleRewind', $this->describe($enter));
                }
                if (($enter['status'] ?? null) !== 'rewinding') {
                    return $this->fail($label, 'Emulator.ToggleRewind',
                        'enter status is '.json_encode($enter['status'] ?? null).', expected rewinding');
                }

                $exit = $this->call('Emulator.ToggleRewind', $surface);
                if ($exit === null || $this->isError($exit)) {
                    return $this->fail($label, 'Emulator.ToggleRewind', $this->describe($exit));
                }

                return ($exit['status'] ?? null) === 'playing'
                    ? $this->pass($label, 'Emulator.ToggleRewind')
                    : $this->fail($label, 'Emulator.ToggleRewind',
                        'exit status is '.json_encode($exit['status'] ?? null).', expected playing');
            },
        ];
    }

    /**
     * @param  Closure(?array): ?string  $check  returns a failure detail, or null to pass
     */
    private function callStep(string $label, string $function, array $payload, Closure $check): array
    {
        return [
            'label' => $label,
            'function' => $function,
            'run' => function () use ($label, $function, $payload, $check) {
                $response = $this->call($function, $payload);

                if ($response === null || $this->isError($response)) {
                    return $this->fail($label, $function, $this->describe($response));
                }

                $detail = $check($response);

                return $detail === null
                    ? $this->pass($label, $function)
                    : $this->fail($label, $function, $detail);
            },
        ];
    }

    private function errorStep(string $label, string $function, array $payload, string $code): array
    {
        return [
            'label' => $label,
            'function' => $function,
            'run' => function () use ($label, $function, $payload, $code) {
                $response = $this->call($function, $payload);

                return ($response['status'] ?? null) === 'error' && ($response['code'] ?? null) === $code
                    ? $this->pass($label, $function, "errors with {$code} as documented")
                    : $this->fail($label, $function, "expected {$code} error, got ".json_encode($response));
            },
        ];
    }

    private function statusStep(string $label, string $expected): array
    {
        return $this->callStep($label, 'Emulator.GetStatus', ['surface' => self::SURFACE], function (?array $r) use ($expected) {
            return ($r['status'] ?? null) === $expected
                ? null
                : 'status is '.json_encode($r['status'] ?? null).", expected {$expected}";
        });
    }

    private function waitStep(string $label, string $function, string $event, int $timeout, array $expects = [], ?string $poke = null): array
    {
        return [
            'label' => $label,
            'function' => $function,
            'run' => fn () => ['wait' => [
                'event' => $event,
                'timeout' => $timeout,
                'expects' => $expects,
                'poke' => $poke,
            ]],
        ];
    }

    private function call(string $function, array $payload): ?array
    {
        $raw = ($this->bridge)($function, json_encode($payload === [] ? new \stdClass : $payload));

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function isError(?array $response): bool
    {
        return ($response['status'] ?? null) === 'error' && isset($response['code']);
    }

    private function describe(?array $response): string
    {
        if ($response === null) {
            return 'no response from bridge';
        }

        return ($response['code'] ?? 'error').': '.($response['message'] ?? json_encode($response));
    }

    private function pass(string $label, string $function, string $detail = ''): array
    {
        return ['label' => $label, 'function' => $function, 'status' => 'pass', 'detail' => $detail];
    }

    private function fail(string $label, string $function, string $detail): array
    {
        return ['label' => $label, 'function' => $function, 'status' => 'fail', 'detail' => $detail];
    }
}
