{{-- Stack: the ball's field is the fixed backdrop, the header + scrolling
     variations ride on top of it, so scrolling the pads leaves the field put.

     The ball is offset with margin rather than the circle's own left/top props:
     this host build's CircleRenderer is a bare Box and ignores them. --}}
<native:stack class="flex-1 w-full h-full bg-gray-950">
    <native:column class="w-full h-full px-10 pt-24 pb-10">
        <native:column class="flex-1 w-full bg-gray-900 rounded-2xl border border-white/10">
            <native:column class="w-6 h-6 rounded-full bg-red-500 ml-[{{ (int) $ballX }}] mt-[{{ (int) $ballY }}]" />
        </native:column>
    </native:column>

    <native:column class="w-full h-full">
        <native:row class="w-full px-4 pt-8 pb-1 items-center justify-between">
            <native:button label="Back" @press="leave" />
            <native:text class="text-white text-lg font-semibold">D-pad demo</native:text>
            <native:column class="w-16" />
        </native:row>

        {{-- Every pad here sets only the prop named under it, so the middle of
             each row is the shipped default. --}}
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

        <native:row class="w-full px-4 pb-6 items-end justify-between">
            <native:text class="text-gray-400 text-xs">steering pad reports "{{ $heldDirections ?: 'nothing' }}"</native:text>
            <native:dpad class="w-28 h-28" @change="steer" />
        </native:row>
    </native:column>
</native:stack>
