@php
    use App\Support\Catalog;

    $groups = $groups ?? ['dpad' => [], 'face' => [], 'shoulder' => [], 'system' => []];
    $has = fn ($b) => in_array($b, array_merge(...array_values($groups)), true);

    // Face-cluster arrangement per console, row-major top→bottom (null = gap).
    // Mirrors each pad's real layout; anything unexpected falls back to rows.
    $faceRows = match ($id) {
        'sfc' => [[null, 'X', null], ['Y', null, 'A'], [null, 'B', null]],
        'md' => [['X', 'Y', 'Z'], ['A', 'B', 'C']],
        'n64' => [[null, 'C-Up', null], ['C-Left', null, 'C-Right'], ['B', 'C-Down', null], ['A', null, null]],
        default => [[null, 'A'], ['B', null]],   // fc / gb / gbc / gba
    };
    $faceRows = array_values(array_filter(
        array_map(fn ($r) => array_map(fn ($b) => $b !== null && $has($b) ? $b : null, $r), $faceRows),
        fn ($r) => array_filter($r) !== [],
    ));

    // Anything the arrangement doesn't place (N64's Z trigger, oddballs from a
    // future port read) still gets a row — no button may be unreachable.
    $placed = array_merge(['L', 'R'], ...array_map(fn ($r) => array_filter($r), $faceRows));
    $stray = array_values(array_diff(array_merge($groups['face'], $groups['shoulder']), $placed));
    if ($stray !== []) {
        $faceRows[] = $stray;
    }

    $transport = [
        ['label' => $status === 'paused' ? '▶' : 'II', 'press' => 'togglePause', 'active' => $status === 'paused'],
        ['label' => 'Save', 'press' => 'saveState'],
        ['label' => 'Load', 'press' => 'loadState'],
        ['label' => 'Undo', 'press' => 'undo'],
        ['label' => 'RR', 'press' => 'rewind', 'active' => $rewinding],
        ['label' => $fastForward ? 'FF✓' : 'FF', 'press' => 'toggleFastForward', 'active' => $fastForward],
        ['label' => 'Spd−', 'press' => 'bumpSpeed(-0.25)'],
        ['label' => 'Spd+', 'press' => 'bumpSpeed(0.25)'],
        ['label' => 'Shot', 'press' => 'screenshot'],
    ];
    $transportBg = fn ($t) => ($t['active'] ?? false) ? 'bg-green-600/60'
        : ($flash === $t['label'] ? 'bg-blue-600/60' : 'bg-gray-800/60');

    // Round game buttons; d-pad arms are square-ish. Translucent so the game
    // reads through.
    $btn = 'w-16 h-16 rounded-full bg-gray-700/40 items-center justify-center';
    $pad = 'w-14 h-14 bg-gray-700/40 items-center justify-center';
    $lbl = 'text-white/90 text-sm text-center';
@endphp

{{-- The game fills the screen; controls hug the corners like a real handheld:
     shoulders top, d-pad cross bottom-left, face cluster bottom-right,
     select/start centered. Transport lives behind the ☰ toggle. --}}
<native:stack class="flex-1 bg-black">
    <native:emulator name="play" class="w-full h-full" />

    <native:column class="w-full h-full justify-between">
        {{-- Top edge: back + shoulders + menu/settings --}}
        <native:column class="gap-2">
            <native:row class="px-2 py-1 items-center justify-between">
                <native:button label="←" @press="leave" />
                @if ($error !== '')
                    <native:text class="text-red-400 text-sm">⚠ {{ $error }}</native:text>
                @endif
                <native:row class="gap-2 items-center">
                    <native:button label="☰" @press="toggleMenu" />
                    <native:button label="⚙" @press="openRomSettings" />
                </native:row>
            </native:row>

            @if ($has('L') || $has('R'))
                <native:row class="px-4 items-center justify-between">
                    @if ($has('L'))
                        <native:pressable class="w-36 py-2 rounded-full bg-gray-700/40 items-center" @pressDown="press('L')" @pressUp="release('L')">
                            <native:text class="{{ $lbl }}">L</native:text>
                        </native:pressable>
                    @else
                        <native:column class="w-4" />
                    @endif
                    @if ($has('R'))
                        <native:pressable class="w-36 py-2 rounded-full bg-gray-700/40 items-center" @pressDown="press('R')" @pressUp="release('R')">
                            <native:text class="{{ $lbl }}">R</native:text>
                        </native:pressable>
                    @else
                        <native:column class="w-4" />
                    @endif
                </native:row>
            @endif

            @if ($menuOpen)
                <native:row class="gap-2 px-4 flex-wrap">
                    @foreach ($transport as $t)
                        <native:pressable class="py-2 px-4 rounded-full {{ $transportBg($t) }}" @press="{{ $t['press'] }}">
                            <native:text class="{{ $lbl }}">{{ $t['label'] }}</native:text>
                        </native:pressable>
                    @endforeach
                </native:row>
            @endif
        </native:column>

        {{-- Bottom edge: d-pad cross · select/start · face cluster --}}
        <native:row class="px-4 pb-4 items-end justify-between">
            <native:column class="items-center">
                @foreach ([[null, 'Up', null], ['Left', null, 'Right'], [null, 'Down', null]] as $row)
                    <native:row>
                        @foreach ($row as $b)
                            @if ($b !== null && $has($b))
                                <native:pressable class="{{ $pad }} {{ $b === 'Up' ? 'rounded-t-lg' : ($b === 'Down' ? 'rounded-b-lg' : ($b === 'Left' ? 'rounded-l-lg' : 'rounded-r-lg')) }}" @pressDown="press('{{ $b }}')" @pressUp="release('{{ $b }}')">
                                    <native:text class="{{ $lbl }}">{{ ['Up' => '▲', 'Down' => '▼', 'Left' => '◀', 'Right' => '▶'][$b] }}</native:text>
                                </native:pressable>
                            @else
                                <native:column class="w-14 h-14" />
                            @endif
                        @endforeach
                    </native:row>
                @endforeach
            </native:column>

            <native:row class="gap-3 pb-2">
                @foreach ($groups['system'] as $b)
                    <native:pressable class="py-1 px-5 rounded-full bg-gray-700/40" @pressDown="press('{{ $b }}')" @pressUp="release('{{ $b }}')">
                        <native:text class="text-white/70 text-xs">{{ strtoupper($b) }}</native:text>
                    </native:pressable>
                @endforeach
            </native:row>

            <native:column class="items-center gap-1">
                @foreach ($faceRows as $row)
                    <native:row class="gap-1 items-center">
                        @foreach ($row as $b)
                            @if ($b !== null)
                                <native:pressable class="{{ $btn }}" @pressDown="press('{{ $b }}')" @pressUp="release('{{ $b }}')">
                                    <native:text class="{{ $lbl }}">{{ str_replace('C-', 'C', $b) }}</native:text>
                                </native:pressable>
                            @else
                                <native:column class="w-16 h-16" />
                            @endif
                        @endforeach
                    </native:row>
                @endforeach
            </native:column>
        </native:row>
    </native:column>
</native:stack>
