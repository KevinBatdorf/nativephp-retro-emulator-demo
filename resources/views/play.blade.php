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
    $face = "w-12 h-12 rounded-full items-center justify-center $ring";

    // Transport rows (chunked into pairs — lazy-grid mislays out in EDGE).
    $transport = [
        ['label' => $status === 'paused' ? '▶ Resume' : 'II Pause', 'press' => 'togglePause', 'active' => $status === 'paused'],
        ['label' => 'Save state', 'press' => 'saveState'],
        ['label' => 'Load state', 'press' => 'loadState'],
        ['label' => 'Undo load', 'press' => 'undo'],
        ['label' => $rewindEnabled ? ($rewinding ? 'Rewind ✓' : 'Rewind') : 'Rewind (off)', 'press' => 'rewind', 'active' => $rewinding],
        ['label' => $fastForward ? 'Fast-fwd ✓' : 'Fast-fwd', 'press' => 'toggleFastForward', 'active' => $fastForward],
        ['label' => 'Screenshot', 'press' => 'screenshot'],
    ];
    $tBg = fn ($t) => ($t['active'] ?? false) ? 'bg-green-600' : 'bg-gray-700';
@endphp

{{-- The game fills the screen; controls hug the corners like a real handheld.
     input-capture="global" installs a window-level gamepad capturer so the
     Thor's built-in pad + any paired BT controller drive the game regardless of
     view focus. Touch still reaches the overlay buttons. --}}
