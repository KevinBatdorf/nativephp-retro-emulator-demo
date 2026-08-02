<native:column class="flex-1 w-full h-full bg-black px-5 pt-14 pb-10 gap-3">
    @forelse (array_chunk($systems, 2) as $pair)
        <native:row class="flex-1 w-full gap-3 items-stretch">
            @foreach ($pair as $system)
                @php
                    $sid = $system['id'];
                    $sname = \App\Support\Catalog::shortName($sid, $system['name']);
                    $sroute = '/play/'.$sid;
                @endphp
                <native:pressable native:key="{{ $sid }}" class="flex-1 h-full rounded-2xl bg-gray-800 items-center justify-center border border-white/10" @navigate.none="$sroute">
                    <native:text class="text-white text-lg font-semibold">{{ $sname }}</native:text>
                </native:pressable>
            @endforeach
            @if (count($pair) === 1)
                <native:column class="flex-1" />
            @endif
        </native:row>
    @empty
        <native:text class="text-yellow-400 py-4">No systems compiled into this build.</native:text>
    @endforelse

    <native:row class="flex-1 w-full items-stretch">
        <native:pressable class="flex-1 h-full rounded-2xl bg-gray-900 items-center justify-center border border-white/10" @navigate.none="'/dpads'">
            <native:text class="text-white text-base font-semibold">D-pad demo</native:text>
        </native:pressable>
    </native:row>
</native:column>
