@php
    $arrows = ['Left' => '◀', 'Up' => '▲', 'Down' => '▼', 'Right' => '▶'];

    // Flat, ordered game buttons: d-pad (arrow glyphs) then shoulder/face/system.
    $game = [];
    foreach ($arrows as $name => $arrow) {
        if (in_array($name, $groups['dpad'], true)) {
            $game[] = ['key' => $name, 'label' => $arrow];
        }
    }
    // Compact glyphs for the system buttons; press/release still use the real key.
    $glyphs = ['Select' => '−', 'Start' => '+'];
    foreach (['shoulder', 'face', 'system'] as $g) {
        foreach ($groups[$g] as $b) {
            $game[] = ['key' => $b, 'label' => $glyphs[$b] ?? $b];
        }
    }

    // `active` toggles stay lit green; plain actions flash for one tick on press.
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

    // Half-opaque so the emulator shows through the pad.
    $pill = 'flex-1 items-center justify-center rounded-full py-3 px-2';

    // Transport: green while a toggle is active, blue for a one-tick action
    // flash, gray otherwise.
    $transportBg = function ($t) use ($flash) {
        if ($t['active'] ?? false) {
            return 'bg-green-600/50';
        }

        return $flash === $t['label'] ? 'bg-blue-600/50' : 'bg-gray-700/50';
    };
@endphp

{{-- Emulator fills the screen; the chrome floats on top so the ROM stays the
     full surface, not a letterboxed sliver. The overlay column is transparent —
     only the pills are opaque — so the game shows through around the controls. --}}
<native:stack class="flex-1 bg-black">
    <native:emulator name="play" class="w-full h-full" />

    <native:column class="w-full h-full justify-between">
        {{-- Top bar: back to ROM picker + ROM settings --}}
        <native:row class="px-2 py-1 items-center justify-between">
            <native:button label="← Back" @press="leave" />
            @if ($error !== '')
                <native:text class="text-red-400 text-sm">⚠ {{ $error }}</native:text>
            @endif
            <native:button label="⚙ Settings" @press="openRomSettings" />
        </native:row>

        {{-- Chunked rows of equal-width pills: reflow to fixed rows so the pad
             stays visible on any screen width, no horizontal scroll. Game
             buttons use @pressDown/@pressUp for real press-and-hold. --}}
        <native:column class="gap-3 p-3">
            @foreach (array_chunk($game, 6) as $rowButtons)
                <native:row class="gap-3">
                    @foreach ($rowButtons as $b)
                        <native:pressable class="{{ $pill }} bg-gray-700/50" @pressDown="press('{{ $b['key'] }}')" @pressUp="release('{{ $b['key'] }}')">
                            <native:text class="text-white text-center">{{ $b['label'] }}</native:text>
                        </native:pressable>
                    @endforeach
                </native:row>
            @endforeach

            @foreach (array_chunk($transport, 5) as $rowButtons)
                <native:row class="gap-3">
                    @foreach ($rowButtons as $b)
                        <native:pressable class="{{ $pill }} {{ $transportBg($b) }}" @press="{{ $b['press'] }}">
                            <native:text class="text-white text-center">{{ $b['label'] }}</native:text>
                        </native:pressable>
                    @endforeach
                </native:row>
            @endforeach
        </native:column>
    </native:column>
</native:stack>
