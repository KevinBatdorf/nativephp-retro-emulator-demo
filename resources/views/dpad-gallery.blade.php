{{-- Every pad below sets only the prop named under it; the rest are the
     element's defaults, so the middle of each row is the shipped look. --}}
<native:column class="flex-1 w-full h-full bg-gray-950">
    <native:row class="w-full px-4 pt-8 pb-1 items-center justify-between">
        <native:button label="Back" @press="leave" />
        <native:text class="text-white text-lg font-semibold">D-pad variations</native:text>
        <native:column class="w-16" />
    </native:row>

    <native:scroll-view class="flex-1 w-full">
        <native:column class="w-full px-3 pb-8 gap-4">
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
</native:column>
