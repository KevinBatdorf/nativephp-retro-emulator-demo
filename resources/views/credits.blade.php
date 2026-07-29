<native:column class="flex-1 w-full h-full bg-black">
    <native:row class="w-full px-4 pt-8 pb-2 items-center justify-between">
        <native:button label="Back" @press="leave" />
        <native:text class="text-white text-lg font-semibold">Credits</native:text>
        {{-- Spacer keeps the title centered against the Back button. --}}
        <native:column class="w-16" />
    </native:row>

    <native:scroll-view class="flex-1 w-full">
        <native:column class="w-full px-5 pb-10 gap-4">
            <native:text class="text-gray-400 text-sm pt-2">
                Every bundled game is freely-licensed homebrew, included with
                gratitude to its author. Full license texts ship in the app
                bundle (resources/roms/license.txt).
            </native:text>

            <native:text class="text-white text-base font-semibold pt-2">Games</native:text>
            @foreach ($games as $g)
                <native:column class="w-full gap-1 rounded-xl bg-gray-900 border border-white/10 px-4 py-3">
                    <native:text class="text-white text-sm font-semibold">{{ $g['game'] }}</native:text>
                    <native:text class="text-gray-300 text-xs">by {{ $g['by'] }} — {{ $g['license'] }}</native:text>
                    <native:text class="text-gray-400 text-xs">{{ $g['url'] }}</native:text>
                    @isset ($g['note'])
                        <native:text class="text-gray-400 text-xs pt-1">{{ $g['note'] }}</native:text>
                    @endisset
                </native:column>
            @endforeach

            <native:text class="text-white text-base font-semibold pt-2">Powered by</native:text>
            @foreach ($components as $c)
                <native:column class="w-full gap-1 rounded-xl bg-gray-900 border border-white/10 px-4 py-3">
                    <native:text class="text-white text-sm font-semibold">{{ $c['name'] }}</native:text>
                    <native:text class="text-gray-400 text-xs">{{ $c['detail'] }}</native:text>
                </native:column>
            @endforeach
        </native:column>
    </native:scroll-view>
</native:column>
