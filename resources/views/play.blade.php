@php
    use App\Support\Catalog;

    $groups = $groups ?? ['dpad' => [], 'face' => [], 'shoulder' => [], 'system' => []];
    $has = fn ($b) => in_array($b, array_merge(...array_values($groups)), true);

    // Face-cluster arrangement per console, row-major top→bottom (null = gap).
    $faceRows = match ($id) {
        'sfc' => [[null, 'X', null], ['Y', null, 'A'], [null, 'B', null]],
        'md' => [['X', 'Y', 'Z'], ['A', 'B', 'C']],
        default => [[null, 'A'], ['B', null]],   // fc / gb / gbc / gba
    };
    $faceRows = array_values(array_filter(
        array_map(fn ($r) => array_map(fn ($b) => $b !== null && $has($b) ? $b : null, $r), $faceRows),
        fn ($r) => array_filter($r) !== [],
    ));

    // Per-button face colors, styled like the console's own pad. USA palettes.
    $faceColor = fn ($b) => match ([$id, $b]) {
        ['sfc', 'A'], ['sfc', 'B'] => 'bg-rose-600/55',
        ['sfc', 'X'], ['sfc', 'Y'] => 'bg-indigo-600/55',
        ['md', 'A'], ['md', 'B'], ['md', 'C'],
        ['md', 'X'], ['md', 'Y'], ['md', 'Z'] => 'bg-gray-800/55',
        default => str_starts_with($b, 'C-') ? 'bg-yellow-500/60' : 'bg-gray-700/45',
    };

    // A held button (finger down) lights up bright white — the reactive-demo
    // highlight Kevin asked for. `ring` utilities don't render in EDGE, so the
    // highlight is a background swap (colored bg-* provably renders).
    $bg = fn ($b, $rest) => ($held[$b] ?? false) ? 'bg-white' : $rest;

    $ring = 'border-2 border-white/80';
    $lbl = 'text-white text-sm text-center font-semibold';
    $face = "w-11 h-11 rounded-full items-center justify-center $ring";

    // Transport rows (explicit chunked rows — lazy-grid mislays out in EDGE).
    $transport = [
        ['label' => '−10s', 'press' => 'rewindBack', 'enabled' => $rewindEnabled],
        ['label' => $status === 'paused' ? 'Resume' : 'Pause', 'press' => 'togglePause', 'active' => $status === 'paused'],
        ['label' => 'Screenshot', 'press' => 'screenshot'],
    ];
    $tBg = fn ($t) => ! ($t['enabled'] ?? true) ? 'bg-gray-800'
        : (($t['active'] ?? false) ? 'bg-green-600' : 'bg-gray-700');
    $tLbl = fn ($t) => ! ($t['enabled'] ?? true)
        ? 'text-gray-600 text-sm text-center font-semibold'
        : 'text-white text-sm text-center font-semibold';
@endphp

{{-- The game fills the screen; controls hug the corners like a real handheld.
     input-capture="global" installs a window-level gamepad capturer so the
     a device's built-in pad + any paired BT controller drive the game regardless of
     view focus. Touch still reaches the overlay buttons. --}}
