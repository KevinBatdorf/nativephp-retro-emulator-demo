{{-- Eight rows do not fit a phone; the grid scrolls. --}}
<native:scroll-view class="flex-1 w-full h-full bg-black">
<native:column class="w-full px-5 pt-14 pb-10 gap-2">
    @foreach ($rows as $row)
        <native:text class="{{ $row['raw'] ? 'text-amber-400' : 'text-teal-400' }} text-xs">{{ $row['label'] }}</native:text>
        <native:row class="w-full h-20 gap-3 items-stretch">
            @forelse ($row['roms'] as $rom)
                <native:pressable native:key="{{ $row['system'].'-'.$row['engine'].'-'.($row['raw'] ? 'r' : 'd').'-'.basename($rom) }}"
                    class="flex-1 h-full rounded-2xl bg-gray-800 items-center justify-center border border-white/10"
                    @press="play('{{ $row['system'] }}', '{{ $rom }}', '{{ $row['engine'] }}', {{ $row['raw'] ? 'true' : 'false' }})">
                    <native:text class="text-white text-sm font-semibold text-center">{{ pathinfo($rom, PATHINFO_FILENAME) }}</native:text>
                </native:pressable>
            @empty
                <native:column class="flex-1 items-center justify-center">
                    <native:text class="text-yellow-400 text-xs">drop ROMs into storage/app/roms/{{ $row['system'] }}/</native:text>
                </native:column>
            @endforelse
            @for ($i = count($row['roms']); $i < 3; $i++)
                <native:column class="flex-1" />
            @endfor
        </native:row>
    @endforeach
</native:column>
</native:scroll-view>