<native:stack class="flex-1 w-full h-full bg-black">
    <native:emulator name="play" input-capture="global" class="w-full h-full" />

    {{-- pt-8 clears the iPhone notch / Dynamic Island (Android is immersive). --}}
    <native:column class="w-full h-full justify-between">
        <native:column class="w-full gap-2">
            <native:row class="w-full px-3 pt-8 pb-1 items-center justify-between">
                <native:button label="Back" @press="leave" />
                @if ($error !== '')
                    <native:text class="text-red-400 text-sm">⚠ {{ $error }}</native:text>
                @endif
                <native:button label="☰ Menu" @press="toggleMenu" />
            </native:row>

            @if ($has('L') || $has('R'))
                <native:row class="w-full px-4 items-center justify-between">
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

        {{-- Bottom edge. Select/Start get their own centered row — three
             clusters in one row overflow a portrait phone's width. --}}
        <native:column class="w-full gap-2">
        <native:row class="w-full px-3 pb-5 items-end justify-between">
            {{-- One input area, not four buttons: the plugin's element resolves
                 the finger's position natively, so diagonals work and sliding
                 off the pad keeps walking. No PHP runs per press. --}}
            <native:dpad surface="play" class="w-36 h-36"
                :threshold="$dpadThreshold" :diagonal-ratio="$dpadDiagonalRatio" />

            <native:column class="items-center gap-1">
                {{-- Select/Start ride with the face cluster; centred on screen
                     they sat on top of the game. --}}
                <native:row class="gap-2 items-center pb-8">
                    @foreach ($groups['system'] as $b)
                        <native:pressable class="py-1 px-3 rounded-full {{ $ring }} {{ $bg($b, 'bg-gray-700/40') }}" @pressDown="press('{{ $b }}')" @pressUp="release('{{ $b }}')">
                            <native:text class="text-white/80 text-xs">{{ strtoupper($b) }}</native:text>
                        </native:pressable>
                    @endforeach
                </native:row>
                @foreach ($faceRows as $row)
                    <native:row class="gap-1 items-center">
                        @foreach ($row as $b)
                            @if ($b !== null)
                                <native:pressable class="{{ $face }} {{ $bg($b, $faceColor($b)) }}" @pressDown="press('{{ $b }}')" @pressUp="release('{{ $b }}')">
                                    <native:text class="{{ $lbl }}">{{ $b }}</native:text>
                                </native:pressable>
                            @else
                                <native:column class="w-12 h-12" />
                            @endif
                        @endforeach
                    </native:row>
                @endforeach
            </native:column>
        </native:row>
        </native:column>
    </native:column>

    {{-- In-place overlay: transport + settings over the paused game. Opening it
         never leaves this screen, so the emulator surface stays alive. Solid
         background (EDGE lazy-grid mislays out, so rows are explicit). --}}
    @if ($menuOpen)
        <native:column class="w-full h-full bg-gray-950">
            <native:row class="w-full px-4 pt-8 pb-2 items-center justify-between">
                <native:text class="text-white text-lg font-semibold">Menu</native:text>
                <native:button label="✕ Resume" @press="toggleMenu" />
            </native:row>

            <native:scroll-view class="flex-1 w-full">
                <native:column class="w-full px-4 pb-8 gap-5">
                    {{-- Transport — explicit pairs, uniform pill styling. --}}
                    <native:column class="w-full gap-2">
                        <native:text class="text-white text-base font-semibold">Playback</native:text>
                        @foreach (array_chunk($transport, 2) as $pairRow)
                            <native:row class="w-full gap-2">
                                @foreach ($pairRow as $t)
                                    <native:pressable native:key="{{ $t['press'] }}" class="flex-1 py-3 rounded-xl items-center {{ $tBg($t) }}" @press="{{ $t['press'] }}">
                                        <native:text class="{{ $lbl }}">{{ $t['label'] }}</native:text>
                                    </native:pressable>
                                @endforeach
                                @if (count($pairRow) === 1)
                                    <native:column class="flex-1" />
                                @endif
                            </native:row>
                        @endforeach
                    </native:column>

                    {{-- Shader — the headline visual feature. One global CRT
                         preset (crt-lottes) for the demo, so it's a toggle. --}}
                    <native:column class="w-full gap-2">
                        <native:text class="text-white text-base font-semibold">CRT shader</native:text>
                        {{-- EDGE toggle labels render near-invisible on dark bg;
                             carry every label as our own white text instead. --}}
                        <native:row class="w-full items-center justify-between">
                            <native:text class="text-gray-200 text-sm">crt-lottes — applies to every system</native:text>
                            <native:toggle label="" :value="$crt" @change="setCrt" />
                        </native:row>
                    </native:column>

                    {{-- Rewind costs CPU continuously while a game runs, so it
                         ships off and says so. --}}
                    <native:column class="w-full gap-2">
                        <native:text class="text-white text-base font-semibold">Rewind</native:text>
                        <native:row class="w-full items-center justify-between">
                            <native:text class="text-gray-200 text-sm">Capture history — costs CPU while playing</native:text>
                            <native:toggle label="" :value="$rewindEnabled" @change="setRewind" />
                        </native:row>
                    </native:column>

                    <native:column class="w-full gap-2">
                        <native:text class="text-white text-base font-semibold">Accurate rendering</native:text>
                        <native:row class="w-full items-center justify-between">
                            <native:text class="text-gray-200 text-sm">Dot/cycle renderer (SNES, GBA) — costs CPU</native:text>
                            <native:toggle label="" :value="$accurate" @change="setAccurate" />
                        </native:row>
                    </native:column>

                    {{-- Touch pad feel. Sliders carry percentages; the element
                         takes fractions of the pad's half-extent. --}}
                    <native:column class="w-full gap-1">
                        <native:text class="text-white text-base font-semibold">Touch pad</native:text>
                        <native:column class="w-full">
                            <native:text class="text-gray-200 text-sm">Engage threshold — {{ $dpadThreshold }}%</native:text>
                            <native:slider class="w-full" min="5" max="70" step="1" :value="$dpadThreshold" @change="setDpadThreshold" />
                        </native:column>
                        <native:column class="w-full">
                            <native:text class="text-gray-200 text-sm">Diagonal bias — {{ $dpadDiagonalRatio }}%</native:text>
                            <native:slider class="w-full" min="0" max="95" step="5" :value="$dpadDiagonalRatio" @change="setDpadDiagonalRatio" />
                        </native:column>
                    </native:column>

                    {{-- Picture — applies live on the running surface. --}}
                    <native:column class="w-full gap-3">
                        <native:text class="text-white text-base font-semibold">Picture</native:text>
                        <native:column class="w-full gap-1">
                            <native:text class="text-gray-200 text-sm">Luminance — {{ $luminance }}</native:text>
                            <native:slider class="w-full" min="0" max="100" step="5" :value="$luminance" @change="setLuminance" />
                        </native:column>
                        <native:column class="w-full gap-1">
                            <native:text class="text-gray-200 text-sm">Saturation — {{ $saturation }}</native:text>
                            <native:slider class="w-full" min="0" max="100" step="5" :value="$saturation" @change="setSaturation" />
                        </native:column>
                        <native:column class="w-full gap-1">
                            <native:text class="text-gray-200 text-sm">Gamma — {{ $gamma }}</native:text>
                            <native:slider class="w-full" min="50" max="200" step="5" :value="$gamma" @change="setGamma" />
                        </native:column>
                        <native:row class="w-full items-center justify-between">
                            <native:text class="text-gray-200 text-sm">Show overscan borders</native:text>
                            <native:toggle label="" :value="$overscan" @change="setOverscan" />
                        </native:row>
                    </native:column>

                    {{-- Audio. --}}
                    <native:column class="w-full gap-1">
                        <native:text class="text-white text-base font-semibold">Audio</native:text>
                        <native:text class="text-gray-200 text-sm">Volume — {{ $volume }}</native:text>
                        <native:slider class="w-full" min="0" max="100" step="5" :value="$volume" @change="setVolume" />
                    </native:column>

                    {{-- Per-system options that visibly change the image. --}}
                    @if ($toggleLabels !== [])
                        <native:column class="w-full gap-2">
                            <native:text class="text-white text-base font-semibold">{{ strtoupper($id) }} options</native:text>
                            @foreach ($toggleLabels as $field => $label)
                                <native:row native:key="{{ $field }}" class="w-full items-center justify-between">
                                    <native:text class="text-gray-200 text-sm">{{ $label }}</native:text>
                                    <native:toggle label="" :value="$toggles[$field] ?? false" @change="setToggle('{{ $field }}')" />
                                </native:row>
                            @endforeach
                        </native:column>
                    @endif

                    {{-- Which hardware controllers the OS reports as paired. --}}
                    <native:column class="w-full gap-2">
                        <native:text class="text-white text-base font-semibold">Controllers</native:text>
                        @forelse ($controllers as $controller)
                            <native:text native:key="{{ $loop->index }}" class="text-gray-200 text-sm">🎮 {{ $controller }}</native:text>
                        @empty
                            <native:text class="text-gray-400 text-sm">No hardware controller paired — using on-screen controls.</native:text>
                        @endforelse
                    </native:column>

                    @if ($rebootNeeded)
                        {{-- Plain "and": EDGE renders label text verbatim (no
                             HTML-entity decode), so &amp; shows literally. --}}
                        <native:button label="Apply and reboot" @press="applyReboot" />
                        <native:text class="text-gray-300 text-xs">Shader + system options take effect on reboot.</native:text>
                    @endif

                    <native:button label="Reset to defaults" color="#f87171" @press="resetSettings" />

                    {{-- Under the hood: the exact config handed to Emulator::loadSystem. --}}
                    <native:column class="w-full gap-1">
                        <native:text class="text-white text-base font-semibold">Config (dev view)</native:text>
                        <native:text class="text-gray-400 text-xs">The exact array Emulator::loadSystem() receives.</native:text>
                        <native:column class="w-full p-3 rounded-lg bg-gray-900">
                            <native:text class="text-green-300 text-xs">{{ $configJson }}</native:text>
                        </native:column>
                    </native:column>
                </native:column>
            </native:scroll-view>
        </native:column>
    @endif
</native:stack>