<native:stack class="flex-1 w-full h-full bg-black">
    <native:emulator name="play" input-capture="global" class="w-full h-full" />

    {{-- pt-8 clears the iPhone notch / Dynamic Island (Android is immersive). --}}
    <native:column class="w-full h-full justify-between">
        <native:column class="w-full gap-2">
            {{-- px-5: buttons flush with the screen edge sit in the rounded
                 corners / rotated-island shadow and miss taps. --}}
            <native:row class="w-full px-5 pt-8 pb-1 items-center justify-between">
                <native:button label="Back" @press="leave" />
                @if ($error !== '')
                    <native:text class="text-red-400 text-sm">⚠ {{ $error }}</native:text>
                @endif
                <native:button label="☰ Menu" @press="toggleMenu" />
            </native:row>

            @if ($has('L') || $has('R'))
                <native:row class="w-full px-5 items-center justify-between">
                    @if ($has('L'))
                        <native:pressable class="w-28 py-2 rounded-full items-center {{ $ring }} {{ $bg('L', 'bg-gray-700/40') }}" @pressDown="press('L')" @pressUp="release('L')">
                            <native:text class="{{ $lbl }}">L</native:text>
                        </native:pressable>
                    @else
                        <native:column class="w-4" />
                    @endif
                    @if ($has('R'))
                        <native:pressable class="w-28 py-2 rounded-full items-center {{ $ring }} {{ $bg('R', 'bg-gray-700/40') }}" @pressDown="press('R')" @pressUp="release('R')">
                            <native:text class="{{ $lbl }}">R</native:text>
                        </native:pressable>
                    @else
                        <native:column class="w-4" />
                    @endif
                </native:row>
            @endif
        </native:column>

        <native:column class="w-full gap-2">
        <native:row class="w-full px-5 pb-6 items-end justify-between">
            {{-- One input area, not four buttons: the plugin's element resolves
                 the finger's position natively, so diagonals work and sliding
                 off the pad keeps walking. No PHP runs per press. --}}
            {{-- Spacer columns, not margins: EDGE margin utilities don't move
                 native surface elements. w-9 + the row's px-5 ≈ the iPhone's
                 59pt landscape safe-area, so the clusters clear the island. --}}
            <native:row class="items-end">
                <native:column class="w-9" />
                <native:dpad surface="play" class="w-32 h-32"
                    :threshold="$dpadThreshold" :diagonal-ratio="$dpadDiagonalRatio" />
            </native:row>

            <native:row class="items-end">
            <native:column class="items-center gap-1">
                @foreach ($faceRows as $row)
                    <native:row class="gap-1 items-center">
                        @foreach ($row as $b)
                            @if ($b !== null)
                                <native:pressable class="{{ $face }} {{ $bg($b, $faceColor($b)) }}" @pressDown="press('{{ $b }}')" @pressUp="release('{{ $b }}')">
                                    <native:text class="{{ $lbl }}">{{ $b }}</native:text>
                                </native:pressable>
                            @else
                                <native:column class="w-11 h-11" />
                            @endif
                        @endforeach
                    </native:row>
                @endforeach
            </native:column>
                <native:column class="w-9" />
            </native:row>
        </native:row>

        {{-- Select/Start under the game's letterbox — a full-width centered
             row also can't overflow portrait. pb-9 lifts it out of the iPhone
             home-indicator gesture zone, which eats taps. --}}
        @if (count($groups['system']))
            <native:row class="w-full pb-9 items-center justify-center gap-3">
                @foreach ($groups['system'] as $b)
                    <native:pressable class="py-1 px-3 rounded-full {{ $ring }} {{ $bg($b, 'bg-gray-700/40') }}" @pressDown="press('{{ $b }}')" @pressUp="release('{{ $b }}')">
                        <native:text class="text-white/80 text-xs">{{ strtoupper($b) }}</native:text>
                    </native:pressable>
                @endforeach
            </native:row>
        @endif
        </native:column>
    </native:column>

    {{-- In-place overlay: transport + settings over the paused game. Opening it
         never leaves this screen, so the emulator surface stays alive. Solid
         background (EDGE lazy-grid mislays out, so rows are explicit). --}}
    @if ($menuOpen)
        <native:column class="w-full h-full bg-gray-950">
            <native:row class="w-full px-5 pt-8 pb-2 items-center justify-between">
                <native:text class="text-white text-lg font-semibold">Menu</native:text>
                <native:button label="✕ Resume" @press="toggleMenu" />
            </native:row>

            {{-- Pinned outside the scroll so a change made anywhere is never
                 announced below the fold. --}}
            @if ($pending !== [])
                <native:row class="w-full px-5 py-2 items-center justify-between bg-amber-900">
                    <native:text class="text-amber-200 text-sm">{{ count($pending) }} {{ count($pending) === 1 ? 'change applies' : 'changes apply' }} on reboot</native:text>
                    <native:button label="Reboot now" @press="applyReboot" />
                </native:row>
            @endif

            <native:scroll-view class="flex-1 w-full">
                <native:column class="w-full px-5 pb-10 gap-4">
                    {{-- Transport — explicit pairs, uniform pill styling. --}}
                    <native:column class="w-full gap-2">
                        <native:text class="text-gray-400 text-xs font-semibold">PLAYBACK</native:text>
                        @foreach (array_chunk($transport, 3) as $chipRow)
                            <native:row class="w-full gap-2">
                                @foreach ($chipRow as $t)
                                    <native:pressable native:key="{{ $t['press'] }}" class="flex-1 py-3 rounded-xl items-center {{ $tBg($t) }}" @press="{{ $t['press'] }}">
                                        <native:text class="{{ $tLbl($t) }}">{{ $t['label'] }}</native:text>
                                    </native:pressable>
                                @endforeach
                                @for ($i = count($chipRow); $i < 3; $i++)
                                    <native:column class="flex-1" />
                                @endfor
                            </native:row>
                        @endforeach
                        @unless ($rewindEnabled)
                            <native:text class="text-gray-500 text-xs">−10s needs Rewind enabled (All systems, below)</native:text>
                        @endunless
                    </native:column>

                    <native:column class="w-full gap-2">
                        <native:text class="text-gray-400 text-xs font-semibold">SPEED</native:text>
                        <native:row class="w-full gap-2">
                            @foreach (['0.5', '1', '1.5', '2'] as $choice)
                                <native:pressable native:key="sp-{{ $choice }}"
                                    class="flex-1 py-2 rounded-full items-center border border-white/10 {{ (float) $choice === $speed ? 'bg-green-600' : 'bg-gray-800' }}"
                                    @press="setSpeedChoice('{{ $choice }}')">
                                    <native:text class="text-white text-sm text-center">{{ $choice }}x</native:text>
                                </native:pressable>
                            @endforeach
                        </native:row>
                    </native:column>

                    <native:column class="w-full gap-2">
                        <native:text class="text-gray-400 text-xs font-semibold">GAME</native:text>
                        <native:text class="text-gray-200 text-sm">Playing {{ $romName !== '' ? $romName : '(nothing)' }}</native:text>
                        <native:pressable class="w-full py-3 rounded-xl items-center bg-gray-700" @press="pickRom">
                            <native:text class="{{ $lbl }}">Load ROM…</native:text>
                        </native:pressable>
                        <native:text class="text-gray-500 text-xs">Pick a ROM for this system — zips are unpacked, a wrong file fails to load</native:text>
                    </native:column>

                    {{-- ── Save states: one button in, three timestamped slots out. ── --}}
                    @if ($gates['saves'])
                        <native:column class="w-full gap-2">
                            <native:text class="text-gray-400 text-xs font-semibold">SAVE STATES</native:text>
                            <native:pressable class="w-full py-3 rounded-xl items-center bg-gray-700" @press="saveStateNow">
                                <native:text class="{{ $lbl }}">Save current state</native:text>
                            </native:pressable>
                            @foreach ($saves as $save)
                                <native:row native:key="save-{{ $save['slot'] }}" class="w-full items-center justify-between px-1">
                                    <native:text class="text-gray-200 text-sm">Saved {{ date('g:i:s A', $save['at']) }}</native:text>
                                    <native:button label="Restore" @press="restoreState('{{ $save['slot'] }}')" />
                                </native:row>
                            @endforeach
                        </native:column>
                    @endif

                    {{-- ── System scope: everything here affects only this console. ── --}}
                    <native:text class="text-gray-400 text-xs font-semibold pt-2">THIS SYSTEM — {{ strtoupper(\App\Support\Catalog::shortName($id)) }}</native:text>

                    <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-2">
                        <native:text class="text-white text-base font-semibold">Engine</native:text>
                        @if ($benchEngine !== '')
                            <native:text class="text-gray-400 text-sm">Forced to {{ $benchEngine }} by the audio bench for this boot.</native:text>
                        @else
                            @foreach (array_chunk($engineChips, 3) as $chipRow)
                                <native:row class="w-full gap-2">
                                    @foreach ($chipRow as $chip)
                                        @if ($chip['available'])
                                            <native:pressable native:key="be-{{ $chip['name'] }}"
                                                class="flex-1 py-2 rounded-full items-center border border-white/10 {{ $engineSelected === $chip['name'] ? 'bg-green-600' : 'bg-gray-800' }}"
                                                @press="selectBackend('{{ $chip['name'] }}')">
                                                <native:text class="text-white text-sm text-center">{{ $chip['name'] }}</native:text>
                                            </native:pressable>
                                        @else
                                            <native:column native:key="be-{{ $chip['name'] }}"
                                                class="flex-1 py-2 rounded-full items-center border border-white/5 bg-gray-900">
                                                <native:text class="text-gray-600 text-sm text-center">{{ $chip['name'] }}</native:text>
                                            </native:column>
                                        @endif
                                    @endforeach
                                    @for ($i = count($chipRow); $i < 3; $i++)
                                        <native:column class="flex-1" />
                                    @endfor
                                </native:row>
                            @endforeach
                            @foreach ($engineChips as $chip)
                                @unless ($chip['available'])
                                    <native:text class="text-gray-500 text-xs">{{ $chip['name'] }} is Android-only</native:text>
                                @endunless
                            @endforeach
                            @isset ($pending['backend'])
                                <native:text class="text-amber-400 text-xs">Takes effect on reboot</native:text>
                            @endisset
                        @endif
                    </native:column>

                    @if ($bootOptionRows !== [])
                        <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-2">
                            <native:text class="text-white text-base font-semibold">Boot options</native:text>
                            @foreach ($bootOptionRows as $field => $row)
                                <native:row native:key="boot-{{ $field }}" class="w-full items-center justify-between">
                                    <native:column class="gap-0">
                                        <native:text class="{{ $row['enabled'] ? 'text-gray-200' : 'text-gray-600' }} text-sm">{{ $row['label'] }}</native:text>
                                        <native:text class="text-gray-500 text-xs">{{ $row['help'] }}{{ $row['note'] !== '' ? ' · '.$row['note'] : '' }}</native:text>
                                    </native:column>
                                    @if ($row['enabled'])
                                        <native:toggle label="" :value="$row['value']" @change="setBootOption('{{ $field }}')" />
                                    @else
                                        <native:text class="text-gray-600 text-xs">n/a</native:text>
                                    @endif
                                </native:row>
                                @isset ($pending[$field === 'pixelAccuracy' ? 'pixelAccuracy' : $field])
                                    <native:text class="text-amber-400 text-xs">Takes effect on reboot</native:text>
                                @endisset
                            @endforeach
                        </native:column>
                    @endif

                    @if ($toggleRows !== [])
                        <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-2">
                            <native:text class="text-white text-base font-semibold">{{ \App\Support\Catalog::shortName($id) }} options</native:text>
                            @foreach ($toggleRows as $field => $row)
                                <native:row native:key="{{ $field }}" class="w-full items-center justify-between">
                                    <native:column class="gap-0">
                                        <native:text class="{{ $row['enabled'] ? 'text-gray-200' : 'text-gray-600' }} text-sm">{{ $row['label'] }}</native:text>
                                        @if ($row['note'] !== '')
                                            <native:text class="text-gray-500 text-xs">{{ $row['note'] }}</native:text>
                                        @endif
                                    </native:column>
                                    @if ($row['enabled'])
                                        <native:toggle label="" :value="$toggles[$field] ?? false" @change="setToggle('{{ $field }}')" />
                                    @else
                                        <native:text class="text-gray-600 text-xs">n/a</native:text>
                                    @endif
                                </native:row>
                            @endforeach
                        </native:column>
                    @endif

                    {{-- ── Global scope: shared by every console. ── --}}
                    <native:text class="text-gray-400 text-xs font-semibold pt-2">ALL SYSTEMS</native:text>

                    <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-2">
                        {{-- EDGE toggle labels render near-invisible on dark bg;
                             carry every label as our own text instead. --}}
                        <native:row class="w-full items-center justify-between">
                            <native:column class="gap-0">
                                <native:text class="text-white text-base font-semibold">CRT shader</native:text>
                                <native:text class="text-gray-400 text-xs">crt-lottes · applies instantly</native:text>
                            </native:column>
                            <native:toggle label="" :value="$crt" @change="setCrt" />
                        </native:row>
                        <native:row class="w-full items-center justify-between">
                            <native:column class="gap-0">
                                <native:text class="text-white text-base font-semibold">Rewind</native:text>
                                <native:text class="text-gray-400 text-xs">Costs CPU while playing · history starts when enabled</native:text>
                            </native:column>
                            <native:toggle label="" :value="$rewindEnabled" @change="setRewind" />
                        </native:row>
                    </native:column>

                    <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-3">
                        <native:column class="gap-0">
                            <native:text class="text-white text-base font-semibold">Picture</native:text>
                            <native:text class="text-gray-400 text-xs">All systems · applies instantly</native:text>
                        </native:column>
                        @if ($gates['picture'])
                            <native:column class="w-full gap-1">
                                <native:text class="text-gray-200 text-sm">Luminance — {{ $luminance }}% {{ $luminance === 100 ? '(unchanged)' : '' }}</native:text>
                                <native:slider class="w-full" min="0" max="100" step="5" native:model.debounce.300ms="luminance" />
                            </native:column>
                            <native:column class="w-full gap-1">
                                <native:text class="text-gray-200 text-sm">Saturation — {{ $saturation }}% {{ $saturation === 100 ? '(unchanged)' : '' }}</native:text>
                                <native:slider class="w-full" min="0" max="100" step="5" native:model.debounce.300ms="saturation" />
                            </native:column>
                            <native:row class="w-full items-center justify-between">
                                <native:text class="text-gray-200 text-sm">Show overscan borders</native:text>
                                <native:toggle label="" :value="$overscan" @change="setOverscan" />
                            </native:row>
                        @else
                            <native:text class="text-gray-400 text-sm">{{ $gates['pictureNote'] }}</native:text>
                        @endif
                    </native:column>

                    <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-3">
                        <native:text class="text-white text-base font-semibold">Audio</native:text>
                        <native:column class="w-full gap-1">
                            <native:text class="text-gray-200 text-sm">Volume — {{ $volume }}%</native:text>
                            <native:slider class="w-full" min="0" max="100" step="5" native:model.debounce.300ms="volume" />
                        </native:column>
                        <native:column class="w-full gap-1">
                            <native:text class="text-gray-200 text-sm">Balance — {{ $balance === 0 ? 'centred' : ($balance < 0 ? abs($balance).'% left' : $balance.'% right') }}</native:text>
                            <native:slider class="w-full" min="-100" max="100" step="10" native:model.debounce.300ms="balance" />
                        </native:column>
                    </native:column>

                    {{-- The on-screen d-pad element's feel. Sliders carry
                         percentages; the element takes fractions. --}}
                    <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-3">
                        <native:column class="gap-0">
                            <native:text class="text-white text-base font-semibold">On-screen D-pad</native:text>
                            <native:text class="text-gray-400 text-xs">Applies instantly</native:text>
                        </native:column>
                        <native:column class="w-full gap-1">
                            <native:text class="text-gray-200 text-sm">Dead zone — {{ $dpadThreshold }}%</native:text>
                            <native:text class="text-gray-500 text-xs">How far you push before a direction registers</native:text>
                            <native:slider class="w-full" min="5" max="90" step="1" native:model.debounce.300ms="dpadThreshold" />
                        </native:column>
                        <native:column class="w-full gap-1">
                            <native:text class="text-gray-200 text-sm">Cardinal snap — {{ $dpadDiagonalRatio }}%</native:text>
                            <native:text class="text-gray-500 text-xs">Higher keeps ↑ ↓ ← → clean · 0% = free diagonals</native:text>
                            <native:slider class="w-full" min="0" max="95" step="5" native:model.debounce.300ms="dpadDiagonalRatio" />
                        </native:column>
                    </native:column>

                    {{-- Which hardware controllers the OS reports as paired. --}}
                    <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-2">
                        <native:text class="text-white text-base font-semibold">Controllers</native:text>
                        @forelse ($controllers as $controller)
                            <native:text native:key="{{ $loop->index }}" class="text-gray-200 text-sm">🎮 {{ $controller }}</native:text>
                        @empty
                            <native:text class="text-gray-400 text-sm">No hardware controller paired — using on-screen controls.</native:text>
                        @endforelse
                    </native:column>

                    <native:button label="Reset to defaults" color="#f87171" @press="resetSettings" />

                    {{-- ── Dev: what we asked for vs what's actually running. ── --}}
                    <native:text class="text-gray-400 text-xs font-semibold pt-2">DEV</native:text>

                    <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-2">
                        <native:column class="gap-0">
                            <native:text class="text-white text-base font-semibold">Config</native:text>
                            <native:text class="text-gray-400 text-xs">Booted values · pending reboot changes in amber</native:text>
                        </native:column>
                        @foreach ($devRows as $group => $rows)
                            <native:text native:key="dg-{{ $group }}" class="text-gray-500 text-xs font-semibold pt-1">{{ strtoupper($group) }}</native:text>
                            @foreach ($rows as $row)
                                <native:row native:key="dv-{{ $row['key'] }}" class="w-full justify-between">
                                    <native:text class="text-gray-300 text-xs">{{ $row['key'] }}</native:text>
                                    <native:text class="{{ $row['pending'] ? 'text-amber-300' : 'text-green-300' }} text-xs">{{ $row['display'] }}</native:text>
                                </native:row>
                            @endforeach
                        @endforeach
                    </native:column>

                    @if ($devState !== [])
                        <native:column class="w-full p-4 rounded-2xl bg-gray-900 gap-2">
                            <native:column class="gap-0">
                                <native:text class="text-white text-base font-semibold">State</native:text>
                                <native:text class="text-gray-400 text-xs">Read back from the running core when the menu opened</native:text>
                            </native:column>
                            @foreach ($devState as $key => $value)
                                <native:row native:key="ds-{{ $key }}" class="w-full justify-between">
                                    <native:text class="text-gray-300 text-xs">{{ $key }}</native:text>
                                    <native:text class="text-teal-300 text-xs">{{ $value }}</native:text>
                                </native:row>
                            @endforeach
                        </native:column>
                    @endif
                </native:column>
            </native:scroll-view>
        </native:column>
    @endif
</native:stack>
