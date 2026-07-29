{{-- The ball's field is the fixed full-bleed backdrop; the pads scroll over it.
     Every pad steers the ball, so each variation can be felt as well as seen.

     The ball is offset with margin rather than the circle element's own left/top
     props: this host build's CircleRenderer is a bare Box and ignores them. --}}
<native:stack class="flex-1 w-full h-full bg-gray-950">
    <native:column class="w-full h-full">
        <native:column class="w-6 h-6 rounded-full bg-red-500 ml-[{{ (int) $ballX }}] mt-[{{ (int) $ballY }}]" />
    </native:column>

    <native:column class="w-full h-full">
        <native:row class="w-full px-4 pt-8 pb-1 items-center justify-between">
            <native:button label="Back" @press="leave" />
            <native:text class="text-white text-lg font-semibold">D-pad demo</native:text>
            <native:text class="text-gray-400 text-xs w-44 text-right">{{ $heldDirections ?: '—' }} · {{ (int) $ballX }},{{ (int) $ballY }}</native:text>
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
                                        :threshold="$attrs['threshold'] ?? null"
                                        :diagonal-ratio="$attrs['diagonalRatio'] ?? null"
                                        :thickness="$attrs['thickness'] ?? null"
                                        :radius="$attrs['radius'] ?? null"
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
