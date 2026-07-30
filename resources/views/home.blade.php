<native:column class="flex-1 w-full h-full bg-black">
    <native:text class="text-white text-2xl font-semibold px-4 pt-10 pb-4">Retro Emulator</native:text>

    <native:scroll-view class="flex-1 w-full">
        <native:column class="w-full items-center px-4 pb-8 gap-3">
            @forelse (array_chunk($systems, 2) as $pair)
                <native:row class="gap-3 justify-center">
                    @foreach ($pair as $system)
                        @php
                            $sid = $system['id'];
                            $sname = \App\Support\Catalog::shortName($sid, $system['name']);
                            $sroute = '/play/'.$sid;
                        @endphp
                        <native:pressable native:key="{{ $sid }}" class="w-44 py-6 rounded-2xl bg-gray-800 items-center justify-center border border-white/10" @navigate.none="$sroute">
                            <native:text class="text-white text-lg font-semibold">{{ $sname }}</native:text>
                        </native:pressable>
                    @endforeach
                </native:row>
            @empty
                <native:text class="text-yellow-400 py-4">No systems compiled into this build.</native:text>
            @endforelse

            <native:pressable class="w-44 mt-4 py-4 rounded-2xl bg-gray-900 items-center justify-center border border-white/10" @navigate.none="'/dpads'">
                <native:text class="text-white text-base font-semibold">D-pad demo</native:text>
            </native:pressable>
        </native:column>
    </native:scroll-view>
</native:column>
