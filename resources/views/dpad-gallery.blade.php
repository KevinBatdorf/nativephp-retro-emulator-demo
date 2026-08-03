{{-- The ball's field is the fixed full-bleed backdrop; the pads scroll over it.
     Every pad steers the ball, so each variation can be felt as well as seen.

     Motion is native: each pad integrates its held direction into the two
     SharedValues below on the frame clock, and the ball's translate binds to
     them. PHP only hears about direction changes, never frames. --}}
<native:stack class="flex-1 w-full h-full bg-gray-950">
    <native:column class="w-full h-full">
        {{-- mt-24 clears the header, so translate 0 still shows the ball. --}}
        <native:column class="w-8 h-8 rounded-full bg-red-500 ml-6 mt-24"
            :translate-x="$ballX" :translate-y="$ballY" />
    </native:column>

    <native:column class="w-full h-full">
        <native:row class="w-full px-4 pt-8 pb-1 items-center justify-between">
            <native:button label="Back" @press="leave" />
            <native:text class="text-white text-lg font-semibold">D-pad demo</native:text>
            <native:text class="text-gray-400 text-xs w-24 text-right">{{ $heldDirections ?: '—' }}</native:text>
        </native:row>

        {{-- Each pad sets only the prop named under it, so the middle of every
             row is the shipped default. --}}
        <native:scroll-view class="flex-1 w-full">
            <native:column class="w-full px-3 pt-2 pb-8 gap-4">
                @foreach ($rows as [$heading, $pads])
                    <native:column class="w-full gap-1">
                        <native:text class="text-gray-400 text-xs">{{ $heading }}</native:text>
                        <native:row class="w-full items-start justify-between">
                            @foreach ($pads as [$label, $attrs])
                                <native:column class="items-center gap-1">
                                    <native:dpad
                                        class="w-20 h-20"
                                        @change="steer"
                                        :pan-x="$ballX" :pan-y="$ballY"
                                        {{-- Bounds must fit the narrowest surface (iPhone portrait) or the ball leaves the screen. --}}
                                        pan-x-min="0" pan-x-max="300"
                                        pan-y-min="0" pan-y-max="280"
                                        :threshold="$attrs['threshold'] ?? null"
                                        :diagonal-ratio="$attrs['diagonalRatio'] ?? null"
                                        :thickness="$attrs['thickness'] ?? null"
                                        :radius="$attrs['radius'] ?? null"
                                        :diagonals="$attrs['diagonals'] ?? null"
                                        :color="$attrs['color'] ?? null"
                                        :active-color="$attrs['activeColor'] ?? null" />
                                    <native:text class="text-gray-300 text-xs text-center">{{ $label }}</native:text>
                                </native:column>
                            @endforeach
                        </native:row>
                    </native:column>
                @endforeach
            </native:column>
        </native:scroll-view>
    </native:column>
</native:stack>
